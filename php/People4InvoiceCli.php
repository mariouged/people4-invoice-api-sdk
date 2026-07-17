<?php

declare(strict_types=1);

include_once __DIR__ . '/People4AuthenticationCli.php';

/**
 * People4 Invoice API REST Client
 *
 * Sends an invoice payload (JSON) to the People4 Peppol endpoint
 * and returns the XML response.
 */

/**
 * Uses doc/people4-invoice-example.json by default
 * ```php php/People4InvoiceCli.php```
 *
 * Or pass a custom payload file
 * ```php php/People4InvoiceCli.php /path/to/other-invoice.json```
 */

// ---------------------------------------------------------------------------
// Value objects
// ---------------------------------------------------------------------------

class EndpointId
{
    public function __construct(
        public readonly string $id,
        public readonly string $scheme
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['scheme']);
    }
}

class Address
{
    public function __construct(
        public readonly string $street,
        public readonly string $city,
        public readonly string $postalCode,
        public readonly string $country
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['street'],
            $data['city'],
            $data['postalCode'],
            $data['country']
        );
    }
}

class Party
{
    public function __construct(
        public readonly string     $legalName,
        public readonly string     $vatId,
        public readonly Address    $address,
        public readonly ?string    $registrationId = null,
        public readonly ?string    $endpointId = null,
        public readonly ?string    $endpointSchemeId = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['legalName'],
            $data['vatId'],
            Address::fromArray($data['address']),
            $data['registrationId'] ?? null,
            $data['endpointId'] ?? null,
            $data['endpointSchemeId'] ?? null
        );
    }
}

class Tax
{
    public function __construct(
        public readonly string $category,
        public readonly string $percent
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['category'], $data['percent']);
    }
}

class InvoiceLine
{
    public function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly string $quantity,
        public readonly string $unitCode,
        public readonly string $unitPrice,
        public readonly Tax    $tax
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['description'],
            $data['quantity'],
            $data['unitCode'],
            $data['unitPrice'],
            Tax::fromArray($data['tax'])
        );
    }
}

class TaxTotal
{
    public function __construct(
        public readonly string $category,
        public readonly string $percent,
        public readonly string $taxableAmount,
        public readonly string $taxAmount
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['category'],
            $data['percent'],
            $data['taxableAmount'],
            $data['taxAmount']
        );
    }
}

class InvoiceTotals
{
    public function __construct(
        public readonly string $lineExtension,
        public readonly string $taxExclusive,
        public readonly string $taxInclusive,
        public readonly string $payable
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['lineExtension'],
            $data['taxExclusive'],
            $data['taxInclusive'],
            $data['payable']
        );
    }
}

class InvoiceHeader
{
    public function __construct(
        public readonly string  $number,
        public readonly string  $issueDate,
        public readonly string  $typeCode,
        public readonly string  $currency,
        public readonly string  $buyerReference,
        public readonly ?string $orderReference = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['number'],
            $data['issueDate'],
            $data['typeCode'],
            $data['currency'],
            $data['buyerReference'],
            $data['orderReference'] ?? null
        );
    }
}

class InvoicePayload
{
    /** @param InvoiceLine[] $lines */
    /** @param TaxTotal[]    $taxTotals */
    public function __construct(
        public readonly InvoiceHeader $invoice,
        public readonly Party         $seller,
        public readonly Party         $buyer,
        public readonly array         $lines,
        public readonly array         $taxTotals,
        public readonly InvoiceTotals $totals
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            InvoiceHeader::fromArray($data['invoice']),
            Party::fromArray($data['seller']),
            Party::fromArray($data['buyer']),
            array_map([InvoiceLine::class, 'fromArray'], $data['lines']),
            array_map([TaxTotal::class,    'fromArray'], $data['taxTotals']),
            InvoiceTotals::fromArray($data['totals'])
        );
    }

    public static function fromJsonFile(string $filePath): self
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException("Cannot read payload file: {$filePath}");
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException("Failed to read payload file: {$filePath}");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    public function toArray(): array
    {
        $lines = array_map(static fn(InvoiceLine $l): array => [
            'id'          => $l->id,
            'description' => $l->description,
            'quantity'    => $l->quantity,
            'unitCode'    => $l->unitCode,
            'unitPrice'   => $l->unitPrice,
            'tax'         => ['category' => $l->tax->category, 'percent' => $l->tax->percent],
        ], $this->lines);

        $taxTotals = array_map(static fn(TaxTotal $t): array => [
            'category'      => $t->category,
            'percent'       => $t->percent,
            'taxableAmount' => $t->taxableAmount,
            'taxAmount'     => $t->taxAmount,
        ], $this->taxTotals);

        $partyToArray = static function (Party $p): array {
            $out = [
                'legalName'  => $p->legalName,
                'vatId'      => $p->vatId,
                'address'    => [
                    'street'     => $p->address->street,
                    'city'       => $p->address->city,
                    'postalCode' => $p->address->postalCode,
                    'country'    => $p->address->country,
                ],
            ];
            if ($p->registrationId !== null) {
                $out['registrationId'] = $p->registrationId;
            }
            if ($p->endpointId !== null) {
                $out['endpointId'] = $p->endpointId;
            }
            if ($p->endpointSchemeId !== null) {
                $out['endpointSchemeId'] = $p->endpointSchemeId;
            }
            return $out;
        };

        $invoiceArray = [
            'number'          => $this->invoice->number,
            'issueDate'       => $this->invoice->issueDate,
            'typeCode'        => $this->invoice->typeCode,
            'currency'        => $this->invoice->currency,
            'buyerReference'  => $this->invoice->buyerReference,
        ];
        if ($this->invoice->orderReference !== null) {
            $invoiceArray['orderReference'] = $this->invoice->orderReference;
        }

        return [
            'invoice'   => $invoiceArray,
            'seller'    => $partyToArray($this->seller),
            'buyer'     => $partyToArray($this->buyer),
            'lines'     => $lines,
            'taxTotals' => $taxTotals,
            'totals'    => [
                'lineExtension' => $this->totals->lineExtension,
                'taxExclusive'  => $this->totals->taxExclusive,
                'taxInclusive'  => $this->totals->taxInclusive,
                'payable'       => $this->totals->payable,
            ],
        ];
    }
}

