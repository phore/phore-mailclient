<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = static function (string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }
    return $default ?? throw new RuntimeException(sprintf('Missing environment variable %s.', $name));
};

// MailClient is the single account-level entry point. Folders and messages are
// reached through it, so applications do not need a second "mailbox client".
return MailClient::connect(
    host: $env('MAIL_IMAP_HOST'),
    username: $env('MAIL_IMAP_USERNAME'),
    password: $env('MAIL_IMAP_PASSWORD'),
    port: (int) $env('MAIL_IMAP_PORT', '993'),
    draftsFolder: $env('MAIL_IMAP_DRAFTS', 'Drafts'),
);
