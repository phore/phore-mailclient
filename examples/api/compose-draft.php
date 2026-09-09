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
    // From is optional: saveDraft() supplies the configured mailbox identity.
    to: new EmailAddress('recipient@example.org', 'Project Partner'),
    subject: 'Projektstand: Prüfung & Freigabe',
))->withMarkdown("# Project update\n\nThe first milestone is **complete**.");

// The application passes ordinary UTF-8 text, without pre-escaping headers.
// Subject is plain text, not HTML: umlauts, &, quotes and literal < > survive.
// Header safety is automatic in constructors, address parsers and local edits:
// reject CR, LF, NUL, other C0 controls/DEL and malformed UTF-8 with
// InvalidArgumentException. Do not silently strip them or truncate bad values.
// Validate decoded display names as well, including MIME-encoded input.
// For example, these calls MUST throw before any connection or server write:
// new Email(to: 'recipient@example.org', subject: "Update\r\nBcc: hidden@example.org");
// new EmailAddress('sender@example.org', "Team\nReply-To: hidden@example.org");
// new Email(to: "recipient@example.org\r\nCc: hidden@example.org", subject: 'Update');
//
// saveDraft() revalidates outgoing headers before IMAP APPEND; its serializer
// handles MIME encoding, address quoting, header folding and filename parameters.
// This also applies to derived reply/forward subjects, names and attachment
// filenames. No caller-built raw header lines or manual htmlspecialchars() here.
// Incoming header folding is handled by the MIME reader; outgoing values are
// validated after decoding. A body has different rules: Markdown keeps newlines.
// HTML conversion must escape literal text/raw HTML and reject unsafe link schemes;
// original received HTML remains untrusted (see read-new.php).
//
// An explicit identity still overrides the mailbox default, including its name:
// $email = new Email(from: new EmailAddress('office@example.org', 'Office'),
//     to: 'recipient@example.org', subject: 'Explicit identity');

// Attach this existing example file so the example needs no missing PDF fixture.
// An application can substitute its own PDF, image or other local file.
$email = $email->attach(Attachment::fromPath(__FILE__));

// Only saving needs a connection. Until then $email->from() is [].
// saveDraft() resolves omitted/null From from MailClient's configured from value;
// it never guesses from the login name or from another message's headers.
// An explicit From is always preserved. Without either identity, saving fails
// before writing to the server; creating the local Email is still allowed.
/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$savedDraft = $mailClient->saveDraft($email);

// saveDraft() returns an Email with the resolved From and a stable server ID;
// it never sends. The original local $email remains unchanged, including its [] From.
// The configured 'new' signature is applied once in the returned draft (see
// create-message-defaults.php). Its HTML and inline images survive serialization.
// $savedDraft->body()->html() exposes the composed HTML alternative;
// $savedDraft->body()->text() includes the plain signature fallback.
// Use $email = $email->withSignature(false) BEFORE saving to omit the signature;
// withSignature($signature) selects an explicit Signature instead of the default.
// Retry the same unchanged email with the same identity/defaults without duplicates.
// Reusing its Message-ID with a different identity/signature/defaults is a conflict;
// fail explicitly instead of replacing the saved sender or creating a second draft.
foreach ($savedDraft->from() as $author) {
    echo 'Draft author: ' . $author->toString() . "\n";
}
// Editing/replacing an already saved draft is outside this initial API sketch.
// Saving different content with an already stored Message-ID fails explicitly.
echo 'Draft saved as ' . $savedDraft->id() . "\n";
