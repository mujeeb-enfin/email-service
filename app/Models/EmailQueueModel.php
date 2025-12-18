<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailQueueModel extends Model
{
    protected $table            = 'email_queue';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'eq_account_id',
        'eq_template_id',
        'eq_from_email',
        'eq_payload',
        'eq_recipient_to',
        'eq_recipient_cc',
        'eq_recipient_bcc',
        'eq_subject',
        'eq_scheduled_time',
        'eq_email_status',
        'eq_attachments',
        'eq_sent_at',
        'eq_retry_count',
        'eq_max_retries',
        'eq_last_error',
        'eq_message_id'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Validation
    protected $validationRules = [
        'eq_account_id'    => 'permit_empty|integer',
        'eq_template_id'   => 'permit_empty|integer',
        'eq_recipient_to'  => 'required',
        'eq_subject'       => 'required|max_length[500]',
        'eq_email_status'  => 'required|in_list[pending,queued,processing,sent,failed,scheduled,cancelled]',
        'eq_max_retries'   => 'permit_empty|integer'
    ];

    protected $validationMessages = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * Get pending emails for queue processing
     */
    public function getPendingEmails($limit = 100)
    {
        return $this->whereIn('eq_email_status', ['pending', 'scheduled'])
            ->groupStart()
                ->where('eq_scheduled_time IS NULL')
                ->orWhere('eq_scheduled_time <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->limit($limit)
            ->findAll();
    }

    /**
     * Get emails by status
     */
    public function getByStatus(string $status, $limit = null)
    {
        $builder = $this->where('eq_email_status', $status);
        
        if ($limit) {
            $builder->limit($limit);
        }
        
        return $builder->findAll();
    }

    /**
     * Update email status
     */
    public function updateStatus(int $id, string $status, array $additionalData = [])
    {
        $data = array_merge(['eq_email_status' => $status], $additionalData);
        return $this->update($id, $data);
    }

    /**
     * Increment retry count
     */
    public function incrementRetry(int $id, string $error = null)
    {
        $email = $this->find($id);
        
        if (!$email) {
            return false;
        }

        $retryCount = $email['eq_retry_count'] + 1;
        $updateData = [
            'eq_retry_count' => $retryCount,
            'eq_last_error'  => $error
        ];

        // Check if max retries reached
        if ($retryCount >= $email['eq_max_retries']) {
            $updateData['eq_email_status'] = 'failed';
            $updateData['eq_failed_at'] = date('Y-m-d H:i:s');
        } else {
            $updateData['eq_email_status'] = 'queued';
        }

        return $this->update($id, $updateData);
    }

    /**
     * Get email queue with template details
     */
    public function getEmailWithTemplate(int $id)
    {
        return $this->select('email_queue.*, email_templates.*')
            ->join('email_templates', 'email_templates.id = email_queue.eq_template_id', 'left')
            ->where('email_queue.id', $id)
            ->first();
    }

    public function searchQueue(array $filters = [])
    {
        $model = $this; // Use Model instance for paginate
        $builder = $this->builder();

        if (!empty($filters['status'])) {
            $builder->where('eq_email_status', $filters['status']);
        }

        if (!empty($filters['account_id'])) {
            $builder->where('eq_account_id', $filters['account_id']);
        }

        if (!empty($filters['template_id'])) {
            $builder->where('eq_template_id', $filters['template_id']);
        }

        if (!empty($filters['from_date'])) {
            $builder->where('created_at >=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $builder->where('created_at <=', $filters['to_date']);
        }

        if (!empty($filters['search'])) {
            $builder->groupStart()
                ->like('eq_recipient_to', $filters['search'])
                ->orLike('eq_subject', $filters['search'])
                ->groupEnd();
        }

        // Instead of returning $builder, return Model for paginate
        $model->builderInstance = $builder; // store builder in model
        return $model;
    }

    /**
     * Override paginate() to use custom builder
     */
    public function paginateWithBuilder($perPage = 10, $page = 1)
    {
        if (!isset($this->builderInstance)) {
            return $this->paginate($perPage, 'default', $page);
        }

        $offset = ($page - 1) * $perPage;
        $data = $this->builderInstance
                    ->orderBy('id', 'DESC')
                    ->limit($perPage, $offset)
                    ->get()
                    ->getResultArray();

        $total = $this->builderInstance->countAllResults(false);

        return [
            'data' => $data,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }


    /**
     * Get failed emails for retry
     */
    public function getFailedEmailsForRetry()
    {
        return $this->where('eq_email_status', 'failed')
            ->where('eq_retry_count <', 'eq_max_retries')
            ->findAll();
    }
}