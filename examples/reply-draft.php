<?php

declare(strict_types=1);

/** @var \Phore\MailClient\MailClient $mail */
$mail = require __DIR__ . '/bootstrap.php';

$message = $mail->listNew(limit: 1)->messages[0] ?? throw new RuntimeException('No new message found.');
$saved = $message
    ->reply()
    ->withMarkdown("Thanks for your message.\n\nI will get back to you tomorrow.")
    ->save();

echo "Reply draft saved as {$saved->reference->remoteId}\n";
