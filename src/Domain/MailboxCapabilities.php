<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

final readonly class MailboxCapabilities
{
    public function __construct(
        public bool $search = false,
        public bool $folders = false,
        public bool $appendDraft = false,
        public bool $idle = false,
        public bool $oauth2 = false,
        public bool $attachments = true,
    ) {}
}
