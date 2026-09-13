# MailAutomation: incoming, sent mail and contact identity

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-13 | dermatthes | §§ 1–9: Proposal mit Beispielen angelegt |
| 2026-09-13 | dermatthes | § 9: Examples nach aktueller Coding-Basis-Referenz nummeriert, abgeflacht und direkt kommentiert |
| 2026-09-13 | dermatthes | §§ 2–7, § 9: Eine Client-Verbindung, geerbte Konfiguration, addAutomation, onInboxMessage/onSentMessage und Gesamtbeispiel |
| 2026-09-13 | dermatthes | §§ 2–3, § 6: Resolver initialisieren/einbinden, Kontext anreichern, Folder-Enum, OnFolderAutomation, optionale automationId und active |
| 2026-09-13 | dermatthes | § 9: Examples als aufbauende Lesereihe gekürzt, Einbindung und Varianten geklärt, Report-Vertragslücke benannt |
| 2026-09-13 | dermatthes | §§ 3–6, § 8: Einheitliche Bearbeitet-Sperre, aktuelle Keyword-Bedingungen und explizite Wiederaufnahme |
| 2026-09-13 | dermatthes | § 9: PHP-Starttag in allen gespeicherten Examples wiederhergestellt |
| 2026-09-13 | dermatthes | §§ 3–4: complete für Abschluss, pass für nächste passende Automatisierung; mehrdeutiges none entfernt |
| 2026-09-13 | dermatthes | §§ 2–3, §§ 5–9: Contact-Kontext, Thread-/Mailbox-Zugriff und vier getrennte Metadatenbereiche; vollständiges Handler-Beispiel |
| 2026-09-13 | dermatthes | §§ 6–7, §§ 7.1–7.2, § 9: Kontaktauflösung, Aliasverwaltung und Klassifizierung erklärt; Metadaten als reine Anwendungsdaten präzisiert |
| 2026-09-13 | dermatthes | §§ 2–3, §§ 6–7, §§ 6.1/7.1/7.4, § 9: ContactResolution-Fälle, einheitliche Benennung und typisierte Anwendungsmetadaten ohne Contact.classify |

## § 1 Status and scope

This is an API design PR, not a runtime implementation. Every file under
[examples/proposed-automation](../examples/proposed-automation/README.md) illustrates
future application code. Automation classes, actions and attributes are not available
in the installed package. PHP 8.5 is the target. No real mail is sent or accessed.

