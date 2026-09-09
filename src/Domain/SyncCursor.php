<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

final readonly class SyncCursor
{
    public function __construct(public string $value) {}
}
