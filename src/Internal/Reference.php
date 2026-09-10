<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;
use InvalidArgumentException;

/** @internal IDs identify locations, not access grants. */
final readonly class Reference
{
    public function __construct(public string $account, public string $folder, public int $validity, public int $uid)
    {
        Headers::validate($folder);
        if ($account === '' || $folder === '' || $validity < 1 || $uid < 0) { throw new InvalidArgumentException('Invalid mail reference.'); }
    }
    public function encode(): string { return rtrim(strtr(base64_encode(json_encode([1,$this->account,$this->folder,$this->validity,$this->uid], JSON_THROW_ON_ERROR)), '+/', '-_'), '='); }
    public static function decode(string $id, string $account, bool $cursor = false): self
    {
        try {
            $raw = base64_decode(strtr($id, '-_', '+/'), true);
            $data = json_decode($raw === false ? '' : $raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) { throw new InvalidArgumentException('Malformed mail reference.'); }
        if (!is_array($data) || count($data) !== 5 || $data[0] !== 1 || $data[1] !== $account || !is_string($data[2]) || !is_int($data[3]) || !is_int($data[4]) || (!$cursor && $data[4] < 1)) { throw new InvalidArgumentException('Foreign or invalid mail reference.'); }
        return new self($account, $data[2], $data[3], $data[4]);
    }
}
