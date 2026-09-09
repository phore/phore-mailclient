<?php

declare(strict_types=1);

namespace Phore\MailClient\Test\Support;

use Phore\MailClient\Contract\MailboxConnector;
use Phore\MailClient\Domain\ConnectionReport;
use Phore\MailClient\Domain\MailMessage;
use Phore\MailClient\Domain\MailReference;
use Phore\MailClient\Domain\MailSearch;
use Phore\MailClient\Domain\MailboxCapabilities;
use Phore\MailClient\Domain\SyncBatch;
use Phore\MailClient\Domain\SyncCursor;

final class FakeMailboxConnector implements MailboxConnector
{
    public ?SyncCursor $receivedCursor = null;

    /** @param list<MailMessage> $messages */
    public function __construct(private array $messages) {}

    public function testConnection(): ConnectionReport
    {
        return new ConnectionReport(true, new MailboxCapabilities(search: true));
    }

    public function listNew(?SyncCursor $cursor, int $limit): SyncBatch
    {
        $this->receivedCursor = $cursor;
        return new SyncBatch(array_slice($this->messages, 0, $limit), new SyncCursor('42'));
    }

    public function search(MailSearch $search, int $limit): array { return array_slice($this->messages, 0, $limit); }

    public function get(MailReference $reference): MailMessage { return $this->messages[0]; }
}
