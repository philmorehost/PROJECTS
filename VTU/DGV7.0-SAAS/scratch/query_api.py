import urllib.request
import urllib.parse
import json

def test_url(url, data=None):
    print(f"Testing URL: {url}")
    try:
        if data:
            req_data = urllib.parse.urlencode(data).encode('utf-8')
            req = urllib.request.Request(url, data=req_data, method='POST')
        else:
            req = urllib.request.Request(url, method='GET')
        
        with urllib.request.urlopen(req, timeout=10) as response:
            code = response.getcode()
            body = response.read().decode('utf-8', errors='ignore')
            print(f"HTTP Code: {code}")
            print(f"Response (first 200 chars): {body[:200]}")
            try:
                parsed = json.loads(body)
                print(f"JSON parsed successfully: {parsed}")
            except Exception as je:
                print(f"Failed to parse JSON: {je}")
    except Exception as e:
        print(f"Error requesting URL: {e}")
    print("-" * 50)

# Test 1: licensing.philmorehost.com
test_url("https://licensing.philmorehost.com/verify.php?key=test_key&domain=test_domain")

# Test 2: manager.pmhserver.name.ng/api.php (GET)
test_url("https://manager.pmhserver.name.ng/api.php?key=test_key&domain=test_domain")

# Test 3: manager.pmhserver.name.ng/api.php (POST)
test_url("https://manager.pmhserver.name.ng/api.php", {"key": "test_key", "domain": "test_domain"})
