<?php

declare(strict_types=1);

namespace Phore\MailClient\Contract;

interface CredentialProvider
{
    public function secret(): string;
}
