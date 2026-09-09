<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

// API DESIGN ONLY. Each invocation selects exactly ONE action and a concrete ID.
// Example: php message-actions.php <email-id> mark-read
// These are proposed real server writes, not changes to an in-memory Email.
$emailId = $argv[1] ?? throw new RuntimeException('Provide an email ID from read-new.php.');
$action = $argv[2] ?? throw new RuntimeException('Choose an explicit action.');
$allowed = ['mark-read', 'mark-unread', 'mark-answered', 'mark-forwarded', 'star', 'unstar',
    'add-flag', 'remove-flag', 'trash'];
if (!in_array($action, $allowed, true)) {
    throw new RuntimeException('Unknown action: ' . $action);
}

// Validate the destructive choice before connecting. No implicit first-message target.
if ($action === 'trash' && ($argv[3] ?? '') !== '--confirm') {
    throw new RuntimeException('Use: php message-actions.php <email-id> trash --confirm');
}

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$email = $mailClient->get($emailId);

// Common actions are conveniences over addFlag()/removeFlag().
// All return a refreshed Email; the original object is an immutable snapshot.
// A new, unsaved Email has no server ID: remote actions reject it before any I/O.
// Foreign-account or stale references are rejected, never silently re-targeted.
// Unsupported flags/keywords fail explicitly; flag updates preserve other flags.
$updatedEmail = match ($action) {
    'mark-read' => $mailClient->markRead($email),             // \Seen
    'mark-unread' => $mailClient->markUnread($email),         // remove \Seen
    'mark-answered' => $mailClient->markAnswered($email),     // \Answered
    'mark-forwarded' => $mailClient->markForwarded($email),   // $Forwarded
    'star' => $mailClient->addFlag($email, '\\Flagged'),
    'unstar' => $mailClient->removeFlag($email, '\\Flagged'),
    'add-flag' => $mailClient->addFlag($email, 'ProjectReviewed'),
    'remove-flag' => $mailClient->removeFlag($email, 'ProjectReviewed'),
    'trash' => $mailClient->moveToTrash($email),
};

// Answered/forwarded assert actual past actions; setting a flag never sends mail.
// moveToTrash() is recoverable deletion with a new folder-scoped ID. Missing trash
// folder/capability must fail; never fall back to permanent deletion or broad EXPUNGE.
// Permanent deletion and protocol-controlled flags are outside this example API.
echo 'Updated email ID: ' . $updatedEmail->id() . "\n";
