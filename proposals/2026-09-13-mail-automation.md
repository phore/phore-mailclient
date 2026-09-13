# MailAutomation: incoming, sent mail and user identity

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
users, aliases, metadata and history. No caller-written factory or interface is needed.
The parent directory must exist; PDO creates the file. Initialization rejects incompatible
newer schemas and never silently destroys existing state.

Advanced callers can pass AutomationStorage directly. Non-SQLite PDO fails clearly
with instructions to supply that interface. SqliteStorage is an optional explicit
implementation; it composes SqliteAutomationState, SqliteAliasStore and
SqliteMailHistoryStore over one connection. Custom UserIdGenerator and DraftSender
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

AutomationStorage provides state(), users() and history() through their respective
interfaces. One storage instance belongs to this one client/account, not an implicit
multi-account namespace. Bind persisted state to the client's account identity and
reject a foreign client using that state; a deliberate migration requires review.
User IDs are unique within the store. Repeated runs with the same client reuse state.
Configuration is fixed for the lifetime of an automation instance.

Optional identity: new ReplyIdentityResolver() explicitly supplies the default identity
strategy. When omitted, MailAutomation uses the same strategy internally. The engine
binds it to the supplied client and storage's users/history before any rule evaluation.
No second connection, independent database or application-written factory is needed.
The resolver learns users/aliases from verified replies by default; ID generation
belongs to the shared store and its UserIdGenerator, not to handlers. A configured
resolver instance is bound to one automation only; using it unbound for direct learning
fails clearly. Custom strategies implement IdentityResolver with bind(MailClient,
AutomationStorage): void and resolve(Email): IdentityResult. Binding occurs once;
resolve performs incoming lookup/allowed learning before predicates and handlers, only for messages admitted by the processed gate.
Outgoing contexts only look up recipients; creation remains an explicit outgoing action. ReplyIdentityResolver
also retains learnAliasFromReply(Email) for explicit specialized use after binding.

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
marker resets are eligible; a folder event is not proof of new delivery. [geändert]

Every handler returns MailActions: complete() explicitly finishes without further
mail actions; pass() declines responsibility without setting phore_processed.
create() builds an action list for the current message; successful execution finishes
the chain, except the documented deferred reprocess handoff. An empty create() list
also finishes; use complete() to express that intent directly. complete()/pass() are
standalone results and cannot be combined with queued actions. null, missing returns
and exceptions are errors, not delegation. There is no none() result. [neu]

Handlers must decide to pass before any mail mutation, metadata write, user creation
or external side effect. Such changes cannot be rolled back by pass(); this is a handler
contract, not a promised transaction around arbitrary application code. Identity enrichment
occurs once before this chain and remains available to later handlers; pass() does not
undo that engine step or trigger it again. If every active rule skips or passes, the
engine marks the message processed as checked. A handler/action failure instead stops
the chain and keeps work pending; no fallback handler executes after a failure. [neu]

First check the current phore_processed keyword. Marked messages skip identity learning,
predicates, handlers and mail actions. For eligible incoming messages, resolve a known From or learn identity from a verified
outgoing reply link. MailContext exposes user (?MailUser), users (AliasStore),
history (MailHistoryStore), identity (IdentityResult), folder and direction.
For outgoing mail, user is the already known sole external recipient, not ourselves;
recipientUsers exposes each external recipient mapped to a user or null. For multiple
recipients, user=null; applications must explicitly address each recipient.

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
not a user ID. Without it, class attributes/invokable handlers use the class short name;
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
the next run; no dynamic switching API is required for V1. [geändert]

## § 4 Processed flags, moves and reprocessing

phore_processed is the single global automation gate for every folder, including
Inbox, Sent, Drafts, Trash and Junk, and every keyword-conditioned rule. If present,
MailAutomation performs no identity learning, predicates, handlers or mail mutations
for that message. A successful action list, MailActions::complete() or an exhausted
chain (all rules skipped or passed) sets this same keyword. pass() alone never sets it. There is no separate outgoing completion marker. [geändert]

Synchronization may still read flags/locations and maintain cursors. Sent evidence is
still read and indexed, including processed Sent messages, so an eligible incoming
reply can be checked against them. This indexing does not create users or aliases
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
Unmarked failures remain pending independently of the sync cursor. [geändert]

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

## § 5 Sent observation and when a user is created

Monitoring the configured Sent folder is the standard source of outgoing evidence.
Index final Message-ID, full intended recipient set (To/Cc and Bcc when retained),
recipient display names, configured sender, sent timestamp and live folder location.
Only messages sent from client's configured sender address qualify. Copied Sent entries are
observable records, not independent SMTP-delivery proof; this design deliberately
trusts the configured Sent folder. Drafts never count.

