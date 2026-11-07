<?php

class BSICardsBridge {
    private $publicKey;
    private $secretKey;
    private $baseUrl = "https://cards.bsigroup.tech/api/";

    public function __construct($publicKey, $secretKey) {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
    }

    private function request($endpoint, $method = 'GET', $data = [], $isMultipart = false) {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        $ch = curl_init($url);

        $headers = [
            "publickey: " . $this->publicKey,
            "secretkey: " . $this->secretKey,
            "Accept: application/json"
        ];

        if (!$isMultipart) {
            $headers[] = "Content-Type: application/json";
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
        return [
            'status' => ($httpCode >= 200 && $httpCode < 300 && (!isset($result['status']) || $result['status'] !== 'error')) ? 'success' : 'error',
            'code' => $httpCode,
            'data' => $result,
            'raw' => $response
        ];
    }

    /**
     * MasterCard Issuance
     */
    public function createMasterCard($useremail, $nameoncard, $pin) {
        return $this->request("newcard", "POST", [
            "useremail" => $useremail,
            "nameoncard" => $nameoncard,
            "pin" => $pin
        ]);
    }

    public function fundMasterCard($useremail, $cardid, $amount) {
        return $this->request("fundcard", "POST", [
            "useremail" => $useremail,
            "cardid" => $cardid,
            "amount" => $amount
        ]);
    }

    public function getMasterCardDetails($useremail, $cardid) {
        return $this->request("getcard", "POST", [
            "useremail" => $useremail,
            "cardid" => $cardid
        ]);
    }

    public function getMasterCardTransactions($useremail, $cardid) {
        return $this->request("getcardtransactions", "POST", [
            "useremail" => $useremail,
            "cardid" => $cardid
        ]);
    }

    /**
     * Digital Visa Wallet
     */
    public function createDigitalVisa($useremail, $firstname, $lastname) {
        return $this->request("digital-wallet-visa/create-card", "POST", [
            "useremail" => $useremail,
            "firstname" => $firstname,
            "lastname" => $lastname
        ]);
    }

    public function fundDigitalVisa($useremail, $cardid, $amount) {
        return $this->request("digital-wallet-visa/fund-card", "POST", [
            "useremail" => $useremail,
            "cardid" => $cardid,
            "amount" => $amount
        ]);
    }

    public function getDigitalVisaDetails($useremail, $cardid) {
        return $this->request("digital-wallet-visa/get-card", "POST", [
            "useremail" => $useremail,
            "cardid" => $cardid
        ]);
    }

    public function getDigitalVisaTransactions($useremail, $cardid) {
        // Transactions endpoint for digital visa not explicitly shown in doc,
        // using get-card as it returns transactions according to some reseller sections.
        return $this->getDigitalVisaDetails($useremail, $cardid);
    }

    public function blockDigitalVisa($useremail, $cardid) {
        return $this->request("digital-wallet-visa/block-card", "POST", [
            "useremail" => $useremail,
            "cardid" => $cardid
        ]);
    }

    public function unblockDigitalVisa($useremail, $cardid) {
        return $this->request("digital-wallet-visa/unblock-card", "POST", [
            "useremail" => $useremail,
            "cardid" => $cardid
        ]);
    }

    public function getWalletBalance() {
        return $this->request("admin/wallet", "GET");
    }
}
