<?php

class PaynetworxApi
{
    private $user;
    private $password;
    private $baseUrl;

    const URL_TEST = 'https://api.qa.paynetworx.net/v0/';
    const URL_LIVE = 'https://api.prod.paynetworx.net/v0/';

    public function __construct($user, $password, $mode = 'test')
    {
        $this->user     = $user;
        $this->password = $password;
        $this->baseUrl  = ($mode === 'live') ? self::URL_LIVE : self::URL_TEST;
    }

    public function authCapture($amount, $currency, $cardData, $billingData = [], $cartId = 0)
    {
        $pan      = (string) $cardData['pan'];
        $expMonth = str_pad((string) $cardData['exp_month'], 2, '0', STR_PAD_LEFT);
        $expYear  = str_pad((string)(((int) $cardData['exp_year']) % 100), 2, '0', STR_PAD_LEFT);
        $cvc      = isset($cardData['cvc']) ? (string) $cardData['cvc'] : '';

        $card = [
            'CardPresent' => false,
            'PAN' => [
                'PAN'      => $pan,
                'ExpMonth' => $expMonth,
                'ExpYear'  => $expYear,
            ],
        ];

        if ($cvc !== '') {
            $card['CVC'] = ['CVC' => $cvc];
        }

        if (!empty($billingData)) {
            $address = [];
            foreach (['Name', 'Line1', 'Line2', 'City', 'State', 'PostalCode', 'Country', 'Phone', 'Email'] as $field) {
                if (!empty($billingData[$field])) {
                    $address[$field] = (string) $billingData[$field];
                }
            }
            if ($address) {
                $card['BillingAddress'] = $address;
            }
        }

        $orderRef = $cartId > 0 ? 'CART-' . (int) $cartId : 'ORD-' . $this->generateRequestId();

        $payload = [
            'Amount' => [
                'Total'    => round((float) $amount, 2),
                'Currency' => (string) $currency,
            ],
            'PaymentMethod' => [
                'Card' => $card,
            ],
            'Attributes' => [
                'EntryMode' => 'manual',
                'ProcessingSpecifiers' => [
                    'InitiatedByECommerce' => true,
                ],
            ],
            'TransactionEntry' => [
                'Device'             => 'NA',
                'DeviceVersion'      => 'NA',
                'Application'        => 'Merchant Website Express',
                'ApplicationVersion' => '1.0',
                'Timestamp'          => gmdate('Y-m-d\TH:i:s') . 'Z',
            ],
            'Detail' => [
                'MerchantData' => [
                    'OrderNumber' => $orderRef,
                    'CustomerID'  => 'Guest',
                ],
            ],
        ];

        return $this->sendRequest('transaction/authcapture', $payload);
    }

    private function sendRequest($endpoint, array $payload)
    {
        $url       = $this->baseUrl . $endpoint;
        $requestId = $this->generateRequestId();
        $body      = json_encode($payload);

        if ($body === false) {
            throw new Exception('Failed to encode payment payload: ' . json_last_error_msg());
        }

        $headers = [
            'Content-Type: application/json',
            'Request-ID: ' . $requestId,
            'Authorization: Basic ' . base64_encode($this->user . ':' . $this->password),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Network error contacting payment gateway: ' . $error);
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Unexpected response from payment gateway.');
        }

        return [
            'status' => $httpCode,
            'body'   => $decoded,
        ];
    }

    /**
     * Generates a 27-character unique request ID using rejection sampling to eliminate
     * modulo bias (62 chars does not divide 256 evenly — biased bytes are discarded).
     */
    private function generateRequestId()
    {
        $chars   = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max     = 256 - (256 % 62); // 248 — reject bytes >= this
        $result  = '';
        while (strlen($result) < 27) {
            $byte = ord(random_bytes(1));
            if ($byte < $max) {
                $result .= $chars[$byte % 62];
            }
        }
        return $result;
    }
}
