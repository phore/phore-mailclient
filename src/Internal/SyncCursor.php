<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

use InvalidArgumentException;

/** @internal Versioned, self-contained observation state, not a credential or authorization token. */
final readonly class SyncCursor
{
    public const MAX_MESSAGES = 10000;
    public const MAX_BYTES = 8000000;
    public function __construct(
        public string $account,
        public string $folder,
        public int $validity,
        public array $flags,
    ) {}

    public static function flags(array $flags): array
    {
        if (count($flags) > 128) { throw new InvalidArgumentException('Too many message flags.'); }
        $normalized = [];
        foreach ($flags as $flag) {
            if (!is_string($flag) || $flag === '' || strlen($flag) > 256 || preg_match('/[\x00-\x20\x7f]/', $flag)) {
                throw new InvalidArgumentException('Invalid message flags.');
            }
            // Recent is session-dependent, not persistent classification state.
            if (strcasecmp($flag, '\\Recent') !== 0) { $normalized[strtolower($flag)] = $flag; }
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    public static function sameFlags(array $a, array $b): bool
    { return array_map('strtolower', $a) === array_map('strtolower', $b); }

    public function encode(): string
    {
        $flags = $this->flags; ksort($flags, SORT_NUMERIC);
        $json = json_encode(['folder-sync-v1', $this->account, $this->folder, $this->validity, $flags], JSON_THROW_ON_ERROR);
        $cursor = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        if (strlen($cursor) > self::MAX_BYTES) { throw new \RuntimeException('Folder synchronization cursor exceeds byte limit.'); }
        return $cursor;
    }

    public static function decode(string $cursor, string $account, string $folder): self
    {
        try {
            if (strlen($cursor) > self::MAX_BYTES || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) { throw new InvalidArgumentException(); }
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            $data = json_decode($raw === false ? '' : $raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !array_is_list($data) || count($data) !== 5 || $data[0] !== 'folder-sync-v1'
                || $data[1] !== $account || $data[2] !== $folder || !is_int($data[3]) || $data[3] < 1
                || !is_array($data[4]) || count($data[4]) > self::MAX_MESSAGES) { throw new InvalidArgumentException(); }
            $flags = [];
            foreach ($data[4] as $uid => $values) {
                if (!is_int($uid) || $uid < 1 || $uid > 4294967295 || !is_array($values) || !array_is_list($values)) { throw new InvalidArgumentException(); }
                $flags[$uid] = self::flags($values);
            }
            return new self($account, $folder, $data[3], $flags);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('Malformed, unsupported, or foreign folder sync cursor.', 0, $error);
        }
    }
}
