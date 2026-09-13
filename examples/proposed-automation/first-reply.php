<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use Phore\MailClient\Email;
use Phore\MailClient\Automation\{IdentityResult, ReplyIdentityResolver};

function inspectReplyLearning(ReplyIdentityResolver $resolver, Email $reply): IdentityResult
{
    // Usually called internally by MailAutomation, not by every application rule.
    return $resolver->learnAliasFromReply('support', $reply);
}

/*
Scenario A: default creation on first reply
1. Sent contains a message from our configured own address to
   "Anna Müller <anna@old.example>", Message-ID <out-1@example.org>.
2. Engine indexes it. No outgoing creation rule exists: no user inserted yet.
3. Incoming From is anna@new.example, In-Reply-To is <out-1@example.org>.
4. Engine verifies that exact outgoing mail EXISTS NOW in configured Sent, with
   our sender and one matching external recipient. Cached evidence alone is insufficient.
5. Create/find Anna; primary=anna@old.example, alias=anna@new.example.
   Example ID: anna-mueller-e7k3p9x2r.
6. Incoming rules immediately see context.user, aliases and existing metadata.

Scenario B: missing names
- Outgoing and first reply have no usable display name: derive prefix from the
  original recipient's local part; "a@example.org" becomes "a-example-org".
- A later name change leaves the ID stable.
- Reply From is not substituted for the established primary.

Scenario C: no outgoing evidence
- New unknown message, merely "Re:" in subject: Unknown, user=null, no insert.
- Previously indexed outgoing was deleted/moved out of Sent: OutgoingMissing,
  no new user/alias; route for review. Known From can still resolve an existing user.
- No cached record but outgoing exists in Sent: live exact lookup may establish it.
- Network failure during verification: error; no inferred match.

Scenario D: conflicts
- More than one external recipient or duplicate ambiguous Message-ID: Conflict.
- Reply From belongs to a different user: Conflict, never automatic merge.
- A contradictory In-Reply-To does not fall back to a convenient older References ID.
- Own sender, bounce or auto-generated response: no automatic learning.

Scenario E: later replies
- Another reply from the same alias updates lastSeenAt, preserves creation evidence.
- A third address with a verified reply link is added to the same user.
- Preserve firstSeenAt, source and incoming/outgoing evidence per alias.
- Preserve main address, user ID, classification and user metadata.
- Reply linkage is accepted conversation trust, not proof of personal identity.

Scenario F: answering unsolicited incoming
- Moving/classifying unknown mail does not create a user.
- Our answer appears in Sent: optional outgoing rule creates its recipient now.
- Without that rule, the recipient's NEXT linked reply creates the user.
*/