The default is creation on the first qualifying, unprocessed reply. Sent observation alone stores
recipient evidence but no MailUser. An outgoing rule may call
context.createUserForRecipient(?string $email = null): MailUser to enable creation at
the time outgoing mail is observed. This works only in outgoing context. With one
external recipient the argument is optional; with several it is required and must
match an actual recipient. Existing users are reused without overwriting their fields.

The incoming context's user=null is normal for unsolicited unknown mail. There is no
automatic user creation merely for receiving, marking, moving or drafting a response.
If we answer unknown mail, our reply is observed in Sent: an outgoing rule may create
the user then, otherwise wait until they answer that outgoing reply.

| Scenario | Default | With outgoing creation rule |
|---|---|---|
| Message already has phore_processed | No business processing or identity learning | Same; Sent indexing remains allowed |
| Unknown incoming, no reply link | user=null; no insertion | Same |
| New outgoing to unknown recipient | Index pending recipient | Rule creates addressed recipient |
| First verified reply | Create/find recipient user; learn From alias | Reuse user; learn From alias |
| Known From, no reply headers | Return known user | Same |
| Referenced outgoing no longer exists | No new user or alias | No new user or alias |
| Outgoing baseline on first setup | Index and mark processed, no user creation | Same unless explicitly opted into rules |

The primary address is the originally addressed recipient; the reply's different
From is an alias. Existing primary never changes automatically. If recipient name is
missing, use a usable first-reply display name under this conversation-trust policy,
otherwise the email slug fallback. Creation on outgoing observation without a name
uses the fallback immediately. Later name changes never change the ID.

## § 6 Live reply verification and alias learning

ReplyIdentityResolver::learnAliasFromReply(Email $email): IdentityResult
is the explicit learning method used by the default resolver's resolve operation.
MailAutomation invokes its bound strategy only after the processed gate admits the
incoming message, before predicates/handlers, and assigns
IdentityResult to context.identity and its user to context.user. Handlers need no resolver call.
It returns status (Unknown, KnownAddress, UserCreated, AliasAdded, Conflict,
OutgoingMissing), nullable user, matched outgoing evidence and aliasAdded.
needsReview() is true for Conflict and OutgoingMissing; isConflict() only for Conflict.

Inspect exact In-Reply-To IDs first. Resolve conflicting direct targets as Conflict;
do not pick an older References entry to escape a conflict or a missing direct target.
If In-Reply-To is absent, inspect References for the nearest unambiguous verified
outgoing ancestor. Do not treat Subject, quoted text, From display name or incoming
Reply-To as identity evidence. Own addresses, bounces and auto-generated replies
are excluded from automatic user creation and alias learning.

Before EVERY new user or alias from a reply, verify the corresponding outgoing message
currently exists in that client's configured Sent folder. An index entry alone is
insufficient. Re-read exact Message-ID, own sender and recipients using current
UIDVALIDITY. If its location is stale, search Sent by Message-ID then compare exact
values (IMAP header search may be substring based). If Sent indexing lagged, perform
that same live search rather than prematurely calling the sender unknown.
Zero matches yields OutgoingMissing; ambiguous duplicate matches yield Conflict;
network/permission errors are errors, never successful verification.

V1 learns only from a unique outgoing record with exactly one external recipient.
A multi-recipient or mismatched record is Conflict; no automatic user merges.
If any candidate address already belongs to another user, all evidence must agree;
otherwise return Conflict. Known From users can still be exposed in user, but
identity.status must reveal conflicting reply evidence and prevent new learning.

For pending recipients, first look up their address again: separate outgoing messages
to the same recipient converge on one user. The original recipient becomes primary,
the reply From becomes an alias. Record firstSeenAt, lastSeenAt, source, incoming
and outgoing Message-IDs and alias metadata. Re-observation preserves first evidence,
updates lastSeenAt and appends history. Manual metadata edits never rewrite evidence.

A referenced mail existing in Sent establishes conversation linkage, not proof that
the reply author is the same natural person. A colleague may answer a forwarded mail,
and headers can be fabricated. This is accepted automatic contact-learning behavior,
not authentication or permission to disclose sensitive data.

## § 7 Users, IDs, classification and interfaces

