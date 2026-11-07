import urllib.request
import urllib.parse
import json

url = "https://www.nellobytesystems.com/APIDatabundleV1.asp?UserID=test&APIKey=test&MobileNetwork=01&DataPlan=1000.0&MobileNumber=08011111111&RequestID=123"

try:
    response = urllib.request.urlopen(url)
    data = response.read().decode('utf-8')
    print("Test 1:", data)
except Exception as e:
    print(e)

# test 2: with MobileNumber instead? Wait, MobileNumber is in test 1. Let's see what else it might be. maybe PhoneNo ?
url2 = "https://www.nellobytesystems.com/APIDatabundleV1.asp?UserID=test&APIKey=test&MobileNetwork=01&DataPlan=1000.0&PhoneNo=08011111111&RequestID=123"
try:
    response = urllib.request.urlopen(url2)
    data = response.read().decode('utf-8')
    print("Test 2:", data)
except Exception as e:
    print(e)
