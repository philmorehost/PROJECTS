import os
import glob

admin_dir = r"c:\Users\User\Downloads\DGV7.0 SAAS and NON-SAAS and LM\DGV7.0-SAAS\bc-admin"
files = glob.glob(os.path.join(admin_dir, "*.php"))

search_string = """$product_smart_table = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$get_item_status_details["api_id"]."' && product_id='".$product_table["id"]."'");"""
replace_string = """if(empty($get_item_status_details) || empty($product_table)) continue;\n\n""" + search_string

for file_path in files:
    with open(file_path, "r", encoding="utf-8") as f:
        content = f.read()
    
    if search_string in content and "if(empty($get_item_status_details)" not in content:
        # Get the indentation
        lines = content.split('\n')
        for i, line in enumerate(lines):
            if search_string in line:
                indent = line[:len(line) - len(line.lstrip())]
                new_line = indent + "if(empty($get_item_status_details) || empty($product_table)) continue;\n\n" + line
                lines[i] = new_line
        
        with open(file_path, "w", encoding="utf-8") as f:
            f.write('\n'.join(lines))
        print(f"Fixed {os.path.basename(file_path)}")
