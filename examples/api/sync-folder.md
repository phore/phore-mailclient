# Folder synchronization and cursor persistence

`MailClient::syncFolder()` compares the current state of one exact IMAP folder with the state encoded in the cursor from the previous successful run. It returns additions, persistent flag changes, removals and a new opaque `nextCursor`.

The mail client does not decide where durable state lives. Applications persist the opaque cursor through `SyncCursorStore`. A store is addressed by the stable mail-client `accountId()` plus the exact folder name:

```php
interface SyncCursorStore
{
    public function load(string $accountId, string $folder): ?string;
    public function save(string $accountId, string $folder, string $cursor): void;
}
```

`load()` returns `null` when that account/folder combination has never been synchronized. Passing that `null` cursor to `syncFolder()` starts an initial sync and reports all messages currently present in the folder across one or more batches. The returned cursor is opaque application state; applications must not parse or modify it.

Persist `nextCursor` only after every observation in the returned batch has been processed successfully. If processing fails, retain the previous cursor and retry. When `hasMore` is true, continue immediately with the newly persisted cursor until the current difference set is drained.

## Filesystem example

[`FileSyncCursorStore.php`](FileSyncCursorStore.php) is the smallest example implementation. It stores one file per account/folder key in a directory supplied to its constructor. The file name is a SHA-256 hash of `accountId` and folder, so folder names are not used as filesystem paths. This implementation is useful as a reference for writing a custom store; applications with transactional processing will usually prefer a database-backed implementation.

## SQLite example

[`SqliteSyncCursorStore.php`](SqliteSyncCursorStore.php) accepts any SQLite database file. The file may already be used by other libraries or application components. On construction the store executes `CREATE TABLE IF NOT EXISTS` for its own table and leaves unrelated tables untouched.

The table is named `phore_mailclient_sync_cursor`:

| Column | Type | Meaning |
|---|---|---|
| `account_id` | `TEXT NOT NULL` | Stable value returned by `MailClient::accountId()` |
| `folder` | `TEXT NOT NULL` | Exact IMAP folder name |
| `cursor` | `TEXT NOT NULL` | Opaque `nextCursor` returned by `syncFolder()` |
| `updated_at` | `TEXT NOT NULL` | UTC timestamp of the last successful save |

The primary key is `(account_id, folder)`, therefore one SQLite state file can hold independent cursors for all folders of all configured mail accounts while also containing tables belonging to other libraries.

The runnable [`sync-folder.php`](sync-folder.php) example uses this SQLite store. Usage:

```text
php sync-folder.php INBOX /var/lib/my-app/state.sqlite
```

The example loads the current cursor, processes each returned batch, saves `nextCursor`, and keeps calling `syncFolder()` while `hasMore` is true.

## Custom store

Implement `SyncCursorStore` when the cursor should live somewhere else, for example in the same database transaction as your message cache, Redis, a key/value store or an application-specific state repository. The synchronization algorithm does not depend on the storage backend; only the two `load()` and `save()` operations are required.

If multiple independent consumers synchronize the same account/folder, each consumer needs its own logical cursor store or namespace. Do not let independent workers overwrite the same cursor unless they intentionally share one synchronization stream.
