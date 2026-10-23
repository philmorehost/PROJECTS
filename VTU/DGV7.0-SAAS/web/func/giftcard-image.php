<?php
include_once(__DIR__ . "/../../func/bc-connect.php");

$id_param = $_GET['id'] ?? '';
$cacheDir = __DIR__ . "/../../asset/gift-card/";

if (is_numeric($id_param)) {
    $productId = intval($id_param);
    $localFile = $cacheDir . $productId . ".png";

    if (file_exists($localFile)) {
        header('Content-Type: image/png');
        readfile($localFile);
        exit;
    }

    $q = mysqli_query($connection_server, "SELECT logo_url FROM sas_vendor_giftcard_products WHERE reloadly_product_id = '$productId' LIMIT 1");
    $data = mysqli_fetch_assoc($q);

    if (!$data || !$data['logo_url']) {
        $q = mysqli_query($connection_server, "SELECT logo_url FROM sas_global_giftcard_products WHERE reloadly_product_id = '$productId' LIMIT 1");
        $data = mysqli_fetch_assoc($q);
    }
} else {
    // Handle name-based lookup (e.g. id=amazon)
    $name = mysqli_real_escape_string($connection_server, $id_param);
    $localFile = $cacheDir . strtolower($id_param) . ".png";

    if (file_exists($localFile)) {
        header('Content-Type: image/png');
        readfile($localFile);
        exit;
    }

    $q = mysqli_query($connection_server, "SELECT logo_url FROM sas_global_giftcard_products WHERE product_name LIKE '%$name%' LIMIT 1");
    $data = mysqli_fetch_assoc($q);
}

if ($data && $data['logo_url']) {
    // Download it silently
    $imgData = @file_get_contents($data['logo_url']);
    if ($imgData) {
        if(!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
        @file_put_contents($localFile, $imgData);

        header('Content-Type: image/png');
        echo $imgData;
        exit;
    }
}

// 3. Fallback: Show a generic gift card placeholder if everything fails
if (file_exists(__DIR__ . "/../../asset/dash_unknown.jpg")) {
    header('Content-Type: image/jpeg');
    readfile(__DIR__ . "/../../asset/dash_unknown.jpg");
} else {
    // Create a simple blank image or text
    header('Content-Type: image/png');
    $im = imagecreatetruecolor(100, 100);
    $bg = imagecolorallocate($im, 240, 240, 240);
    imagefill($im, 0, 0, $bg);
    $text_color = imagecolorallocate($im, 100, 100, 100);
    imagestring($im, 2, 20, 40,  'GIFT CARD', $text_color);
    imagepng($im);
    imagedestroy($im);
}
?>
