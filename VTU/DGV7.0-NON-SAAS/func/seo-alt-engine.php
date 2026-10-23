<?php
/**
 * Advanced SEO Image Alt Engine
 * Automatically parses HTML string and adds descriptive, context-aware alt attributes to img tags.
 */

function seo_auto_alt($html, $site_title = "VTU Portal") {
    if (empty($html)) return $html;
    
    // Regex to match all <img> tags
    return preg_replace_callback('/<img\b([^>]*)/i', function($matches) use ($site_title) {
        $img_attributes = $matches[1];
        
        // Check if alt attribute already exists and is not empty
        $has_alt = preg_match('/alt=(["\'])(.*?)\1/i', $img_attributes, $alt_matches);
        
        if ($has_alt && !empty(trim($alt_matches[2]))) {
            // Already has a non-empty alt tag, keep it
            return $matches[0];
        }
        
        // Extract src to deduce context
        $src = '';
        if (preg_match('/src=(["\'])(.*?)\1/i', $img_attributes, $src_matches)) {
            $src = $src_matches[2];
        }
        
        // Determine a contextual alt description
        $filename = basename($src);
        $filename_no_ext = pathinfo($filename, PATHINFO_FILENAME);
        // Replace dashes and underscores with spaces, capitalize
        $clean_name = ucwords(str_replace(['-', '_'], ' ', $filename_no_ext));
        
        // Provide standard clean fallback names if generic
        if (empty($clean_name) || in_array(strtolower($clean_name), ['logo', 'img', 'image', 'banner', 'bg', 'pic', 'picture'])) {
            // Check context from class or id if any
            if (preg_match('/class=(["\'])(.*?)\1/i', $img_attributes, $class_matches)) {
                $class = strtolower($class_matches[2]);
                if (strpos($class, 'logo') !== false) {
                    $clean_name = "{$site_title} Logo";
                } elseif (strpos($class, 'avatar') !== false || strpos($class, 'profile') !== false) {
                    $clean_name = "User Profile Avatar";
                } elseif (strpos($class, 'hero') !== false) {
                    $clean_name = "{$site_title} Services Banner";
                }
            }
            if (empty($clean_name) || in_array(strtolower($clean_name), ['logo', 'img', 'image', 'banner', 'bg', 'pic', 'picture'])) {
                $clean_name = "{$site_title} Feature Illustration";
            }
        } else {
            // Make it more descriptive
            $clean_name = $clean_name . " - " . $site_title;
        }
        
        // Remove existing blank alt tag if it exists
        if ($has_alt) {
            $img_attributes = preg_replace('/alt=(["\'])(.*?)\1/i', '', $img_attributes);
        }
        
        // Inject the new alt attribute
        $img_attributes = trim($img_attributes) . ' alt="' . htmlspecialchars($clean_name, ENT_QUOTES, 'UTF-8') . '"';
        
        return '<img ' . $img_attributes;
    }, $html);
}
