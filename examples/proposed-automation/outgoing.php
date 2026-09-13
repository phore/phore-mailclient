<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailAutomation, MailActions, MailContext};

function outgoingRules(MailAutomation $automation): void
{
    $automation->onOutgoing('support')->add(
        id: 'outgoing.create-recipient', priority: 0,
        matches: fn (Email $mail, MailContext $context): bool =>
            count($context->recipientUsers) === 1,
        handle: function (Email $mail, MailContext $context): MailActions {
            // Context user initially resolves the SOLE external recipient, if known.
            // Explicit opt-in: create now if absent; otherwise reuse without overwrite.
            $user = $context->createUserForRecipient();
            if ($user->classification === null) {
                $user->classify('new_contact');
            }
            $user->setMetadata('source', 'sent_folder');
            return MailActions::none();
        },
    );
    // Omit this rule to defer user creation until their first verified reply.
    // No name on outgoing recipient? Email-based slug plus random suffix is used.
    // phore_outgoing_processed is set after success, separately from incoming marker.
}

// Alternative to outgoingRules(): explicit handling of multiple recipients.
function outgoingGroupRule(MailAutomation $automation): void
{
    $automation->onOutgoing('support')->add(
        id: 'outgoing.create-all-recipients', priority: 0,
        matches: fn (Email $mail, MailContext $context): bool => true,
        handle: function (Email $mail, MailContext $context): MailActions {
            foreach ($context->recipientUsers as $address => $existingUser) {
                $user = $context->createUserForRecipient($address);
                // Each real external recipient is separate. Never merge the group.
            }
            return MailActions::none();
        },
    );
    // Incoming unknown replies to group mail remain ambiguous: no guessed alias.
}
