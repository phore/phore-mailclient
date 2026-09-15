<?php
declare(strict_types=1);

use Phore\MailClient\SqliteSyncCursorStore;

$folder = $argv[1] ?? throw new RuntimeException('Provide an exact IMAP folder name.');
$sqliteFile = $argv[2] ?? throw new RuntimeException('Provide a SQLite state file.');

/** @var \Phore\MailClient\MailClient $client */
$client = require __DIR__ . '/connect-mail-client.php';

$store = new SqliteSyncCursorStore($sqliteFile);
$accountId = $client->accountId();
$cursor = $store->load($accountId, $folder);

do {
    $changes = $client->syncFolder(folder: $folder, cursor: $cursor);

    echo json_encode([
        'folder' => $changes->folder,
        'isInitialSync' => $changes->isInitialSync,
        'added' => array_map(static fn($email): array => ['id' => $email->id(), 'flags' => $email->flags()], $changes->added),
        'flagsChanged' => $changes->flagsChanged,
        'removed' => $changes->removed,
        'hasMore' => $changes->hasMore,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

    // Advance the durable cursor only after this batch was processed successfully.
    $store->save($accountId, $folder, $changes->nextCursor);
    $cursor = $changes->nextCursor;
} while ($changes->hasMore);
