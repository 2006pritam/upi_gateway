<?php

namespace App\Services\Imap;

use RuntimeException;

/**
 * Lightweight, read-only IMAP-over-TLS client using PHP's built-in OpenSSL
 * stream sockets. Avoids requiring the native ext-imap extension or external
 * Composer packages so it works on any PHP 8.2+ hosting environment.
 */
class SimpleImapClient
{
    /** @var resource|null */
    private $socket = null;

    private int $tagCounter = 0;

    /**
     * Fetch recent raw messages matching a sender from the given folder.
     *
     * @return list<array{seq: int, headers: string, body: string}>
     */
    public function fetchRecentFromSender(
        string $host,
        int $port,
        string $username,
        string $password,
        string $sender,
        string $sinceDate,
        string $folder = 'INBOX',
        int $limit = 25,
        int $timeout = 10,
    ): array {
        $this->connect($host, $port, $timeout);

        try {
            $this->command('LOGIN '.$this->quote($username).' '.$this->quote($password));
            $this->command('EXAMINE '.$this->quote($folder));

            $searchLines = $this->command('SEARCH SINCE '.$sinceDate.' FROM '.$this->quote($sender));
            $seqs = $this->parseSearchResponse($searchLines);

            if ($seqs === []) {
                return [];
            }

            // Inspect newest messages first, bounded by $limit.
            $recentSeqs = array_reverse(array_slice($seqs, -$limit));
            $messages = [];

            foreach ($recentSeqs as $seq) {
                $fetchRaw = $this->commandRaw(
                    "FETCH {$seq} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE AUTHENTICATION-RESULTS MESSAGE-ID)] BODY.PEEK[TEXT])"
                );
                [$headers, $body] = $this->extractFetchParts($fetchRaw);
                $messages[] = [
                    'seq' => $seq,
                    'headers' => $headers,
                    'body' => $body,
                ];
            }

            return $messages;
        } finally {
            $this->disconnect();
        }
    }

    private function connect(string $host, int $port, int $timeout): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            "tls://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($socket)) {
            throw new RuntimeException("IMAP connection failed ({$errno}): {$errstr}");
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;

        $greeting = $this->readLine();
        if (! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('Unexpected IMAP greeting.');
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            try {
                $tag = $this->nextTag();
                @fwrite($this->socket, "{$tag} LOGOUT\r\n");
            } finally {
                @fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function command(string $command): array
    {
        $raw = $this->commandRaw($command);

        return preg_split('/\r?\n/', trim($raw)) ?: [];
    }

    private function commandRaw(string $command): string
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('IMAP socket is not connected.');
        }

        $tag = $this->nextTag();
        $written = @fwrite($this->socket, "{$tag} {$command}\r\n");
        if ($written === false) {
            throw new RuntimeException('Failed to write IMAP command.');
        }

        $buffer = '';

        while (true) {
            $line = $this->readLine();
            $buffer .= $line;

            // Handle IMAP literal strings: {byte_count}\r\n followed by exact bytes.
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literalBytes = (int) $m[1];
                if ($literalBytes > 0) {
                    $buffer .= $this->readExact($literalBytes);
                }

                continue;
            }

            if (str_starts_with($line, "{$tag} ")) {
                if (! str_starts_with($line, "{$tag} OK")) {
                    throw new RuntimeException('IMAP command failed: '.trim($line));
                }

                break;
            }
        }

        return $buffer;
    }

    private function readLine(): string
    {
        $line = @fgets($this->socket);
        if ($line === false) {
            $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : [];
            if (! empty($meta['timed_out'])) {
                throw new RuntimeException('IMAP read timed out.');
            }

            throw new RuntimeException('IMAP connection closed unexpectedly.');
        }

        return $line;
    }

    private function readExact(int $length): string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @fread($this->socket, min($remaining, 8192));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP literal read failed.');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function parseSearchResponse(array $lines): array
    {
        foreach ($lines as $line) {
            if (preg_match('/^\*\s+SEARCH\s+(.+)$/i', trim($line), $m)) {
                $ids = preg_split('/\s+/', trim($m[1])) ?: [];

                return array_values(array_map('intval', array_filter($ids, 'ctype_digit')));
            }
        }

        return [];
    }

    /**
     * Extract the header fields literal and body text literal from a FETCH response.
     *
     * @return array{0: string, 1: string}
     */
    private function extractFetchParts(string $raw): array
    {
        $headers = '';
        $body = '';

        if (preg_match('/BODY\[HEADER\.FIELDS\s*\([^\)]*\)\]\s*\{(\d+)\}\r?\n/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $headers = substr($raw, $offset, (int) $m[1][0]);
        }

        if (preg_match('/BODY\[TEXT\]\s*\{(\d+)\}\r?\n/i', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $body = substr($raw, $offset, (int) $m[1][0]);
        }

        return [$headers, $body];
    }

    private function nextTag(): string
    {
        $this->tagCounter++;

        return sprintf('A%03d', $this->tagCounter);
    }

    private function quote(string $value): string
    {
        $escaped = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);

        return '"'.$escaped.'"';
    }
}
