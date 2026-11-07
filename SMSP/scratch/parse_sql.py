import os

sql_file = r'C:\Users\User\Downloads\SCHOOLAPP\NEW-SCHOOLAPP\sms_school.sql'
if not os.path.exists(sql_file):
    print("File not found")
    exit()

counts = {
    'sm_students': 0,
    'sm_teachers': 0,
    'sm_parents': 0,
    'sm_school_details': 0,
    'sm_results': 0
}

current_table = None

with open(sql_file, 'r', encoding='utf-8', errors='ignore') as f:
    for line in f:
        line_strip = line.strip()
        if not line_strip:
            continue
            
        # Check if this line starts a new INSERT statement
        if line_strip.startswith("INSERT INTO"):
            current_table = None
            for table in counts:
                if f"`{table}`" in line_strip:
                    current_table = table
                    break
            continue
            
        # If we are inside a tracked table, count the rows
        if current_table:
            # Each row starts with '('
            if line_strip.startswith("("):
                counts[current_table] += 1
            # Check if this line ends the insert statement
            if line_strip.endswith(");") or line_strip.endswith(";"):
                current_table = None

print("=== STATS IN SQL FILE ===")
for k, v in counts.items():
    print(f"{k}: {v} rows")
