<?php
declare(strict_types=1);

namespace Phore\MailClient;

/**
 * Persists the opaque cursor returned by MailClient::syncFolder().
 *
 * A cursor belongs to exactly one mail account and one exact folder name.
 * Implementations must return null when no cursor has been stored yet.
 */
interface SyncCursorStore
{
    public function load(string $accountId, string $folder): ?string;

    public function save(string $accountId, string $folder, string $cursor): void;
}
