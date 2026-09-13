# MailAutomation: incoming, sent mail and user identity

| Datum | Benutzername | Kurzbeschreibung |
|---|---|---|
| 2026-09-13 | dermatthes | §§ 1–9: Proposal mit Beispielen angelegt |
| 2026-09-13 | dermatthes | § 9: Examples nach aktueller Coding-Basis-Referenz nummeriert, abgeflacht und direkt kommentiert |
| 2026-09-13 | dermatthes | §§ 2–7, § 9: Eine Client-Verbindung, geerbte Konfiguration, addAutomation, onInboxMessage/onSentMessage und Gesamtbeispiel |
| 2026-09-13 | dermatthes | §§ 2–3, § 6: Resolver initialisieren/einbinden, Kontext anreichern, Folder-Enum, OnFolderAutomation, optionale automationId und active |

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
alongside any added configuration fields. [geändert]

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
resolve performs incoming lookup/allowed learning before predicates and handlers.
Outgoing contexts only look up recipients; creation remains an explicit outgoing action. ReplyIdentityResolver
also retains learnAliasFromReply(Email) for explicit specialized use after binding. [neu]

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
These selectors replace separate onInboxMessage/onSentMessage methods. onFlagAdded
also accepts Folder|string and uses addAutomation. Predicates receive
(Email, MailContext): bool; handlers receive (Email, MailContext): MailActions.
Higher priority wins, ties use registration order. Only the first matching handler
runs per event route. Duplicate IDs/incompatible signatures fail before writes.
Exceptions are failures, never a fall-through. Unmarked observed messages and deliberate
marker resets are eligible; a folder event is not proof of new delivery. [geändert]

Before incoming predicates run, resolve a known From or learn identity from a verified
outgoing reply link. MailContext exposes user (?MailUser), users (AliasStore),
history (MailHistoryStore), identity (IdentityResult), folder and direction.
For outgoing mail, user is the already known sole external recipient, not ourselves;
recipientUsers exposes each external recipient mapped to a user or null. For multiple
recipients, user=null; applications must explicitly address each recipient.

Class instances, invokable classes and attributed function callables are registered
with addRules(object|callable). OnFolderAutomation(folder: Folder::Inbox),
OnFolderAutomation(folder: Folder::Sent) and OnFlagAdded attributes compile to the same rules as builders. Trigger attributes on a class apply
to __invoke; method attributes apply to that method. Constructor dependencies are
provided by the application. Do not register one rule through both mechanisms. [geändert]

The optional parameter is automationId (camelCase, consistent with the PHP API),
not a user ID. Without it, class attributes/invokable handlers use the class short name;
method handlers use ShortClassName::method and named functions their qualified name.
Anonymous closures receive an internal per-registration ID, stable only for that instance;
provide automationId for stable cross-run diagnostics. Duplicate inferred or explicit IDs
fail during registration, including inactive rules; disambiguate with automationId.
Renaming a class changes its inferred ID but does not reset message processed flags. [neu]

Both addAutomation and trigger attributes accept active: bool = true. With false,
the rule is registered but neither its predicate nor handler executes. Other active rules
continue normally. If none matches, normal no-match marking still applies; active: false
is not a folder pause or backlog retention mechanism. Re-enabling affects eligible mail;
already processed messages require an explicit marker reset to run again. Resolver enrichment
and Sent indexing are independent of a rule's active setting. Change configuration before
the next run; no dynamic switching API is required for V1. [neu]

## § 4 Processed flags, moves and reprocessing

Ordinary incoming/folder rules run only without phore_processed. A successful handler
or no-match outcome sets that marker. Outgoing rules use phore_outgoing_processed
so a Sent observation cannot suppress the ordinary incoming/folder workflow.
Sent history indexing occurs even for already marked outgoing messages.

A manual move/copy with phore_processed retained does not restart ordinary processing.
Removing it deliberately schedules the destination chain even for an old UID.
Unmarked messages that fail remain pending in AutomationStateStore independently of
the last cursor. Single-instance does not imply crash-safe retry guarantees.

