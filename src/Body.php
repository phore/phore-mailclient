<?php
declare(strict_types=1);
namespace Phore\MailClient;

use Phore\Markdown\Markdown;

final readonly class Body
{
    public function __construct(private ?string $plain = null, private ?string $htmlSource = null, private ?string $markdownSource = null) {}
    public static function fromMarkdown(string $markdown): self
    {
        $html = Markdown::toHtml($markdown);
        return new self(Markdown::textFromHtml($html), $html, $markdown);
    }
    public function text(): string { return $this->plain ?? Markdown::textFromHtml($this->htmlSource ?? ''); }
    /** Original received HTML is untrusted. */
    public function html(): ?string { return $this->htmlSource; }
    public function markdown(): string { return $this->markdownSource ?? ($this->htmlSource === null ? $this->text() : Markdown::fromHtml($this->htmlSource)); }
}
