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
        
        # Find the syntax error and fix it
        # $curl_http_headers = array(
        #   $clean_api_key = trim(str_ireplace("Token ", "", $api_detail["api_key"]));
        #   "Authorization: Token " . $clean_api_key,
        
        bad_pattern = r'\$curl_http_headers = array\(\s*\$clean_api_key = trim\(str_ireplace\("Token ", "", \$api_detail\["api_key"\]\)\);\s*"Authorization: Token " \. \$clean_api_key,'
        good_pattern = r"""$clean_api_key = trim(str_ireplace("Token ", "", $api_detail["api_key"]));
    $curl_http_headers = array(
      "Authorization: Token " . $clean_api_key,"""
        
        content = re.sub(bad_pattern, good_pattern, content)
        
        with open(file_path, "w", encoding="utf-8") as f:
            f.write(content)
        print(f"Syntax Fixed {filename}")
