<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use InvalidArgumentException;

final readonly class MailReference
{
    public function __construct(
        public string $accountId,
        public string $mailbox,
        public string $remoteId,
        public ?string $messageId = null,
    ) {
        if ($accountId === '' || $mailbox === '' || $remoteId === '') {
            throw new InvalidArgumentException('Mail reference fields must not be empty.');
        }
    }
}
