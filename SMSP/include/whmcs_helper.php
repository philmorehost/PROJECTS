<?php
// WHMCS API Helper for Custom Domains

class WHMCSHelper {
    public static function checkDomainAvailability($domainName, $tld) {
        $api_url = defined('WHMCS_API_URL') ? WHMCS_API_URL : '';
        $api_identifier = defined('WHMCS_API_IDENTIFIER') ? WHMCS_API_IDENTIFIER : '';
        $api_secret = defined('WHMCS_API_SECRET') ? WHMCS_API_SECRET : '';
        
        $domain = trim($domainName) . trim($tld);

        if (empty($api_url) || empty($api_identifier) || empty($api_secret)) {
            // Mock response if WHMCS is not fully configured
            // Check if domain contains "taken" or similar for testing
            if (str_contains(strtolower($domainName), 'taken') || str_contains(strtolower($domainName), 'google') || str_contains(strtolower($domainName), 'facebook')) {
                return [
                    'status' => 'success',
                    'available' => false,
                    'message' => 'Domain is already registered.'
                ];
            }
            return [
                'status' => 'success',
                'available' => true,
                'message' => 'Domain is available!'
            ];
        }

        $postfields = [
            'identifier' => $api_identifier,
            'secret' => $api_secret,
            'action' => 'DomainWhois',
            'domain' => $domain,
            'responsetype' => 'json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['result']) && $data['result'] === 'success') {
                // WHMCS domainwhois returns status: "available" or "expired" or "registered"
                $status = isset($data['status']) ? strtolower($data['status']) : '';
                return [
                    'status' => 'success',
                    'available' => ($status === 'available'),
                    'message' => ($status === 'available') ? 'Domain is available!' : 'Domain is already registered.'
                ];
            }
        }

        return [
            'status' => 'error',
            'available' => false,
            'message' => 'WHMCS API connection failed or returned an error.'
        ];
    }
}
?>
