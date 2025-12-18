<?php

namespace App\Models;

use CodeIgniter\Model;

class MessageBodyModel extends Model
{
    protected $table            = 'message_body';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;

    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'email_queue_id',
        'message_body',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Get message body by email_queue_id
     */
    public function getByQueueId(int $queueId)
    {
        return $this->where('email_queue_id', $queueId)->first();
    }

    /**
     * Upsert message body for a queue
     */
    public function upsertByQueueId(int $queueId, string $body)
    {
        $existing = $this->where('email_queue_id', $queueId)->first();

        if ($existing) {
            return $this->update($existing['id'], [
                'message_body' => $body
            ]);
        }

        return $this->insert([
            'email_queue_id' => $queueId,
            'message_body'   => $body
        ]);
    }
}
