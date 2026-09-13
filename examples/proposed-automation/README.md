# Proposed MailAutomation application examples

**API design only.** The Automation classes, stores and attributes shown here are not
implemented by this PR. These are numbered, flat application excerpts, not runnable
demos. Numbering specifies reading order, not execution dependencies. Each file
states its prerequisites with explicit instanceof assertions before use. See the [contract](../../proposals/2026-09-13-mail-automation.md).

| File | Scenarios |
|---|---|
| [01-setup.php](01-setup.php) | One PDO SQLite connection; automatic tables/stores; main and Sent folders; one run |
| [02-incoming.php](02-incoming.php) | Route by known B2B classification; unknown unsolicited mail; conflict review; folder routing |
| [03-outgoing.php](03-outgoing.php) | Observe Sent; explicitly create recipient now; default first-reply alternative; multiple recipients |
| [04-first-reply.php](04-first-reply.php) | Live Sent verification, changed From alias, preserved primary; unknown/missing outgoing outcomes |
| [05-metadata.php](05-metadata.php) | Persist classification/metadata; search aliases and read history |
| [06-sender-rule.php](06-sender-rule.php) | Specific sender/form mail; contact proposal as draft; explicitly configured sending alternative |
| [07-attributes.php](07-attributes.php) | Equivalent attribute API; method and invokable class; subject and ordinary folder rules |
| [08-custom-storage.php](08-custom-storage.php) | Direct PDO default, ID-generator injection, explicit SQLite implementation, custom interfaces |
| [09-send-reply.php](09-send-reply.php) | Explicit sending with a provided DraftSender adapter |
| [10-manual-flags.php](10-manual-flags.php) | Attributed function: Thunderbird flag classifies a known user and schedules B2B processing |

Read 01-setup.php first. In an application, insert the registrations from 02-incoming.php
and optionally 03-outgoing.php before run(). Do not include these files in sequence;
each is an independent excerpt with its own prerequisites.
Without an outgoing creation rule, users are created only on their first verified
reply. addMailbox() indexes Sent either way. The recipient, never our own sender,
is the new user. The originally addressed email remains primary when a reply arrives
from a different alias.

The attribute application is an alternative to the programmatic one; do not register
both sets as duplicate business rules. The optional sender-specific registration in 06-sender-rule.php (priority 250) runs
after identity review (300) and before ordinary B2B/unknown routing. Its reply draft
uses the actual source reply target; it does not automatically send to an address
found inside the form content.

| State transition | Expected result |
|---|---|
| Unknown incoming, no outgoing link | user=null; classify/move message without inserting user |
| Known alias sends a new unrelated message | Existing user and its metadata available |
| Outgoing observed, no creation rule | Pending recipient/history only |
| Outgoing observed, creation rule | Find/create addressed recipient |
| First reply from another address | Verify live Sent mail; create/find original recipient; add alias |
| Referenced outgoing missing or ambiguous | No new user/alias; review outcome |
| Two users implicated | Conflict, no merge |
| Normal move | Preserve/set ordinary processed at destination |
| Manual move with processed retained | No ordinary reprocessing |
| Remove processed manually | Next run schedules the registered destination chain |
| moveTo(..., reprocess: true) | Defer destination chain to next run |
| Explicit application flag added | Its registered event route can run despite processed |
| No rule matches | Mark checked |
| Handler fails | Report error; keep eligible work pending |
| Initial incoming scan | All unmarked existing mail eligible across all pages |
| Initial Sent scan | Index history; no outgoing rule execution unless opted in |
| Missing name | Email local-part slug; add domain if too short |
| Same name twice | Different random suffixes |
| Name/primary changes later | Stable ID; search current name and aliases |
| Cross-server move | Separate future API; not moveTo |

Single instance is assumed. No crash-safety or duplicate-processing guarantee is
claimed. Permanent custom keywords must be supported. All target folders must exist.
PDO SQLite requires its extension; no schema factories are written by application
code. No real mailbox, AI service or sending service is called by these files.
