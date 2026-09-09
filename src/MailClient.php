<?php

declare(strict_types=1);

namespace Phore\MailClient;

use Phore\MailClient\Connector\Imap\WebklexImapConnector;
use Phore\MailClient\Contract\DraftConnector;
use Phore\MailClient\Contract\MailboxConnector;
use Phore\MailClient\Contract\MailStateStore;
use Phore\MailClient\Domain\ConnectionReport;
use Phore\MailClient\Domain\MailAccount;
use Phore\MailClient\Domain\MailMessage;
use Phore\MailClient\Domain\MailReference;
use Phore\MailClient\Domain\MailSearch;
use Phore\MailClient\Domain\SyncBatch;
use Phore\MailClient\State\InMemoryMailState;

final readonly class MailClient
{
    private function __construct(
        private MailAccount $account,
        private MailboxConnector $mailbox,
        private ?DraftConnector $drafts,
        private MailStateStore $state,
    ) {}

    public static function connect(
        MailAccount $account,
        ?MailboxConnector $mailbox = null,
        ?DraftConnector $drafts = null,
        ?MailStateStore $state = null,
    ): self {
        $mailbox ??= new WebklexImapConnector($account);
        return new self($account, $mailbox, $drafts, $state ?? new InMemoryMailState());
    }

    public function testConnection(): ConnectionReport { return $this->mailbox->testConnection(); }

    public function listNew(int $limit = 25): SyncBatch
    {
        $batch = $this->mailbox->listNew($this->state->cursor($this->account->id), $limit)
            ->map(fn (MailMessage $message): MailMessage => $this->bind($message));
        $this->state->saveCursor($this->account->id, $batch->cursor);
        return $batch;
    }

    /** @return list<MailMessage> */
    public function search(MailSearch $search, int $limit = 50): array
    {
        return array_map(fn (MailMessage $message): MailMessage => $this->bind($message), $this->mailbox->search($search, $limit));
    }

    public function get(MailReference $reference): MailMessage { return $this->bind($this->mailbox->get($reference)); }

    private function bind(MailMessage $message): MailMessage
    {
        return $message->bindDrafts($this->account->address, $this->drafts);
    }
}
