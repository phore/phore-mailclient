<?php

declare(strict_types=1);

namespace Phore\MailClient\Support;

use InvalidArgumentException;
use Phore\MailClient\Domain\MailAddress;
use Phore\MailClient\Domain\MailDraft;
use RuntimeException;

final class MimeDraftBuilder
{
    private const MAX_ATTACHMENT_BYTES = 25_000_000;

    public static function build(MailDraft $draft): string
    {
        $headers = [
            'From: ' . self::addresses([$draft->from]),
            'To: ' . self::addresses($draft->to),
            'Subject: ' . self::header($draft->subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . $draft->messageId,
            'MIME-Version: 1.0',
        ];
        if ($draft->cc !== []) { $headers[] = 'Cc: ' . self::addresses($draft->cc); }
        foreach ($draft->headers as $name => $value) {
            self::assertHeader($name); self::assertHeader($value);
            $headers[] = $name . ': ' . $value;
        }

        if ($draft->attachments === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
            return implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($draft->markdown());
        }

        $boundary = 'phore_' . bin2hex(random_bytes(16));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($draft->markdown()),
        ];
        foreach ($draft->attachments as $attachment) {
            $stream = $attachment->openStream(self::MAX_ATTACHMENT_BYTES);
            $content = stream_get_contents($stream);
            if ($content === false) { throw new RuntimeException('Unable to read attachment stream.'); }
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . self::header($attachment->mediaType) . '; name="' . self::header($attachment->filename) . '"';
            $parts[] = 'Content-Disposition: attachment; filename="' . self::header($attachment->filename) . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = '';
            $parts[] = rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
        }
        $parts[] = '--' . $boundary . '--';
        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    /** @param list<MailAddress> $addresses */
    private static function addresses(array $addresses): string
    {
        return implode(', ', array_map(static fn (MailAddress $address): string => (string) $address, $addresses));
    }

    private static function header(string $value): string
    {
        self::assertHeader($value);
        return mb_encode_mimeheader($value, 'UTF-8', 'Q', "\r\n");
    }

    private static function assertHeader(string $value): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) { throw new InvalidArgumentException('Header values must not contain line breaks.'); }
    }
}
