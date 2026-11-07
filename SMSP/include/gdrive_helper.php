<?php
// Production-Ready Storage & Google Drive API Engine
class GDriveHelper {
    private static $localStorageDir = ROOT_PATH . '/uploads';

    /**
     * Obtains an OAuth Access Token from Google API using Service Account Credentials
     */
    private static function getAccessToken($clientEmail, $privateKey) {
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $payload = json_encode([
            'iss' => $clientEmail,
            'sub' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $signatureInput = $base64UrlHeader . "." . $base64UrlPayload;
        $signature = '';
        
        if (!openssl_sign($signatureInput, $signature, $privateKey, 'SHA256')) {
            return false;
        }
        
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $jwt = $signatureInput . "." . $base64UrlSignature;

        $postFields = 'grant_type=' . urlencode('urn:ietf:params:oauth:grant-type:jwt-bearer') . '&assertion=' . urlencode($jwt);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $data['access_token'] ?? false;
        }
        return false;
    }

    /**
     * Uploads file to Google Drive if configured, otherwise falls back to local server upload.
     */
    public static function uploadFile($tempFilePath, $originalName, $mimeType, $schoolId, $folderName = 'general') {
        if (!file_exists($tempFilePath)) {
            return false;
        }

        // Load credentials from Config if defined
        $clientEmail = defined('GDRIVE_CLIENT_EMAIL') ? GDRIVE_CLIENT_EMAIL : '';
        $privateKey = defined('GDRIVE_PRIVATE_KEY') ? GDRIVE_PRIVATE_KEY : '';
        $parentFolderId = defined('GDRIVE_FOLDER_ID') ? GDRIVE_FOLDER_ID : '';

        // If credentials are configured, execute actual Google Drive upload
        if (!empty($clientEmail) && !empty($privateKey)) {
            $accessToken = self::getAccessToken($clientEmail, $privateKey);
            if ($accessToken) {
                $metadata = ['name' => $originalName];
                if (!empty($parentFolderId)) {
                    $metadata['parents'] = [$parentFolderId];
                }

                $boundary = '-------314159265358979323846';
                $delimiter = "\r\n--" . $boundary . "\r\n";
                $closeDelimiter = "\r\n--" . $boundary . "--";

                $data = $delimiter
                    . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
                    . json_encode($metadata)
                    . $delimiter
                    . "Content-Type: " . $mimeType . "\r\n\r\n"
                    . file_get_contents($tempFilePath)
                    . $closeDelimiter;

                $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: multipart/related; boundary=' . $boundary,
                    'Content-Length: ' . strlen($data)
                ]);

                $response = curl_exec($ch);
                curl_close($ch);

                if ($response) {
                    $resData = json_decode($response, true);
                    if (isset($resData['id'])) {
                        return "https://drive.google.com/file/d/" . $resData['id'] . "/view?usp=drivesdk";
                    }
                }
            }
        }

        // Fallback to real local disk storage
        $targetDir = self::$localStorageDir . '/' . $schoolId . '/' . $folderName;
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueId = uniqid('file_', true);
        $fileName = $uniqueId . '.' . $fileExtension;
        $destination = $targetDir . '/' . $fileName;

        if (copy($tempFilePath, $destination)) {
            return '/uploads/' . $schoolId . '/' . $folderName . '/' . $fileName;
        }

        return false;
    }

    /**
     * Deletes a file from Google Drive or local storage
     */
    public static function deleteFile($fileUrl) {
        $clientEmail = defined('GDRIVE_CLIENT_EMAIL') ? GDRIVE_CLIENT_EMAIL : '';
        $privateKey = defined('GDRIVE_PRIVATE_KEY') ? GDRIVE_PRIVATE_KEY : '';

        // If it's a Google Drive link, delete from Drive
        if (strpos($fileUrl, 'drive.google.com') !== false && !empty($clientEmail) && !empty($privateKey)) {
            if (preg_match('/\/d\/([a-zA-Z0-9_\-]+)/', $fileUrl, $matches)) {
                $fileId = $matches[1];
                $accessToken = self::getAccessToken($clientEmail, $privateKey);
                if ($accessToken) {
                    $ch = curl_init("https://www.googleapis.com/drive/v3/files/" . $fileId);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
                    curl_exec($ch);
                    curl_close($ch);
                    return true;
                }
            }
        }

        // Otherwise delete locally
        $localPath = str_replace('/uploads/', self::$localStorageDir . '/', $fileUrl);
        if (file_exists($localPath)) {
            unlink($localPath);
            return true;
        }
        return false;
    }
}
?>
