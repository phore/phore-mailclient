<?php
declare(strict_types=1);

use Phore\MailClient\SyncCursorStore;

final class FileSyncCursorStore implements SyncCursorStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create cursor directory.');
        }
    }

    public function load(string $accountId, string $folder): ?string
    {
        $path = $this->path($accountId, $folder);
        if (!is_file($path)) {
            return null;
        }

        $cursor = file_get_contents($path);
        if ($cursor === false || trim($cursor) === '') {
            throw new RuntimeException('Cannot read a nonempty sync cursor.');
        }

        return trim($cursor);
    }

    public function save(string $accountId, string $folder, string $cursor): void
    {
        if ($cursor === '') {
            throw new InvalidArgumentException('Cursor must not be empty.');
        }

        $path = $this->path($accountId, $folder);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $cursor . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot persist sync cursor.');
        }
    }

    private function path(string $accountId, string $folder): string
    {
        $key = hash('sha256', $accountId . "\0" . $folder);
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key . '.cursor';
    }
}
