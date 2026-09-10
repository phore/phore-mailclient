<?php
declare(strict_types=1);
namespace Phore\MailClient;

use InvalidArgumentException;
use RuntimeException;
use Phore\MailClient\Internal\Headers;

final readonly class Attachment
{
    private function __construct(private string $name, private string $type, public int $size, public ?string $content, public ?string $sourceId, public ?string $part, public string $encoding = 'BINARY', public ?string $contentId = null)
    {
        Headers::validate($name); Headers::validate($type);
        if (!preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~D', $type) || $size < 0) {
            throw new InvalidArgumentException('Invalid attachment metadata.');
        }
        if ($contentId !== null && !preg_match('/^[^<>\s\x00-\x1f\x7f]+$/D', $contentId)) {
            throw new InvalidArgumentException('Invalid Content-ID.');
        }
    }
    public static function fromPath(string $path, int $maxBytes = 25_000_000): self
    {
        if ($maxBytes < 1 || !is_file($path) || !is_readable($path)) { throw new InvalidArgumentException('Unreadable local attachment.'); }
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot open attachment.'); }
        try { $bytes = stream_get_contents($stream, $maxBytes + 1); } finally { fclose($stream); }
        if ($bytes === false || strlen($bytes) > $maxBytes) { throw new RuntimeException('Attachment exceeds byte limit.'); }
        return self::fromBytes(basename($path), (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), $bytes);
    }
    public static function fromBytes(string $filename, string $mediaType, string $bytes, ?string $contentId = null): self
    { return new self($filename, $mediaType, strlen($bytes), $bytes, null, null, 'BINARY', $contentId); }
    /** @internal No callback, client or connection is retained. */
    public static function remote(string $filename, string $mediaType, int $encodedSize, string $sourceId, string $part, string $encoding, ?string $contentId = null): self
    {
        if (!preg_match('/^[1-9][0-9]*(?:\.[1-9][0-9]*)*$/D', $part)) { throw new InvalidArgumentException('Invalid MIME part.'); }
        return new self($filename, $mediaType, $encodedSize, null, $sourceId, $part, strtoupper($encoding), $contentId);
    }
    public function filename(): string { return $this->name; }
    public function mediaType(): string { return $this->type; }
}