The implementation would use [folder-sync PR #5](https://github.com/phore/phore-mailclient/pull/5),
reviewed at 28025d4c49c0ef90d1463a6d4dd6212f347f6125.
One instance runs at a time. Crash recovery, locks and duplicate-processing guarantees
are deliberately excluded. The engine owns cursors; handlers express business rules.

## § 2 Configuration and storage

MailAutomation is bound to exactly one MailClient and its existing connection:
new MailAutomation(client: $client, storage: $storage).
MailClient is required, as is PDO|AutomationStorage. There is no account registry,
mailbox key, addMailbox method, Mailbox attribute or connection created by the engine.
One sender, one incoming folder and one Sent folder are inherited from that client.
Rule registration and identity calls therefore never take an account argument.

The standard storage argument is a PDO SQLite connection. The engine recognizes the
SQLite driver and internally initializes versioned tables for state, outgoing evidence,
contacts, aliases, metadata and history. No caller-written factory or interface is needed.
The parent directory must exist; PDO creates the file. Initialization rejects incompatible
newer schemas and never silently destroys existing state.

Advanced callers can pass AutomationStorage directly. Non-SQLite PDO fails clearly
with instructions to supply that interface. SqliteStorage is an optional explicit
implementation; it composes SqliteAutomationState, SqliteContactStore and
SqliteMailHistoryStore over one connection. Custom ContactIdGenerator and DraftSender
adapters remain optional customization, not prerequisites for normal usage.

The existing MailClient currently has INBOX fixed internally, an optional from address
and configured Drafts/Trash folders; it does NOT yet expose a Sent-folder setting or
the complete read-only configuration needed here. Future implementation must retain
incomingFolder (default INBOX), sentFolder (default Sent), one configured from address
and existing draft/trash configuration plus a Junk-folder mapping on the client. Automation reads these values
without duplicating them in its constructor or example setup. Provider-specific folder
names are configured once when constructing the client. This proposal does not change
MailboxConfig JSON or its reference yet; implementation must update that reference
alongside any added configuration fields.

The supplied client must identify its single sender; if from is absent, initialization
fails with a clear instruction to configure the client. Never guess from a login name.
Missing folders or identical incoming/Sent folders also fail clearly. No folders are
silently created and the engine does not open another account connection. The client
must provide side-effect-free automation reads without mutating the application's
automatic-mode setting.

AutomationStorage provides state(), contacts(), history(), threads() and metadata()
through AutomationStateStore, ContactStore, MailHistoryStore, ThreadStore and MetadataStore.
PDO SQLite supplies all of these internally, including tables for threads and the four
metadata scopes; callers still need only client and storage. One storage instance belongs to this one client/account, not an implicit
multi-account namespace. Bind persisted state to the client's account identity and
reject a foreign client using that state; a deliberate migration requires review.
Contact IDs are unique within the store. Repeated runs with the same client reuse state.
Configuration is fixed for the lifetime of an automation instance.

Optional contactResolver: new ReplyContactResolver() explicitly supplies the default identity
strategy. When omitted, MailAutomation uses the same strategy internally. The engine
binds it to the supplied client and storage's contacts/history before any rule evaluation.
No second connection, independent database or application-written factory is needed.
The resolver learns contacts/aliases from verified replies by default; ID generation
belongs to the shared store and its ContactIdGenerator, not to handlers. A configured
resolver instance is bound to one automation only; using it unbound for direct learning
fails clearly. Custom strategies implement ContactResolver with bind(MailClient,
AutomationStorage): void and resolve(Email): ContactResolution. Binding occurs once;
resolve performs incoming lookup/allowed learning before predicates and handlers, only for messages admitted by the processed gate.
Outgoing contexts only look up recipients; creation remains an explicit outgoing action. ReplyContactResolver
also retains learnAliasFromReply(Email) for explicit specialized use after binding. [geändert]

## § 3 Run and rule semantics

run(): RunReport first synchronizes the client's Sent folder and runs eligible
outgoing rules, then synchronizes its incoming/ordinary folders and processes their rules.
It returns counts and structured errors. It does not schedule itself. A CLI wrapper
can call this method as automation:run. All message reads are side-effect-free unless
an action explicitly changes flags. No implicit Seen/Answered changes.

onFolder(Folder|string) selects a standard or exact named folder, then
addAutomation(matches, handle, priority: 0, automationId: null, active: true) registers the work. Folder enum values
are Inbox, Sent, Drafts, Trash and Junk; the client maps each to its actual name.
Strings are exact folder names. Special Inbox/Sent behavior follows the resolved
folder identity even when selected by its string name. Missing mappings fail clearly,
never fall back to guessed provider folders. Sent/Inbox configuration must be distinct.
These selectors replace separate incoming/outgoing selectors. Keyword rules belong to
this same folder chain; there is no separate flag-change event route. Predicates receive
(Email, MailContext): bool; handlers receive (Email, MailContext): MailActions.
Higher priority runs first, ties use registration order. A false predicate skips the
handler. A matching handler returning MailActions::pass() continues with the next
rule's predicate in the same folder chain and run. MailActions::complete() or a
successful action list ends the chain for this message. Duplicate IDs/incompatible signatures fail before writes.
Exceptions are failures, never a fall-through. Unmarked observed messages and deliberate
marker resets are eligible; a folder event is not proof of new delivery.

Every handler returns MailActions: complete() explicitly finishes without further
mail actions; pass() declines responsibility without setting phore_processed.
create() builds an action list for the current message; successful execution finishes
the chain, except the documented deferred reprocess handoff. An empty create() list
also finishes; use complete() to express that intent directly. complete()/pass() are
standalone results and cannot be combined with queued actions. null, missing returns
and exceptions are errors, not delegation. There is no none() result.

Handlers must decide to pass before any mail mutation, metadata write, contact creation
or external side effect. Such changes cannot be rolled back by pass(); this is a handler
contract, not a promised transaction around arbitrary application code. Identity enrichment
occurs once before this chain and remains available to later handlers; pass() does not
undo that engine step or trigger it again. If every active rule skips or passes, the
engine marks the message processed as checked. A handler/action failure instead stops
the chain and keeps work pending; no fallback handler executes after a failure.

First check the current phore_processed keyword. Marked messages skip identity learning,
predicates, handlers and mail actions. For eligible incoming messages, resolve a known From or learn identity from a verified
outgoing reply link. MailContext exposes contact (?Contact), thread (MailThread),
metadata (MetadataBag for this message), mailbox (MailboxContext), contactResolution
(ContactResolution), folder and direction. It does not expose global contacts/history stores
directly. MailboxContext reuses the same client/storage and exposes contacts (ContactStore),
mailHistory (MailHistoryStore) and metadata (MetadataBag for this account). Mailbox means
the bound account here, while Folder means an individual IMAP folder.
For outgoing mail, contact is the already known sole external recipient, not ourselves;
recipientContacts exposes each external recipient mapped to a contact or null. For multiple
recipients, contact=null; applications must explicitly address each recipient. [geändert]

Class instances, invokable classes and attributed function callables are registered
with addRules(object|callable). OnFolderAutomation(folder: Folder::Inbox),
OnFolderAutomation(folder: Folder::Sent) attributes compile to the same rules as builders. Trigger attributes on a class apply
to __invoke; method attributes apply to that method. Constructor dependencies are
provided by the application. Do not register one rule through both mechanisms.

OnFolderAutomation accepts optional flag: string, requiring that keyword to be present
at evaluation time; it is combined with other filters using AND. The programmatic
counterpart is MailContext::hasFlag(string $keyword): bool inside matches. Both use
the current keyword set after the global processed gate, not a remembered flag-added
event. Thus a keyword set while locked still matches after explicit unlocking.

The optional parameter is automationId (camelCase, consistent with the PHP API),
not a contact ID. Without it, class attributes/invokable handlers use the class short name;
method handlers use ShortClassName::method and named functions their qualified name.
Anonymous closures receive an internal per-registration ID, stable only for that instance;
provide automationId for stable cross-run diagnostics. Duplicate inferred or explicit IDs
fail during registration, including inactive rules; disambiguate with automationId.
Renaming a class changes its inferred ID but does not reset message processed flags.

Both addAutomation and trigger attributes accept active: bool = true. With false,
the rule is registered but neither its predicate nor handler executes. Other active rules
continue normally. If all rules skip or pass, normal no-match marking still applies; active: false
is not a folder pause or backlog retention mechanism. Re-enabling affects eligible mail;
already processed messages require an explicit marker reset to run again. Resolver enrichment
and Sent indexing are independent of a rule's active setting. Change configuration before
the next run; no dynamic switching API is required for V1.

## § 4 Processed flags, moves and reprocessing

phore_processed is the single global automation gate for every folder, including
Inbox, Sent, Drafts, Trash and Junk, and every keyword-conditioned rule. If present,
MailAutomation performs no identity learning, predicates, handlers or mail mutations
for that message. A successful action list, MailActions::complete() or an exhausted
chain (all rules skipped or passed) sets this same keyword. pass() alone never sets it. There is no separate outgoing completion marker.

Synchronization may still read flags/locations and maintain cursors. Sent evidence is
still read and indexed, including processed Sent messages, so an eligible incoming
reply can be checked against them. This indexing does not create contacts or aliases
or invoke business rules for the marked Sent message.

The gate belongs to each concrete message copy, not its Message-ID. Moving/copying
with phore_processed preserved keeps that destination locked in every folder.
Unlocking one copy does not unlock another. If copying does not preserve keywords,
the new unmarked copy is eligible; there is no cross-copy deduplication guarantee.

Manual workflow: move to the intended folder, set the desired business keyword,
then remove phore_processed. The next run evaluates the current destination and
keywords, including old UIDs. Unlocking before moving is allowed but a concurrent
run could process the old location; therefore unlock last. Pending and deferred work
must recheck current location/flags before evaluation and before applying actions.
If externally marked in the meantime, skip remaining work without clearing the lock.
Single-instance processing does not promise atomicity with concurrent mail-client edits.

MailActions::create()->moveTo('Archive') targets an existing same-account folder;
normal completion leaves phore_processed on the destination, including for Sent rules.
addFlag/removeFlag change business keywords. phore_processed is reserved for engine
completion and explicit external unlocking; attempts to manipulate it through generic
actions are rejected during action validation. A handler cannot bypass the gate.

moveTo('Invoices', reprocess: true) is the explicit automatic handoff: move, remove
phore_processed at the destination and defer its folder chain until the next run.
It is terminal, cannot target the current folder, and suppresses completion marking
at the destination. The destination must have a registered chain. This also applies
to handoffs to or from Sent. Deferred work never executes within the same run.
Avoid cyclic handoff routes.

Keyword-conditioned rules participate in the same priority order and complete/pass
selection as all other folder rules. Setting a business keyword on a processed
message alone does nothing. Remove phore_processed to re-evaluate the CURRENT
keyword set; a past flag-added event is neither needed nor replayed.
Unmarked failures remain pending independently of the sync cursor.

Initial ordinary scans process ALL unmarked existing messages across every page.
Initial Sent scans index all existing outgoing evidence, but baseline existing
messages without running outgoing business rules by default, avoiding accidental
bulk contact creation. This baseline sets phore_processed on previously unmarked
Sent messages without identity learning or handlers, so an explicit later removal
can request processing. Already marked messages remain untouched. The explicit run(processExistingOutgoing: true) option opts into those
rules during the initial Sent scan for unmarked backlog. Persist bootstrap phase across pages: PR #5's
isInitialSync describes only the first page. Removing phore_processed from a baselined
Sent message explicitly enables its Sent chain on the next run. processExistingOutgoing
never overrides an existing phore_processed lock. Existing incoming backlog therefore can
resolve against existing Sent evidence.

UIDVALIDITY changes require explicit resynchronization; network failures never erase
cursors. Preserved flags allow safe eligibility rebuilding under the chosen simple
model. Unknown marker preservation after migration requires review before processing.
IMAP polling sees net state, not every transient event. Thunderbird maps exact keyword
names to its local tag labels/colors; IMAP does not synchronize those colors.

Cross-account/server transfer is NOT moveTo. A future operation must explicitly
define copy, verification and source removal. This proposal covers same-account folders.

## § 5 Sent observation and when a contact is created

Monitoring the configured Sent folder is the standard source of outgoing evidence.
Index final Message-ID, full intended recipient set (To/Cc and Bcc when retained),
recipient display names, configured sender, sent timestamp and live folder location.
Only messages sent from client's configured sender address qualify. Copied Sent entries are
observable records, not independent SMTP-delivery proof; this design deliberately
trusts the configured Sent folder. Drafts never count.

The default is creation on the first qualifying, unprocessed reply. Sent observation alone stores
recipient evidence but no Contact. An outgoing rule may call
context.createContactForRecipient(?string $email = null): Contact to enable creation at
the time outgoing mail is observed. This works only in outgoing context. With one
external recipient the argument is optional; with several it is required and must
match an actual recipient. Existing contacts are reused without overwriting their fields.

The incoming context's contact=null is normal for unsolicited unknown mail. There is no
automatic contact creation merely for receiving, marking, moving or drafting a response.
If we answer unknown mail, our reply is observed in Sent: an outgoing rule may create
the contact then, otherwise wait until they answer that outgoing reply.

| Scenario | Default | With outgoing creation rule |
|---|---|---|
| Message already has phore_processed | No business processing or identity learning | Same; Sent indexing remains allowed |
| Unknown incoming, no reply link | contact=null; no insertion | Same |
| New outgoing to unknown recipient | Index pending recipient | Rule creates addressed recipient |
| First verified reply | Create/find recipient contact; learn From alias | Reuse contact; learn From alias |
| Known From, no reply headers | Return known contact | Same |
| Referenced outgoing no longer exists | No new contact or alias | No new contact or alias |
| Outgoing baseline on first setup | Index and mark processed, no contact creation | Same unless explicitly opted into rules |

The primary address is the originally addressed recipient; the reply's different
From is an alias. Existing primary never changes automatically. If recipient name is
missing, use a usable first-reply display name under this conversation-trust policy,
otherwise the email slug fallback. Creation on outgoing observation without a name
uses the fallback immediately. Later name changes never change the ID.

## § 6 Live reply verification and alias learning

ReplyContactResolver::learnAliasFromReply(Email $email): ContactResolution
is the explicit learning method used by the default resolver's resolve operation.
MailAutomation invokes its bound strategy only after the processed gate admits the
incoming message, before predicates/handlers, and assigns
ContactResolution to context.contactResolution and its contact to context.contact. Handlers need no resolver call.
It returns status (Unknown, KnownAddress, ContactCreated, AliasAdded, Conflict,
OutgoingMissing), nullable contact, matched outgoing evidence and aliasAdded.
needsReview() is true for Conflict and OutgoingMissing; isConflict() only for Conflict.
ContactResolution describes this message's resolution outcome, not another person or a login.
ContactCreated takes precedence when a new contact was inserted; aliasAdded can also be true
if its reply From differs from the primary recipient. AliasAdded means an existing contact
gained an alias; KnownAddress means a known address without conflicting evidence. [geändert]

Inspect exact In-Reply-To IDs first. Resolve conflicting direct targets as Conflict;
do not pick an older References entry to escape a conflict or a missing direct target.
If In-Reply-To is absent, inspect References for the nearest unambiguous verified
outgoing ancestor. Do not treat Subject, quoted text, From display name or incoming
Reply-To as identity evidence. Own addresses, bounces and auto-generated replies
are excluded from automatic contact creation and alias learning.

Before EVERY new contact or alias from a reply, verify the corresponding outgoing message
currently exists in that client's configured Sent folder. An index entry alone is
insufficient. Re-read exact Message-ID, own sender and recipients using current
UIDVALIDITY. If its location is stale, search Sent by Message-ID then compare exact
values (IMAP header search may be substring based). If Sent indexing lagged, perform
that same live search rather than prematurely calling the sender unknown.
Zero matches yields OutgoingMissing; ambiguous duplicate matches yield Conflict;
network/permission errors are errors, never successful verification.

V1 learns only from a unique outgoing record with exactly one external recipient.
A multi-recipient or mismatched record is Conflict; no automatic contact merges.
If any candidate address already belongs to another contact, all evidence must agree;
otherwise return Conflict. Known From contacts can still be exposed in contact, but
contactResolution.status must reveal conflicting reply evidence and prevent new learning. [geändert]

For pending recipients, first look up their address again: separate outgoing messages
to the same recipient converge on one contact. The original recipient becomes primary,
the reply From becomes an alias. Record firstSeenAt, lastSeenAt, source, incoming
and outgoing Message-IDs and alias metadata. Re-observation preserves first evidence,
updates lastSeenAt and appends history. Manual metadata edits never rewrite evidence.

A referenced mail existing in Sent establishes conversation linkage, not proof that
the reply author is the same natural person. A colleague may answer a forwarded mail,
and headers can be fabricated. This is accepted automatic contact-learning behavior,
not authentication or permission to disclose sensitive data.

### § 6.1 ContactResolution results and application decisions

ContactResolution is the technical result of resolving this message, not a login identity,
business classification or application metadata. Its status is ContactResolutionStatus,
a PHP enum with Unknown, KnownAddress, ContactCreated, AliasAdded, Conflict and OutgoingMissing.
It exposes contact (?Contact), aliasAdded (bool) and matchedOutgoing (?OutgoingContactEvidence).
Incoming context.contact is the same contact as this result. No custom application metadata
class replaces this technical result; custom metadata is configured independently in § 7.4. [neu]

| Incoming status | Origin and contact | Recommended application response |
|---|---|---|
| Unknown | No known From and no eligible outgoing reply evidence; contact=null, no insertion | Route for first-contact handling; do not create blindly |
| KnownAddress | Existing From alias, no contradictory evidence; existing contact, no new alias | Use existing contact metadata for business routing |
| ContactCreated | Live verified reply created the originally addressed contact; contact is present | Initial business review/classification; do not create again |
| AliasAdded | Verified reply attached a new From to an existing contact | Continue using that same contact; do not replace primary |
| Conflict | Ambiguous references/outgoing copies, multiple recipients or incompatible contact ownership; known From contact may remain | Stop normal routing; review references and alias ownership, never auto-merge |
| OutgoingMissing | Exact referenced outgoing absent after successful live Sent lookup; known From contact may remain | Check configured Sent and restore/clarify evidence, then explicitly reprocess |

ContactCreated takes precedence over AliasAdded; aliasAdded=true only when a distinct
reply From alias was newly added, including during contact creation. Both successful learning
statuses provide matchedOutgoing. KnownAddress may provide it for a verified reply and otherwise
has null. Unknown, Conflict and OutgoingMissing provide null; an ambiguous candidate is never
presented as a verified match. aliasAdded=false for all non-learning results.
OutgoingContactEvidence exposes messageId (exact RFC Message-ID), recipientEmail (sole external
recipient) and folder (resolved Sent name). These fields explain a checked link, not a promise
of ongoing existence or permission to disclose private correspondence. [neu]

Own addresses, bounces and auto-generated replies are excluded from learning. Existing From
lookup may still yield KnownAddress; otherwise Unknown, without treating exclusion as a conflict.
Technical network/permission/storage failures abort resolution and leave the mail pending;
they are not Unknown or OutgoingMissing and do not invoke a business handler with a fabricated result.
The report records the failure; its detailed public error-access API is still an open design point. [neu]

needsReview() is equivalent to Conflict or OutgoingMissing; isConflict() means Conflict only.
Neither method performs routing, sends mail or schedules retries. Example 13 deliberately
moves review cases to Review and completes processing; it does not leave them pending.
After human correction move back to the intended folder, then remove phore_processed last.
The next run resolves again. pass() delegates only within the current chain and never reverses
the resolver's already persisted learning. Marked messages never reach resolution/handlers,
so there is no Processed or Skipped ContactResolutionStatus. [neu]

For Sent, this result describes recipient lookup before handlers, not incoming reply verification.
A sole known external recipient yields KnownAddress/contact; a sole unknown recipient yields
Unknown/null. Zero or multiple external recipients yield Unknown/null without review by default;
recipientContacts holds each individual lookup for applications that need it.
An explicit createContactForRecipient() returns the created/reused Contact; callers use that
return value, not the pre-handler resolution snapshot, to inspect the creation.
See example 04. Other folders follow the incoming lookup rules for received correspondence. [neu]

## § 7 Contacts, IDs, classification and interfaces

Contact exposes id, nullable name, primaryEmail, aliases and metadata.
There is no built-in classification field or classify() method on Contact or ContactStore.
Applications may store metadata.set('classification', 'b2b') and explicitly read that key
for routing, or put domain methods on their own MetadataBag subclass (§ 7.4).
setName(?string $name): void changes only the person's display name, never its ID or alias names.
Reads do not insert. Learning never overwrites established primary/name,
alias display names or application metadata. [geändert]

Contact.aliases is list<ContactAlias> of active addresses including primaryEmail exactly once.
ContactAlias exposes email, nullable name (this address's display name), firstSeenAt,
lastSeenAt, source and alias metadata, with preserved reply evidence as in § 6.
The contact name and each alias name are independent. Primary alias names originate
from the addressed recipient; learned reply alias names originate from that From, or null
when absent. These names are labels, not verification evidence.

The following Contact mutators delegate to its bound ContactStore and persist immediately:
addAlias(string $email, ?string $name = null): ContactAlias,
setAliasName(string $email, ?string $name): void,
setPrimaryEmail(string $email): void and removeAlias(string $email): void.
No save() or engine run is required; the mutated Contact exposes the updated state.
The store exposes the same operations with a leading string $contactId argument,
plus setName(string $contactId, ?string $name): void.
Existing findByEmail/findById/search/createContact remain available. [geändert]

Manual addAlias creates source=manual with nullable reply evidence, records the addition
time and leaves the primary unchanged. An address already attached to this contact
returns its existing alias unchanged; another contact owning it causes a conflict error.
setAliasName changes only that alias's name; null clears that name. Naming, removal and
primary selection require an existing active alias; unknown addresses cause an error.
Removing the current primary is rejected. Correct an address by explicitly adding the
confirmed replacement, optionally selecting it as primary, then removing the old alias.
Removal retires the active mapping, preserves historical evidence and does not rewrite
past message associations. Re-adding starts a new active association with its own provenance;
a later qualifying reply may learn the removed address again, so removal is not a blocklist.

Manual contact edits are an explicit application decision, not a bypass used by the
automatic unknown-incoming path. Store conflicts/validation failures propagate and leave
that failing operation unchanged; no transaction over a sequence of edits is promised.
After any successful mutation a handler cannot pass(). None of these changes edits mail,
reruns processed messages or changes an existing contact ID. Example 12 shows a deliberate
administrative workflow using the same SqliteStorage, without creating an unknown contact.

Default ID: <name-slug>-e<8 random characters>, e.g. anna-mueller-e7k3p9x2r.
Use secure random sampling from 23456789abcdefghjkmnpqrstuvwxyz. Enforce uniqueness
in the store, retry collisions at most 10 times, then fail clearly. IDs are not secrets.

Slug: trim/lowercase, German umlauts to ae/oe/ue, ß to ss, deterministic transliteration,
non-alphanumeric runs to hyphens, trim hyphens, prefix capped at 48 characters.
Require three surviving alphanumeric characters for the name. Otherwise use email
local part; if too short, append domain. If nothing usable survives, use contact.
A valid primary email is required. Preserve the original supplied display name.

| Name / address | Example ID |
|---|---|
| Anna Müller / anna@example.org | anna-mueller-e7k3p9x2r |
| Another Anna Müller | anna-mueller-e9r4m6w8k |
| No name / anna.mueller@example.org | anna-mueller-e5n8h2r7k |
| A / anna@example.org | anna-e8p4k7m3r |
| No name / a@example.org | a-example-org-e6w2n9h4k |

ContactIdGenerator::generate(?string $displayName, string $primaryEmail): string is
injectable. Custom IDs must match [a-z0-9]+(?:-[a-z0-9]+)* and be at most 128 characters.
Search covers current names, every address and IDs. IDs never change on rename,
primary changes or added aliases. Lowercase address domains but preserve local parts;
do not silently strip plus tags or dots.

AutomationStateStore loads/saves FolderState (cursor, bootstrap state, pending and
deferred locations) per folder within the bound client. MailHistoryStore indexes observed Sent,
records incoming metadata with nullable contact ID and provides forContact(id, limit: 50).
Historical records retain evidence and can link previously unknown conversation
entries after resolution; unrelated unknown messages stay unassigned. Full bodies
and attachments are not archived by default.

SqliteContactStore returns contact metadata with resolved contacts, not a
second mandatory application lookup. Custom AutomationStorage may return contacts backed
by a CRM. A custom ContactIdGenerator does not require reimplementing any store.
The constructor's optional idGenerator parameter applies to PDO-backed storage;
supplying it with an already-built storage is rejected (configure that storage itself). [geändert]

### § 7.1 Contact, thread, message and mailbox metadata

Contact denotes an external correspondent, not a local login. Its stable ID identifies
the same contact across aliases and threads. Thread identity is independent of contact
identity: one thread may have several participants, and one contact may have many threads.
A message without a known contact still receives a thread. MailContext is the current
message's context; context.mailbox provides an explicit path to the account-wide services.

The same extensible MetadataBag base class is used at context.metadata, context.contact.metadata
when a contact exists, context.thread.metadata and context.mailbox.metadata.
get(string $key): mixed returns null for a missing key; set(string $key, mixed $value): void
immediately persists a JSON-compatible value in that one scope. Stored null is allowed
and is indistinguishable from an absent key through get(). Writes replace that key only;
there is no inheritance or automatic copying between scopes. Reserved system evidence
and processed keywords are not stored in these application bags; classification is an
ordinary application key with no reserved semantics.
set() failures propagate as errors. After a handler writes any metadata it must not pass();
complete() does not perform an additional metadata commit. [geändert]

All application metadata bags are opaque to the engine: storing reviewed=true, status=reviewed
or any other key has no internal processing, scheduling, identity-learning or eligibility effect.
Only application filters/individual logic may interpret these values. reviewed in example 06
records that application's review decision; it is not an engine review state or a processed flag.
phore_processed remains the sole processing gate independently of metadata.

Contact metadata can hold customerNumber or lastReviewedCase. Thread metadata can hold
caseId and status. Message metadata can hold reviewed or a classification result for
that specific message. Mailbox metadata can hold lastReviewedThread or lastExportAt.
A later reply shares its resolved contact/thread bags but starts with fresh message
metadata. These values live in the configured store, never automatically in headers
or IMAP keywords; moving to another server does not transfer them without store migration.

MetadataStore exposes forContact(string $id), forThread(string $id), forMessage(string $id)
and forMailbox(), each returning MetadataBag. Scope plus stable owner ID determines the
storage key; identical application keys in different scopes cannot collide. MailContext.id
is the internal stored message-copy ID, distinct from the RFC Message-ID. ThreadStore
persists stable opaque thread IDs and membership. Engine-created context objects attach
the appropriate bags automatically. MailboxContext.contacts and .mailHistory use the
same contacts()/history() returned by AutomationStorage, not independent connections.

### § 7.2 Thread access and resolution

MailThread exposes id, metadata and messages(int $limit = 50): list<MailHistoryEntry>.
messages() returns observed members of this thread, including the current message,
in chronological order with a stable internal-ID tie break; if limited, return the most
recent limit entries in that order. limit must be positive. Entries provide recorded
message references and summary data, not guaranteed bodies or a complete remote archive.
A one-message thread therefore returns that message. Account-wide mailHistory.forContact()
can span several threads and may return an empty list; it is not the current conversation.

Resolve exact In-Reply-To references first, then an unambiguous References ancestor.
Never combine threads just by Subject or matching contact. If no predecessor is known,
start a singleton thread for the current message. If later evidence would combine two
already assigned thread IDs, preserve them separately and report the candidate link for
explicit reconciliation. This rule is independent of whether their metadata bags are empty;
application metadata must not influence the engine's thread resolution. Reconciliation
must explicitly decide the retained ID and preserve both bags, without silently overwriting
application values. Subsequent messages in a resolved thread use its stable ID regardless
of folder. A thread link alone never authorizes contact/alias learning; the independent
live Sent verification from § 6 still applies.

### § 7.3 Moves, copies and metadata identity

The storage tracks concrete message copies with internal IDs and maps their live
locations using account, folder, UIDVALIDITY and UID. A verified same-account move
performed by the engine preserves the message ID and metadata using authoritative
source/destination mapping. A proven external move may also rebind that identity.
A copy receives a new message-copy ID with an empty message bag; resolved contact
and thread references may stay shared. Preserved phore_processed still locks the copy
even though its new message metadata is empty: metadata is not an eligibility gate.

Neither RFC Message-ID nor a matching body alone proves which copy moved. If manual
moves, UIDVALIDITY resets or migrations cannot be reconciled unambiguously, retain
the old metadata under its old identity and report the unresolved mapping; never attach
it to a guessed destination. A newly observed copy can have empty message metadata
while awaiting explicit reconciliation. The general processed gate and migration-review
behavior from § 4 remain in force. Thread/contact IDs do not depend on folder UIDs.
The future implementation must provide reliable mapping or explicit review for these
cases before claiming transparent metadata preservation across arbitrary manual moves.

### § 7.4 Typed application metadata

The optional constructor arguments contactMetadata, messageMetadata, threadMetadata and
mailboxMetadata accept class-string<T> for instantiable subclasses of MetadataBag; each defaults
to MetadataBag::class. Pass a class, not a mutable prototype shared by different owners.
Subclasses inherit the bag's storage binding/constructor and must not require application
constructor dependencies. Initialization rejects invalid types before processing.
The engine constructs each view over that owner's existing scoped storage; it does not
serialize the PHP object. Custom methods use get()/set(); arbitrary properties are not persisted.
Changing a view class neither rewrites stored keys nor changes contact/thread/message IDs. [neu]

With PDO, MailAutomation configures these types on its internally created SqliteStorage.
Explicit SqliteStorage accepts the same optional type arguments for use outside handlers.
With an already-built AutomationStorage, configure its metadata types on that storage;
additional type arguments on MailAutomation are rejected, as with idGenerator.
All context objects, resolver results, contacts returned by mailbox.contacts and outgoing
creation helpers must use the same configured contact view. Raw values remain JSON-compatible
and the engine interprets none of their application meanings. [neu]

The proposed PHPDoc generic order is MailAutomation<TContact, TMessage, TThread, TMailbox>
and MailContext<TContact, TMessage, TThread, TMailbox>, each bounded by MetadataBag.
Contact<TContact>.metadata and ContactResolution<TContact>.contact retain the contact type;
MailThread<TThread>.metadata and MailboxContext<TContact, TMailbox> retain their respective
types and typed contacts. Context.metadata uses TMessage. Storage/registry annotations must
propagate these relationships for both programmatic and attributed handlers.
Example 14 explicitly annotates its named handler and binds CustomerMetadata::class at runtime.
PHPDoc does not instantiate types or enforce native generics; automatic inference across
reflection-based registration is not assumed and must be validated when implementing the API. [neu]

## § 8 Actions involving outgoing mail and contact suggestions

The existing package creates drafts but exposes no SMTP send API. For form-mail
notifications, the proposed MailActions::createReplyDraft(markdown) prepares and saves
a reply draft in the configured Drafts folder; it does not send it or create a contact.
It respects the source reply target and does not silently use a contact's primary alias.

Optional sending is explicit: configure a DraftSender adapter and return
MailActions::sendReply(markdown). The engine prepares the draft and hands it to
DraftSender::send(Email $draft): void. Without that adapter the action fails.
The adapter owns transport and saving the final transmitted message in Sent, including
any rewritten Message-ID. The newly sent message is a separate message, initially
without phore_processed; it does not inherit the source message's automation marker.
Outgoing rules can process it on a later Sent synchronization. This proposal
does not introduce a concrete SMTP implementation or claim draft saving is sending.

For automated form notifications, sender matching is a routing predicate, not proof
of trust. Treat form content as untrusted. A draft can request human review; AI output
is a suggestion and does not automatically attach submitted addresses as contact aliases.
Send actions are deliberately explicit to avoid accidental automatic responses.

## § 9 Examples and validation

See the [scenario index](../examples/proposed-automation/README.md) and fourteen numbered
application excerpts. The entry shows one complete default routing case. Subsequent
files build on introduced concepts, explicitly replace or extend known code, and show
outgoing creation, resolver binding, metadata, forms, attributes and advanced adapters.
Shared type names, prerequisites and design status appear once in the index. The PHP
fragments start with <?php on the first line for PHP recognition, omit additional
wrappers/imports and are not standalone executable files. [geändert]

Examples separate fixture outcomes from handler conditions, omit redundant type assertions
and optional defaults, and show when changes occur immediately or on a later run.
Example 06 is a complete attributed handler with registration/run, showing contact ID,
thread messages and four metadata scopes, when contact exists, what contactResolution means,
reading all aliases and how application metadata feeds the routing in 03. Example 12 shows explicit
contact and alias edits with immediate persistence. Global stores are reached through context.mailbox;
explicit SqliteStorage in examples 09/12 shows access outside handlers. External ID/storage/sender services have named application origins.
Example 13 explains every contact-resolution case with a registered handler and outcomes;
example 14 binds an application metadata class and shows its methods in a typed handler.
The concrete public RunReport fields/error-access API remain unspecified and must be
defined before runnable error-handling examples can be delivered. [geändert]

Future implementation needs side-effect-free reading, permanent keyword checks,
general same-account moves, Sent lookup and identity/header access, SQLite tables,
the rule registry, thread resolution, scoped metadata stores and optional sending adapter. This PR adds only documentation and
example source files. Static review is not execution evidence.

Protocol references:
- [Sync PR #5](https://github.com/phore/phore-mailclient/pull/5)
- [IMAP keywords and COPY/MOVE](https://www.rfc-editor.org/rfc/rfc9051.html)
- [Reply headers](https://www.rfc-editor.org/rfc/rfc5322.html)
- [Thunderbird tags](https://support.mozilla.org/en-US/kb/message-tags)
