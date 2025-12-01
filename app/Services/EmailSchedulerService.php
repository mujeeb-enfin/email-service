<?php

namespace App\Services;

use App\Libraries\RabbitMQLibrary;
use App\Models\EmailQueueModel;
use CodeIgniter\CLI\CLI;

class EmailSchedulerService
{
    protected $rabbitMQ;
    protected $emailQueueModel;

    public function __construct()
    {
        $this->rabbitMQ = new RabbitMQLibrary();
        $this->emailQueueModel = new EmailQueueModel();
    }

    /**
     * Process pending emails
     * 
     * @param bool $cliMode If true, outputs messages to CLI
     * @return array Summary of processed emails
     */
    public function processPendingEmails(bool $cliMode = false): array
    {
        $pendingEmails = $this->emailQueueModel->getPendingEmails(100);

        if (empty($pendingEmails)) {
            if ($cliMode) CLI::write('No pending emails to process', 'yellow');
            return [
                'total_processed' => 0,
                'success' => 0,
                'failed' => 0
            ];
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($pendingEmails as $email) {
            $emailId = $email['id'];
            if ($cliMode) CLI::write("Processing Email ID: {$emailId}", 'cyan');

            $success = $this->rabbitMQ->publish($email);

            if ($success) {
                $this->emailQueueModel->update($emailId, [
                    'eq_email_status' => 'queued'
                ]);
                $successCount++;
                if ($cliMode) CLI::write("Email ID {$emailId} queued successfully", 'green');
            } else {
                $this->emailQueueModel->update($emailId, [
                    'eq_email_status' => 'failed',
                    'eq_last_error' => 'Failed to push to RabbitMQ'
                ]);
                $failCount++;
                if ($cliMode) CLI::error("Failed to push Email ID {$emailId} to queue");
            }
        }

        return [
            'total_processed' => count($pendingEmails),
            'success' => $successCount,
            'failed' => $failCount
        ];
    }
}
