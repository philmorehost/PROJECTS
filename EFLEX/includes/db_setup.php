<?php
// This file contains the SQL statements to create the database tables.
// It will be included and executed by db_connect.php to ensure the schema exists.

function setup_database_tables($mysqli) {
    $table_creation_queries = [
        "users" => "CREATE TABLE `users` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `username` varchar(50) NOT NULL,
          `password` varchar(255) NOT NULL,
          `email` varchar(100) NOT NULL,
          `role_id` int(11) DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `username` (`username`),
          UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "categories" => "CREATE TABLE `categories` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(255) NOT NULL,
          `parent_id` int(11) DEFAULT NULL,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `parent_id` (`parent_id`),
          CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "products" => "CREATE TABLE `products` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `category_id` int(11) DEFAULT NULL,
          `name` varchar(255) NOT NULL,
          `description` text NOT NULL,
          `price` decimal(10,2) NOT NULL,
          `image` varchar(255) DEFAULT 'default.jpg',
          `has_variants` tinyint(1) NOT NULL DEFAULT '0',
          `is_featured` tinyint(1) NOT NULL DEFAULT '0',
          `is_top_seller` tinyint(1) NOT NULL DEFAULT '0',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `category_id` (`category_id`),
          CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "orders" => "CREATE TABLE `orders` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `total_amount` decimal(10,2) NOT NULL,
      `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
      `payment_method` varchar(50) DEFAULT NULL,
      `payment_proof` varchar(255) DEFAULT NULL,
          `status` varchar(50) NOT NULL DEFAULT 'Pending',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `user_id` (`user_id`),
          CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "order_items" => "CREATE TABLE `order_items` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `order_id` int(11) NOT NULL,
          `product_id` int(11) NOT NULL,
          `variant_id` int(11) DEFAULT NULL,
          `quantity` int(11) NOT NULL,
          `price` decimal(10,2) NOT NULL,
          PRIMARY KEY (`id`),
          KEY `order_id` (`order_id`),
          KEY `product_id` (`product_id`),
          CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
          CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "settings" => "CREATE TABLE `settings` (
      `setting_key` varchar(255) NOT NULL,
      `setting_value` text,
      PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "banners" => "CREATE TABLE `banners` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `image_url` varchar(255) NOT NULL,
        `link_url` varchar(255) DEFAULT NULL,
        `is_active` tinyint(1) NOT NULL DEFAULT '1',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "hero_slides" => "CREATE TABLE `hero_slides` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `type` enum('image','video') NOT NULL DEFAULT 'image',
        `content_url` varchar(255) NOT NULL,
        `title` varchar(255) DEFAULT NULL,
        `description` text,
        `is_active` tinyint(1) NOT NULL DEFAULT '1',
        `sort_order` int(11) NOT NULL DEFAULT '0',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "product_images" => "CREATE TABLE `product_images` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL,
        `image_url` varchar(255) NOT NULL,
        `sort_order` int(11) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `product_id` (`product_id`),
        CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "roles" => "CREATE TABLE `roles` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `role_name` varchar(255) NOT NULL UNIQUE,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "permissions" => "CREATE TABLE `permissions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `permission_name` varchar(255) NOT NULL UNIQUE,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "role_permissions" => "CREATE TABLE `role_permissions` (
        `role_id` int(11) NOT NULL,
        `permission_id` int(11) NOT NULL,
        PRIMARY KEY (`role_id`, `permission_id`),
        KEY `role_id` (`role_id`),
        KEY `permission_id` (`permission_id`),
        CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
        CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "wishlist" => "CREATE TABLE `wishlist` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `product_id` int(11) NOT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_product` (`user_id`, `product_id`),
        KEY `user_id` (`user_id`),
        KEY `product_id` (`product_id`),
        CONSTRAINT `wishlist_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `wishlist_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "modal_ads" => "CREATE TABLE `modal_ads` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `content` text,
        `image_url` varchar(255) DEFAULT NULL,
        `start_time` datetime DEFAULT NULL,
        `end_time` datetime DEFAULT NULL,
        `show_countdown` tinyint(1) NOT NULL DEFAULT '0',
        `display_pages` text,
        `is_active` tinyint(1) NOT NULL DEFAULT '1',
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "otp_codes" => "CREATE TABLE `otp_codes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `otp_code` varchar(10) NOT NULL,
        `expires_at` datetime NOT NULL,
        `is_used` tinyint(1) NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "pages" => "CREATE TABLE `pages` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `title` varchar(255) NOT NULL,
        `slug` varchar(255) NOT NULL UNIQUE,
        `content` longtext,
        `is_published` tinyint(1) NOT NULL DEFAULT '0',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "product_reviews" => "CREATE TABLE `product_reviews` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        `rating` int(1) NOT NULL,
        `review_text` text,
        `is_approved` tinyint(1) NOT NULL DEFAULT '1',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `product_id` (`product_id`),
        KEY `user_id` (`user_id`),
        CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
        CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "product_attributes" => "CREATE TABLE `product_attributes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(255) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "attribute_values" => "CREATE TABLE `attribute_values` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `attribute_id` int(11) NOT NULL,
        `value` varchar(255) NOT NULL,
        PRIMARY KEY (`id`),
        KEY `attribute_id` (`attribute_id`),
        CONSTRAINT `values_ibfk_1` FOREIGN KEY (`attribute_id`) REFERENCES `product_attributes` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "product_variants" => "CREATE TABLE `product_variants` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_id` int(11) NOT NULL,
        `sku` varchar(100) DEFAULT NULL,
        `price` decimal(10,2) DEFAULT NULL,
        `stock` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `product_id` (`product_id`),
        CONSTRAINT `variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "product_variant_options" => "CREATE TABLE `product_variant_options` (
        `variant_id` int(11) NOT NULL,
        `attribute_id` int(11) NOT NULL,
        `value_id` int(11) NOT NULL,
        PRIMARY KEY (`variant_id`, `attribute_id`),
        KEY `variant_id` (`variant_id`),
        KEY `value_id` (`value_id`),
        CONSTRAINT `options_ibfk_1` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
        CONSTRAINT `options_ibfk_2` FOREIGN KEY (`value_id`) REFERENCES `attribute_values` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "auth_tokens" => "CREATE TABLE `auth_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `token` varchar(255) NOT NULL,
        `expires_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `token` (`token`),
        KEY `user_id` (`user_id`),
        CONSTRAINT `auth_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    ];

    // The foreign key constraints require the tables to be created in a specific order.
    // We can ensure this by the order in the array above.
    foreach($table_creation_queries as $table_name => $query){
        // Check if table exists
        $result = $mysqli->query("SHOW TABLES LIKE '".$table_name."'");
        if($result->num_rows == 0){
            // Table does not exist, create it
            if(!$mysqli->query($query)){
                 // Handle error - for now, we'll just die.
                 die("Table creation failed for '$table_name': (" . $mysqli->errno . ") " . $mysqli->error);
            }
        }
    }

    // --- Schema Migration Checks ---
    // RBAC: Drop old 'role' column from users table if it exists
    if ($mysqli->query("SHOW COLUMNS FROM `users` LIKE 'role'")->num_rows > 0) {
        $mysqli->query("ALTER TABLE `users` DROP COLUMN `role`");
    }

    // RBAC: Add 'role_id' to users table if it doesn't exist
    if ($mysqli->query("SHOW COLUMNS FROM `users` LIKE 'role_id'")->num_rows == 0) {
        $mysqli->query("ALTER TABLE `users` ADD `role_id` INT(11) NULL AFTER `email`");
    }

    // Check for is_featured column in products table
    $result_featured = $mysqli->query("SHOW COLUMNS FROM `products` LIKE 'is_featured'");
    if($result_featured->num_rows == 0){
        $mysqli->query("ALTER TABLE `products` ADD `is_featured` TINYINT(1) NOT NULL DEFAULT 0 AFTER `image`");
    }

    // Check for is_top_seller column in products table
    $result_topseller = $mysqli->query("SHOW COLUMNS FROM `products` LIKE 'is_top_seller'");
    if($result_topseller->num_rows == 0){
        $mysqli->query("ALTER TABLE `products` ADD `is_top_seller` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_featured`");
    }

    // Check for has_variants column in products table
    $result_has_variants = $mysqli->query("SHOW COLUMNS FROM `products` LIKE 'has_variants'");
    if($result_has_variants->num_rows == 0){
        $mysqli->query("ALTER TABLE `products` ADD `has_variants` TINYINT(1) NOT NULL DEFAULT 0 AFTER `image`");
    }

    // Check for variant_id column in order_items table
    $result_oi_variant = $mysqli->query("SHOW COLUMNS FROM `order_items` LIKE 'variant_id'");
    if($result_oi_variant->num_rows == 0){
        $mysqli->query("ALTER TABLE `order_items` ADD `variant_id` INT(11) DEFAULT NULL AFTER `product_id`");
    }

    // Check for stripe_payment_intent_id column in orders table
    $result_stripe = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE 'stripe_payment_intent_id'");
    if($result_stripe->num_rows == 0){
        $mysqli->query("ALTER TABLE `orders` ADD `stripe_payment_intent_id` VARCHAR(255) DEFAULT NULL AFTER `total_amount`");
    }

    // Check for payment_method column in orders table
    $result_pm = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE 'payment_method'");
    if($result_pm->num_rows == 0){
        $mysqli->query("ALTER TABLE `orders` ADD `payment_method` VARCHAR(50) DEFAULT NULL AFTER `stripe_payment_intent_id`");
    }

    // Check for payment_proof column in orders table
    $result_pp = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE 'payment_proof'");
    if($result_pp->num_rows == 0){
        $mysqli->query("ALTER TABLE `orders` ADD `payment_proof` VARCHAR(255) DEFAULT NULL AFTER `payment_method`");
    }

    // Check for image column in categories table
    $result_cat_img = $mysqli->query("SHOW COLUMNS FROM `categories` LIKE 'image'");
    if($result_cat_img->num_rows == 0){
        $mysqli->query("ALTER TABLE `categories` ADD `image` VARCHAR(255) DEFAULT NULL AFTER `name`");
    }

    // Check for parent_id column in categories table
    $result_cat_parent = $mysqli->query("SHOW COLUMNS FROM `categories` LIKE 'parent_id'");
    if($result_cat_parent->num_rows == 0){
        $mysqli->query("ALTER TABLE `categories` ADD `parent_id` INT(11) DEFAULT NULL AFTER `name`, ADD KEY `parent_id` (`parent_id`)");
        // Add foreign key constraint separately
        $mysqli->query("ALTER TABLE `categories` ADD CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL");
    }

    // Check for transaction_id column in orders table
    $result_tr = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE 'transaction_id'");
    if($result_tr->num_rows == 0){
        // Check if old transaction_reference column exists and rename it
        $result_old_tr = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE 'transaction_reference'");
        if ($result_old_tr->num_rows > 0) {
            $mysqli->query("ALTER TABLE `orders` CHANGE `transaction_reference` `transaction_id` VARCHAR(255) DEFAULT NULL");
        } else {
            $mysqli->query("ALTER TABLE `orders` ADD `transaction_id` VARCHAR(255) DEFAULT NULL AFTER `payment_proof`");
        }
    }

    // Check for overlay_color column in hero_slides table
    $result_oc = $mysqli->query("SHOW COLUMNS FROM `hero_slides` LIKE 'overlay_color'");
    if($result_oc->num_rows == 0){
        $mysqli->query("ALTER TABLE `hero_slides` ADD `overlay_color` VARCHAR(10) DEFAULT '#000000' AFTER `description`");
    }

    // Check for overlay_opacity column in hero_slides table
    $result_oo = $mysqli->query("SHOW COLUMNS FROM `hero_slides` LIKE 'overlay_opacity'");
    if($result_oo->num_rows == 0){
        $mysqli->query("ALTER TABLE `hero_slides` ADD `overlay_opacity` DECIMAL(2,1) DEFAULT 0.5 AFTER `overlay_color`");
    }

    // Check for onesignal_player_id column in users table
    $result_osid = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'onesignal_player_id'");
    if($result_osid->num_rows == 0){
        $mysqli->query("ALTER TABLE `users` ADD `onesignal_player_id` VARCHAR(255) NULL DEFAULT NULL AFTER `role_id`");
    }

    // Check for is_verified column in users table for OTP
    $result_iv = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'is_verified'");
    if($result_iv->num_rows == 0){
        $mysqli->query("ALTER TABLE `users` ADD `is_verified` TINYINT(1) NOT NULL DEFAULT 1 AFTER `onesignal_player_id`");
    }

    // Check for purpose column in otp_codes table
    $result_purpose = $mysqli->query("SHOW COLUMNS FROM `otp_codes` LIKE 'purpose'");
    if($result_purpose->num_rows == 0){
        $mysqli->query("ALTER TABLE `otp_codes` ADD `purpose` VARCHAR(255) NOT NULL DEFAULT 'login' AFTER `is_used`");
    }

    // Add phone and address to users table
    $result_phone = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'phone'");
    if($result_phone->num_rows == 0){
        // Check if old phone_number column exists and rename it
        $result_old_phone = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'phone_number'");
        if ($result_old_phone->num_rows > 0) {
            $mysqli->query("ALTER TABLE `users` CHANGE `phone_number` `phone` VARCHAR(25) NULL DEFAULT NULL");
        } else {
            $mysqli->query("ALTER TABLE `users` ADD `phone` VARCHAR(25) NULL DEFAULT NULL AFTER `email`");
        }
    }
    $result_address = $mysqli->query("SHOW COLUMNS FROM `users` LIKE 'address'");
    if($result_address->num_rows == 0){
        $mysqli->query("ALTER TABLE `users` ADD `address` TEXT NULL DEFAULT NULL AFTER `phone`");
    }

    // Add order_notes to orders table
    $result_notes = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE 'order_notes'");
    if($result_notes->num_rows == 0){
        $mysqli->query("ALTER TABLE `orders` ADD `order_notes` TEXT NULL DEFAULT NULL AFTER `status`");
    }

    // Add stock to products table
    $result_stock = $mysqli->query("SHOW COLUMNS FROM `products` LIKE 'stock'");
    if($result_stock->num_rows == 0){
        $mysqli->query("ALTER TABLE `products` ADD `stock` INT(11) NULL DEFAULT 0 AFTER `price`");
    }

    // Add stock_restored to orders table
    $result_sr = $mysqli->query("SHOW COLUMNS FROM `orders` LIKE 'stock_restored'");
    if($result_sr->num_rows == 0){
        $mysqli->query("ALTER TABLE `orders` ADD `stock_restored` TINYINT(1) NOT NULL DEFAULT 0 AFTER `order_notes`");
    }

    // --- Seed Roles and Permissions ---
    $super_admin_role_id = 0;
    $role_result = $mysqli->query("SELECT id FROM roles WHERE role_name = 'Super Admin'");
    if($role_result->num_rows == 0){
        // Create Super Admin Role if it doesn't exist
        $mysqli->query("INSERT INTO roles (role_name) VALUES ('Super Admin')");
        $super_admin_role_id = $mysqli->insert_id;

        // Define and create all permissions
        $permissions = [
            'manage_products', 'manage_categories', 'manage_orders',
            'manage_users', 'manage_site_settings', 'manage_banners',
            'manage_hero_slider', 'manage_roles', 'manage_modal_ads', 'manage_pages'
        ];
        $stmt_perm = $mysqli->prepare("INSERT INTO permissions (permission_name) VALUES (?)");
        $stmt_rp = $mysqli->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach($permissions as $p_name){
            // Create permission if it doesn't exist
            $perm_check = $mysqli->query("SELECT id FROM permissions WHERE permission_name = '$p_name'");
            if($perm_check->num_rows == 0){
                $stmt_perm->bind_param("s", $p_name);
                $stmt_perm->execute();
                $permission_id = $mysqli->insert_id;

                // Assign new permission to the Super Admin role
                $stmt_rp->bind_param("ii", $super_admin_role_id, $permission_id);
                $stmt_rp->execute();
            }
        }
        $stmt_perm->close();
        $stmt_rp->close();
    } else {
        $super_admin_role_id = $role_result->fetch_assoc()['id'];
    }

    // --- Create/Update Default Admin User ---
    if($super_admin_role_id > 0){
        $admin_user_result = $mysqli->query("SELECT id FROM users WHERE username = 'admin'");
        if($admin_user_result->num_rows == 0){
            // Admin user does not exist, create it
            $username = 'admin';
            $email = 'admin@example.com';
            $password = 'password';
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql_user = "INSERT INTO users (username, email, password, role_id) VALUES (?, ?, ?, ?)";
            if($stmt_user = $mysqli->prepare($sql_user)){
                $stmt_user->bind_param("sssi", $username, $email, $hashed_password, $super_admin_role_id);
                $stmt_user->execute();
                $stmt_user->close();
            }
        } else {
            // Admin user exists, ensure its role_id is set correctly
            $admin_user_id = $admin_user_result->fetch_assoc()['id'];
            $mysqli->query("UPDATE users SET role_id = $super_admin_role_id WHERE id = $admin_user_id");
        }
    }
}
?>
