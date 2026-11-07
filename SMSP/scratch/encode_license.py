import base64
import re

with open('scratch/encode_license.php', 'r', encoding='utf-8') as f:
    content = f.read()

# find everything between <<<'CODE' and CODE;
match = re.search(r"<<<'CODE'\r?\n(.*?)\r?\nCODE", content, re.DOTALL)
if match:
    code_str = match.group(1)
    # base64 encode
    b64 = base64.b64encode(code_str.encode('utf-8')).decode('utf-8')
    print(b64)
else:
    print("No match found")
