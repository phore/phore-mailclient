<?php
declare(strict_types=1);
namespace Phore\MailClient;

/** Read-only observations, not commands and not an audit log. */
final readonly class FolderChanges
{
    /**
     * @param list<Email> $added Newly observed in this folder, not necessarily newly delivered.
     * @param list<FlagChange> $flagsChanged Persistent flags changed on known messages.
     * @param list<string> $removed IDs no longer in this folder; removal does not prove deletion.
     * @param string $nextCursor Persist only after processing every returned observation.
     * @param bool $hasMore More observations remain; call again with nextCursor.
     * @param bool $isInitialSync True only when this call received a null cursor.
     */
    public function __construct(
        public string $folder,
        public array $added,
        public array $flagsChanged,
        public array $removed,
        public string $nextCursor,
        public bool $hasMore,
        public bool $isInitialSync,
    ) {}
}
