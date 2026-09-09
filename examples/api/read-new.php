<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';

foreach ($mailClient->inbox()->listNew(limit: 10) as $email) {
    echo 'Subject: ' . $email->subject() . "\n";
    echo 'From: ' . $email->from() . "\n\n";

    echo "Plain text:\n" . $email->body()->text() . "\n\n";
    echo "Markdown:\n" . $email->body()->markdown() . "\n\n";

    // HTML is the unmodified, untrusted source alternative. Rendering it safely
    // remains the application's responsibility.
    echo "Untrusted HTML:\n" . ($email->body()->html() ?? '[none]') . "\n";

    foreach ($email->attachments() as $attachment) {
        echo 'Attachment: ' . $attachment->filename() . ' (' . $attachment->mediaType() . ")\n";
    }
}
