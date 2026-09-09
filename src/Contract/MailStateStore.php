<?php

declare(strict_types=1);

namespace Phore\MailClient\Contract;

use Phore\MailClient\Domain\SyncCursor;

interface MailStateStore
{
    public function cursor(string $accountId): ?SyncCursor;
    public function saveCursor(string $accountId, SyncCursor $cursor): void;
}
