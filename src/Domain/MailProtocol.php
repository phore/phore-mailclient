<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

enum MailProtocol: string
{
    case Imap = 'imap';
    case Pop3 = 'pop3';
}
