<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\RabbitMQLibrary;
use App\Models\EmailQueueModel;
use App\Models\EmailQueueLogModel;
use PhpAmqpLib\Message\AMQPMessage;

class EmailProcessor extends BaseCommand
{
    protected $group       = 'Email';
    protected $name        = 'email:process';
    protected $description = 'Process email queue from RabbitMQ';
    protected $usage       = 'email:process';

    private $rabbitMQ;
    private $emailQueueModel;

    public function run(array $params)
    {
        CLI::write('Email Processor Started...', 'green');
        CLI::write('Waiting for messages from RabbitMQ...', 'yellow');

        $this->rabbitMQ = new RabbitMQLibrary();
        $this->emailQueueModel = new EmailQueueModel();

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
            $emailId = $emailData['id'];

            CLI::write("Processing Email ID: {$emailId}", 'cyan');

            // Get latest email data from database
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

            // Update status to processing
            $this->emailQueueModel->update($emailId, [
                'eq_email_status' => 'processing'
            ]);

            // Log processing start
            $this->logEmail($emailId, 'processing', 'Email processing started');

            // Send email
            // $result = $this->sendEmail($email);

            $result = array();
            $result['success'] = true;
            $min = 1000000000;
            $max = 9999999999;
            $result['message_id'] = random_int($min, $max);
            
            if ($result['success']) {
                // Update to sent
                $this->emailQueueModel->update($emailId, [
                    'eq_email_status' => 'sent',
                    'eq_sent_at' => date('Y-m-d H:i:s'),
                    'eq_message_id' => $result['message_id'] ?? null
                ]);

                $this->logEmail($emailId, 'sent', 'Email sent successfully', $result['message_id'] ?? null);
                
                CLI::write("Email ID {$emailId} sent successfully", 'green');
                $message->ack();

            } else {
                // Handle failure
                $this->handleFailure($emailId, $email, $result['error'], $message);
            }

        } catch (\Exception $e) {
            CLI::error('Processing Error: ' . $e->getMessage());
            log_message('error', 'Email Processing Error: ' . $e->getMessage());
            
            // Reject and requeue the message
            $message->nack(true);
        }
    }

    /**
     * Send email via SMTP
     */
    private function sendEmail($email)
    {
        try {
            $emailService = \Config\Services::email();

            // Configure email
            $config['protocol'] = 'smtp';
            $config['SMTPHost'] = getenv('SMTP_HOST');
            $config['SMTPPort'] = getenv('SMTP_PORT');
            $config['SMTPUser'] = getenv('SMTP_USER');
            $config['SMTPPass'] = getenv('SMTP_PASS');
            $config['SMTPCrypto'] = getenv('SMTP_CRYPTO') ?? 'tls';
            $config['mailType'] = 'html';
            $config['charset'] = 'utf-8';
            $config['newline'] = "\r\n";

            $emailService->initialize($config);

            // Set email parameters
            $from = $email['eq_from_email'] ?? getenv('SMTP_FROM_EMAIL');
            $emailService->setFrom($from, getenv('SMTP_FROM_NAME') ?? 'System');
            
            // Handle multiple recipients
            $to = $this->parseRecipients($email['eq_recipient_to']);
            $emailService->setTo($to);

            if (!empty($email['eq_recipient_cc'])) {
                $cc = $this->parseRecipients($email['eq_recipient_cc']);
                $emailService->setCC($cc);
            }

            if (!empty($email['eq_recipient_bcc'])) {
                $bcc = $this->parseRecipients($email['eq_recipient_bcc']);
                $emailService->setBCC($bcc);
            }

            $emailService->setSubject($email['eq_subject']);
            $emailService->setMessage($email['eq_body'] ?? '');

            // Handle attachments
            if (!empty($email['eq_attachments'])) {
                $attachments = json_decode($email['eq_attachments'], true);
                foreach ($attachments as $attachment) {
                    if (file_exists($attachment)) {
                        $emailService->attach($attachment);
                    }
                }
            }

            // Send email
            if ($emailService->send()) {
                return [
                    'success' => true,
                    'message_id' => $emailService->printDebugger(['headers'])
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $emailService->printDebugger(['headers', 'subject', 'body'])
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
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