<?php session_start();
include("../func/bc-spadmin-config.php");

// Security: Explicitly verify Super Admin session
if (!isset($_SESSION['spadmin_session'])) {
    header("HTTP/1.1 403 Forbidden");
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Super Admin Asset Upload Handler for GrapesJS
if (isset($_FILES['files'])) {
    $upload_dir = '../uploaded-image/marketing/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $responses = [];
    foreach ($_FILES['files']['name'] as $key => $name) {
        $tmp_name = $_FILES['files']['tmp_name'][$key];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_extensions)) continue;

        $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $upload_dir . $filename;

        if (move_uploaded_file($tmp_name, $target)) {
            $responses[] = [
                'src' => $web_http_host . '/uploaded-image/marketing/' . $filename,
                'type' => 'image'
            ];
        }
    }
    echo json_encode(['data' => $responses]);
    exit;
}
?>