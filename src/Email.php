<?php
declare(strict_types=1);
namespace Phore\MailClient;

use DateTimeImmutable;
use InvalidArgumentException;
use Phore\MailClient\Internal\Headers;
use Phore\MailClient\Internal\Html;
use Phore\MailClient\Internal\MessageDefaults;

final class Email
{
    private array $authors;
    private array $recipients;
    private array $copies;
    private array $blindCopies;
    private array $replyTargets;
    private ?EmailAddress $sendingAgent;
    private string $title;
    private ?string $serverId = null;
    private ?string $messageId;
    private ?DateTimeImmutable $createdAt;
    private Body $ownBody;
    private ?Body $quoteBody = null;
    private string $introduction = '';
    private array $files = [];
    private Signature|false|null $signature = null;
    private string $signaturePosition = 'above-quote';
    private bool $resolved = false;
    private array $flags = [];
    private array $references = [];
    private ?string $inReplyTo = null;
    private ?string $sourceId = null;
    private ?string $sourceAction = null;

    public function __construct(EmailAddress|string|array|null $from = null, EmailAddress|string|array $to = [], string $subject = '', EmailAddress|string|array $cc = [], EmailAddress|string|array $bcc = [], EmailAddress|string|array $replyTo = [], EmailAddress|string|null $sender = null)
    {
        if ($from === []) { throw new InvalidArgumentException('Explicit From must not be empty.'); }
        $this->authors = $from === null ? [] : EmailAddress::normalize($from);
        $this->recipients = EmailAddress::normalize($to); $this->copies = EmailAddress::normalize($cc);
        $this->blindCopies = EmailAddress::normalize($bcc); $this->replyTargets = EmailAddress::normalize($replyTo);
        $this->sendingAgent = is_string($sender) ? EmailAddress::parse($sender) : $sender;
        if (count($this->authors) > 1 && $this->sendingAgent === null) { throw new InvalidArgumentException('Multiple authors require Sender.'); }
        $this->title = Headers::validate($subject);
        $this->messageId = '<' . bin2hex(random_bytes(20)) . '@phore.local>';
        $this->createdAt = new DateTimeImmutable(); $this->ownBody = Body::fromMarkdown('');
    }
    public function id(): ?string { return $this->serverId; }
    public function messageId(): ?string { return $this->messageId; }
    public function date(): ?DateTimeImmutable { return $this->createdAt; }
    public function from(): array { return $this->authors; }
    public function to(): array { return $this->recipients; }
    public function cc(): array { return $this->copies; }
    public function bcc(): array { return $this->blindCopies; }
    public function replyTo(): array { return $this->replyTargets; }
    public function sender(): ?EmailAddress { return $this->sendingAgent; }
    public function subject(): string { return $this->title; }
    public function flags(): array { return $this->flags; }
    public function references(): array { return $this->references; }
    public function inReplyTo(): ?string { return $this->inReplyTo; }
    /** @internal Origin for automatic flags after successful draft storage. */
    public function sourceId(): ?string { return $this->sourceId; }
    /** @internal */
    public function sourceAction(): ?string { return $this->sourceAction; }
    public function attachments(): array { return [...$this->files, ...($this->signature instanceof Signature ? $this->signature->images : [])]; }
    public function withMarkdown(string $markdown): self { $copy = clone $this; $copy->ownBody = Body::fromMarkdown($markdown); return $copy; }
    public function attach(Attachment $attachment): self { $copy = clone $this; $copy->files[] = $attachment; return $copy; }
    public function withSignature(Signature|false|null $signature): self { $copy = clone $this; $copy->signature = $signature; return $copy; }
    public function body(): Body
    {
        if ($this->quoteBody === null && !$this->signature instanceof Signature) { return $this->ownBody; }
        $text = [$this->ownBody->text()]; $html = [$this->ownBody->html() ?? Html::literal($this->ownBody->text())]; $md = [$this->ownBody->markdown()];
        $addSignature = function () use (&$text, &$html, &$md): void {
            if ($this->signature instanceof Signature) {
                $text[] = $this->signature->body->text(); $html[] = $this->signature->body->html(); $md[] = $this->signature->body->markdown();
            }
        };
        if ($this->signaturePosition === 'above-quote') { $addSignature(); }
        if ($this->quoteBody !== null) {
            $text[] = $this->introduction;
            $text[] = implode("\n", array_map(static fn(string $line): string => '> ' . $line, explode("\n", $this->quoteBody->text())));
            // Introduction and original plain content are literal text, never executable Markdown.
            $html[] = Html::literal($this->introduction) . '<blockquote>' . Html::literal($this->quoteBody->text()) . '</blockquote>';
            $escape = static fn(string $s): string => preg_replace('/([\\\\`*_{}\[\]()#+.!<>-])/', '\\\\$1', $s);
            $md[] = $escape($this->introduction);
            $md[] = implode("\n", array_map(static fn(string $line): string => '> ' . $line, explode("\n", $this->quoteBody->markdown())));
        }
        if ($this->signaturePosition === 'below-quote') { $addSignature(); }
        return new Body(implode("\n\n", array_filter($text, static fn($s) => $s !== '')), implode("\n", $html), implode("\n\n", $md));
    }

