<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailAutomation, MailActions, MailContext};

function formMailRule(MailAutomation $automation): void
{
    $automation->onIncoming('support')->add(
        id: 'incoming.form-contact-proposal', priority: 250,
        matches: fn (Email $mail, MailContext $context): bool =>
            count($mail->from()) === 1
            && $mail->from()[0]->getAddress() === 'forms@example.org',
        handle: fn (Email $mail, MailContext $context): MailActions =>
            MailActions::create()
                ->createReplyDraft('Danke. Bitte prüfen Sie den Kontaktvorschlag.')
                ->addFlag('phore_review')
                ->moveTo('FormRequests'),
    );
    // Creates a draft addressed using the original reply target, NOT a sent mail.
    // No user is inserted just because form content contains a name/address.
    // From matching routes mail; it does not authenticate the form service.
}

// If actual automatic sending is wanted, explicitly configure a DraftSender adapter
// through new MailAutomation(storage: $pdo, sender: $sender), then use:
function sendContactProposal(): MailActions
{
    return MailActions::create()
        ->sendReply('Danke. Bitte bestätigen Sie den Kontaktvorschlag.');
}
// sendReply without an adapter fails. The adapter sends and stores final mail in Sent;
// onOutgoing subsequently observes it. The package currently has no SMTP sender.
// A draft-only rule and a send rule are alternative choices, not both registrations.
