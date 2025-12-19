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

        if (!$apiKey || !$timestamp || !$signature) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => 'Missing authentication headers']);
        }

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

        // Validate timestamp (5 minute window)
        // $currentTime = time();
        // if (abs($currentTime - (int)$timestamp) > 300) {
        //     return service('response')
        //         ->setStatusCode(401)
        //         ->setJSON(['error' => 'Request timestamp expired']);
        // }

        $dataToSign = $apiKey . '|' . $app['aa_account_id'] . '|' . $timestamp;
        $serverSignature = hash_hmac('sha256', $dataToSign, $app['aa_api_secret']);

        if (!hash_equals($serverSignature, $signature)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => 'Invalid Signature']);
        }

        // ✅ SET RUNTIME CONFIG HERE
        $config = config('RuntimeConfig');
        $config->accountId = (int) $app['aa_account_id'];
        
        // Update last used time
        $model->update($app['aa_id'], [
            'aa_last_used_at' => date('Y-m-d H:i:s')
        ]);

        return;
    }


    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Not needed
    }
}
