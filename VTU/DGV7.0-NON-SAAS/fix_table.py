import os, glob

folder = r'c:\Users\User\Downloads\DGV7-NEW\bc-admin'
count = 0

for filepath in glob.glob(os.path.join(folder, '*.php')):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    orig1 = '$get_item_status_details["api_id"]'
    new1 = '($get_item_status_details["api_id"] ?? "")'
    
    orig2 = '$product_table["id"]'
    new2 = '($product_table["id"] ?? "")'
    
    if orig1 in content or orig2 in content:
        content = content.replace(orig1, new1)
        content = content.replace(orig2, new2)
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        count += 1
        print(f'Fixed {os.path.basename(filepath)}')

print(f'Total files fixed: {count}')
