<?php

declare(strict_types=1);

namespace Phore\MailClient\Contract;

use Phore\MailClient\Domain\ConnectionReport;
use Phore\MailClient\Domain\MailMessage;
use Phore\MailClient\Domain\MailReference;
use Phore\MailClient\Domain\MailSearch;
use Phore\MailClient\Domain\SyncBatch;
use Phore\MailClient\Domain\SyncCursor;

interface MailboxConnector
{
    public function testConnection(): ConnectionReport;
    public function listNew(?SyncCursor $cursor, int $limit): SyncBatch;

    /** @return list<MailMessage> */
    public function search(MailSearch $search, int $limit): array;

    public function get(MailReference $reference): MailMessage;
}
