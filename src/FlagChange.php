<?php
declare(strict_types=1);
namespace Phore\MailClient;

final readonly class FlagChange
{
    /** @param list<string> $oldFlags @param list<string> $newFlags */
    public function __construct(public string $id, public array $oldFlags, public array $newFlags) {}
}
