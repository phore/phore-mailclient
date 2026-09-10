<?php

declare(strict_types=1);

use Phore\MailClient\Email;
use Phore\MailClient\EmailAddress;

// Executable API example.
// All examples below work locally, without IMAP credentials or a connection.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// One immutable address value, independent of whether it is an author/recipient.
// Constructor: address is a bare mailbox; name is optional, decoded text.
// An empty name is normalized to null; getDisplayName() then uses the address.
$team = new EmailAddress('team@example.org', 'Project Team');
$bare = EmailAddress::parse('anna@example.org');
$named = EmailAddress::parse('Anna Example <anna@example.org>');
$quoted = EmailAddress::parse('"Example, Anna" <anna@example.org>');
$unicode = EmailAddress::parse('Jörg Beispiel <joerg@example.org>');
$encoded = EmailAddress::parse('=?UTF-8?Q?J=C3=B6rg_Beispiel?= <joerg@example.org>');

echo $quoted->getAddress() . "\n";     // anna@example.org
echo $quoted->getName() . "\n";        // Example, Anna (no surrounding quotes)
echo $quoted->getDisplayName() . "\n"; // Example, Anna
echo $quoted->toString() . "\n";       // "Example, Anna" <anna@example.org>
echo $bare->getAddress() . "\n";       // anna@example.org
var_dump($bare->getName());            // null: no explicit display name
echo $bare->getDisplayName() . "\n";   // anna@example.org: useful UI fallback
echo $bare->toString() . "\n";         // anna@example.org
echo $encoded->getName() . "\n";       // Jörg Beispiel, same as $unicode
// toString() formats a readable address, quoting/escaping names as needed.
// MIME header encoding/folding belongs to serialization, not string concatenation.

// parse() accepts exactly ONE mailbox; use parseList() for a whole header value.
// Commas inside quoted names are not separators. Never explode(',', $header).
$recipients = EmailAddress::parseList(
    '"Example, Anna" <anna@example.org>, Ben <ben@example.org>, cara@example.org',
); // list<EmailAddress>, three entries in input order
$empty = EmailAddress::parseList(''); // []
$one = EmailAddress::parseList('anna@example.org'); // list with one object

foreach ($recipients as $recipient) {
    echo $recipient->getDisplayName() . ' => ' . $recipient->getAddress() . "\n";
}

// Input contract for Email's from/to/cc/bcc/replyTo:
// EmailAddress | string | list<EmailAddress|string>.
// Each string denotes ONE mailbox, parsed exactly like EmailAddress::parse().
// Use parseList() explicitly for comma-separated input, even inside arrays.
// Output contract: from(), to(), cc(), bcc(), replyTo() ALWAYS list<EmailAddress>.
// Omitted optional lists return []; no string/object/null shape changes.
// From alone also accepts null and defaults to null when omitted. Before saving,
// from() returns [] for this unresolved identity; saveDraft() fills it from the
// mailbox configuration in the returned Email, leaving the original unchanged.
// Explicit from: [] is invalid; use omission/null to request the default.
// Explicit authors must never be replaced by the configured default.

// No recipients yet: an incomplete draft is valid and can be saved without
// sending. To/Cc/Bcc default to []; choosing recipients may happen later.
$noRecipients = (new Email(subject: 'Work in progress'))
    ->withMarkdown('Still **drafting**.');

// To accepts EmailAddress|string|list<EmailAddress|string> (default []).
// These are equivalent input shapes; the list may contain one or many entries:
// new Email(to: new EmailAddress('anna@example.org'), subject: 'Hello');
// new Email(to: [new EmailAddress('anna@example.org'),
//     new EmailAddress('ben@example.org')], subject: 'Hello');
// Strings and mixed arrays remain convenient, as shown below.
//
// One recipient: string convenience, normalized to an EmailAddress.
$oneRecipient = new Email(from: $team, to: 'anna@example.org', subject: 'Hello');
echo $oneRecipient->to()[0]->getAddress() . "\n";
echo count($oneRecipient->cc()) . "\n"; // 0

// Exactly one entry in each optional recipient field works the same way.
$onePerField = new Email(
    from: $team,
    to: $named,
    cc: 'Ben <ben@example.org>',
    bcc: new EmailAddress('archive@example.org'),
    subject: 'Small review',
);

