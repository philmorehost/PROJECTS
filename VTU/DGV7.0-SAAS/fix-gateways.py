import os
import glob

gateway_dir = r"c:\Users\User\Downloads\DGV7.0 SAAS and NON-SAAS and LM\DGV7.0-SAAS\func\api-gateway"
files = [
    "cg-data-hdkdata-com.php",
    "shared-data-hdkdata-com.php",
    "sme-data-hdkdata-com.php"
]

for filename in files:
    file_path = os.path.join(gateway_dir, filename)
    if os.path.exists(file_path):
        with open(file_path, "r", encoding="utf-8") as f:
            content = f.read()
        
        # Fix 1: api_base_url normalization
        old_url = """$curl_url = "https://" . $api_detail["api_base_url"] . "/api/data/";"""
        new_url = """$clean_base_url = preg_replace('#^https?://#', '', trim($api_detail["api_base_url"]));
        $clean_base_url = rtrim($clean_base_url, "/");
        $curl_url = "https://" . $clean_base_url . "/api/data/";"""
        if old_url in content:
            content = content.replace(old_url, new_url)

        # Fix 2: Token API key double-injection fix
        old_auth = """"Authorization: Token " . $api_detail["api_key"],"""
        new_auth = """$clean_api_key = trim(str_ireplace("Token ", "", $api_detail["api_key"]));
        $curl_http_headers = array(
            "Authorization: Token " . $clean_api_key,"""
        if old_auth in content:
            content = content.replace("        $curl_http_headers = array(\n            " + old_auth, new_auth)
            
        # Fix 3: json_encode true bug
        content = content.replace("), true);", "));")

        with open(file_path, "w", encoding="utf-8") as f:
            f.write(content)
        print(f"Patched {filename}")
