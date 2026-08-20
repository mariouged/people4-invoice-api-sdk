<?php

declare(strict_types=1);

class People4HttpClient
{
    public function __construct(
        private readonly bool $disableSslVerification = false,
    ) {}

    // send an invoice and received ubl, peppol and people4Id in response
    public function sendInvoice(string $url, string $payload, string $jwt): array
    {
        $response = $this->httpRequest(
            $url,
            'POST',
            $payload,
            $jwt,
        );
        if (!$response || !is_string($response)) {
            throw new \RuntimeException("Response failed to retrieve JWT token from People4 Authentication API.");
        }
        $invoiceCreated = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        return $invoiceCreated;
    }

    public function retrieveToken(string $url, RetrieveTokenPayload $payload): string
    {
        $response = $this->httpRequest(
            $url,
            'PUT',
            json_encode($payload->toArray(), JSON_THROW_ON_ERROR),
            $payload->getApiKey(),
        );
        if (!$response || !is_string($response)) {
            throw new \RuntimeException("Response failed to retrieve JWT token from People4 Authentication API.");
        }
        $jwt = json_decode($response, true, 512, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)['token'] ?? throw new \RuntimeException("JWT token not present in response.");
        return $jwt;
    }

    private function httpRequest(string $url, string $method, string $payload, string $bearer): mixed
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            // CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($payload),
                'Authorization: Bearer ' . $bearer, // use for jwt and api key
            ],
            CURLOPT_HEADER         => false, // Collect response headers
            CURLOPT_SSL_VERIFYPEER => !$this->disableSslVerification, // curl -k insecure on DEVELOPMENT, not recommended for production
            CURLOPT_SSL_VERIFYHOST => !$this->disableSslVerification, // curl -k insecure on DEVELOPMENT, not recommended for production
        ]);
        if ($payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        if ($method === 'PUT') {
            //curl_setopt($ch, CURLOPT_PUT, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        }
        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // echo "HTTP status code: " . $statusCode . PHP_EOL;
        // echo "HTTP response body length: " . strlen((string) $body) . PHP_EOL;
        if ($statusCode >= 400 || $statusCode < 200 || $body === false) {
            $curlError  = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request failed with status code {$statusCode}: {$curlError}");
        }
        curl_close($ch);

        return $body;
    }
}

class RetrieveTokenPayload
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $domain,
        private readonly string $legalName,
        private readonly string $vatId
    ) {}

    public function toArray(): array
    {
        return [
            'domain'     => $this->domain,
            'legalname'  => $this->legalName,
            'vatId'      => $this->vatId,
        ];
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

class People4InvoiceCli
{
    private string $jwt;

    public function __construct() {}

    // retrieve JWT token from Authentication API
    public function getToken(): string
    {
        $httpCli   = new People4HttpClient(disableSslVerification: true); // only for testing, not recommended for production
        $payload = new RetrieveTokenPayload(
            apiKey: "1c8f86e857cb65b20b26c481b0e2d2d1bf0b93a93aa7ccc35704c37ee63c597b",
            domain: "acme-corporation.eu",
            legalName: "ACME Corp",
            vatId: "NL8200.98.395.B.01",
        );
        $jwt = $httpCli->retrieveToken(
            url: 'https://app.people4.eu/auth-api/token',
            payload: $payload,
        );
        $this->jwt = $jwt;

        return $jwt;
    }

    // send invoice payload to Invoice API POST method
    public function createUblInvoice($invoiceJsonRaw): array
    {
        $httpCli   = new People4HttpClient(disableSslVerification: true); // only for testing, not recommended for production
        $invoiceCreated = $httpCli->sendInvoice(
            url: 'https://app.people4.eu/invoice-api/invoice/v1',
            payload: $invoiceJsonRaw,
            jwt: $this->jwt,
        );
        return $invoiceCreated;
    }
}

// example
try {
    $easyComplianceCli = new People4InvoiceCli();
    echo "Trying to retrieve JWT token from Authentication API" . PHP_EOL;
    $jwt = $easyComplianceCli->getToken();
    // echo "OK: JWT token retrieved: '" . $jwt . "'" . PHP_EOL;

    echo "Read json invoice minimal test file and send to People4 Invoice API" . PHP_EOL;
    $invoiceFile = __DIR__ . '/../inputs/invoice-v1-minimal-test.json';
    $invoiceJsonRaw = file_get_contents($invoiceFile);
    echo "Trying to send invoice payload to Invoice API to generate UBL and PEPPOL invoice" . PHP_EOL;
    $invoiceCreated = $easyComplianceCli->createUblInvoice($invoiceJsonRaw);
    // echo "DEBUG: Invoice created response: " . json_encode($invoiceCreated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $invoiceCreated['people4Id'] ?? throw new \RuntimeException("People4 ID not present in response.");
    $invoiceCreated['ubl'] ?? throw new \RuntimeException("UBL not present in response.");
    $invoiceCreated['peppol'] ?? throw new \RuntimeException("PEPPOL not present in response.");
    if (!empty($invoiceCreated['messages']) && is_array($invoiceCreated['messages'])) {
        foreach ($invoiceCreated['messages'] as $message) {
            echo "Error Message: " . $message . PHP_EOL;
        }
        throw new \RuntimeException("Error on generate UBL or PEPPOL message: " . $message);
    }
    if (isset($invoiceCreated['people4Id'])) {
        echo "OK: Invoice created with People4 ID: " . $invoiceCreated['people4Id'] . PHP_EOL;
    }
    if (isset($invoiceCreated['ubl'])) {
        echo "OK: UBL invoice created with length: " . strlen($invoiceCreated['ubl']) . PHP_EOL;
    }
    if (isset($invoiceCreated['peppol'])) {
        echo "OK: PEPPOL invoice created with length: " . strlen($invoiceCreated['peppol']) . PHP_EOL;
    }
    file_put_contents(__DIR__ . '/../outputs/response-invoice-v1-minimal-created.json', json_encode($invoiceCreated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    file_put_contents(__DIR__ . '/../outputs/response-invoice-v1-minimal-test-ubl.xml', $invoiceCreated['ubl']);
    file_put_contents(__DIR__ . '/../outputs/response-invoice-v1-minimal-test-peppol.xml', $invoiceCreated['peppol']);
    return 0;
} catch (\Throwable $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
    echo "Error: " . $e->getMessage() . PHP_EOL;
    return 1;
}
