<?php

namespace App\Controllers;

use App\Models\EmailTemplateModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class EmailTemplateController extends ResourceController
{
    use ResponseTrait;

    protected $modelName = 'App\Models\EmailTemplateModel';
    protected $format    = 'json';

    public function current_time()
    {
        die(date('Y-m-d H:i:s'));
    }

    /**
     * Get all email templates with pagination and filtering
     * GET /api/email-templates
     */
    public function index()
    {
        try {
            $model = new EmailTemplateModel();
            
            // Get query parameters
            $page      = (int) ($this->request->getGet('page') ?? 1);
            $perPage   = (int) ($this->request->getGet('per_page') ?? 10);
            $search    = $this->request->getGet('search');
            $status    = $this->request->getGet('status');
            $accountId = $this->request->getGet('account_id');

            // Build filters
            $filters = [];
            if ($search) $filters['search'] = $search;
            if ($status) $filters['status'] = $status;
            if ($accountId !== null) $filters['account_id'] = $accountId;

            // Apply filters
            $model->searchTemplates($filters);

            // Get paginated results
            $data = $model->orderBy('id', 'DESC')
                        ->paginate($perPage, 'default', $page);

            $pager = $model->pager;

            return $this->respond([
                'status' => 'success',
                'message' => 'Email templates retrieved successfully',
                'data' => $data,
                'pagination' => [
                    'current_page' => $page,
                    'per_page'     => $perPage,
                    'total'        => $pager->getTotal(),
                    'total_pages'  => $pager->getPageCount()
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }


    /**
     * Get single email template
     * GET /api/email-templates/{id}
     */
    public function show($id = null)
    {
        try {
            $model = new EmailTemplateModel();
            $template = $model->find($id);

            if (!$template) {
                return $this->failNotFound('Email template not found');
            }

            return $this->respond([
                'status' => 'success',
                'message' => 'Email template retrieved successfully',
                'data' => $template
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Get template by code
     * GET /api/email-templates/by-code/{code}
     */
    public function getByCode($code = null)
    {
        try {
            $accountId = $this->request->getGet('account_id');
            $model = new EmailTemplateModel();
            $template = $model->getByCode($code, $accountId);

            if (!$template) {
                return $this->failNotFound('Email template not found');
            }

            return $this->respond([
                'status' => 'success',
                'message' => 'Email template retrieved successfully',
                'data' => $template
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Create new email template
     * POST /api/email-templates
     */
    public function create()
    {
        try {
            $model = new EmailTemplateModel();
            
            // Get JSON input
            $json = $this->request->getJSON(true);
            
            if (empty($json)) {
                return $this->fail('Invalid JSON data', 400);
            }
            
            $data = [
                'et_template_name'      => $json['et_template_name'] ?? null,
                'et_code'               => $json['et_code'] ?? null,
                'et_subject'            => $json['et_subject'] ?? null,
                'et_code_account_id'    => $json['et_code']."_".$json['et_account_id'] ?? null,
                'et_body'               => $json['et_body'] ?? null,
                'et_variables'          => isset($json['et_variables']) ? json_encode($json['et_variables']) : null,
                'et_status'             => $json['et_status'] ?? 'active',
                'et_account_id'         => $json['et_account_id'] ?? 0,
                'created_by'            => $json['created_by'] ?? null
            ];
            if ($model->insert($data)) {
                $insertedId = $model->getInsertID();
                $template = $model->find($insertedId);

                return $this->respondCreated([
                    'status' => 'success',
                    'message' => 'Email template created successfully',
                    'data' => $template
                ]);
            } else {
                return $this->fail($model->errors(), 400);
            }

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Update email template
     * PUT /api/email-templates/{id}
     */
    public function update($id = null)
    {
        try {
            $model = new EmailTemplateModel();
            
            $existing = $model->find($id);
            if (!$existing) {
                return $this->failNotFound('Email template not found');
            }

            $json = $this->request->getJSON(true);
            
            $data = [];
            if (isset($json['et_template_name'])) $data['et_template_name'] = $json['et_template_name'];
            if (isset($json['et_code'])) $data['et_code'] = $json['et_code'];
            if (isset($json['et_subject'])) $data['et_subject'] = $json['et_subject'];
            if (isset($json['et_body'])) $data['et_body'] = $json['et_body'];
            if (isset($json['et_variables'])) $data['et_variables'] = json_encode($json['et_variables']);
            if (isset($json['et_status'])) $data['et_status'] = $json['et_status'];
            if (isset($json['et_account_id'])) $data['et_account_id'] = $json['et_account_id'];
            if (isset($json['updated_by'])) $data['updated_by'] = $json['updated_by'];

            if ($model->update($id, $data)) {
                $template = $model->find($id);

                return $this->respond([
                    'status' => 'success',
                    'message' => 'Email template updated successfully',
                    'data' => $template
                ]);
            } else {
                return $this->fail($model->errors(), 400);
            }

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Delete email template
     * DELETE /api/email-templates/{id}
     */
    public function delete($id = null)
    {
        try {
            $model = new EmailTemplateModel();
            
            $existing = $model->find($id);
            if (!$existing) {
                return $this->failNotFound('Email template not found');
            }

            if ($model->delete($id)) {
                return $this->respondDeleted([
                    'status' => 'success',
                    'message' => 'Email template deleted successfully'
                ]);
            } else {
                return $this->fail('Failed to delete email template', 500);
            }

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * Get active templates only
     * GET /api/email-templates/active
     */
    public function getActive()
    {
        try {
            $model = new EmailTemplateModel();
            $templates = $model->getActiveTemplates();

            return $this->respond([
                'status' => 'success',
                'message' => 'Active email templates retrieved successfully',
                'data' => $templates
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }
}