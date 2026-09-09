<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';

$email = $mailClient->inbox()->listNew(limit: 1)[0]
    ?? throw new RuntimeException('No new email found.');

// Common operations have named methods; provider-specific IMAP flags remain
// available through the same Email object when no common method exists.
$email->markRead();
$email->markUnread();
$email->markForwarded();
$email->addFlag('$ProjectReviewed');
$email->removeFlag('$ProjectReviewed');

// delete() moves the email to the account's trash folder by default. Permanent
// deletion should require a separate, deliberately explicit API.
$email->delete();
