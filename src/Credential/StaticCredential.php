<?php

declare(strict_types=1);

namespace Phore\MailClient\Credential;

use Phore\MailClient\Contract\CredentialProvider;

final readonly class StaticCredential implements CredentialProvider
{
    public function __construct(private string $value) {}

    public function secret(): string
    {
        return $this->value;
    }
}
