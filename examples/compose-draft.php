<?php

declare(strict_types=1);

/** @var \Phore\MailClient\MailClient $mail */
$mail = require __DIR__ . '/bootstrap.php';

$saved = $mail
    ->draft(['recipient@example.org'], 'Project update')
    ->withMarkdown("# Project update\n\nThe first milestone is **complete**.")
    ->save();

echo "Draft saved as {$saved->reference->remoteId}\n";
