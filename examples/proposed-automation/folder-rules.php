<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailActions, MailAutomation, MailContext};
use Phore\MailClient\Automation\Attributes\{Mailbox, OnIncoming, OnOutgoing, OnFolder, OnFlagAdded};

#[Mailbox('support')]
final class CustomerRules
{
    #[OnIncoming(id: 'attribute.b2b', priority: 100)]
    public function incoming(Email $mail, MailContext $context): MailActions
    {
        if ($context->user?->classification === 'b2b') {
            return MailActions::create()->moveTo('B2B', reprocess: true);
        }
        return MailActions::create()->addFlag('phore_review');
    }

    #[OnOutgoing(id: 'attribute.create-recipient', priority: 0)]
    public function outgoing(Email $mail, MailContext $context): MailActions
    {
        if (count($context->recipientUsers) !== 1) {
            return MailActions::create()->addFlag('phore_review');
        }
        $user = $context->createUserForRecipient();
        if ($user->classification === null) {
            $user->classify('new_contact');
        }
        return MailActions::none();
    }

    #[OnFolder(id: 'attribute.b2b-ready', folder: 'B2B', priority: 0)]
    public function b2b(Email $mail, MailContext $context): MailActions
    {
        return MailActions::create()->addFlag('b2b_ready');
    }
}

#[Mailbox('support')]
#[OnIncoming(id: 'attribute.invoice', priority: 200, subjectContains: 'Rechnung')]
final class InvoiceRule
{
    public function __invoke(Email $mail, MailContext $context): MailActions
    {
        return MailActions::create()->addFlag('invoice')->moveTo('Invoices');
        // Normal completion marks destination processed. No rerun there.
    }
}

#[Mailbox('support')]
#[OnFlagAdded(id: 'attribute.approve', folder: 'Invoices', flag: 'approved')]
function approveInvoice(Email $mail, MailContext $context): MailActions
{
    return MailActions::create()->removeFlag('phore_review')->moveTo('Archive');
}

function registerAttributes(MailAutomation $automation): void
{
    $automation->addRules(new InvoiceRule());
    $automation->addRules(new CustomerRules());
    $automation->addRules(__NAMESPACE__ . '\\approveInvoice');
}
// Alternative application to the programmatic examples, not duplicate registration.
// Attribute conditions are ANDed; the first matching rule owns the event.
// The general CustomerRules incoming method is unconditional and therefore last.
// Manual move retains processed. Removing processed re-enables the destination's
// ordinary chain if registered. Explicit approved events work despite processed.
