<?php
// download.php
// API endpoint to download update packages securely using one-time tokens

header('Content-Type: application/json');

require_once('db.php');

// Helper to log actions
function download_log($message) {
    file_put_contents('api_download.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Download token is missing.']);
    exit;
}

download_log("Download attempt with token: {$token}");

try {
    // Clean up expired tokens silently
    $pdo->exec("DELETE FROM download_tokens WHERE expires_at < NOW()");

    // Query token details and script update zip path
    $stmt = $pdo->prepare("
        SELECT dt.id, dt.is_used, dt.expires_at, su.zip_path, su.version_number
        FROM download_tokens dt
        JOIN script_updates su ON dt.update_id = su.id
        WHERE dt.token = ?
    ");
    $stmt->execute([$token]);
    $token_record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token_record) {
        download_log("Download failed: Token not found or expired.");
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired download token.']);
        exit;
    }

    if ($token_record['is_used'] == 1) {
        download_log("Download failed: Token already used.");
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This download link has already been used.']);
        exit;
    }

    // Check if the token has expired
    if (strtotime($token_record['expires_at']) < time()) {
        download_log("Download failed: Token expired.");
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This download link has expired.']);
        exit;
    }

    $file_path = $token_record['zip_path'];

    // Security check: Verify file exists and is within limits
    if (!file_exists($file_path)) {
        // Fallback: Check relative path in case it was stored relative to the project root
        $relative_path = dirname(__DIR__) . '/' . ltrim($file_path, '/\\');
        if (file_exists($relative_path)) {
            $file_path = $relative_path;
        } else {
            download_log("Download failed: File not found at '{$file_path}' or '{$relative_path}'");
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Update file not found on the server. Please contact support.']);
            exit;
        }
    }

    // Mark token as used immediately before streaming to prevent parallel download exploits
    $update_stmt = $pdo->prepare("UPDATE download_tokens SET is_used = 1 WHERE id = ?");
    $update_stmt->execute([$token_record['id']]);

    download_log("Starting stream for file: {$file_path}");

    // Clear buffer to prevent memory exhaustion and output pollution
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Stream the file with hidden paths
    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="update_v' . $token_record['version_number'] . '.zip"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));
    
    // Output file chunks to avoid memory limit issues on large updates
    $file = fopen($file_path, 'rb');
    if ($file) {
        while (!feof($file)) {
            echo fread($file, 1024 * 8); // 8KB chunks
            flush();
        }
        fclose($file);
    }
    
    download_log("Download successfully streamed.");
    exit;

} catch (Exception $e) {
    download_log("Download exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error during download.']);
    exit;
}
