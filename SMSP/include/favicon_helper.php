<?php
/**
 * Dynamic Favicon Helper
 */

if (!function_exists('getFaviconDataUri')) {
    function getFaviconDataUri($pdo, $school_id, $user_role) {
        $letter = 'S';
        $bg = '#4f46e5'; // Premium Indigo

        if ($user_role !== 'super_admin' && $school_id && $school_id !== 'global') {
            try {
                $stmt = $pdo->prepare("SELECT name FROM sm_schools WHERE id = ?");
                $stmt->execute([$school_id]);
                $school = $stmt->fetch();
                if ($school && !empty($school['name'])) {
                    $letter = strtoupper(substr(trim($school['name']), 0, 1));
                    
                    // Simple hash to select a consistent modern color gradient/background
                    $colors = ['#2563eb', '#0284c7', '#0d9488', '#059669', '#7c3aed', '#db2777', '#ea580c'];
                    $char_code = ord($letter);
                    $bg = $colors[$char_code % count($colors)];
                }
            } catch (Exception $e) {
                // Fail-safe to default color
            }
        }

        // SVG string
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
            <rect width="64" height="64" rx="16" fill="' . $bg . '" />
            <text x="50%" y="58%" font-family="-apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif" font-size="38" font-weight="bold" fill="#ffffff" text-anchor="middle" dominant-baseline="middle">' . $letter . '</text>
        </svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
