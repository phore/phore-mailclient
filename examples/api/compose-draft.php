<?php

declare(strict_types=1);

use Phore\MailClient\Attachment;
use Phore\MailClient\Email;
use Phore\MailClient\EmailAddress;
use Phore\MailClient\MailClient;

// API DESIGN ONLY: a usage contract, not a runnable implementation yet.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// No connection, credentials or mailbox are needed to create an email.
// Email is an immutable value: withMarkdown() and attach() return a new Email.
// Address strings remain shorthand; getters always return EmailAddress lists.
// See addresses.php for multiple To/Cc/Bcc recipients and multiple authors.
// Construction creates a stable Message-ID retained by those local edits.
$email = (new Email(
    from: new EmailAddress('sender@example.org', 'Project Team'),
    to: ['recipient@example.org'],
    subject: 'Project update',
))->withMarkdown("# Project update\n\nThe first milestone is **complete**.");

// Attach this existing example file so the example needs no missing PDF fixture.
// An application can substitute its own PDF, image or other local file.
$email = $email->attach(Attachment::fromPath(__FILE__));

// Only saving needs a connection. The explicit From address is preserved;
// the client must not silently substitute the connected account's address.
/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$savedDraft = $mailClient->saveDraft($email);

// saveDraft() returns an Email with a stable server ID; it never sends.
// Repeating saveDraft() for the same unchanged email must not create duplicates.
// Editing/replacing an already saved draft is outside this initial API sketch.
// Saving different content with an already stored Message-ID fails explicitly.
echo 'Draft saved as ' . $savedDraft->id() . "\n";
