<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use Phore\MailClient\Support\SimpleHtmlToMarkdown;

final readonly class MailBody
{
    public string $markdown;

    /** @param list<string> $warnings */
    public function __construct(
        public string $text = '',
        public ?string $html = null,
        ?string $markdown = null,
        public ?string $authoredMarkdown = null,
        public ?string $quotedMarkdown = null,
        public array $warnings = [],
    ) {
        $this->markdown = $markdown ?? ($text !== '' ? $text : SimpleHtmlToMarkdown::convert($html ?? ''));
    }

    public function asText(): string
    {
        return $this->text !== '' ? $this->text : SimpleHtmlToMarkdown::text($this->html ?? '');
    }

    public function asHtml(): ?string { return $this->html; }
    public function asMarkdown(): string { return $this->markdown; }
}
