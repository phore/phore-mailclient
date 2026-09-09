<?php

declare(strict_types=1);

use Phore\MailClient\Signature;

// API DESIGN ONLY. Shared message configuration; no connection required.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Optional local PNG/JPEG/GIF logo, e.g. MAIL_SIGNATURE_IMAGE=/path/to/logo.png.
// Supply an existing readable file; an invalid configured path fails explicitly.
// Without this variable the example remains usable as a text-only HTML signature.
$logoPath = getenv('MAIL_SIGNATURE_IMAGE') ?: null;
$fullSignature = Signature::fromHtml(
    html: '<p>Viele Grüße<br><strong>Support Team</strong><br>'
        . '<a href="mailto:support@example.org">support@example.org</a></p>'
        . ($logoPath === null ? '' : '<p><img src="cid:logo" alt="Firmenlogo" width="160"></p>'),
    text: "Viele Grüße\nSupport Team\nsupport@example.org",
    inlineImages: $logoPath === null ? [] : ['logo' => $logoPath],
    maxImageBytes: 2_000_000,
);
// HTML is retained as a separate signature part, never round-tripped through
// Markdown. text supplies the plain alternative; when omitted, derive a simple
// HTML-to-text fallback. body()->markdown() exposes a derived editable view;
// rendering HTML does not use that lossy view as its source.
// fromHtml() sanitizes the fragment with an allowlist, preserving basic email
// layout (tables, links, fonts, approved inline styles) and bound cid images.
// Remove scripts, event handlers, forms, active embeds and unsafe URLs/CSS.
// No network requests, CSS url()/imports, file: URLs or arbitrary filesystem reads.
// Every cid alias must have a matching explicit inlineImages entry; unknown
// aliases fail. HTML may not itself name a local file to load.
// Image input must be a readable local file with validated raster type and size.
// Snapshot image bytes when creating Signature, so later file changes cannot
// alter a retry. The serializer assigns unique per-message Content-IDs, rewrites
// cid aliases and emits inline MIME parts related to the HTML body. Retries retain
// those IDs; unrelated attachments and quoted image IDs must not collide.
// Plain text uses text/alt text without broken cid URLs or binary image data.
// A saved/reopened draft preserves the HTML signature and related image parts.

// An optional shorter variant for replies (plain text is valid Markdown too).
$shortSignature = Signature::fromMarkdown("Viele Grüße\n\n**Support Team**");

return [
    // Our own placeholder syntax, inspired by Thunderbird's attribution lines.
    // Templates describe BODY text, not MIME headers. Newlines are allowed here.
    'quote' => [
        'reply' => 'Am {{date}} schrieb {{from.name}} ({{from.address}}):',
        'forward' => <<<'TEXT'
-------- Weitergeleitete Nachricht --------
Weitergeleitet von {{from}} am {{date}}
Betreff: {{subject}}
An: {{to}}
Cc: {{cc}}
TEXT,
        'dateFormat' => 'd.m.Y H:i T', // PHP DateTime format
        'timezone' => 'Europe/Berlin',
        'missingDate' => 'unbekanntes Datum',
        'missingAuthor' => 'unbekannter Absender',
    ],
    // null disables a default for that message type. reply also covers replyAll.
    // Use $fullSignature for all three to include the HTML logo everywhere;
    // Outlook-style separate choices are possible, as in this example.
    'signatures' => [
        'new' => $fullSignature,
        'reply' => $shortSignature,
        'forward' => $fullSignature,
    ],
    // above-quote: own text, signature, attribution, complete quoted source.
    // below-quote: own text, attribution, complete quoted source, signature.
    // For a new message (no quote), the signature follows the body in either case.
    // This example selects Thunderbird's configurable "below reply, above quote"
    // arrangement. No claim that Thunderbird and Outlook share every default.
    'signaturePosition' => 'above-quote',
];

// Placeholder contract (applies to reply/replyAll and forward):
// {{date}}: ORIGINAL message date in the configured timezone, never today's date.
// {{from}}: ALL original authors formatted as addresses, joined by ", ".
// {{from.name}}: ALL authors' getDisplayName() values, joined by ", ".
// {{from.address}}: ALL authors' getAddress() values, joined by ", ".
// {{subject}}, {{to}}, {{cc}}: original subject and full recipient lists.
// Missing date/author uses the configured fallback; missing subject/To/Cc is "".
// Bcc, arbitrary headers, code execution and template includes are not available.
// Unknown placeholders/options and invalid timezone/date-format config fail.
// Replace tokens once, non-recursively. Values are literal data: escape Markdown
// metacharacters in them and escape for HTML when rendering, so a sender name
// containing brackets, HTML or another token cannot inject markup/placeholders.
// Templates use plain text with line breaks; the renderer escapes their markup.
// Empty reply/forward template suppresses only the introduction, not the quote.
// The entire original body is still added as a Markdown blockquote/HTML blockquote.
//
// Defaults are applied to newly composed messages and reply/forward derivatives,
// never appended to an unchanged fetched message when saving it as a draft.
// MailClient::reply/replyAll/forward resolve and snapshot them immediately;
// saveDraft(new Email(...)) resolves them in the returned copy. Explicit per-mail
// signature overrides win; false disables it, null/omission requests the default.
// Email::withSignature(Signature|false|null) returns a copy and replaces only the
// dedicated signature part; it never searches/removes text in the quoted source.
// Saving again never appends a second signature or duplicates inline images.
// A changed default for an unresolved input with the same stored Message-ID is
// a content conflict, not permission to overwrite that draft. Return/save the
// resolved Email for subsequent unchanged operations.
// Original quoted signatures stay in the quote; do not heuristically delete them.
//
// Design references:
// https://support.mozilla.org/en-US/kb/signatures
// https://searchfox.org/comm-central/source/mailnews/compose/src/nsMsgCompose.cpp
// https://support.microsoft.com/en-us/outlook/mail/how-to-add-and-change-an-email-signature-in-outlook
