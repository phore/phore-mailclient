<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailAutomation, MailActions, MailContext};

function incomingRules(MailAutomation $automation): void
{
    $automation->onIncoming('support')->add(
        id: 'incoming.identity-review', priority: 300,
        matches: fn (Email $mail, MailContext $context): bool =>
            $context->identity->needsReview(),
        handle: fn (Email $mail, MailContext $context): MailActions =>
            MailActions::create()->addFlag('phore_review')->moveTo('Review'),
    );

    $automation->onIncoming('support')->add(
        id: 'incoming.b2b', priority: 200,
        matches: fn (Email $mail, MailContext $context): bool =>
            $context->user?->classification === 'b2b',
        handle: function (Email $mail, MailContext $context): MailActions {
            $user = $context->user; // Already resolved including metadata/aliases.
            $customerNumber = $user->metadata['customerNumber'] ?? null;
            $history = $context->history->forUser($user->id, limit: 20);
            // Use customerNumber/history in application logic.
            return MailActions::create()->moveTo('B2B', reprocess: true);
        },
    );

    $automation->onIncoming('support')->add(
        id: 'incoming.unknown', priority: 100,
        matches: fn (Email $mail, MailContext $context): bool =>
            $context->user === null,
        handle: fn (Email $mail, MailContext $context): MailActions =>
            MailActions::create()->addFlag('new_contact')->moveTo('NewContacts'),
    );
    // Unknown unsolicited mail never inserts a MailUser.
    // It can be classified by a human/AI as a MESSAGE; that alone creates no user.

    $automation->onIncoming('support')->add(
        id: 'incoming.other-known', priority: 0,
        matches: fn (Email $mail, MailContext $context): bool => true,
        handle: fn (Email $mail, MailContext $context): MailActions =>
            MailActions::create()->moveTo('Customers'),
    );

    $automation->onFolder('support', 'B2B')->add(
        id: 'b2b.ready', priority: 0,
        matches: fn (Email $mail, MailContext $context): bool => true,
        handle: fn (Email $mail, MailContext $context): MailActions =>
            MailActions::create()->addFlag('b2b_ready'),
    );
    // B2B destination processing waits until NEXT run; no recursive same-run loop.
    // Other normal moves retain/set processed and do not rerun destination rules.
    // All destination folders must already exist.
}
