<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

/** @internal Boundary used by deterministic tests; no mail library types escape. */
interface Transport
{
    public function select(string $folder, bool $write = false): array;
    public function search(array $criteria): array;
    public function metadata(int $uid): array;
    public function part(int $uid, string $section, int $maxBytes): string;
    public function append(string $folder, string $mime): void;
    public function flag(int $uid, string $flag, bool $add): void;
    /** Return destination UIDVALIDITY and UID; never emulate MOVE. */
    public function move(int $uid, string $folder): array;
}
