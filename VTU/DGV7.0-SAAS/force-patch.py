import os
import glob
import re

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
        if 'preg_replace(\'#^https?://#\'' not in content:
            old_url_pattern = re.compile(r'\$curl_url = "https://" \. \$api_detail\["api_base_url"\] \. "/api/data/";')
            new_url = """$clean_base_url = preg_replace('#^https?://#', '', trim($api_detail["api_base_url"]));
        $clean_base_url = rtrim($clean_base_url, "/");
        $curl_url = "https://" . $clean_base_url . "/api/data/";"""
            content = old_url_pattern.sub(new_url, content)

        # Fix 2: Token API key double-injection fix
        if 'str_ireplace("Token "' not in content:
            # We use regex to match the Authorization header regardless of whitespace
            old_auth_pattern = re.compile(r'"Authorization: Token " \. \$api_detail\["api_key"\],')
            new_auth = """$clean_api_key = trim(str_ireplace("Token ", "", $api_detail["api_key"]));
      "Authorization: Token " . $clean_api_key,"""
            content = old_auth_pattern.sub(new_auth, content)
            
        # Fix 3: json_encode true bug
        # match ), true); and replace with ));
        content = content.replace("), true);", "));")

        with open(file_path, "w", encoding="utf-8") as f:
            f.write(content)
        print(f"Force Patched {filename}")
