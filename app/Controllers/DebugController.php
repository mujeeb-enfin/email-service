<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class DebugController extends ResourceController
{
    use ResponseTrait;

    public function current_time()
    {
        die(getAccountId().'---'.date('Y-m-d H:i:s'));
    }

    public function headers()
    {
        try {
            
            $apiKey = $this->request->getPost('api_key');;
            $apiSecret = $this->request->getPost('api_secret');
            $accountId = $this->request->getPost('account_id');
            $timestamp = $this->request->getPost('timestamp');
            $timestamp = $this->isValidTimestamp($timestamp) ? $timestamp : time();

            if (!$apiKey) {
                return $this->failNotFound('APP KEY MISSING');
            }

            if (!$apiSecret) {
                return $this->failNotFound('APP SECRET MISSING');
            }

            if ($accountId == "") {
                return $this->failNotFound('ACCOUNT ID MISSING');
            }
    
            $stringToSign = $apiKey . '|' . $accountId . '|' . $timestamp;
            $signature = hash_hmac('sha256', $stringToSign, $apiSecret);
    
            $headers = [
                "X-API-KEY: $apiKey",
                "X-TIMESTAMP: $timestamp",
                "X-SIGNATURE: $signature",
                "Content-Type: application/json"
            ];
            return $this->respond([
                'status' => 'success',
                'message' => 'Email templates retrieved successfully',
                'data' => $headers
            ]);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 500);
        }
    }

    private function isValidTimestamp($timestamp) {
        return is_numeric($timestamp) && (int)$timestamp == $timestamp && abs($timestamp) <= 2147483647;
    }
    
}