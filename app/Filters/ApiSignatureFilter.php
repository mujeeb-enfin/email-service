<?php

namespace App\Filters;

use App\Models\AppAccountModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class ApiSignatureFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $apiKey     = $request->getHeaderLine('X-API-KEY');
        $timestamp  = $request->getHeaderLine('X-TIMESTAMP');
        $signature  = $request->getHeaderLine('X-SIGNATURE');

        // Step 1: Validate headers present
        if (!$apiKey || !$timestamp || !$signature) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => 'Missing authentication headers']);
        }

        // Step 2: Validate timestamp (5 minute window)
        // $currentTime = time();
        // if (abs($currentTime - (int)$timestamp) > 300) {
        //     return service('response')
        //         ->setStatusCode(401)
        //         ->setJSON(['error' => 'Request timestamp expired']);
        // }

        // Step 3: Fetch app account by API key
        $model = new AppAccountModel();
        $app = $model->where('aa_api_key', $apiKey)->first();

        if (!$app) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => 'Invalid API Key']);
        }

        if ($app['aa_status'] !== '1') {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => 'Account Disabled']);
        }

        // Step 4: Generate server-side signature
        $dataToSign = $apiKey . '|' . $timestamp;
        $serverSignature = hash_hmac('sha256', $dataToSign, $app['aa_api_secret']);

        // Step 5: Compare signatures (secure comparison)
        if (!hash_equals($serverSignature, $signature)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => 'Invalid Signature']);
        }

        // Step 6: Update last used time
        $model->update($app['aa_id'], ['aa_last_used_at' => date('Y-m-d H:i:s')]);

        // Allow request to continue
        return;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Not needed
    }
}
