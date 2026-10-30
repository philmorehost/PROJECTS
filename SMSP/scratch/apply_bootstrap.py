import base64
import re

# Read the PHP code from encode_license.php
with open('scratch/encode_license.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Extract code between <<<'CODE' and CODE;
match = re.search(r"<<<'CODE'\r?\n(.*?)\r?\nCODE", content, re.DOTALL)
if not match:
    print("Could not find PHP code in encode_license.php")
    exit(1)

php_code = match.group(1)
# Generate correct base64
b64_str = base64.b64encode(php_code.encode('utf-8')).decode('utf-8')

# Read bootstrap.php
with open('include/bootstrap.php', 'r', encoding='utf-8') as f:
    bootstrap = f.read()

# Replace the base64_decode("...") content
# We search for: eval(base64_decode("..."));
# Note: we use a regex that handles newlines inside the quotes
pattern = r'(eval\(base64_decode\(")(.*?)("\)\);)'
new_bootstrap, count = re.subn(pattern, lambda m: m.group(1) + b64_str + m.group(3), bootstrap, flags=re.DOTALL)

if count > 0:
    with open('include/bootstrap.php', 'w', encoding='utf-8', newline='\r\n') as f:
        f.write(new_bootstrap)
    print(f"Successfully replaced base64 string in bootstrap.php (count={count})")
else:
    print("Could not find base64_decode block in bootstrap.php")
