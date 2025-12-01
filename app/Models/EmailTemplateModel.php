<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailTemplateModel extends Model
{
    protected $table            = 'email_templates';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'et_template_name',
        'et_code',
        'et_code_account_id',
        'et_subject',
        'et_body',
        'et_variables',
        'et_status',
        'et_account_id',
        'created_by',
        'updated_by'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Validation
    protected $validationRules = [
        'et_template_name'      => 'required|max_length[255]',
        'et_code'               => 'required|max_length[100]',
        'et_code_account_id'    => 'required|max_length[100]|is_unique[email_templates.et_code_account_id,id,{id}]',
        'et_subject'            => 'required|max_length[500]',
        'et_body'               => 'required',
        'et_status'             => 'required|in_list[active,inactive,draft]',
        'et_account_id'         => 'permit_empty|integer'
    ];

    protected $validationMessages = [
        'et_code_account_id' => [
            'is_unique' => 'Template code already exists.'
        ]
    ];

    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // ------------------------------
    // Custom Methods
    // ------------------------------

    public function getByCode(string $code, string $accountId = null)
    {
        return $this->where(array('et_code' => $code, 'et_account_id' => $accountId))->first();
    }

    public function getActiveTemplates()
    {
        return $this->where('et_status', 'active')->findAll();
    }

    public function getByAccount(int $accountId)
    {
        return $this->where('et_account_id', $accountId)->findAll();
    }

    /**
     * Search templates with filters
     * Returns the **model itself** so you can call paginate()
     */
    public function searchTemplates(array $filters = [])
    {
        if (!empty($filters['search'])) {
            $this->groupStart()
                ->like('et_template_name', $filters['search'])
                ->orLike('et_code', $filters['search'])
                ->orLike('et_subject', $filters['search'])
                ->groupEnd();
        }

        // Special logic for account filtering
        if (isset($filters['account_id'])) {

            $accountId = (int)$filters['account_id'];

            if ($accountId === 0) {
                // Case 1: Only global templates
                $this->where('et_account_id', 0);

            } else {
                // Case 2: Global templates except overridden + account-specific templates

                // Subquery: Get overridden codes
                $subQuery = $this->select('et_code')
                    ->where('et_account_id', $accountId)
                    ->getCompiledSelect();

                $this->groupStart()
                    ->groupStart() // global templates except overridden
                        ->where('et_account_id', 0)
                        ->where("et_code NOT IN ($subQuery)")
                    ->groupEnd()
                    ->orGroupStart() // account-specific templates
                        ->where('et_account_id', $accountId)
                    ->groupEnd()
                ->groupEnd();
            }
        }

        if (!empty($filters['status'])) {
            $this->where('et_status', $filters['status']);
        }

        return $this;
    }

}
