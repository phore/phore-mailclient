<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

final readonly class MailBody
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $markdown,
        public ?string $authoredMarkdown = null,
        public ?string $quotedMarkdown = null,
        public array $warnings = [],
    ) {}
}
