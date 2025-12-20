<?php

if (!function_exists('getAccountId')) {
    function getAccountId()
    {
        $config = config('RuntimeConfig');

        // Optional: return 0 if not set
        return $config->accountId ?? 0;
    }
}

if (!function_exists('getRootAccount')) {
    function isRootAccount()
    {
        return getAccountId() == 0;
    }
}

if (!function_exists('getWhitelistedDomains')) {
    function getWhitelistedDomains()
    {
        return [
            'api.emailservice.com',
            'cdn.emailservice.com',
        ];
    }
}

if (!function_exists('getEmailAttachmentMaxSize')) {
    function getEmailAttachmentMaxSize()
    {
        return 0.5; // in (MB)
    }
}

