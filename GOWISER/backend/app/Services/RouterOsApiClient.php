<?php

namespace App\Services;

/**
 * Minimal client for the MikroTik RouterOS API — the binary protocol served on the
 * `api` / `api-ssl` service (8728 / 8729 by default), not the HTTP REST interface.
 *
 * Protocol: a sentence is a list of length-prefixed words ended by a zero-length word.
 * Replies are `!re` (one record), `!done` (end of reply), `!trap` (command error, still
 * followed by `!done`) and `!fatal` (connection closed by the router).
 *
 * Only what the status sync needs: login and `print` queries. Records come back as
 * `attribute => value` arrays with the same names the REST interface uses (`.id`,
 * `name`, `group`, `user`, `user-address`, ...), so callers can treat both alike.
 */
class RouterOsApiClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private int $timeout = 10,
        private bool $ssl = false
    ) {
    }

    /**
     * Open the connection and log in. Throws on any network or authentication failure.
     */
    public function connect(string $username, string $password): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]]);

        $transport = $this->ssl ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            "{$transport}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new \RuntimeException("RouterOS API connection to {$this->host}:{$this->port} failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        // RouterOS 6.43+ logs in with the plain password in one step.
        $reply = $this->talk(['/login', '=name=' . $username, '=password=' . $password]);

        // Older RouterOS answers with an MD5 challenge instead.
        $challenge = $reply['done']['ret'] ?? null;
        if ($challenge !== null && $challenge !== '') {
            $response = '00' . md5(chr(0) . $password . pack('H*', $challenge));
            $this->talk(['/login', '=name=' . $username, '=response=' . $response]);
        }
    }

    /**
     * Run a command (e.g. `/user-manager/user/print`) and return its `!re` records.
     *
     * @param string[] $arguments Extra words, e.g. `=.proplist=name,group` or `?disabled=false`.
     * @return array<int, array<string, string>>
     */
    public function query(string $command, array $arguments = []): array
    {
        return $this->talk(array_merge([$command], $arguments))['re'];
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Send one sentence and read the full reply.
     *
     * @return array{re: array<int, array<string, string>>, done: array<string, string>}
     */
    private function talk(array $words): array
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('RouterOS API is not connected');
        }

        $this->writeSentence($words);

        $records = [];
        $trap = null;

        while (true) {
            $sentence = $this->readSentence();
            if ($sentence === []) {
                continue;
            }

            $type = array_shift($sentence);
            $attributes = $this->parseAttributes($sentence);

            switch ($type) {
                case '!re':
                    $records[] = $attributes;
                    break;

                case '!trap':
                    // The router still sends !done after a trap; read up to it so the
                    // connection stays in step, then report the error.
                    $trap = $attributes['message'] ?? 'unknown error';
                    break;

                case '!fatal':
                    $message = $sentence[0] ?? ($attributes['message'] ?? 'connection closed by router');
                    $this->close();
                    throw new \RuntimeException("RouterOS API fatal: {$message}");

                case '!done':
                    if ($trap !== null) {
                        throw new RouterOsApiTrapException($trap);
                    }
                    return ['re' => $records, 'done' => $attributes];

                default:
                    // e.g. !empty (RouterOS 7.18+) — nothing to collect.
                    break;
            }
        }
    }

    /**
     * `=key=value` words to an array; the value may itself contain `=`.
     */
    private function parseAttributes(array $words): array
    {
        $attributes = [];
        foreach ($words as $word) {
            if ($word === '' || $word[0] !== '=') {
                continue;
            }
            $pair = explode('=', substr($word, 1), 2);
            $attributes[$pair[0]] = $pair[1] ?? '';
        }
        return $attributes;
    }

    private function writeSentence(array $words): void
    {
        $payload = '';
        foreach ($words as $word) {
            $payload .= $this->encodeLength(strlen($word)) . $word;
        }
        $payload .= chr(0);

        $written = 0;
        $total = strlen($payload);
        while ($written < $total) {
            $bytes = @fwrite($this->socket, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                throw new \RuntimeException('RouterOS API write failed');
            }
            $written += $bytes;
        }
    }

    /**
     * @return string[]
     */
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $length = $this->readLength();
            if ($length === 0) {
                return $words;
            }
            $words[] = $this->readBytes($length);
        }
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . pack('N', $length);
    }

    private function readLength(): int
    {
        $first = ord($this->readBytes(1));

        if (($first & 0x80) === 0x00) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            return (($first & 0x3F) << 8) + ord($this->readBytes(1));
        }
        if (($first & 0xE0) === 0xC0) {
            $rest = $this->readBytes(2);
            return (($first & 0x1F) << 16) + (ord($rest[0]) << 8) + ord($rest[1]);
        }
        if (($first & 0xF0) === 0xE0) {
            $rest = $this->readBytes(3);
            return (($first & 0x0F) << 24) + (ord($rest[0]) << 16) + (ord($rest[1]) << 8) + ord($rest[2]);
        }
        if ($first === 0xF0) {
            return unpack('N', $this->readBytes(4))[1];
        }

        throw new \RuntimeException(sprintf('RouterOS API sent an invalid length byte 0x%02X', $first));
    }

    private function readBytes(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : [];
                $reason = !empty($meta['timed_out']) ? 'timed out' : 'connection closed';
                throw new \RuntimeException("RouterOS API read failed: {$reason}");
            }
            $data .= $chunk;
        }
        return $data;
    }
}
