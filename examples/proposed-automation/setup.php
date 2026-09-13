<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use PDO;
use Phore\MailClient\MailClient;
use Phore\MailClient\Automation\MailAutomation;

function setup(MailClient $client): MailAutomation
{
    // Only a SQLite connector: engine creates its tables and internal stores.
    // The parent directory must exist. PDO creates the file.
    $automation = new MailAutomation(
        storage: new PDO('sqlite:/var/lib/app/mail.sqlite'),
    );
    $automation->addMailbox(
        'support',
        $client,
        ownAddresses: ['support@example.org'],
        incomingFolder: 'INBOX',
        sentFolder: 'Sent', // Configure exact provider folder, e.g. "Gesendet".
    );
    return $automation;
}

// Application entry point, after registering rules:
// $automation = setup($client);
// incomingRules($automation);
// outgoingRules($automation); // Optional: enables creation on observed outgoing mail.
// $report = $automation->run();
//
// Sent is indexed first. Initial Sent backlog is indexed without running outgoing
// rules; addMailbox(..., processExistingOutgoing: true) explicitly enables those.
// Every unmarked existing incoming message is eligible on first setup.
// No outgoing creation rule: create a user only on a verified reply.
// No cursors, factories or user-store interfaces in normal application code.
