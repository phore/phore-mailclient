<?php

declare(strict_types=1);

namespace Phore\MailClient\Domain;

use InvalidArgumentException;
use Phore\MailClient\Contract\CredentialProvider;

final readonly class MailAccount
{
    /** @param array<string, string> $folders */
    private function __construct(
        public string $id,
        public MailProtocol $protocol,
        public string $host,
        public int $port,
        public TlsMode $tls,
        public string $username,
        public MailAddress $address,
        public CredentialProvider $credential,
        public array $folders,
    ) {
        if ($id === '' || $host === '' || $username === '') {
            throw new InvalidArgumentException('Account id, host, and username must not be empty.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Mail server port is out of range.');
        }
    }

    /** @param array<string, string> $folders */
    public static function imap(
        string $id,
        string $host,
        string $username,
        CredentialProvider $credential,
        ?MailAddress $address = null,
        int $port = 993,
        TlsMode $tls = TlsMode::Implicit,
        array $folders = [],
    ): self {
        return new self($id, MailProtocol::Imap, $host, $port, $tls, $username, $address ?? new MailAddress($username), $credential, $folders);
    }
}
