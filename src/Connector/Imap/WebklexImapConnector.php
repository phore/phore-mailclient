<?php

declare(strict_types=1);

namespace Phore\MailClient\Connector\Imap;

use DateTimeImmutable;
use InvalidArgumentException;
use League\HTMLToMarkdown\HtmlConverter;
use Phore\MailClient\Contract\MailboxConnector;
use Phore\MailClient\Domain\ConnectionReport;
use Phore\MailClient\Domain\MailAccount;
use Phore\MailClient\Domain\MailAddress;
use Phore\MailClient\Domain\MailAttachment;
use Phore\MailClient\Domain\MailBody;
use Phore\MailClient\Domain\MailMessage;
use Phore\MailClient\Domain\MailProtocol;
use Phore\MailClient\Domain\MailReference;
use Phore\MailClient\Domain\MailSearch;
use Phore\MailClient\Domain\MailboxCapabilities;
use Phore\MailClient\Domain\SyncBatch;
use Phore\MailClient\Domain\SyncCursor;
use Phore\MailClient\Domain\TlsMode;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

final class WebklexImapConnector implements MailboxConnector
{
    private ?Client $client = null;
    private HtmlConverter $htmlConverter;

    public function __construct(private readonly MailAccount $account)
    {
        if ($account->protocol !== MailProtocol::Imap) {
            throw new InvalidArgumentException('WebklexImapConnector requires an IMAP account.');
        }
        $this->htmlConverter = new HtmlConverter([
            'strip_tags' => true,
            'remove_nodes' => 'script style form iframe img',
            'hard_break' => true,
        ]);
    }

    public function testConnection(): ConnectionReport
    {
        try {
            $this->client();
            return new ConnectionReport(true, $this->capabilities());
        } catch (Throwable $exception) {
            return new ConnectionReport(false, $this->capabilities(), ['Connection failed: ' . $exception::class]);
        }
    }

    public function listNew(?SyncCursor $cursor, int $limit): SyncBatch
    {
        $this->assertLimit($limit);
        $lastUid = max(0, (int) ($cursor?->value ?? 0));
        $query = $this->inbox()->messages()->all()->setFetchOrderAsc()->limit($limit);
        if ($lastUid > 0) {
            $query->whereUid(($lastUid + 1) . ':*');
        }

        $messages = [];
        $highestUid = $lastUid;
        foreach ($query->get() as $message) {
            $mapped = $this->mapMessage($message);
            $messages[] = $mapped;
            $highestUid = max($highestUid, (int) $mapped->reference->remoteId);
        }
        return new SyncBatch($messages, new SyncCursor((string) $highestUid));
    }

    public function search(MailSearch $search, int $limit): array
    {
        $this->assertLimit($limit);
        $query = $this->inbox()->messages()->all()->limit($limit);
        if ($search->fromAddress !== null) { $query->from($search->fromAddress); }
        if ($search->subject !== null) { $query->subject($search->subject); }
        if ($search->sinceDate !== null) { $query->since($search->sinceDate->format('d-M-Y')); }
        return array_map(fn (Message $message): MailMessage => $this->mapMessage($message), $query->get()->all());
    }

    public function get(MailReference $reference): MailMessage
    {
        if ($reference->accountId !== $this->account->id) {
            throw new InvalidArgumentException('Mail reference belongs to a different account.');
        }
        $message = $this->folder($reference->mailbox)->messages()->whereUid($reference->remoteId)->get()->first();
        if (!$message instanceof Message) {
            throw new RuntimeException('Message not found.');
        }
        return $this->mapMessage($message, $reference->mailbox);
    }

    private function client(): Client
    {
        if ($this->client !== null) { return $this->client; }
        $this->client = (new ClientManager())->make([
            'host' => $this->account->host,
            'port' => $this->account->port,
            'encryption' => $this->account->tls === TlsMode::Implicit ? 'ssl' : 'tls',
            'validate_cert' => true,
            'username' => $this->account->username,
            'password' => $this->account->credential->secret(),
            'protocol' => 'imap',
        ]);
        $this->client->connect();
        return $this->client;
    }

    private function inbox(): mixed { return $this->folder($this->account->folders['inbox'] ?? 'INBOX'); }

    private function folder(string $name): mixed
    {
        $folder = $this->client()->getFolderByName($name);
        if ($folder === null) { throw new RuntimeException(sprintf('Mailbox "%s" was not found.', $name)); }
        return $folder;
    }

    private function capabilities(): MailboxCapabilities
    {
        return new MailboxCapabilities(search: true, folders: true, appendDraft: false, idle: true, oauth2: true);
    }

    private function mapMessage(Message $message, string $mailbox = 'INBOX'): MailMessage
    {
        $plain = trim($message->getTextBody());
        $markdown = $plain !== '' ? $plain : trim($this->htmlConverter->convert($message->getHTMLBody()));
        $messageId = trim((string) $message->getMessageId());
        $date = $message->getDate()->toDate();
        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = new MailAttachment(
                id: (string) ($attachment->getId() ?: $attachment->getHash()),
                filename: (string) ($attachment->getName() ?: 'attachment'),
                mediaType: (string) ($attachment->getContentType() ?: 'application/octet-stream'),
                size: (int) $attachment->getSize(),
                streamOpener: static function () use ($attachment) {
                    $stream = fopen('php://temp', 'w+b');
                    if ($stream === false) { throw new RuntimeException('Unable to open attachment stream.'); }
                    fwrite($stream, (string) $attachment->getContent());
                    rewind($stream);
                    return $stream;
                },
                sha256: (string) ($attachment->getHash() ?: '') ?: null,
                inline: strtolower((string) $attachment->getDisposition()) === 'inline',
            );
        }

        return new MailMessage(
            reference: new MailReference($this->account->id, $mailbox, (string) $message->getUid(), $messageId !== '' ? $messageId : null),
            from: $this->addresses($message->getFrom()->all())[0] ?? new MailAddress('unknown@example.invalid'),
            to: $this->addresses($message->getTo()->all()),
            cc: $this->addresses($message->getCc()->all()),
            subject: trim((string) $message->getSubject()),
            date: DateTimeImmutable::createFromInterface($date),
            body: new MailBody($markdown),
            attachments: $attachments,
            references: array_values(array_filter(array_map('strval', $message->getReferences()->all()))),
        );
    }

    /** @param array<int, mixed> $addresses @return list<MailAddress> */
    private function addresses(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $address) {
            if (!is_object($address) || !isset($address->mail) || filter_var($address->mail, FILTER_VALIDATE_EMAIL) === false) { continue; }
            $result[] = new MailAddress($address->mail, $address->personal ?: null);
        }
        return $result;
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) { throw new InvalidArgumentException('Limit must be between 1 and 500.'); }
    }
}
