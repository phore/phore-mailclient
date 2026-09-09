<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

final readonly class SavedDraft
{
    public function __construct(
        public string $messageId,
        public MailReference $reference,
        public ?DraftRelation $relation = null,
    ) {}
}
