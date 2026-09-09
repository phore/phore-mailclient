<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

enum TlsMode: string
{
    case Implicit = 'implicit';
    case StartTls = 'starttls';
}
