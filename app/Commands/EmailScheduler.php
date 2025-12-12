<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\EmailSchedulerService;

class EmailScheduler extends BaseCommand
{
    protected $group       = 'Email';
    protected $name        = 'email:schedule';
    protected $description = 'Push pending and scheduled emails to RabbitMQ queue';
    protected $usage       = 'email:schedule';

    public function run(array $params)
    {
        CLI::write('Email Scheduler Started...', 'green');

        $scheduler = new EmailSchedulerService();

        try {
            $summary = $scheduler->processPendingEmails(true);

            CLI::newLine();
            CLI::write("Scheduler Summary:", 'green');
            CLI::write("Total Processed: " . $summary['total_processed'], 'white');
            CLI::write("Successfully Queued: " . $summary['success'], 'green');
            CLI::write("Failed: " . $summary['failed'], 'red');

        } catch (\Exception $e) {
            CLI::error('Scheduler Error: ' . $e->getMessage());
            log_message('error', 'Email Scheduler Error: ' . $e->getMessage());
        }
    }
}
