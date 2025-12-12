<?php

namespace App\Models;

use CodeIgniter\Model;

class AppAccountModel extends Model
{
    protected $table      = 'app_account';
    protected $primaryKey = 'aa_id';

    protected $allowedFields = [
        'aa_account_id',
        'aa_email_id',
        'aa_credits',
        'aa_api_key',
        'aa_api_secret',
        'aa_status',
        'aa_created_at',
        'aa_updated_at',
        'aa_last_used_at'
    ];

    protected $returnType = 'array';
}
