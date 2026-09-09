<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use Phore\Markdown\HtmlToMarkdown;

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
        $this->markdown = $markdown ?? ($text !== '' ? $text : HtmlToMarkdown::convert($html ?? ''));
    }

    public function asText(): string
    {
        return $this->text !== '' ? $this->text : HtmlToMarkdown::toText($this->html ?? '');
    }

    public function asHtml(): ?string { return $this->html; }
    public function asMarkdown(): string { return $this->markdown; }
}
