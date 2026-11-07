<?php
/**
 * cPanel Domain Automation Helper
 */
if (!defined('PORTAL_PATH')) exit;

class CPanelDomainHelper {
    /**
     * Automates domain/subdomain mapping to cPanel
     * @param string $domain The custom domain to add (e.g. myschool.com or portal.myschool.com)
     * @return array array('status' => bool, 'message' => string)
     */
    public static function addAddonDomain($domain) {
        $host = defined('CPANEL_HOST') ? CPANEL_HOST : 'localhost';
        $user = defined('CPANEL_USERNAME') ? CPANEL_USERNAME : 'sms';
        $token = defined('CPANEL_API_TOKEN') ? CPANEL_API_TOKEN : '';
        $doc_root = defined('CPANEL_DOC_ROOT') ? CPANEL_DOC_ROOT : 'portal.schoolapp.ng';

        if (empty($token)) {
            return [
                'status' => true, 
                'message' => 'Saved locally (cPanel automation is not configured: API Token is empty).'
            ];
        }

        // Clean domain string
        $domain = strtolower(trim(preg_replace('/^https?:\/\//', '', $domain), '/ '));
        if (empty($domain)) {
            return ['status' => false, 'message' => 'Empty domain name provided'];
        }
        
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return ['status' => false, 'message' => 'Invalid domain format: ' . $domain];
        }
        
        // Determine whether it's a subdomain (e.g. portal.school.com) or primary (school.com)
        $is_subdomain = count($parts) > 2;
        
        if ($is_subdomain) {
            $subdomain = $parts[0];
            $root_domain = implode('.', array_slice($parts, 1));
            
            $query_data = [
                'domain' => $subdomain,
                'rootdomain' => $root_domain,
                'dir' => $doc_root,
                'disallowed_domains' => 0
            ];
            
            $response = self::execute_cpanel_uapi('SubDomain', 'addsubdomain', $query_data, $host, $user, $token);
            
            if ($response['status']) {
                $result = json_decode($response['data'], true);
                if (isset($result['status']) && $result['status'] == 1) {
                    return ['status' => true, 'message' => "Successfully mapped subdomain {$domain} in cPanel."];
                }
                
                $error_msg = isset($result['errors']) ? implode(', ', $result['errors']) : '';
                
                // If the root domain belongs to another cPanel account on the same server,
                // fall back to mapping the full subdomain string using the reliable API2 AddonDomain function.
                if (stripos($error_msg, 'belong') !== false || stripos($error_msg, 'not own') !== false || stripos($error_msg, 'does not') !== false) {
                    $is_subdomain = false;
                } else {
                    return ['status' => false, 'message' => "cPanel API Error: " . ($error_msg ?: 'Unknown error')];
                }
            } else {
                return ['status' => false, 'message' => $response['message']];
            }
        }

        if (!$is_subdomain) {
            $subdomain_prefix = preg_replace('/[^a-z0-9]/', '', $parts[0]) . '-' . mt_rand(100, 999);
            
            $query_data = [
                'newdomain' => $domain,
                'subdomain' => $subdomain_prefix,
                'dir' => $doc_root,
                'ftp_user' => substr($subdomain_prefix, 0, 8),
                'ftp_pass' => password_hash(random_bytes(10), PASSWORD_DEFAULT)
            ];
            
            // Fallback: Use cPanel API2 AddonDomain::addaddondomain to ensure compatibility
            $response = self::execute_cpanel_api2('AddonDomain', 'addaddondomain', $query_data, $host, $user, $token);
            
            if ($response['status']) {
                $result = json_decode($response['data'], true);
                if (isset($result['cpanelresult']['data'][0]['result']) && $result['cpanelresult']['data'][0]['result'] == 1) {
                    return ['status' => true, 'message' => "Successfully mapped {$domain} in cPanel."];
                } else {
                    $error_msg = isset($result['cpanelresult']['error']) ? $result['cpanelresult']['error'] : (isset($result['cpanelresult']['data'][0]['reason']) ? $result['cpanelresult']['data'][0]['reason'] : 'Unknown cPanel API2 error');
                    return ['status' => false, 'message' => "cPanel API Error: " . $error_msg];
                }
            } else {
                return ['status' => false, 'message' => $response['message']];
            }
        }
        
        return ['status' => false, 'message' => 'Unexpected domain configuration state'];
    }

    /**
     * Executes cPanel UAPI call
     */
    private static function execute_cpanel_uapi($module, $function, $query_data, $host, $user, $token) {
        $url = "https://{$host}:2083/execute/{$module}/{$function}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($query_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: cpanel {$user}:{$token}"
        ]);
        
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            return ['status' => false, 'message' => "cPanel Connection failure: " . $curl_error];
        }
        
        return ['status' => true, 'data' => $response];
    }

    /**
     * Executes cPanel API2 call
     */
    private static function execute_cpanel_api2($module, $function, $query_data, $host, $user, $token) {
        $url = "https://{$host}:2083/json-api/cpanel";
        
        $post_data = array_merge([
            'cpanel_jsonapi_user' => $user,
            'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function
        ], $query_data);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: cpanel {$user}:{$token}"
        ]);
        
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            return ['status' => false, 'message' => "cPanel Connection failure: " . $curl_error];
        }
        
        return ['status' => true, 'data' => $response];
    }
}
