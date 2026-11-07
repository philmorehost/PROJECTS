import urllib.request
import json

url = "https://www.nellobytesystems.com/APIDatabundleV1.asp?UserID=test&APIKey=test&MobileNetwork=01&DataPlan=1000.0&MobileNumber=08011111111&RequestID=123"
try:
    response = urllib.request.urlopen(url)
    data = response.read().decode('utf-8')
    print("Response:")
    print(data)
    try:
        parsed = json.loads(data)
        print("Valid JSON:", parsed)
    except json.JSONDecodeError:
        print("Not JSON")
except Exception as e:
    print(e)
