<?php
declare(strict_types=1);

namespace Phore\MailClient\Test\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Phore\MailClient\SyncCursorStore;

final class SyncCursorStoreTest extends TestCase
{
    public function testFilesystemStoreLoadsAndUpdatesCursorPerMailbox(): void
    {
        require_once dirname(__DIR__, 2) . '/examples/api/FileSyncCursorStore.php';

        $directory = sys_get_temp_dir() . '/phore-mailclient-cursor-' . bin2hex(random_bytes(6));
        $store = new \FileSyncCursorStore($directory);

        self::assertInstanceOf(SyncCursorStore::class, $store);
        self::assertNull($store->load('account-a', 'INBOX'));

        $store->save('account-a', 'INBOX', 'cursor-1');
        $store->save('account-a', 'Archive', 'cursor-archive');
        self::assertSame('cursor-1', $store->load('account-a', 'INBOX'));
        self::assertSame('cursor-archive', $store->load('account-a', 'Archive'));

        $store->save('account-a', 'INBOX', 'cursor-2');
        self::assertSame('cursor-2', $store->load('account-a', 'INBOX'));

        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    public function testSqliteStoreCreatesItsTableAndKeepsOtherTables(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        require_once dirname(__DIR__, 2) . '/examples/api/SqliteSyncCursorStore.php';

        $file = tempnam(sys_get_temp_dir(), 'phore-mailclient-sqlite-');
        self::assertNotFalse($file);

        $pdo = new PDO('sqlite:' . $file);
        $pdo->exec('CREATE TABLE other_library_state (state TEXT NOT NULL)');
        $pdo = null;

        $store = new \SqliteSyncCursorStore($file);
        self::assertNull($store->load('account-a', 'INBOX'));
        $store->save('account-a', 'INBOX', 'cursor-1');
        $store->save('account-a', 'Archive', 'cursor-archive');

        self::assertSame('cursor-1', $store->load('account-a', 'INBOX'));
        self::assertSame('cursor-archive', $store->load('account-a', 'Archive'));

        $pdo = new PDO('sqlite:' . $file);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('other_library_state', $tables);
        self::assertContains('phore_mailclient_sync_cursor', $tables);

        $columns = $pdo->query('PRAGMA table_info(phore_mailclient_sync_cursor)')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['account_id', 'folder', 'cursor', 'updated_at'], array_column($columns, 'name'));

        $pdo = null;
        unlink($file);
    }
}
