<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use Phore\MailClient\Email;
use Phore\MailClient\Automation\{AliasStore, MailAutomation, MailActions, MailContext};

function classifyExistingUser(AliasStore $users, string $address): void
{
    $user = $users->findByEmail($address); // Lookup only, no insertion.
    if ($user === null) {
        return; // Unsolicited unknown senders have no user record to classify yet.
    }
    $user->classify('b2b');
    $user->setMetadata('customerNumber', 'C-1042');
    $user->setMetadata('language', 'de');
    // Stored immediately; next incoming context resolves these fields automatically.
    $results = $users->search('Anna'); // Current names, IDs and ALL aliases.
}

function manualClassificationRule(MailAutomation $automation): void
{
    $automation->onFlagAdded('support', 'Customers', 'classify_b2b')->add(
        id: 'customer.classify-b2b', priority: 0,
        matches: fn (Email $mail, MailContext $context): bool => true,
        handle: function (Email $mail, MailContext $context): MailActions {
            if ($context->user === null) {
                // A human tag on a MESSAGE cannot itself create a USER.
                return MailActions::create()->addFlag('phore_review');
            }
            $context->user->classify('b2b');
            return MailActions::create()
                ->removeFlag('classify_b2b')
                ->moveTo('B2B', reprocess: true);
        },
    );
    // Explicit flag-event rules can run on already processed messages.
    // Register the B2B folder chain as shown in incoming.php.
}

// AI integration: pass message content to your chosen classifier in application code.
// Store a suggested message tag and request review. Update a persisted user's
// classification explicitly once accepted; never infer aliases from form content.