// Multiple recipients, mixed strings/objects, multiple Cc and Bcc.
$manyRecipients = (new Email(
    from: $team,
    to: $recipients,
    cc: [new EmailAddress('dana@example.org', 'Dana'), 'eli@example.org'],
    bcc: ['archive@example.org', new EmailAddress('audit@example.org', 'Audit')],
    replyTo: ['Support <support@example.org>', 'support-backup@example.org'],
    subject: 'Project update',
))->withMarkdown("Hello everyone,\n\nHere is the **update**.");

// Bcc-only is valid too; no artificial public To recipient is needed.
$bccOnly = new Email(
    from: $team,
    to: [],
    cc: [],
    bcc: ['anna@example.org', 'ben@example.org'],
    subject: 'Private distribution',
);

// Multiple From entries are authors, not multiple Sender headers.
// RFC 5322 requires a single explicit Sender when From has multiple mailboxes.
// sender accepts ONE EmailAddress|string|null; sender() returns ?EmailAddress.
// A one-author email normally omits Sender; sender() then returns null.
$coauthored = new Email(
    from: [$named, new EmailAddress('ben@example.org', 'Ben')],
    sender: $team,
    to: $recipients,
    subject: 'Joint proposal',
);
foreach ($coauthored->from() as $author) {
    echo 'Author: ' . $author->toString() . "\n";
}
echo 'Sender: ' . ($coauthored->sender()?->toString() ?? '[not specified]') . "\n";

// Identical access for a composed Email and $mailClient->get($emailId).
// Missing Bcc on received mail means unknown/not disclosed, not "no Bcc sent".
foreach ([
    'From' => $manyRecipients->from(),
    'To' => $manyRecipients->to(),
    'Cc' => $manyRecipients->cc(),
    'Bcc' => $manyRecipients->bcc(),
    'Reply-To' => $manyRecipients->replyTo(),
] as $field => $addresses) {
    echo $field . ': ' . count($addresses) . " address(es)\n";
    foreach ($addresses as $address) {
        echo '  ' . $address->toString() . "\n";
    }
}
// Bcc display here is a LOCAL inspection of our own draft, not a public preview.
// saveDraft() retains Bcc for later editing. Replies/forwards never copy source
// Bcc to recipients or quoted headers. A later sending API must keep it private.
//
// To save ONE chosen example, explicitly connect and pass that Email:
// $mailClient = require __DIR__ . '/connect-mail-client.php';
// $savedDraft = $mailClient->saveDraft($manyRecipients);

// Parsing contract: invalid or unsupported input raises InvalidArgumentException;
// no silently dropped entries or partial list results. parse('a@x.org, b@x.org')
// fails rather than choosing the first. Reject CR/LF in caller-supplied input;
// incoming folded headers are unfolded by the MIME reader before address parsing.
// Validate decoded names too; see compose-draft.php for the full header-safety contract.
// This first sketch covers bare/name-addr, quoted names (including escaped quotes),
// UTF-8 display names and MIME-encoded names. Group syntax and obsolete forms are
// not yet promised; unsupported syntax must fail explicitly, not be misparsed.
// Incoming malformed address headers must surface an explicit parsing error.
// A composed multi-author From without Sender is rejected before saving.

// Library research (design references; no dependency added by these examples):
// Webklex Address already has mail/personal/full; wrap those in EmailAddress.
// https://www.php-imap.com/api/address
// Its 6.2.0 Header parser has a pure-PHP path, but is private and regex-based;
// do not treat it as a general, fully RFC-compliant public address-list parser.
// Implementation must use the pure-PHP path, never require ext-imap/mailparse.
// https://github.com/Webklex/php-imap/blob/6.2.0/src/Header.php
// Symfony Mime Address demonstrates string/object input and name/address values;
// a useful API reference, not a reason to add a second MIME library here.
// https://symfony.com/doc/current/mailer.html#email-addresses
// From/Sender and recipient semantics:
// https://www.rfc-editor.org/rfc/rfc5322.html#section-3.6.2
// https://www.rfc-editor.org/rfc/rfc5322.html#section-3.6.3
