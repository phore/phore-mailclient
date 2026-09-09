<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use Phore\MailClient\Contract\DraftConnector;
use Phore\MailClient\Exception\UnsupportedCapabilityException;

final class MailDraft
{
    /**
     * @param list<MailAddress> $to
     * @param list<MailAddress> $cc
     * @param list<MailAttachment> $attachments
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly MailAddress $from,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly string $messageId,
        public readonly ?DraftRelation $relation = null,
        public readonly array $headers = [],
        public readonly array $attachments = [],
        private string $authoredMarkdown = '',
        private readonly string $quotedMarkdown = '',
        private readonly ?DraftConnector $connector = null,
    ) {}

    public function withMarkdown(string $markdown): self
    {
        $clone = clone $this;
        $clone->authoredMarkdown = $markdown;
        return $clone;
    }

    public function markdown(): string
    {
        return implode("\n\n", array_filter([$this->authoredMarkdown, $this->quotedMarkdown], static fn (string $part): bool => $part !== ''));
    }

    public function save(): SavedDraft
    {
        if ($this->connector === null) {
            throw new UnsupportedCapabilityException('This account has no draft connector.');
        }
        return $this->connector->save($this);
    }
}
