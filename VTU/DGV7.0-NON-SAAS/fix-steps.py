import glob
import re

count = 0
files = glob.glob('bc-admin/*.php')
for f in files:
    with open(f, 'r', encoding='utf-8', errors='ignore') as file:
        content = file.read()
    
    new_content = re.sub(r'step=\"0\.01\"', 'step="any"', content)
    new_content = re.sub(r'step=\"0\.1\"', 'step="any"', new_content)
    
    if content != new_content:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(new_content)
        count += 1
        print(f"Updated {f}")

print(f"Total files updated: {count}")
