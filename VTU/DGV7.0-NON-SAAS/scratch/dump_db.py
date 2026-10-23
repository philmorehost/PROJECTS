import mysql.connector
import json

try:
    conn = mysql.connector.connect(
        host="localhost",
        user="v7pmh_vtuserver",
        password="v7pmh_vtuserver",
        database="v7pmh_vtuserver"
    )
    cursor = conn.cursor(dictionary=True)
    
    print("=== sas_products (cable) ===")
    cursor.execute("SELECT id, product_name, status, api_type FROM sas_products WHERE api_type='cable' OR product_name LIKE '%gotv%' OR product_name LIKE '%dstv%'")
    for r in cursor.fetchall():
        print(r)
        
    print("\n=== sas_apis (cable) ===")
    cursor.execute("SELECT id, api_name, api_base_url, status, api_type FROM sas_apis WHERE api_type='cable'")
    for r in cursor.fetchall():
        print(r)
        
    print("\n=== sas_cable_status ===")
    cursor.execute("SELECT * FROM sas_cable_status")
    for r in cursor.fetchall():
        print(r)
        
    print("\n=== sas_smart_parameter_values ===")
    cursor.execute("SELECT id, product_id, val_1, val_2, val_3, val_4, status FROM sas_smart_parameter_values WHERE product_id IN (SELECT id FROM sas_products WHERE api_type='cable') LIMIT 20")
    for r in cursor.fetchall():
        print(r)
        
    cursor.close()
    conn.close()
except Exception as e:
    print("Error:", e)
