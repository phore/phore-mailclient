<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

// API DESIGN ONLY: these classes/methods are proposed, not implemented in this PR.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = static function (string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }
    return $default ?? throw new RuntimeException(sprintf('Missing environment variable %s.', $name));
};

// Two responsibilities, without an additional public Mailbox abstraction:
// Email holds content; withMarkdown(), attach(), reply(), forward() work locally.
// MailClient performs ALL server reads/writes. Email never carries a connection.
// All examples use the inbox by default; drafts/trash are resolved by the client.
// TLS and certificate verification are mandatory; secrets must never be logged.
return MailClient::connect(
    host: $env('MAIL_IMAP_HOST'),
    username: $env('MAIL_IMAP_USERNAME'),
    password: $env('MAIL_IMAP_PASSWORD'),
    port: (int) $env('MAIL_IMAP_PORT', '993'),
    draftsFolder: $env('MAIL_IMAP_DRAFTS', 'Drafts'),
    trashFolder: $env('MAIL_IMAP_TRASH', 'Trash'),
);
