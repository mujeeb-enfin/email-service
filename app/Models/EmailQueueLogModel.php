<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailQueueLogModel extends Model
{
    protected $table            = 'email_queue_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'eql_queue_id',
        'eql_status',
        'eql_message',
        'eql_error_details'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    // Validation
    protected $validationRules = [
        'eql_queue_id' => 'required|integer',
        'eql_status'   => 'required|max_length[50]'
    ];

    protected $validationMessages = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    /**
     * Get logs for specific email queue
     */
    public function getLogsByQueueId(int $queueId)
    {
        return $this->where('eql_queue_id', $queueId)
                    ->orderBy('created_at', 'DESC')
                    ->findAll();
    }

    /**
     * Get recent logs
     */
    public function getRecentLogs($limit = 100)
    {
        return $this->orderBy('created_at', 'DESC')
                    ->limit($limit)
                    ->findAll();
    }
}