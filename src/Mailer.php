<?php
declare(strict_types=1);

namespace Festkasse;

/**
 * Minimal dependency-free SMTP client — no Composer/vendor, single file, same "zero dependencies,
 * FTP deploy" model as the rest of the app. Talks raw SMTP over a socket (optionally STARTTLS or
 * implicit TLS), AUTH LOGIN, and builds a simple multipart/mixed MIME message with 0+ attachments.
 * Not a general-purpose mail library — just enough for this app's own report emails.
 */
final class Mailer
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly ?string $host,
        private readonly int $port,
        private readonly string $encryption, // 'tls' (STARTTLS) | 'ssl' (implicit TLS) | 'none'
        private readonly ?string $user,
        private readonly ?string $pass,
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
    }

    /** @param array{host:string, port:int, encryption:string, user:string, pass:string, fromEmail:string, fromName:string} $smtp */
    public static function fromConfig(array $smtp): self
    {
        return new self(
            $smtp['host'] !== '' ? $smtp['host'] : null,
            $smtp['port'],
            $smtp['encryption'],
            $smtp['user'] !== '' ? $smtp['user'] : null,
            $smtp['pass'] !== '' ? $smtp['pass'] : null,
            $smtp['fromEmail'],
            $smtp['fromName']
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== null && $this->fromEmail !== '';
    }

    /**
     * @param string[] $to
     * @param array<int, array{filename: string, mime: string, content: string}> $attachments
     */
    public function send(array $to, string $subject, string $textBody, array $attachments = []): void
    {
        if (!$this->isConfigured()) {
            throw new ApiException(400, 'Kein Mailserver konfiguriert · SMTP_* in .env setzen (siehe .env.example)');
        }
        $to = array_values(array_filter($to, static fn (string $addr): bool => $addr !== ''));
        if (empty($to)) {
            throw new ApiException(400, 'Keine Berichts-E-Mail hinterlegt · Verwaltung → Einstellungen');
        }

        $socket = $this->connect();
        try {
            $this->expect($socket, [220]);
            $this->ehlo($socket);
            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new ApiException(502, 'TLS-Verbindung zum Mailserver fehlgeschlagen');
                }
                $this->ehlo($socket);
            }
            if ($this->user !== null) {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->user), [334]);
                $this->command($socket, base64_encode((string) $this->pass), [235]);
            }
            $this->command($socket, 'MAIL FROM:<' . $this->fromEmail . '>', [250]);
            foreach ($to as $recipient) {
                $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command($socket, 'DATA', [354]);
            $message = $this->buildMessage($to, $subject, $textBody, $attachments);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect()
    {
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new ApiException(502, 'Verbindung zum Mailserver fehlgeschlagen: ' . $errstr);
        }
        stream_set_timeout($socket, self::TIMEOUT_SECONDS);
        return $socket;
    }

    /** @param resource $socket */
    private function ehlo($socket): void
    {
        $this->command($socket, 'EHLO ' . (gethostname() ?: 'localhost'), [250]);
    }

    /**
     * @param resource $socket
     * @param int[] $expectCodes
     */
    private function command($socket, string $line, array $expectCodes): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expectCodes);
    }

    /**
     * @param resource $socket
     * @param int[] $expectCodes
     */
    private function expect($socket, array $expectCodes): void
    {
        $code = 0;
        $lastLine = '';
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new ApiException(502, 'Mailserver hat die Verbindung ohne Antwort beendet');
            }
            $lastLine = $line;
            $code = (int) substr($line, 0, 3);
            $continues = isset($line[3]) && $line[3] === '-';
        } while ($continues);
        if (!in_array($code, $expectCodes, true)) {
            throw new ApiException(502, 'Mailserver-Fehler: ' . trim($lastLine));
        }
    }

    /**
     * @param string[] $to
     * @param array<int, array{filename: string, mime: string, content: string}> $attachments
     */
    private function buildMessage(array $to, string $subject, string $textBody, array $attachments): string
    {
        $boundary = 'festkasse_' . bin2hex(random_bytes(12));
        $encodeWord = static fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';

        $headers = [
            'From: ' . $encodeWord($this->fromName) . ' <' . $this->fromEmail . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . $encodeWord($subject),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];

        $parts = [];
        $parts[] = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody));
        foreach ($attachments as $a) {
            $parts[] = "--{$boundary}\r\nContent-Type: {$a['mime']}; name=\"{$a['filename']}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$a['filename']}\"\r\n\r\n"
                . chunk_split(base64_encode($a['content']));
        }
        $body = implode('', $parts) . "--{$boundary}--";

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        // Dot-stuff any line starting with '.', per RFC 5321 — the raw ".\r\n" terminator this
        // class appends after send() would otherwise end DATA early. Base64 body lines never
        // start with '.' (outside its alphabet), so this only ever touches header/body text lines.
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }
}
