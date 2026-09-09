<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

enum DraftRelationType: string
{
    case Reply = 'reply';
    case ReplyAll = 'reply_all';
    case Forward = 'forward';
}
