<?php

declare(strict_types=1);

/** @var \Phore\MailClient\MailClient $mail */
$mail = require __DIR__ . '/bootstrap.php';

foreach ($mail->listNew(limit: 10)->messages as $message) {
    echo "Subject: {$message->subject}\n";
    echo "From: {$message->from}\n\n";
    echo "Plain text:\n{$message->body->asText()}\n\n";
    echo "Untrusted HTML source:\n" . ($message->body->asHtml() ?? '[none]') . "\n\n";
    echo "Markdown:\n{$message->body->asMarkdown()}\n";
    echo str_repeat('-', 72) . "\n";
}
