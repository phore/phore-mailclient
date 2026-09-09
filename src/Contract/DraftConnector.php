<?php

declare(strict_types=1);

namespace Phore\MailClient\Contract;

use Phore\MailClient\Domain\MailDraft;
use Phore\MailClient\Domain\SavedDraft;

interface DraftConnector
{
    public function save(MailDraft $draft): SavedDraft;
}
