<?php
// Unified Addon Manager Helper class

class AddonHelper {
    private static $default_addons = ['CBT', 'Bulk Report Card', 'Data Cleanup', 'ID Card', 'Live Classes', 'SMS', 'Mobile App'];

    public static function isAddonGloballyEnabled($pdo, $addonName) {
        $keyMap = [
            'CBT' => 'addon_cbt_enabled',
            'Bulk Report Card' => 'addon_bulk_report_card_enabled',
            'Data Cleanup' => 'addon_data_cleanup_enabled',
            'ID Card' => 'addon_id_card_enabled',
            'Live Classes' => 'addon_live_classes_enabled',
            'SMS' => 'addon_sms_enabled',
            'Mobile App' => 'addon_mobile_app_enabled'
        ];
        
        $key = $keyMap[$addonName] ?? '';
        if (!$key) return false;
        
        try {
            $stmt = $pdo->prepare("SELECT `setting_value` FROM `sm_global_settings` WHERE `setting_key` = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val === '1');
        } catch (PDOException $e) {
            return true; // fallback to active if query fails
        }
    }

    public static function isAddonActive($pdo, $schoolId, $addonName) {
        if ($addonName === 'Mobile App') {
            // Check global enablement for mobile app
            try {
                $stmt = $pdo->prepare("SELECT `setting_value` FROM `sm_global_settings` WHERE `setting_key` = 'addon_mobile_app_enabled'");
                $stmt->execute();
                $val = $stmt->fetchColumn();
                if ($val !== false && $val === '0') return false; // globally disabled
            } catch (PDOException $e) {}
        } else {
            if (!self::isAddonGloballyEnabled($pdo, $addonName)) {
                return false; // disabled globally
            }
        }

        if ($schoolId === 'global') {
            return true; // Super Admin has access to all modules
        }

        try {
            $stmt = $pdo->prepare("SELECT `status` FROM `sm_school_addons` WHERE `school_id_number` = ? AND `addon_name` = ?");
            $stmt->execute([$schoolId, $addonName]);
            $result = $stmt->fetch();
            return ($result && $result['status'] === 'active');
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function toggleAddon($pdo, $schoolId, $addonName, $status) {
        if (!in_array($addonName, self::$default_addons)) {
            return false;
        }

        $statusValue = ($status === 'active' || $status === true) ? 'active' : 'inactive';
        $now = date('Y-m-d H:i:s');

        try {
            $stmt = $pdo->prepare("
                INSERT INTO `sm_school_addons` (`school_id_number`, `addon_name`, `status`, `activated_at`) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `activated_at` = VALUES(`activated_at`)
            ");
            return $stmt->execute([$schoolId, $addonName, $statusValue, $now]);
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function getAddonPrice($pdo, $addonName) {
        $keyMap = [
            'CBT' => 'price_addon_cbt',
            'Bulk Report Card' => 'price_addon_bulk_report_card',
            'Data Cleanup' => 'price_addon_data_cleanup',
            'ID Card' => 'price_addon_id_card',
            'Live Classes' => 'price_addon_live_classes',
            'SMS' => 'price_addon_sms',
            'Mobile App' => 'price_addon_mobile_app'
        ];
        
        $key = $keyMap[$addonName] ?? '';
        if (!$key) return 0.00;
        
        try {
            $stmt = $pdo->prepare("SELECT `setting_value` FROM `sm_global_settings` WHERE `setting_key` = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return $val !== false ? (float)$val : 0.00;
        } catch (PDOException $e) {
            return 0.00;
        }
    }

    public static function getAddonPurchaseStatus($pdo, $schoolId, $addonName) {
        try {
            $stmt = $pdo->prepare("SELECT `status` FROM `sm_addon_purchases` WHERE `school_id_number` = ? AND `addon_name` = ? ORDER BY `id` DESC LIMIT 1");
            $stmt->execute([$schoolId, $addonName]);
            return $stmt->fetchColumn() ?: 'unpurchased';
        } catch (PDOException $e) {
            return 'unpurchased';
        }
    }

    public static function getSchoolAddonSummary($pdo, $schoolId) {
        $summary = [];
        foreach (self::$default_addons as $addon) {
            $summary[$addon] = self::isAddonActive($pdo, $schoolId, $addon);
        }
        return $summary;
    }
}
?>