// ---------------------------------------------------------------------------
// HTTP client
// ---------------------------------------------------------------------------

class People4ApiResponse
{
    public function __construct(
        public readonly int    $statusCode,
        public readonly string $body,
        public readonly array  $headers
    ) {}

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** Returns the raw BODY string. */
    public function getBody(): string
    {
        return $this->body;
    }
}

class People4InvoiceClient
{
    private const DEFAULT_TIMEOUT = 30;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int    $timeout = self::DEFAULT_TIMEOUT
    ) {}

    /**
     * Submit an invoice payload and return the server response.
     *
     * @throws \RuntimeException on cURL / HTTP error
     */
    public function submitInvoice(InvoicePayload $payload, string $jwtTokenRaw): People4ApiResponse
    {
        $json = json_encode($payload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/xml',
                'Content-Length: ' . strlen($json),
                'Authorization: Bearer ' . $jwtTokenRaw,
            ],
            // Collect response headers
            CURLOPT_HEADER         => false,
        ]);

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaders): int {
            $len  = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        });

        $body       = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("cURL request failed: {$curlError}");
        }

        return new People4ApiResponse($statusCode, (string) $body, $responseHeaders);
    }
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

class People4InvoiceCli
{
    private const API_URL      = 'http://172.28.0.10:8080/invoice/v1';
    private const PAYLOAD_FILE = __DIR__ . '/../doc/people4-invoice-example.json';

    private const SSH_HOST = '172.29.0.10';
    private const SSH_PORT = 2222;
    private const SSH_USER = 'acmecorporationeu';
    private const KEY_FILE = __DIR__ . '/../ssh-keys/acmecorporationeu_key';

    public static function run(array $argv): int
    {
        $payloadFile = $argv[1] ?? self::PAYLOAD_FILE;

        try {
            echo "Loading payload from: {$payloadFile}" . PHP_EOL;
            $payload = InvoicePayload::fromJsonFile($payloadFile);

            $jwtTokenRaw = People4AuthenticationCli::getJwtTokenRaw(
                self::SSH_HOST,
                self::SSH_PORT,
                self::SSH_USER,
                self::KEY_FILE
            );

            $client   = new People4InvoiceClient(self::API_URL);

            echo "Submitting invoice {$payload->invoice->number} to " . self::API_URL . PHP_EOL;
            $response = $client->submitInvoice($payload, $jwtTokenRaw);

            echo "HTTP Status: {$response->statusCode}" . PHP_EOL;

            if (!$response->isSuccess()) {
                fwrite(STDERR, "Error response ({$response->statusCode}):" . PHP_EOL);
                fwrite(STDERR, $response->body . PHP_EOL);
                return 1;
            }

            echo "Response BODY:" . PHP_EOL;
            echo $response->getBody() . PHP_EOL;

            echo "Response DECODED:" . PHP_EOL;
            $apiResponse = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if ($apiResponse['people4Id'] ?? null) {
                echo "People4 ID: " . $apiResponse['people4Id'] . PHP_EOL;
            } else {
                fwrite(STDERR, "Error People4 ID not present in response." . PHP_EOL);
                return 1;
            }

            if ($apiResponse['ubl'] ?? null) {
                echo "UBL: " . $apiResponse['ubl'] . PHP_EOL;
            } else {
                fwrite(STDERR, "Error UBL not present in response." . PHP_EOL);
                return 1;
            }

            if ($apiResponse['peppol'] ?? null) {
                echo "PEPPOL: " . $apiResponse['peppol'] . PHP_EOL;
            } else {
                fwrite(STDERR, "Error PEPPOL not present in response." . PHP_EOL);
                return 1;
            }

            echo "OK HTTP Status: {$response->statusCode}" . PHP_EOL;
            echo "OK Response Body, len: " . strlen($response->getBody()) . PHP_EOL;
            echo "OK people4Id: " . $apiResponse['people4Id'] . PHP_EOL;
            echo "OK ubl, len: " . strlen($apiResponse['ubl'] ?? '') . PHP_EOL;
            echo "OK peppol, len: " . strlen($apiResponse['peppol'] ?? '') . PHP_EOL;

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}

exit(People4InvoiceCli::run($argv));

