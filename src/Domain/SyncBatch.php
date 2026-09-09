<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

final readonly class SyncBatch
{
    /** @param list<MailMessage> $messages @param list<string> $warnings */
    public function __construct(
        public array $messages,
        public SyncCursor $cursor,
        public array $warnings = [],
    ) {}

    /** @param callable(MailMessage): MailMessage $binder */
    public function map(callable $binder): self
    {
        return new self(array_map($binder, $this->messages), $this->cursor, $this->warnings);
    }
}
