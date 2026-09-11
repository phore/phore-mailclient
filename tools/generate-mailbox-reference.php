<?php
declare(strict_types=1);

use Phore\MailClient\MailboxConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
$path = dirname(__DIR__) . '/mailbox-config.reference.json';
$json = json_encode(MailboxConfig::reference(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (($argv[1] ?? null) === '--check') {
    if (!is_file($path) || file_get_contents($path) !== $json) {
        fwrite(STDERR, "Mailbox reference is stale. Run composer generate-config-reference and commit the result.\n");
        exit(1);
    }
} elseif (file_put_contents($path, $json) === false) {
    fwrite(STDERR, "Cannot write mailbox reference.\n");
    exit(1);
}
