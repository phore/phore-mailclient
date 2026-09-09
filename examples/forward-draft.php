<?php

declare(strict_types=1);

/** @var \Phore\MailClient\MailClient $mail */
$mail = require __DIR__ . '/bootstrap.php';

$message = $mail->listNew(limit: 1)->messages[0] ?? throw new RuntimeException('No new message found.');
$saved = $message
    ->forward(['colleague@example.org'])
    ->withMarkdown('For your information.')
    ->save();

echo "Forward draft saved as {$saved->reference->remoteId}\n";
