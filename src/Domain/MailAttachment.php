<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use Closure;
use InvalidArgumentException;
use Phore\MailClient\Exception\AttachmentTooLargeException;

final readonly class MailAttachment
{
    /** @var Closure(): resource */
    private Closure $streamOpener;

    /** @param callable(): resource $streamOpener */
    public function __construct(
        public string $id,
        public string $filename,
        public string $mediaType,
        public int $size,
        callable $streamOpener,
        public ?string $sha256 = null,
        public bool $inline = false,
    ) {
        if ($id === '' || $filename === '' || $size < 0) {
            throw new InvalidArgumentException('Invalid attachment metadata.');
        }
        $this->streamOpener = Closure::fromCallable($streamOpener);
    }

    /** @return resource */
    public function openStream(int $maxBytes): mixed
    {
        if ($maxBytes < 0 || $this->size > $maxBytes) {
            throw new AttachmentTooLargeException(sprintf('Attachment exceeds the %d byte limit.', $maxBytes));
        }

        $stream = ($this->streamOpener)();
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Attachment opener did not return a stream resource.');
        }
        return $stream;
    }
}
