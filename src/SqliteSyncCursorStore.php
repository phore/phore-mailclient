<?php
declare(strict_types=1);

namespace Phore\MailClient;

use InvalidArgumentException;
use PDO;

final class SqliteSyncCursorStore implements SyncCursorStore
{
    private PDO $pdo;

    public function __construct(string $sqliteFile)
    {
        $this->pdo = new PDO('sqlite:' . $sqliteFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS phore_mailclient_sync_cursor (
                account_id TEXT NOT NULL,
                folder TEXT NOT NULL,
                cursor TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (account_id, folder)
            )'
        );
    }

    public function load(string $accountId, string $folder): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT cursor FROM phore_mailclient_sync_cursor WHERE account_id = :account_id AND folder = :folder'
        );
        $statement->execute(['account_id' => $accountId, 'folder' => $folder]);
        $cursor = $statement->fetchColumn();

        return $cursor === false ? null : (string) $cursor;
    }

    public function save(string $accountId, string $folder, string $cursor): void
    {
        if ($cursor === '') {
            throw new InvalidArgumentException('Cursor must not be empty.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO phore_mailclient_sync_cursor (account_id, folder, cursor, updated_at)
             VALUES (:account_id, :folder, :cursor, :updated_at)
             ON CONFLICT(account_id, folder) DO UPDATE SET
                 cursor = excluded.cursor,
                 updated_at = excluded.updated_at'
        );
        $statement->execute([
            'account_id' => $accountId,
            'folder' => $folder,
            'cursor' => $cursor,
            'updated_at' => gmdate('c'),
        ]);
    }
}