    public function reply(EmailAddress|string $from, string $markdown = '', array $quote = [], Signature|false|null $signature = null, string $signaturePosition = 'above-quote'): self
    { return $this->answer($from, $markdown, false, [], $quote, $signature, $signaturePosition); }
    public function replyAll(EmailAddress|string $from, string $markdown = '', array $exclude = [], array $quote = [], Signature|false|null $signature = null, string $signaturePosition = 'above-quote'): self
    { return $this->answer($from, $markdown, true, $exclude, $quote, $signature, $signaturePosition); }
    private function answer(EmailAddress|string $from, string $markdown, bool $all, array $exclude, array $quote, Signature|false|null $signature, string $position): self
    {
        $author = is_string($from) ? EmailAddress::parse($from) : $from;
        $targets = $this->replyTargets ?: $this->authors; $cc = []; $seen = [];
        if ($all) {
            foreach ([$author, ...EmailAddress::normalize($exclude)] as $a) { $seen[$a->getAddress()] = true; }
        }
        $unique = static function (array $addresses) use (&$seen): array {
            $result = [];
            foreach ($addresses as $address) {
                if (!isset($seen[$address->getAddress()])) { $result[] = $address; $seen[$address->getAddress()] = true; }
            }
            return $result;
        };
        $targets = $unique($targets);
        if ($all) { $cc = $unique([...$this->recipients, ...$this->copies]); }
        if ($targets === []) { throw new InvalidArgumentException('No usable reply targets.'); }
        $copy = (new self(from: $author, to: $targets, subject: preg_match('/^Re:/i', $this->title) ? $this->title : 'Re: ' . $this->title, cc: $cc))->withMarkdown($markdown);
        $copy->quoteBody = $this->body(); $copy->introduction = MessageDefaults::introduction($this, 'reply', $quote);
        $copy->sourceId = $this->serverId; $copy->sourceAction = 'reply';
        $copy->signature = $signature; $copy->signaturePosition = MessageDefaults::position($position); $copy->resolved = true;
        if (Headers::messageId($this->messageId) !== null) {
            $copy->inReplyTo = $this->messageId;
            $copy->references = array_values(array_unique([...$this->references, $this->messageId]));
        }
        return $copy;
    }
    public function forward(EmailAddress|string $from, EmailAddress|string|array $to = [], string $markdown = '', bool $includeAttachments = false, array $quote = [], Signature|false|null $signature = null, string $signaturePosition = 'above-quote'): self
    {
        $copy = (new self(from: $from, to: $to, subject: preg_match('/^Fwd:/i', $this->title) ? $this->title : 'Fwd: ' . $this->title))->withMarkdown($markdown);
        $copy->quoteBody = $this->body(); $copy->introduction = MessageDefaults::introduction($this, 'forward', $quote);
        $copy->sourceId = $this->serverId; $copy->sourceAction = 'forward';
        $copy->signature = $signature; $copy->signaturePosition = MessageDefaults::position($signaturePosition); $copy->resolved = true;
        if ($includeAttachments) { $copy->files = $this->attachments(); }
        return $copy;
    }
    /** @internal Resolve defaults once without changing this value. */
    public function resolve(?EmailAddress $from, MessageDefaults $defaults): self
    {
        $copy = clone $this;
        if ($copy->authors === []) {
            if ($from === null) { throw new InvalidArgumentException('No configured or explicit From.'); }
            $copy->authors = [$from];
        }
        if (!$copy->resolved) {
            $copy->signature ??= $defaults->signatures['new'];
            $copy->signaturePosition = $defaults->position; $copy->resolved = true;
        }
        return $copy;
    }
    /** @internal */
    public function onServer(string $id, array $flags): self
    { $copy = clone $this; $copy->serverId = $id; $copy->flags = $flags; $copy->resolved = true; return $copy; }
    /** @internal MIME reader entry point. */
    public static function received(array $headers, Body $body, array $attachments, string $id, array $flags): self
    {
        $one = static function (string $name) use ($headers): ?string {
            $values = $headers[$name] ?? [];
            if (count($values) > 1) { throw new InvalidArgumentException('Duplicate singleton header: ' . $name); }
            return $values[0] ?? null;
        };
        $addresses = static fn(string $name): array => EmailAddress::parseList($one($name) ?? '');
        $from = $addresses('from'); $sender = $one('sender');
        $copy = new self(from: $from ?: null, to: $addresses('to'), cc: $addresses('cc'), bcc: $addresses('bcc'), replyTo: $addresses('reply-to'), sender: $sender === null ? null : EmailAddress::parse($sender), subject: Headers::decode($one('subject') ?? ''));
        $copy->messageId = Headers::messageId($one('message-id'));
        $copy->inReplyTo = Headers::messageId($one('in-reply-to'));
        preg_match_all('/<[^<>\s]+@[^<>\s]+>/', $one('references') ?? '', $matches);
        $copy->references = array_values(array_filter($matches[0], static fn(string $v): bool => Headers::messageId($v) !== null));
        $date = $one('date');
        try { $copy->createdAt = $date === null ? null : new DateTimeImmutable($date); } catch (\Exception) { $copy->createdAt = null; }
        $copy->ownBody = $body; $copy->files = $attachments;
        return $copy->onServer($id, $flags);
    }
}
