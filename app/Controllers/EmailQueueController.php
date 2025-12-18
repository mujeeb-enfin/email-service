<?php

namespace App\Controllers;

use App\Models\EmailQueueModel;
use App\Models\EmailTemplateModel;
use App\Libraries\RabbitMQLibrary;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;
use App\Services\EmailSchedulerService;

class EmailQueueController extends ResourceController
{
    use ResponseTrait;

    protected $modelName = 'App\Models\EmailQueueModel';
    protected $format    = 'json';
    protected $rabbitMQ;

    public function __construct()
    {
        $this->rabbitMQ = new RabbitMQLibrary();
    }


    public function run_scheduler()
    {
        $scheduler = new EmailSchedulerService();

        try {
            $summary = $scheduler->processPendingEmails(false);

            return $this->respond([
                'error' => false,
                'total_processed' => $summary['total_processed'],
                'success' => $summary['success'],
                'failed' => $summary['failed']
            ]);

        } catch (\Exception $e) {
            log_message('error', 'Email Scheduler API Error: ' . $e->getMessage());
            return $this->respond([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * Get all email queue with pagination and filtering
     * GET /api/email-queue
     */
    public function index()
    {
        try {
            $model = new EmailQueueModel();
            
            // Get query parameters
            $page       = (int) ($this->request->getGet('page') ?? 1);
            $perPage    = (int) ($this->request->getGet('per_page') ?? 10);
            $status     = $this->request->getGet('status');
            $accountId  = $this->request->getGet('account_id');
            $templateId = $this->request->getGet('template_id');
            $search     = $this->request->getGet('search');
            $fromDate   = $this->request->getGet('from_date');
            $toDate     = $this->request->getGet('to_date');

            // Build filters
            $filters = [];
            if ($status) $filters['status'] = $status;
            if ($accountId) $filters['account_id'] = $accountId;
            if ($templateId) $filters['template_id'] = $templateId;
            if ($search) $filters['search'] = $search;
            if ($fromDate) $filters['from_date'] = $fromDate;
            if ($toDate) $filters['to_date'] = $toDate;

            // Get filtered model
            $searchModel = $model->searchQueue($filters);

            // Use custom pagination method
            $paginated = $searchModel->paginateWithBuilder($perPage, $page);

            return $this->respond([
                'status' => 'success',
                'message' => 'Email queue retrieved successfully',
                'data' => $paginated['data'],
                'pagination' => [
                    'current_page' => $paginated['current_page'],
                    'per_page' => $paginated['per_page'],
                    'total' => $paginated['total'],
                    'total_pages' => $paginated['total_pages']
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }


    /**
     * Get single email queue item
     * GET /api/email-queue/{id}
     */
    public function show($id = null)
    {
        try {
            $model = new EmailQueueModel();
            $email = $model->getEmailWithTemplate($id);

            if (!$email) {
                return $this->failNotFound('Email queue item not found');
            }

            return $this->respond([
                'status' => 'success',
                'message' => 'Email queue item retrieved successfully',
                'data' => $email
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Create new email queue (send email)
     * POST /api/email-queue
     */
    public function create()
    {
        try {
            $model = new EmailQueueModel();
            $templateModel = new EmailTemplateModel();

            $json = $this->request->getJSON(true);
            
            $templateId = $json['eq_template_id'] ?? null;
            $payload = $json['eq_payload'] ?? [];
            
            // If template provided, get template and process
            $subject = $json['eq_subject'] ?? '';
            $body = $json['eq_body'] ?? '';
            if ($templateId) {
                $template = $templateModel->find($templateId);
                
                if (!$template) {
                    return $this->failNotFound('Email template not found');
                }

                // Replace variables in subject and body
                $subject = $this->replaceVariables($template['et_subject'], $payload);
                $body = $this->replaceVariables($template['et_body'], $payload);
            }

            // Prepare queue data
            $data = [
                'eq_account_id'     => $json['eq_account_id'] ?? 0,
                'eq_template_id'    => $templateId,
                'eq_from_email'     => $json['eq_from_email'] ?? null,
                'eq_payload'        => json_encode($payload),
                'eq_body'           => $body,
                'eq_recipient_to'   => $json['eq_recipient_to'],
                'eq_recipient_cc'   => $json['eq_recipient_cc'] ?? null,
                'eq_recipient_bcc'  => $json['eq_recipient_bcc'] ?? null,
                'eq_subject'        => $subject,
                'eq_scheduled_time' => $json['eq_scheduled_time'] ?? null,
                'eq_email_status'   => isset($json['eq_scheduled_time']) ? 'scheduled' : 'pending',
                'eq_attachments'    => isset($json['eq_attachments']) ? json_encode($json['eq_attachments']) : null,
                'eq_max_retries'    => $json['eq_max_retries'] ?? 3
            ];

            if ($model->insert($data)) {
                $insertedId = $model->getInsertID();
                $email = $model->find($insertedId);

                // If not scheduled, immediately push to RabbitMQ
                if (!isset($json['eq_scheduled_time'])) {
                    $this->pushToQueue($email);
                }

                return $this->respondCreated([
                    'status' => 'success',
                    'message' => 'Email queued successfully',
                    'data' => $email
                ]);
            } else {
                return $this->fail($model->errors(), 400);
            }

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Update email queue item
     * PUT /api/email-queue/{id}
     */
    public function update($id = null)
    {
        try {
            $model = new EmailQueueModel();
            
            $existing = $model->find($id);
            if (!$existing) {
                return $this->failNotFound('Email queue item not found');
            }

            // Can only update if status is pending, scheduled, or failed
            if (!in_array($existing['eq_email_status'], ['pending', 'scheduled', 'failed'])) {
                return $this->fail('Cannot update email in current status', 400);
            }

            $json = $this->request->getJSON(true);
            
            $data = [];
            if (isset($json['eq_recipient_to'])) $data['eq_recipient_to'] = $json['eq_recipient_to'];
            if (isset($json['eq_recipient_cc'])) $data['eq_recipient_cc'] = $json['eq_recipient_cc'];
            if (isset($json['eq_recipient_bcc'])) $data['eq_recipient_bcc'] = $json['eq_recipient_bcc'];
            if (isset($json['eq_subject'])) $data['eq_subject'] = $json['eq_subject'];
            if (isset($json['eq_scheduled_time'])) {
                $data['eq_scheduled_time'] = $json['eq_scheduled_time'];
                $data['eq_email_status'] = 'scheduled';
            }
            if (isset($json['eq_attachments'])) $data['eq_attachments'] = json_encode($json['eq_attachments']);

            if ($model->update($id, $data)) {
                $email = $model->find($id);

                return $this->respond([
                    'status' => 'success',
                    'message' => 'Email queue updated successfully',
                    'data' => $email
                ]);
            } else {
                return $this->fail($model->errors(), 400);
            }

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Delete/Cancel email queue item
     * DELETE /api/email-queue/{id}
     */
    public function delete($id = null)
    {
        try {
            $model = new EmailQueueModel();
            
            $existing = $model->find($id);
            if (!$existing) {
                return $this->failNotFound('Email queue item not found');
            }

            // Can only cancel if status is pending, scheduled, or queued
            if (in_array($existing['eq_email_status'], ['pending', 'scheduled', 'queued'])) {
                // Update status to cancelled instead of deleting
                $model->update($id, ['eq_email_status' => 'cancelled']);

                return $this->respondDeleted([
                    'status' => 'success',
                    'message' => 'Email cancelled successfully'
                ]);
            } else {
                return $this->fail('Cannot cancel email in current status: ' . $existing['eq_email_status'], 400);
            }

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Retry failed email
     * POST /api/email-queue/{id}/retry
     */
    public function retry($id = null)
    {
        try {
            $model = new EmailQueueModel();
            
            $email = $model->find($id);
            if (!$email) {
                return $this->failNotFound('Email queue item not found');
            }

            if ($email['eq_email_status'] !== 'failed') {
                return $this->fail('Only failed emails can be retried', 400);
            }

            // Reset retry count and status
            $model->update($id, [
                'eq_email_status' => 'pending',
                'eq_retry_count' => 0,
                'eq_last_error' => null
            ]);

            $email = $model->find($id);
            
            // Push to queue
            $this->pushToQueue($email);

            return $this->respond([
                'status' => 'success',
                'message' => 'Email retry initiated successfully',
                'data' => $email
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Get queue statistics
     * GET /api/email-queue/statistics
     */
    public function statistics()
    {
        try {
            $model = new EmailQueueModel();
            $db = \Config\Database::connect();

            $stats = [
                'total' => $model->countAllResults(false),
                'pending' => $model->where('eq_email_status', 'pending')->countAllResults(false),
                'queued' => $model->where('eq_email_status', 'queued')->countAllResults(false),
                'processing' => $model->where('eq_email_status', 'processing')->countAllResults(false),
                'sent' => $model->where('eq_email_status', 'sent')->countAllResults(false),
                'failed' => $model->where('eq_email_status', 'failed')->countAllResults(false),
                'scheduled' => $model->where('eq_email_status', 'scheduled')->countAllResults(false),
                'cancelled' => $model->where('eq_email_status', 'cancelled')->countAllResults(false),
            ];

            // Get RabbitMQ queue count
            $stats['rabbitmq_queue_count'] = $this->rabbitMQ->getQueueCount();

            return $this->respond([
                'status' => 'success',
                'message' => 'Queue statistics retrieved successfully',
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Helper: Replace variables in template
     */
    private function replaceVariables($text, $variables)
    {
        foreach ($variables as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        return $text;
    }

    /**
     * Helper: Push email to RabbitMQ
     */
    private function pushToQueue($email)
    {
        $model = new EmailQueueModel();
        
        $success = $this->rabbitMQ->publish($email);
        
        if ($success) {
            $model->update($email['id'], ['eq_email_status' => 'queued']);
        } else {
            $model->update($email['id'], [
                'eq_email_status' => 'failed',
                'eq_last_error' => 'Failed to push to RabbitMQ'
            ]);
        }
    }
}