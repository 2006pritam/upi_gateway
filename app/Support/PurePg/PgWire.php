<?php

namespace App\Support\PurePg;

use PDOException;

/**
 * Minimal PostgreSQL v3 wire-protocol client in plain PHP (simple query protocol, TLS, SCRAM-SHA-256).
 * It exists for hosts whose PHP has no pdo_pgsql (for example Appwrite Functions).
 */
final class PgWire
{
    /** @var resource|null */
    private $sock = null;

    /** Transaction status from the last ReadyForQuery: I idle, T in transaction, E failed transaction. */
    public string $txStatus = 'I';

    public function __construct(
        private string $host,
        private int $port,
        private string $database,
        private string $user,
        private string $password,
        private string $sslmode = 'require',
        private float $timeout = 15.0,
    ) {}

    public function isConnected(): bool
    {
        return is_resource($this->sock) && ! feof($this->sock);
    }

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'peer_name' => $this->host,
            'SNI_enabled' => true,
            'verify_peer' => $this->sslmode !== 'disable' && $this->sslmode !== 'allow' && $this->sslmode !== 'prefer',
            'verify_peer_name' => $this->sslmode === 'verify-full' || $this->sslmode === 'require',
            'allow_self_signed' => false,
        ]]);

        $sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (! $sock) {
            throw $this->error("could not connect to server: {$err}", '08006');
        }
        stream_set_timeout($sock, (int) $this->timeout);
        $this->sock = $sock;

        if ($this->sslmode !== 'disable') {
            $this->write(pack('NN', 8, 80877103)); // SSLRequest
            $answer = $this->read(1);
            if ($answer === 'S') {
                if (! @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw $this->error('TLS handshake with the database failed', '08006');
                }
            } elseif (in_array($this->sslmode, ['require', 'verify-ca', 'verify-full'], true)) {
                throw $this->error('server does not support SSL, but SSL was required', '08006');
            }
        }

        $params = "user\0{$this->user}\0database\0{$this->database}\0client_encoding\0UTF8\0\0";
        $this->write(pack('NN', 8 + strlen($params), 196608).$params);
        $this->authenticate();
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fwrite($this->sock, 'X'.pack('N', 4));
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    /**
     * Run SQL (one or several statements) with the simple query protocol.
     *
     * @return array<int, array{fields: array<int, array{0: string, 1: int}>, rows: array<int, array<int, ?string>>, tag: string}>
     */
    public function query(string $sql): array
    {
        if (! $this->isConnected()) {
            throw $this->error('server closed the connection unexpectedly', '08006');
        }

        $this->write('Q'.pack('N', 5 + strlen($sql)).$sql."\0");

        $results = [];
        $current = ['fields' => [], 'rows' => [], 'tag' => ''];
        $failure = null;

        while (true) {
            [$type, $body] = $this->readMessage();
            switch ($type) {
                case 'T':
                    $current = ['fields' => $this->parseRowDescription($body), 'rows' => [], 'tag' => ''];
                    break;
                case 'D':
                    $current['rows'][] = $this->parseDataRow($body);
                    break;
                case 'C':
                    $current['tag'] = rtrim($body, "\0");
                    $results[] = $current;
                    $current = ['fields' => [], 'rows' => [], 'tag' => ''];
                    break;
                case 'E':
                    $failure ??= $this->parseError($body);
                    break;
                case 'Z':
                    $this->txStatus = $body[0] ?? 'I';
                    if ($failure) {
                        throw $failure;
                    }

                    return $results;
                default: // I (empty query), N (notice), S (parameter status), A (notification), c/d/G/H (copy: unsupported)
                    break;
            }
        }
    }

    private function authenticate(): void
    {
        $scram = null;

        while (true) {
            [$type, $body] = $this->readMessage();

            if ($type === 'E') {
                throw $this->parseError($body);
            }
            if ($type === 'Z') {
                $this->txStatus = $body[0] ?? 'I';

                return;
            }
            if ($type !== 'R') {
                continue; // ParameterStatus, BackendKeyData, NoticeResponse
            }

            $code = unpack('N', substr($body, 0, 4))[1];
            switch ($code) {
                case 0: // AuthenticationOk
                    break;
                case 3: // cleartext
                    $this->sendPassword($this->password."\0");
                    break;
                case 5: // md5
                    $salt = substr($body, 4, 4);
                    $this->sendPassword('md5'.md5(md5($this->password.$this->user).$salt)."\0");
                    break;
                case 10: // SASL: pick SCRAM-SHA-256
                    if (! str_contains($body, 'SCRAM-SHA-256')) {
                        throw $this->error('server offers no supported SASL mechanism', '28000');
                    }
                    $nonce = rtrim(base64_encode(random_bytes(18)), '=');
                    $scram = ['nonce' => $nonce, 'bare' => "n=,r={$nonce}"];
                    $first = 'n,,'.$scram['bare'];
                    $this->sendPassword("SCRAM-SHA-256\0".pack('N', strlen($first)).$first);
                    break;
                case 11: // SASLContinue
                    $scram['server_first'] = substr($body, 4);
                    $this->sendPassword($this->scramFinal($scram));
                    break;
                case 12: // SASLFinal
                    $final = substr($body, 4);
                    if (! isset($scram['server_signature']) || ! hash_equals('v='.$scram['server_signature'], $final)) {
                        throw $this->error('SCRAM server signature mismatch', '28000');
                    }
                    break;
                default:
                    throw $this->error("unsupported authentication method ({$code})", '28000');
            }
        }
    }

    private function scramFinal(array &$scram): string
    {
        $attrs = [];
        foreach (explode(',', $scram['server_first']) as $part) {
            $attrs[$part[0]] = substr($part, 2);
        }
        if (! isset($attrs['r'], $attrs['s'], $attrs['i']) || ! str_starts_with($attrs['r'], $scram['nonce'])) {
            throw $this->error('invalid SCRAM server response', '28000');
        }

        $salted = hash_pbkdf2('sha256', $this->password, base64_decode($attrs['s']), (int) $attrs['i'], 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
        $withoutProof = 'c=biws,r='.$attrs['r'];
        $authMessage = $scram['bare'].','.$scram['server_first'].','.$withoutProof;
        $signature = hash_hmac('sha256', $authMessage, hash('sha256', $clientKey, true), true);
        $proof = base64_encode($clientKey ^ $signature);

        $serverKey = hash_hmac('sha256', 'Server Key', $salted, true);
        $scram['server_signature'] = base64_encode(hash_hmac('sha256', $authMessage, $serverKey, true));

        return $withoutProof.',p='.$proof;
    }

    private function sendPassword(string $payload): void
    {
        $this->write('p'.pack('N', 4 + strlen($payload)).$payload);
    }

    /** @return array<int, array{0: string, 1: int}> */
    private function parseRowDescription(string $body): array
    {
        $n = unpack('n', $body)[1];
        $pos = 2;
        $fields = [];
        for ($i = 0; $i < $n; $i++) {
            $end = strpos($body, "\0", $pos);
            $name = substr($body, $pos, $end - $pos);
            $pos = $end + 1;
            $oid = unpack('N', substr($body, $pos + 6, 4))[1];
            $pos += 18; // table oid(4) attnum(2) type oid(4) typlen(2) typmod(4) format(2)
            $fields[] = [$name, $oid];
        }

        return $fields;
    }

    /** @return array<int, ?string> */
    private function parseDataRow(string $body): array
    {
        $n = unpack('n', $body)[1];
        $pos = 2;
        $row = [];
        for ($i = 0; $i < $n; $i++) {
            $len = unpack('N', substr($body, $pos, 4))[1];
            $pos += 4;
            if ($len === 0xFFFFFFFF) {
                $row[] = null;
                continue;
            }
            $row[] = substr($body, $pos, $len);
            $pos += $len;
        }

        return $row;
    }

    private function parseError(string $body): PDOException
    {
        $fields = [];
        $pos = 0;
        while ($pos < strlen($body) && $body[$pos] !== "\0") {
            $code = $body[$pos];
            $end = strpos($body, "\0", $pos + 1);
            $fields[$code] = substr($body, $pos + 1, $end - $pos - 1);
            $pos = $end + 1;
        }

        $state = $fields['C'] ?? 'XX000';
        $message = $fields['M'] ?? 'unknown error';

        return PurePgException::make('ERROR:  '.$message.(isset($fields['D']) ? "\nDETAIL:  ".$fields['D'] : ''), $state);
    }

    private function error(string $message, string $sqlState): PDOException
    {
        return PurePgException::make($message, $sqlState);
    }

    /** @return array{0: string, 1: string} */
    private function readMessage(): array
    {
        $head = $this->read(5);
        $len = unpack('N', substr($head, 1, 4))[1] - 4;

        return [$head[0], $len > 0 ? $this->read($len) : ''];
    }

    private function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = is_resource($this->sock) ? fread($this->sock, $length - strlen($data)) : false;
            if ($chunk === false || $chunk === '') {
                $this->close();
                throw $this->error('server closed the connection unexpectedly', '08006');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function write(string $data): void
    {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $n = is_resource($this->sock) ? @fwrite($this->sock, substr($data, $written)) : false;
            if ($n === false || $n === 0) {
                $this->close();
                throw $this->error('server closed the connection unexpectedly', '08006');
            }
            $written += $n;
        }
    }
}