MailActions::create()->moveTo('Archive') targets an existing folder in the same account;
normal completion leaves phore_processed on the destination for ordinary rules.
Outgoing completion uses its outgoing marker. addFlag/removeFlag alter only that keyword.
MailActions::none() is successful completion without business actions.

moveTo('Invoices', reprocess: true) moves, clears the ordinary processed marker at the
destination and defers ordinary processing to the next run. It is terminal, cannot
target the current folder, and suppresses the normal ordinary completion marker.
Destination ordinary processing requires a registered folder. Deferred work never
executes in the same run, regardless of folder scan order. Cross-folder routing loops
are an application configuration error; avoid cyclic reprocess routes.

onFlagAdded is an explicitly separate event route that can act on processed mail;
it does not implicitly reset the ordinary chain. Marker changes made by the engine
do not themselves trigger business handlers. Route execution for a message is serial;
the engine rechecks current flags/location before applying subsequent actions.

Initial ordinary scans process ALL unmarked existing messages across every page.
Initial Sent scans index all existing outgoing evidence, but baseline existing
messages without running outgoing business rules by default, avoiding accidental
bulk contact creation. The explicit run(processExistingOutgoing: true) option opts into those
rules during the initial Sent scan for unmarked backlog. Persist bootstrap phase across pages: PR #5's
isInitialSync describes only the first page. Existing incoming backlog therefore can
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

The default is creation on the first qualifying reply. Sent observation alone stores
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
| Unknown incoming, no reply link | user=null; no insertion | Same |
| New outgoing to unknown recipient | Index pending recipient | Rule creates addressed recipient |
| First verified reply | Create/find recipient user; learn From alias | Reuse user; learn From alias |
| Known From, no reply headers | Return known user | Same |
| Referenced outgoing no longer exists | No new user or alias | No new user or alias |
| Outgoing baseline on first setup | Index only | Index only unless explicitly opted in |

The primary address is the originally addressed recipient; the reply's different
From is an alias. Existing primary never changes automatically. If recipient name is
missing, use a usable first-reply display name under this conversation-trust policy,
otherwise the email slug fallback. Creation on outgoing observation without a name
uses the fallback immediately. Later name changes never change the ID.

## § 6 Live reply verification and alias learning

ReplyIdentityResolver::learnAliasFromReply(Email $email): IdentityResult
is the explicit learning method used by the default resolver's resolve operation.
MailAutomation invokes its bound strategy before incoming predicates/handlers and assigns
IdentityResult to context.identity and its user to context.user. Handlers need no resolver call. [geändert]
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
any rewritten Message-ID; outgoing rules later observe it normally. This proposal
does not introduce a concrete SMTP implementation or claim draft saving is sending.

For automated form notifications, sender matching is a routing predicate, not proof
of trust. Treat form content as untrusted. A draft can request human review; AI output
is a suggestion and does not automatically attach submitted addresses as user aliases.
Send actions are deliberately explicit to avoid accidental automatic responses.

## § 9 Examples and validation

See the [scenario index](../examples/proposed-automation/README.md) and eleven numbered
application excerpts starting with an end-to-end example, followed by setup, incoming B2B routing, unknown contacts, Sent
rules, reply/alias learning, metadata/classification, sender-specific drafts, attributes,
custom storage, actual sending and manual flag workflows. Each excerpt is flat,
asserts supplied object types before use, explains API elements at first occurrence
and states concrete results. Numbers specify reading order, not execution dependencies.
Only actual registered callbacks/attribute handlers use functions or methods.
Namespaces under Phore\MailClient\Automation are proposed, not currently shipped.

Future implementation needs side-effect-free reading, permanent keyword checks,
general same-account moves, Sent lookup and identity/header access, SQLite tables,
the rule registry and optional sending adapter. This PR adds only documentation and
example source files. Static review is not execution evidence.

Protocol references:
- [Sync PR #5](https://github.com/phore/phore-mailclient/pull/5)
- [IMAP keywords and COPY/MOVE](https://www.rfc-editor.org/rfc/rfc9051.html)
- [Reply headers](https://www.rfc-editor.org/rfc/rfc5322.html)
- [Thunderbird tags](https://support.mozilla.org/en-US/kb/message-tags)
