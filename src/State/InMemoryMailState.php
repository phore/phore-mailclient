<?php

declare(strict_types=1);

namespace Phore\MailClient\State;

use Phore\MailClient\Contract\MailStateStore;
use Phore\MailClient\Domain\SyncCursor;

final class InMemoryMailState implements MailStateStore
{
    /** @var array<string, SyncCursor> */
    private array $cursors = [];

    public function cursor(string $accountId): ?SyncCursor { return $this->cursors[$accountId] ?? null; }
    public function saveCursor(string $accountId, SyncCursor $cursor): void { $this->cursors[$accountId] = $cursor; }
}
