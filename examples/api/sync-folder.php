<?php
declare(strict_types=1);

// Usage: php sync-folder.php <folder> <sqlite-state-file>
// The SQLite file may already contain tables from other libraries. This example
// creates/uses only phore_mailclient_sync_cursor inside that database.
$folder = $argv[1] ?? throw new RuntimeException('Provide an exact IMAP folder name.');
$sqliteFile = $argv[2] ?? throw new RuntimeException('Provide a SQLite state file.');

/** @var \Phore\MailClient\MailClient $client */
$client = require __DIR__ . '/connect-mail-client.php';
require_once __DIR__ . '/SqliteSyncCursorStore.php';

$store = new SqliteSyncCursorStore($sqliteFile);
$accountId = $client->accountId();
$cursor = $store->load($accountId, $folder);

do {
    $changes = $client->syncFolder(folder: $folder, cursor: $cursor, limit: 50);

    // Replace this output with your durable processing of added, changed and removed messages.
    echo json_encode([
        'folder' => $changes->folder,
        'isInitialSync' => $changes->isInitialSync,
        'added' => array_map(static fn($email): array => ['id' => $email->id(), 'flags' => $email->flags()], $changes->added),
        'flagsChanged' => $changes->flagsChanged,
        'removed' => $changes->removed,
        'hasMore' => $changes->hasMore,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

    // Persist only after every observation in this batch was processed successfully.
    $store->save($accountId, $folder, $changes->nextCursor);
    $cursor = $changes->nextCursor;
} while ($changes->hasMore);
