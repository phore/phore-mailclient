<?php

declare(strict_types=1);

use Phore\MailClient\Credential\StaticCredential;
use Phore\MailClient\Domain\MailAccount;
use Phore\MailClient\MailClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = static function (string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) { return $default; }
        throw new RuntimeException(sprintf('Missing environment variable %s.', $name));
    }
    return $value;
};

return MailClient::connect(MailAccount::imap(
    id: $env('MAIL_ACCOUNT_ID', 'demo'),
    host: $env('MAIL_IMAP_HOST'),
    username: $env('MAIL_IMAP_USERNAME'),
    credential: new StaticCredential($env('MAIL_IMAP_PASSWORD')),
    port: (int) $env('MAIL_IMAP_PORT', '993'),
    folders: ['drafts' => $env('MAIL_IMAP_DRAFTS', 'Drafts')],
));
