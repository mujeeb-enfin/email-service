<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\RabbitMQLibrary;
use App\Models\EmailQueueModel;
use App\Models\EmailTemplateModel;
use App\Models\EmailQueueLogModel;
use App\Models\MessageBodyModel;
use PhpAmqpLib\Message\AMQPMessage;

class EmailProcessor extends BaseCommand
{
    protected $group       = 'Email';
    protected $name        = 'email:process';
    protected $description = 'Process email queue from RabbitMQ';
    protected $usage       = 'email:process';

    private $rabbitMQ;
    private $emailQueueModel;
    private $emailTemplateModel;
    private $messageBodyModel;

    public function run(array $params)
    {
        CLI::write('Email Processor Started...', 'green');
        CLI::write('Waiting for messages from RabbitMQ...', 'yellow');

        $this->rabbitMQ = new RabbitMQLibrary();
        $this->emailQueueModel = new EmailQueueModel();
        $this->emailTemplateModel = new EmailTemplateModel();
        $this->messageBodyModel = new MessageBodyModel();

        try {
            $this->rabbitMQ->consume(function (AMQPMessage $message) {
                $this->processEmail($message);
            });

        } catch (\Exception $e) {
            CLI::error('Error: ' . $e->getMessage());
            log_message('error', 'Email Processor Error: ' . $e->getMessage());
        }
    }

    /**
     * Process individual email message
     */
    private function processEmail(AMQPMessage $message)
    {
        try {
            $emailData = json_decode($message->body, true);
            $emailId   = $emailData['id'];

            CLI::write("Processing Email ID: {$emailId}", 'cyan');

            // Fetch email queue record
            $email = $this->emailQueueModel->find($emailId);

            if (!$email) {
                CLI::error("Email ID {$emailId} not found in database");
                $message->ack();
                return;
            }

            // Check if email was cancelled
            if ($email['eq_email_status'] === 'cancelled') {
                CLI::write("Email ID {$emailId} was cancelled. Skipping...", 'yellow');
                $message->ack();
                return;
            }

            // Fetch email body from message_body table
            $messageBody = $this->messageBodyModel
                ->where('email_queue_id', $emailId)
                ->first();

            if (!$messageBody) {
                CLI::error("Email body not found for Email ID {$emailId}");
                $this->handleFailure(
                    $emailId,
                    $email,
                    'Email body missing',
                    $message
                );
                return;
            }

            // Update status to processing
            $this->emailQueueModel->update($emailId, [
                'eq_email_status' => 'processing'
            ]);

            // Log processing start
            $this->logEmail($emailId, 'processing', 'Email processing started');

            // Send email (PASS BODY EXPLICITLY)
            $result = $this->sendEmail(
                $email,
                $messageBody['message_body'],
                $emailId
            );

            if (!empty($result['success'])) {
                // Update to sent
                $this->emailQueueModel->update($emailId, [
                    'eq_email_status' => 'sent',
                    'eq_sent_at'      => date('Y-m-d H:i:s'),
                    'eq_message_id'   => $result['message_id'] ?? null
                ]);

                $this->logEmail(
                    $emailId,
                    'sent',
                    'Email sent successfully',
                    $result['message_id'] ?? null
                );

                CLI::write("Email ID {$emailId} sent successfully", 'green');
                $message->ack();

            } else {
                // Handle failure
                $this->handleFailure(
                    $emailId,
                    $email,
                    $result['error'] ?? 'Unknown error',
                    $message
                );
            }

        } catch (\Exception $e) {
            CLI::error('Processing Error: ' . $e->getMessage());
            log_message('error', 'Email Processing Error: ' . $e->getMessage());

            // Requeue message
            $message->nack(true);
        }
    }

