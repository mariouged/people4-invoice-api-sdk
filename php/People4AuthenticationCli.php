<?php

declare(strict_types=1);

/**
 * People4 SSH Authentication Client
 *
 * Connects to the People4 SSH auth server using an SSH private key.
 * The server validates the key and responds with a JWT token.
 */

/**
 * Uses the default key file by default:
 * ```php php/People4AuthenticationCli.php```
 *
 * Or pass a custom private key path:
 * ```php php/People4AuthenticationCli.php /path/to/private_key```
 */

// ---------------------------------------------------------------------------
// Value objects
// ---------------------------------------------------------------------------

class SshAuthResponse
{
    public function __construct(
        public readonly string $token
    ) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['token']) || !is_string($data['token'])) {
            throw new \RuntimeException('Missing or invalid "token" field in server response');
        }

        return new self($data['token']);
    }

    /**
     * Decode and return the JWT payload (middle segment) as an associative array.
     * Note: this does NOT verify the signature — use a proper JWT library for that.
     */
    public function getJwtPayload(): array
    {
        $parts = explode('.', $this->token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Token does not appear to be a valid JWT (expected 3 segments)');
        }

        // JWT uses base64url encoding (- and _ instead of + and /)
        $padded  = str_pad(strtr($parts[1], '-_', '+/'), (int) ceil(strlen($parts[1]) / 4) * 4, '=');
        $payload = base64_decode($padded, true);

        if ($payload === false) {
            throw new \RuntimeException('Failed to base64-decode JWT payload segment');
        }

        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
}

// ---------------------------------------------------------------------------
// SSH auth client
// ---------------------------------------------------------------------------

class People4SshAuthClient
{
    private const DEFAULT_CONNECT_TIMEOUT = 10;

    public function __construct(
        private readonly string $host,
        private readonly int    $port,
        private readonly string $user,
        private readonly int    $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT
    ) {}

    /**
     * Authenticate using the supplied private key and return the server's JWT response.
     *
     * @throws \RuntimeException when the key file is unreadable, SSH fails, or the
     *                           response is not valid JSON containing a token.
     */
    public function authenticate(string $privateKeyPath): SshAuthResponse
    {
        $realPath = realpath($privateKeyPath);
        if ($realPath === false || !is_readable($realPath)) {
            throw new \RuntimeException("Cannot read private key file: {$privateKeyPath}");
        }

        // Build the SSH command with all arguments shell-escaped to prevent injection
        $cmd = implode(' ', [
            'ssh',
            '-i',         escapeshellarg($realPath),
            '-p',         (string) $this->port,
            '-o',         'StrictHostKeyChecking=no',
            '-o',         'PasswordAuthentication=no',
            '-o',         'BatchMode=yes',
            '-o',         escapeshellarg('ConnectTimeout=' . $this->connectTimeout),
            escapeshellarg($this->user . '@' . $this->host),
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin  (closed immediately)
            1 => ['pipe', 'w'],  // stdout (server response)
            2 => ['pipe', 'w'],  // stderr (SSH diagnostics)
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start SSH process');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = trim((string) $stdout);

        if ($output === '') {
            $errorDetail = trim((string) $stderr);
            throw new \RuntimeException(
                "SSH command produced no output (exit code {$exitCode})"
                . ($errorDetail !== '' ? ": {$errorDetail}" : '')
            );
        }

        try {
            return SshAuthResponse::fromJson($output);
        } catch (\JsonException) {
            throw new \RuntimeException(
                "Server response is not valid JSON. Raw output: {$output}"
            );
        }
    }
}

// ---------------------------------------------------------------------------
// CLI entry point
// ---------------------------------------------------------------------------

class People4AuthenticationCli
{
    private const SSH_HOST = '172.29.0.10';
    private const SSH_PORT = 2222;
    private const SSH_USER = 'acmecorporationeu';
    private const KEY_FILE = __DIR__ . '/../ssh-keys/acmecorporationeu_key';

    public static function run(array $argv): int
    {
        $privateKey = $argv[2] ?? self::KEY_FILE;

        try {
            echo 'Authenticating as '
                . self::SSH_USER . '@' . self::SSH_HOST . ':' . self::SSH_PORT
                . PHP_EOL;

            $client   = new People4SshAuthClient(self::SSH_HOST, self::SSH_PORT, self::SSH_USER);
            $response = $client->authenticate($privateKey);

            echo 'Authentication successful.' . PHP_EOL;
            echo 'Token: ' . $response->token . PHP_EOL;

            $payload = $response->getJwtPayload();
            echo PHP_EOL . 'JWT Payload:' . PHP_EOL;
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    public static function getJwtTokenRaw(
        string $sshHost = self::SSH_HOST,
        int $sshPort = self::SSH_PORT,
        string $sshUser = self::SSH_USER,
        string $privateKeyPath = self::KEY_FILE
    ): string
    {
        $privateKey = $privateKeyPath;

        try {
            $client   = new People4SshAuthClient($sshHost, $sshPort, $sshUser);
            $response = $client->authenticate($privateKey);

            return $response->token;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Fatal: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }
}

if ($argv[1] === "TEST_STANDALONE") {
    echo "TEST_STANDALONE mode: show the JWT token to stdout." . PHP_EOL;
    exit(People4AuthenticationCli::run($argv));
}
