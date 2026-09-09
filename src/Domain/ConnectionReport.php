<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

final readonly class ConnectionReport
{
    /** @param list<string> $warnings */
    public function __construct(
        public bool $connected,
        public MailboxCapabilities $capabilities,
        public array $warnings = [],
    ) {}
}