MailUser exposes id, nullable name, primaryEmail, aliases, metadata and classification.
classification is a nullable application-defined string (e.g. b2b or new_contact).
Store-backed setMetadata(key, value) and classify(string) persist explicitly.
setName(?string) changes the display name but never the ID. Reads do not insert.
AliasStore also exposes findByEmail, findById, search, createUser, addAlias,
setPrimaryEmail and removeAlias. Removal of the current primary is rejected.
Metadata is JSON-compatible; a value replaces that key, null is a stored value.
Learning never changes established primary/name/classification or merges metadata.

Default ID: <name-slug>-e<8 random characters>, e.g. anna-mueller-e7k3p9x2r.
Use secure random sampling from 23456789abcdefghjkmnpqrstuvwxyz. Enforce uniqueness
in the store, retry collisions at most 10 times, then fail clearly. IDs are not secrets.

Slug: trim/lowercase, German umlauts to ae/oe/ue, ß to ss, deterministic transliteration,
non-alphanumeric runs to hyphens, trim hyphens, prefix capped at 48 characters.
Require three surviving alphanumeric characters for the name. Otherwise use email
local part; if too short, append domain. If nothing usable survives, use user.
A valid primary email is required. Preserve the original supplied display name.

| Name / address | Example ID |
|---|---|
| Anna Müller / anna@example.org | anna-mueller-e7k3p9x2r |
| Another Anna Müller | anna-mueller-e9r4m6w8k |
| No name / anna.mueller@example.org | anna-mueller-e5n8h2r7k |
| A / anna@example.org | anna-e8p4k7m3r |
| No name / a@example.org | a-example-org-e6w2n9h4k |

UserIdGenerator::generate(?string $displayName, string $primaryEmail): string is
injectable. Custom IDs must match [a-z0-9]+(?:-[a-z0-9]+)* and be at most 128 characters.
Search covers current names, every address and IDs. IDs never change on rename,
primary changes or added aliases. Lowercase address domains but preserve local parts;
do not silently strip plus tags or dots.

AutomationStateStore loads/saves FolderState (cursor, bootstrap state, pending and
deferred locations) per folder within the bound client. MailHistoryStore indexes observed Sent,
records incoming metadata with nullable user ID and provides forUser(id, limit: 50).
Historical records retain evidence and can link previously unknown conversation
entries after resolution; unrelated unknown messages stay unassigned. Full bodies
and attachments are not archived by default.

SqliteAliasStore returns user metadata/classification with resolved users, not a
second mandatory application lookup. Custom AutomationStorage may return users backed
by a CRM. A custom UserIdGenerator does not require reimplementing any store.
The constructor's optional idGenerator parameter applies to PDO-backed storage;
supplying it with an already-built storage is rejected (configure that storage itself).

## § 8 Actions involving outgoing mail and contact suggestions

The existing package creates drafts but exposes no SMTP send API. For form-mail
notifications, the proposed MailActions::createReplyDraft(markdown) prepares and saves
a reply draft in the configured Drafts folder; it does not send it or create a contact.
It respects the source reply target and does not silently use a user's primary alias.

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
is a suggestion and does not automatically attach submitted addresses as user aliases.
Send actions are deliberately explicit to avoid accidental automatic responses.

## § 9 Examples and validation

See the [scenario index](../examples/proposed-automation/README.md) and eleven numbered
application excerpts. The entry shows one complete default routing case. Subsequent
files build on introduced concepts, explicitly replace or extend known code, and show
outgoing creation, resolver binding, metadata, forms, attributes and advanced adapters.
Shared type names, prerequisites and design status appear once in the index. The PHP
fragments start with <?php on the first line for PHP recognition, omit additional
wrappers/imports and are not standalone executable files.

Examples separate fixture outcomes from handler conditions, omit redundant type assertions
and optional defaults, and show when changes occur immediately or on a later run.
Metadata examples obtain stores from the actual MailContext; explicit SqliteStorage shows
access outside handlers. External ID/storage/sender services have named application origins.
The concrete public RunReport fields/error-access API remain unspecified and must be
defined before runnable error-handling examples can be delivered.

Future implementation needs side-effect-free reading, permanent keyword checks,
general same-account moves, Sent lookup and identity/header access, SQLite tables,
the rule registry and optional sending adapter. This PR adds only documentation and
example source files. Static review is not execution evidence.

Protocol references:
- [Sync PR #5](https://github.com/phore/phore-mailclient/pull/5)
- [IMAP keywords and COPY/MOVE](https://www.rfc-editor.org/rfc/rfc9051.html)
- [Reply headers](https://www.rfc-editor.org/rfc/rfc5322.html)
- [Thunderbird tags](https://support.mozilla.org/en-US/kb/message-tags)
