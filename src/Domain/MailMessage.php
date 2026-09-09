<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use DateTimeImmutable;
use Phore\MailClient\Contract\DraftConnector;
use Phore\MailClient\Support\MarkdownQuote;

final readonly class MailMessage
{
    /**
     * @param list<MailAddress> $to
     * @param list<MailAddress> $cc
     * @param list<MailAttachment> $attachments
     * @param list<string> $references
     * @param array<string, string> $headers
     */
    public function __construct(
        public MailReference $reference,
        public MailAddress $from,
        public array $to,
        public array $cc,
        public string $subject,
        public DateTimeImmutable $date,
        public MailBody $body,
        public array $attachments = [],
        public array $references = [],
        public array $headers = [],
        private ?MailAddress $accountAddress = null,
        private ?DraftConnector $draftConnector = null,
    ) {}

    public function bindDrafts(MailAddress $accountAddress, ?DraftConnector $connector): self
    {
        return new self(
            $this->reference,
            $this->from,
            $this->to,
            $this->cc,
            $this->subject,
            $this->date,
            $this->body,
            $this->attachments,
            $this->references,
            $this->headers,
            $accountAddress,
            $connector,
        );
    }

    public function reply(): MailDraft
    {
        return $this->draft(DraftRelationType::Reply, [$this->from], [], self::prefixedSubject('Re:', $this->subject), MarkdownQuote::reply($this));
    }

    public function replyAll(): MailDraft
    {
        $recipients = $this->uniqueRecipients([$this->from, ...$this->to]);
        $cc = $this->uniqueRecipients($this->cc, $recipients);
        return $this->draft(DraftRelationType::ReplyAll, $recipients, $cc, self::prefixedSubject('Re:', $this->subject), MarkdownQuote::reply($this));
    }

    /** @param list<MailAddress|string> $to */
    public function forward(array $to = []): MailDraft
    {
        $recipients = array_map(static fn (MailAddress|string $item): MailAddress => $item instanceof MailAddress ? $item : new MailAddress($item), $to);
        return $this->draft(DraftRelationType::Forward, $recipients, [], self::prefixedSubject('Fwd:', $this->subject), MarkdownQuote::forward($this));
    }

    /** @param list<MailAddress> $to @param list<MailAddress> $cc */
    private function draft(DraftRelationType $type, array $to, array $cc, string $subject, string $quote): MailDraft
    {
        $messageId = sprintf('<%s@phore.local>', bin2hex(random_bytes(16)));
        $headers = [];
        if ($this->reference->messageId !== null && $type !== DraftRelationType::Forward) {
            $headers['In-Reply-To'] = $this->reference->messageId;
            $headers['References'] = trim(implode(' ', [...$this->references, $this->reference->messageId]));
        }
        return new MailDraft(
            from: $this->accountAddress ?? $this->to[0] ?? $this->from,
            to: $to,
            cc: $cc,
            subject: $subject,
            messageId: $messageId,
            relation: new DraftRelation($type, $this->reference),
            headers: $headers,
            attachments: $type === DraftRelationType::Forward ? $this->attachments : [],
            quotedMarkdown: $quote,
            connector: $this->draftConnector,
        );
    }

    /** @param list<MailAddress> $addresses @param list<MailAddress> $exclude */
    private function uniqueRecipients(array $addresses, array $exclude = []): array
    {
        $seen = [];
        foreach ([...$exclude, ...($this->accountAddress === null ? [] : [$this->accountAddress])] as $address) {
            $seen[strtolower($address->address)] = true;
        }
        $result = [];
        foreach ($addresses as $address) {
            $key = strtolower($address->address);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $address;
            }
        }
        return $result;
    }

    private static function prefixedSubject(string $prefix, string $subject): string
    {
        return preg_match('/^' . preg_quote($prefix, '/') . '\\s*/i', $subject) === 1 ? $subject : $prefix . ' ' . $subject;
    }
}
