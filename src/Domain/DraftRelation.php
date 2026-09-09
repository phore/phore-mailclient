<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

final readonly class DraftRelation
{
    public function __construct(public DraftRelationType $type, public MailReference $source) {}
}
