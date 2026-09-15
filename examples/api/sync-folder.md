# Folder synchronization and cursor persistence

`MailClient::syncFolder()` compares one exact IMAP folder with the state encoded in the cursor from the previous successful run. It returns additions, persistent flag changes, removals and a new opaque `nextCursor`.

The mail client keeps synchronization and persistence separate. `SyncCursorStore` is the persistence contract: `load()` returns the current cursor for one account/folder pair or `null` on first use, and `save()` stores the opaque cursor returned by `syncFolder()`.

```php
interface SyncCursorStore
{
    public function load(string $accountId, string $folder): ?string;
    public function save(string $accountId, string $folder, string $cursor): void;
}
```

A `null` cursor starts the initial synchronization and reports all messages currently present in the folder across one or more batches. Do not parse or modify the cursor. Save `nextCursor` only after the returned batch was processed successfully; if processing fails, retain the previously stored cursor and retry.

## SQLite store

[`SqliteSyncCursorStore`](../../src/SqliteSyncCursorStore.php) is the database-backed implementation used by the runnable [`sync-folder.php`](sync-folder.php) example. Pass any SQLite file to its constructor; the file may already contain state from other libraries. The store creates only its own table with `CREATE TABLE IF NOT EXISTS`.

```php
$store = new SqliteSyncCursorStore('/var/lib/my-app/state.sqlite');
$cursor = $store->load($client->accountId(), 'INBOX');
```

The table is `phore_mailclient_sync_cursor`:

| Column | Type | Meaning |
|---|---|---|
| `account_id` | `TEXT NOT NULL` | Stable value returned by `MailClient::accountId()` |
| `folder` | `TEXT NOT NULL` | Exact IMAP folder name |
| `cursor` | `TEXT NOT NULL` | Opaque `nextCursor` returned by `syncFolder()` |
| `updated_at` | `TEXT NOT NULL` | UTC timestamp of the last successful save |

Its primary key is `(account_id, folder)`. One shared SQLite state file can therefore hold cursors for multiple accounts and folders alongside unrelated application tables.

## Filesystem store

[`FileSyncCursorStore`](../../src/FileSyncCursorStore.php) stores one cursor file per account/folder pair in a directory supplied to its constructor. The filename is derived from a SHA-256 hash of account ID and folder, so folder names are not used as filesystem paths.

```php
$store = new FileSyncCursorStore('/var/lib/my-app/mail-cursors');
$cursor = $store->load($client->accountId(), 'INBOX');
```

Use this when simple filesystem persistence is sufficient. The synchronization flow is otherwise identical to the SQLite variant.

## Own store implementation

For another backend, implement the same two-method `SyncCursorStore` contract. The application decides where the state lives; the mail client only passes the opaque cursor through that contract.

```php
final class MySyncCursorStore implements SyncCursorStore
{
    public function load(string $accountId, string $folder): ?string
    {
        // Read the cursor from your application's state backend.
    }

    public function save(string $accountId, string $folder, string $cursor): void
    {
        // Persist the cursor in your application's state backend.
    }
}
```

Independent consumers that synchronize the same account/folder need independent cursor namespaces or stores. Workers that intentionally share one synchronization stream must coordinate writes so they do not overwrite each other's cursor.
