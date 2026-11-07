<?php

class ChimoneyBridge {
    private $apiKey;
    private $baseUrl = "https://api.chimoney.io/v1/";

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    private function request($endpoint, $method = 'GET', $data = []) {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        $ch = curl_init($url);

        $headers = [
            "X-API-KEY: " . $this->apiKey,
            "Content-Type: application/json",
            "Accept: application/json"
        ];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
        return [
            'status' => ($httpCode >= 200 && $httpCode < 300) ? 'success' : 'error',
            'code' => $httpCode,
            'data' => $result,
            'raw' => $response
        ];
    }

    /**
     * Virtual Card Methods
     */
    public function createVirtualCard($data) {
        // Mocking structure based on Chimoney docs for Virtual Card issuance
        return $this->request("cards/issue", "POST", $data);
    }

    public function fundVirtualCard($cardId, $amount) {
        return $this->request("cards/fund", "POST", [
            'card_id' => $cardId,
            'amount' => $amount
        ]);
    }

    public function getCardDetails($cardId) {
        return $this->request("cards/details?card_id=" . $cardId);
    }

    public function getTransactions($cardId) {
        return $this->request("cards/transactions?card_id=" . $cardId);
    }

    public function freezeCard($cardId) {
        return $this->request("cards/freeze", "POST", ['card_id' => $cardId]);
    }

    public function unfreezeCard($cardId) {
        return $this->request("cards/unfreeze", "POST", ['card_id' => $cardId]);
    }

    public function terminateCard($cardId) {
        return $this->request("cards/terminate", "POST", ['card_id' => $cardId]);
    }

    public function getExchangeRates() {
        return $this->request("info/exchange-rates");
    }
}
