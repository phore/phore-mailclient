<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

use InvalidArgumentException;

/** @internal */
final class Headers
{
    public static function validate(string $value): string
    {
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid UTF-8 or control character in header.');
        }
        return $value;
    }

    public static function decode(string $value): string
    {
        self::validate($value);
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_STRICT, 'UTF-8');
        if ($decoded === false) { throw new InvalidArgumentException('Invalid encoded header.'); }
        return self::validate($decoded);
    }

    public static function encoded(string $value): string
    {
        self::validate($value);
        // Short encoded words avoid splitting a UTF-8 character or exceeding line limits.
        $words = [];
        while ($value !== '') {
            $chunk = mb_strcut($value, 0, 36, 'UTF-8');
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
            $value = substr($value, strlen($chunk));
        }
        return implode("\r\n ", $words);
    }

    public static function messageId(?string $id): ?string
    {
        return $id !== null && preg_match('/^<[^<>\s@]+@[^<>\s@]+>$/D', $id) ? $id : null;
    }

    public static function parse(string $raw): array
    {
        $raw = preg_replace('/\r?\n[ \t]+/', ' ', trim($raw));
        $headers = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($line === '') { continue; }
            if (!preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/D', $line, $m)) {
                throw new InvalidArgumentException('Malformed MIME header.');
            }
            $key = strtolower($m[1]);
            $headers[$key][] = self::validate($m[2]);
        }
        return $headers;
    }
}
