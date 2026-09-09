<?php

declare(strict_types=1);

namespace Phore\MailClient\Test\Support;

use Phore\MailClient\Contract\DraftConnector;
use Phore\MailClient\Domain\MailDraft;
use Phore\MailClient\Domain\MailReference;
use Phore\MailClient\Domain\SavedDraft;

final class FakeDraftConnector implements DraftConnector
{
    public ?MailDraft $saved = null;

    public function save(MailDraft $draft): SavedDraft
    {
        $this->saved = $draft;
        return new SavedDraft($draft->messageId, new MailReference('support', 'Drafts', '99', $draft->messageId), $draft->relation);
    }
}
