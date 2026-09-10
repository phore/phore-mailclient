<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

// Choose an ID printed by read-new.php, never an arbitrary email.
$emailId = $argv[1] ?? throw new RuntimeException('Usage: php reply.php <email-id>');

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$email = $mailClient->get($emailId);

// Client convenience: resolve the mailbox's From, quote template and reply
// signature locally, then return a new unsaved Email. No network request here.
$reply = $mailClient->reply(
    $email,
    markdown: "Thanks for your message.\n\nI will get back to you tomorrow.",
);

// Default: use ALL Reply-To addresses (otherwise ALL From addresses) as To.
// Sender is not a fallback reply recipient. Prefix Re: once, generate a fresh
// Message-ID, set In-Reply-To to the source Message-ID and extend References.
// If the source has no valid Message-ID, omit thread headers; still quote its body.
// The new answer stays ABOVE the automatically appended Markdown blockquote.
// The configured reply introduction precedes that quote; signaturePosition places
// our own signature above/below the quote. See create-message-defaults.php.
// Quote and signature are structured parts, never concatenated again on save.
// Original attachments are not copied into replies. Source content is unchanged.
//
// Reply-all alternative (not an additional draft to save):
// $reply = $mailClient->replyAll(
//     $email,
//     markdown: 'Thanks, everyone.',
//     exclude: ['support-alias@example.org'],
// );
// replyAll() excludes the NEW reply's From plus explicit aliases, not the
// original authors. Reply targets stay in To; original To/Cc are added to Cc,
// deduplicated across both lists. Address comparison ignores display names,
// normalizes domain case, and preserves local-part case and plus tags.
// Source Bcc is never copied, including into the quote. The reply's Bcc is empty.
// Missing usable reply targets fail explicitly rather than guessing.

// Per-message overrides (use instead of the reply above):
// $reply = $mailClient->reply($email, markdown: 'Thanks.',
//     quote: ['reply' => '{{from.name}} schrieb am {{date}}:'],
//     signature: false); // false explicitly disables the account signature
// quote keys override the account settings; remaining keys are inherited.
// signature: $customSignature uses an explicit Signature for this one reply.
// Fully offline remains possible: $email->reply(from: $address, markdown: 'Thanks.',
//     quote: $quoteOptions, signature: $signature, signaturePosition: 'above-quote').
// The local Email method uses only supplied values and library defaults;
// it cannot discover mailbox configuration.

echo $reply->body()->markdown() . "\n";
$savedDraft = $mailClient->saveDraft($reply);

// Automatic mode marks the original Answered after the reply draft is saved.
// Disable automatic answered to reserve that flag for actual sending;
// markAnswered($email) remains an explicit server action in either mode.
echo 'Reply draft saved as ' . $savedDraft->id() . "\n";
