<?php

declare(strict_types=1);

use Phore\MailClient\MailboxConfig;

// Executable API example.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Standard setup: load one YAML mailbox configuration. Override the path with
// MAIL_CONFIG_FILE when the application stores its mailbox config elsewhere.
// fromFile() performs offline validation; connect() resolves the configured secret
// and then connects with verified TLS. YAML requires PHP's yaml_parse() function.
$configFile = getenv('MAIL_CONFIG_FILE') ?: __DIR__ . '/mailbox.yaml';

return MailboxConfig::fromFile($configFile)->connect();
