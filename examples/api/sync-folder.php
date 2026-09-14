<?php
declare(strict_types=1);

// Read-only inspection. Usage: php sync-folder.php <folder> [cursor-file]
// No cursor-file means first sync: report all existing messages in batches.
// If supplied, the file must exist and contain a previous nextCursor.
// This example never advances your stored cursor automatically.
$folder = $argv[1] ?? throw new RuntimeException('Provide an exact IMAP folder name.');
$cursor = null;
if (isset($argv[2])) {
    $cursor = file_get_contents($argv[2]);
    if ($cursor === false || trim($cursor) === '') { throw new RuntimeException('Cannot read a nonempty cursor file.'); }
    $cursor = trim($cursor);
}
/** @var \Phore\MailClient\MailClient $client */
$client = require __DIR__ . '/connect-mail-client.php';
$changes = $client->syncFolder(folder:$folder, cursor:$cursor, limit:50);
echo json_encode([
    'folder'=>$changes->folder,
    'isInitialSync'=>$changes->isInitialSync,
    'added'=>array_map(static fn($email): array => ['id'=>$email->id(), 'flags'=>$email->flags()], $changes->added),
    'flagsChanged'=>$changes->flagsChanged,
    'removed'=>$changes->removed,
    'hasMore'=>$changes->hasMore,
    'nextCursor'=>$changes->nextCursor,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
// Output contains mailbox metadata; keep it private.
// Persist nextCursor only after processing ALL observations, then repeat if hasMore.
