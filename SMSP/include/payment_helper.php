<?php
// Payment helper for transaction verification

class PaymentHelper {
    public static function verifyPaystack($reference, $secretKey) {
        if (empty($secretKey)) {
            return false;
        }

        $url = "https://api.paystack.co/transaction/verify/" . rawurlencode($reference);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $secretKey,
            "Cache-Control: no-cache"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['data']['status']) && $result['data']['status'] === 'success') {
                return $result['data'];
            }
        }
        return false;
    }

    public static function verifyFlutterwave($txId, $secretKey) {
        if (empty($secretKey)) {
            return false;
        }

        $url = "https://api.flutterwave.com/v3/transactions/" . rawurlencode($txId) . "/verify";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $secretKey,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['data']['status']) && $result['data']['status'] === 'successful') {
                return $result['data'];
            }
        }
        return false;
    }

    public static function saveProofOfPayment($file, $schoolId, $admissionNo) {
        $uploadDir = dirname(__DIR__) . '/uploads/receipts/' . $schoolId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'pop_' . $admissionNo . '_' . time() . '.' . $extension;
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetFile)) {
            // In the future, this file URL can be uploaded to Google Drive
            return '/uploads/receipts/' . $schoolId . '/' . $fileName;
        }
        return false;
    }
}
?>
