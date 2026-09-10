<?php
declare(strict_types=1);
namespace Phore\MailClient;

use InvalidArgumentException;
use Phore\MailClient\Internal\Html;
use Phore\Markdown\Markdown;

final readonly class Signature
{
    private function __construct(public Body $body, public array $images) {}
    public static function fromMarkdown(string $markdown): self { return new self(Body::fromMarkdown($markdown), []); }
    /** @internal Stable per-message IDs prevent collisions when a signature is reused. */
    public function forMessage(string $messageId): self
    {
        $images = []; $replace = [];
        foreach ($this->images as $image) {
            $id = hash('sha256',$messageId . ':' . $image->contentId) . '@phore.signature';
            $replace['cid:' . $image->contentId] = 'cid:' . $id;
            $images[] = Attachment::fromBytes($image->filename(),$image->mediaType(),$image->content,$id);
        }
        return new self(new Body($this->body->text(),$this->body->html() === null ? null : strtr($this->body->html(),$replace),$this->body->markdown()),$images);
    }
    public static function fromHtml(string $html, ?string $text = null, array $inlineImages = [], int $maxImageBytes = 2_000_000): self
    {
        $images = []; $ids = [];
        foreach ($inlineImages as $alias => $path) {
            if (!is_string($alias) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $alias)) { throw new InvalidArgumentException('Invalid image alias.'); }
            $image = Attachment::fromPath($path, $maxImageBytes);
            $size = @getimagesizefromstring($image->content);
            if ($size === false || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) { throw new InvalidArgumentException('Signature image must be PNG, JPEG or GIF.'); }
            $id = bin2hex(random_bytes(16)) . '@phore.signature';
            $ids[$alias] = $id;
            $images[] = Attachment::fromBytes($image->filename(), $size['mime'], $image->content, $id);
        }
        $html = Html::sanitize($html, $ids);
        // Keep alt text in the plain alternative; never expose cid URLs there.
        $fallback = preg_replace_callback('/<img\b[^>]*alt="([^"]*)"[^>]*>/i', static fn(array $m): string => $m[1], $html);
        return new self(new Body($text ?? Markdown::textFromHtml($fallback), $html), $images);
    }
}