    /**
     * Send email via SMTP
     */
    private function sendEmail($email, $body = null, $emailId = 0)
    {
        CLI::write("Start processing Email ID " . $emailId, 'yellow');

        try {
            $emailService = \Config\Services::email();

            // Email config
            $config = [
                'protocol'    => 'smtp',
                'SMTPHost'    => env('SMTP_HOST'),
                'SMTPPort'    => (int) env('SMTP_PORT'),
                'SMTPUser'    => env('SMTP_USER'),
                'SMTPPass'    => env('SMTP_PASS'),
                'SMTPCrypto'  => env('SMTP_CRYPTO', 'tls'),
                'mailType'    => 'html',  // HTML type
                'charset'     => 'utf-8',
                'newline'     => "\r\n",
            ];
            $emailService->initialize($config);

            // From
            $fromEmail = $email['eq_from_email'] ?? env('SMTP_FROM_EMAIL');
            $fromName  = env('SMTP_FROM_NAME', 'System');
            if (empty($fromEmail)) throw new \RuntimeException('From email not configured');
            $emailService->setFrom($fromEmail, $fromName);

            // To / CC / BCC
            $emailService->setTo($this->parseRecipients($email['eq_recipient_to']));
            if (!empty($email['eq_recipient_cc'])) $emailService->setCC($this->parseRecipients($email['eq_recipient_cc']));
            if (!empty($email['eq_recipient_bcc'])) $emailService->setBCC($this->parseRecipients($email['eq_recipient_bcc']));

            // Subject
            $emailService->setSubject((string) ($email['eq_subject'] ?? 'No Subject'));

            // ✅ Message **before attachments**
            $emailService->setMessage((string) ($body ?? ""));

            // $debug = $emailService->printDebugger($email);
            // CLI::write("Email ID {$emailId} failed with details: " . PHP_EOL . $debug, 'yellow');

            // Attachments **after** setting the message
            if (!empty($email['eq_attachments'])) {
                $attachments = json_decode($email['eq_attachments'], true);
                if (is_array($attachments)) {
                    foreach ($attachments as $attachment) {
                        if (is_string($attachment) && file_exists($attachment)) {
                            $emailService->attach($attachment);
                        }
                    }
                }
            }

            // Send
            if ($emailService->send()) {
                CLI::write("Email ID " . $emailId . " sent successfully", 'green');
                return [
                    'success' => true,
                    'message_id' => $emailService->printDebugger(['headers']),
                ];
            }

            $debug = $emailService->printDebugger(['headers','subject','body','smtp']);
            CLI::write("Email ID {$emailId} failed: " . PHP_EOL . $debug, 'red');

            return ['success' => false, 'error' => $debug];

        } catch (\Throwable $e) {
            CLI::write("Email ID {$emailId} failed with exception: " . $e->getMessage(), 'red');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle email sending failure
     */
    private function handleFailure($emailId, $email, $error, AMQPMessage $message)
    {
        $retryCount = $email['eq_retry_count'] + 1;
        $maxRetries = $email['eq_max_retries'];

        CLI::write("Email ID {$emailId} failed. Retry count: {$retryCount}/{$maxRetries}", 'red');

        if ($retryCount >= $maxRetries) {
            // Max retries reached - mark as failed
            $this->emailQueueModel->update($emailId, [
                'eq_email_status' => 'failed',
                'eq_retry_count' => $retryCount,
                'eq_last_error' => $error,
                'eq_failed_at' => date('Y-m-d H:i:s')
            ]);

            $this->logEmail($emailId, 'failed', 'Max retries reached', $error);
            
            CLI::error("Email ID {$emailId} permanently failed after {$maxRetries} attempts");
            $message->ack();

        } else {
            // Retry with exponential backoff
            $delaySeconds = pow(2, $retryCount) * 60; // 2min, 4min, 8min...

            $this->emailQueueModel->update($emailId, [
                'eq_email_status' => 'queued',
                'eq_retry_count' => $retryCount,
                'eq_last_error' => $error
            ]);

            $this->logEmail($emailId, 'retry', "Retry attempt {$retryCount}. Next retry in {$delaySeconds}s", $error);

            // Push back to queue with delay
            $email['eq_retry_count'] = $retryCount;
            $this->rabbitMQ->publishDelayed($email, $delaySeconds);

            CLI::write("Email ID {$emailId} requeued for retry in {$delaySeconds} seconds", 'yellow');
            $message->ack();
        }
    }

    /**
     * Parse recipients (handle comma-separated or JSON array)
     */
    private function parseRecipients($recipients)
    {
        if (empty($recipients)) {
            return [];
        }

        // Try JSON decode first
        $decoded = json_decode($recipients, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Otherwise split by comma
        return array_map('trim', explode(',', $recipients));
    }

    /**
     * Log email activity
     */
    private function logEmail($queueId, $status, $message, $errorDetails = null)
    {
        try {
            // $logModel = new EmailQueueLogModel();
            // $logModel->insert([
            //     'eql_queue_id' => $queueId,
            //     'eql_status' => $status,
            //     'eql_message' => $message,
            //     'eql_error_details' => $errorDetails
            // ]);
        } catch (\Exception $e) {
            log_message('error', 'Failed to log email activity: ' . $e->getMessage());
        }
    }
}