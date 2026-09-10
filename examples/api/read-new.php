<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

// Executable API example.
/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';

// "New" means arrived after the supplied cursor, NOT unread.
// No cursor starts at the oldest available message. Automatic mode marks read mail Seen.
// Use MAIL_MODE=manual (or disable automatic seen) for reads without flag changes.
// The application stores one opaque cursor per account/folder; the client neither
// persists nor advances it implicitly. A stale UIDVALIDITY requires explicit resync.
$cursor = getenv('MAIL_SYNC_CURSOR') ?: null;
$batch = $mailClient->listNew(after: $cursor, limit: 10);

foreach ($batch->emails as $email) {
    // id() is an opaque, serializable account/folder/UIDVALIDITY/UID reference,
    // not the RFC Message-ID or an IMAP sequence number. get($id) retrieves it later.
    echo 'ID: ' . $email->id() . "\n";
    echo 'Subject: ' . $email->subject() . "\n";
    // Always list<EmailAddress>, including single-author messages.
    foreach ($email->from() as $author) {
        echo 'From: ' . $author->getDisplayName() . ' (' . $author->getAddress() . ")\n";
    }
    // The same list shape applies to to(), cc(), bcc() and replyTo().
    // See addresses.php for all fields and the optional single sender().

    // Local body views; derived formats use phore/markdown without fetching URLs.
    // text(): plain alternative or HTML-to-text fallback.
    // markdown(): editable representation; HTML-only mail is converted.
    // html(): original untrusted HTML alternative, or null. Never render directly.
    echo "Plain text:\n" . $email->body()->text() . "\n\n";
    echo "Markdown:\n" . $email->body()->markdown() . "\n\n";
    echo "Untrusted HTML source:\n" . ($email->body()->html() ?? '[none]') . "\n";

    // Listing attachment metadata does not download binary content.
    foreach ($email->attachments() as $attachment) {
        echo 'Attachment: ' . $attachment->filename() . ' (' . $attachment->mediaType() . ")\n";
        // Explicit, size-limited binary access when needed:
        // $stream = $mailClient->openAttachment($email, $attachment, maxBytes: 10_000_000);
        // try { ... consume the stream ... } finally { fclose($stream); }
    }
}

// Persist nextCursor only AFTER successfully processing the whole batch.
// A failure before checkpointing intentionally allows replay (at least once).
// Resume with this cursor to retrieve the next page, including across restarts.
echo 'Next cursor: ' . $batch->nextCursor . "\n";
