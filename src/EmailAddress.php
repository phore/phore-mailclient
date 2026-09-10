<?php
declare(strict_types=1);
namespace Phore\MailClient;

use InvalidArgumentException;
use Phore\MailClient\Internal\Headers;

final readonly class EmailAddress
{
    private string $address;
    private ?string $name;

    public function __construct(string $address, ?string $name = null)
    {
        Headers::validate($address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || strlen($address) > 254) {
            throw new InvalidArgumentException('Invalid mailbox address.');
        }
        $at = strrpos($address, '@');
        $this->address = substr($address, 0, $at + 1) . strtolower(substr($address, $at + 1));
        $this->name = $name === null || $name === '' ? null : Headers::validate($name);
    }

    public function getAddress(): string { return $this->address; }
    public function getName(): ?string { return $this->name; }
    public function getDisplayName(): string { return $this->name ?? $this->address; }
    public function toString(): string
    {
        if ($this->name === null) { return $this->address; }
        $name = preg_match('/[,"\\\\<>@;:()\[\]]/', $this->name)
            ? '"' . addcslashes($this->name, "\\\"") . '"' : $this->name;
        return $name . ' <' . $this->address . '>';
    }

    public static function parse(string $input): self
    {
        $list = self::parseList($input);
        if (count($list) !== 1) { throw new InvalidArgumentException('Expected exactly one mailbox.'); }
        return $list[0];
    }

    /** @return list<self> */
    public static function parseList(string $input): array
    {
        Headers::validate($input);
        if (trim($input) === '') { return []; }
        $parts = []; $part = ''; $quoted = false; $escape = false; $angle = false;
        for ($i = 0; $i < strlen($input); $i++) {
            $c = $input[$i];
            if ($escape) { $part .= $c; $escape = false; continue; }
            if ($quoted && $c === '\\') { $escape = true; $part .= $c; continue; }
            if ($c === '"') { $quoted = !$quoted; }
            if (!$quoted) {
                if ($c === '<') {
                    if ($angle) { throw new InvalidArgumentException('Nested address brackets.'); }
                    $angle = true;
                } elseif ($c === '>') {
                    if (!$angle) { throw new InvalidArgumentException('Unmatched address bracket.'); }
                    $angle = false;
                } elseif (str_contains(':;()', $c)) {
                    throw new InvalidArgumentException('Groups and comments are unsupported.');
                } elseif ($c === ',' && !$angle) { $parts[] = $part; $part = ''; continue; }
            }
            $part .= $c;
        }
        if ($quoted || $angle || $escape) { throw new InvalidArgumentException('Unclosed mailbox syntax.'); }
        $parts[] = $part;
        return array_map(static function (string $part): self {
            $part = trim($part);
            if (preg_match('/^(.*?)<([^<>]+)>$/D', $part, $m)) {
                $name = trim($m[1]);
                if (str_starts_with($name, '"')) {
                    if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/Du', $name, $q)) {
                        throw new InvalidArgumentException('Invalid quoted display name.');
                    }
                    $name = preg_replace('/\\\\(.)/us', '$1', $q[1]);
                } elseif (str_contains($name, '"')) { throw new InvalidArgumentException('Invalid display name.'); }
                return new self(trim($m[2]), Headers::decode($name));
            }
            return new self($part);
        }, $parts);
    }

    /** @internal @return list<self> */
    public static function normalize(self|string|array $values): array
    {
        if (!is_array($values)) { $values = [$values]; }
        if (!array_is_list($values)) { throw new InvalidArgumentException('Addresses must be a list.'); }
        return array_map(static fn (self|string $v): self => is_string($v) ? self::parse($v) : $v, $values);
    }
}
