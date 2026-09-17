<?php
declare(strict_types=1);
namespace Phore\MailClient;

use InvalidArgumentException;
use RuntimeException;
use Phore\MailClient\Internal\Headers;
use Phore\MailClient\Internal\ImapTransport;
use Phore\MailClient\Internal\MessageDefaults;
use Phore\MailClient\Internal\Mime;
use Phore\MailClient\Internal\Reference;
use Phore\MailClient\Internal\Transport;
use Phore\MailClient\Internal\SyncTransport;
use Phore\MailClient\Internal\SyncCursor;

final class MailClient
{
    public const MODE_AUTOMATIC = 'automatic';
    public const MODE_MANUAL = 'manual';
    private const MAX_BYTES = 25_000_000;
    private MessageDefaults $defaults;
    private ?EmailAddress $from;
    private array $automaticActions = [];
    /** @var array<string,string> Application folder alias => exact IMAP folder name. */
    private array $managedFolders = [];

    /** @internal Inject a transport for deterministic tests. Use connect() in applications. */
    public function __construct(
        private Transport $transport,
        private string $account,
        private string $draftsFolder = 'Drafts',
        private string $trashFolder = 'Trash',
        EmailAddress|string|null $from = null,
        array $messageDefaults = [],
        private string $mode = self::MODE_AUTOMATIC,
        private string $incomingFolder = 'INBOX',
        private string $sentFolder = 'Sent',
        private string $junkFolder = 'Junk',
        array $managedFolders = [],
    ) {
        self::validateMode($mode);
        foreach ([$draftsFolder, $trashFolder, $incomingFolder, $sentFolder, $junkFolder] as $folder) {
            Headers::validate($folder);
            if ($folder === '') { throw new InvalidArgumentException('Mailbox folders must be nonempty.'); }
        }
        $this->incomingFolder = strcasecmp($incomingFolder, 'INBOX') === 0 ? 'INBOX' : $incomingFolder;
        $this->from = is_string($from) ? EmailAddress::parse($from) : $from;
        $this->defaults = new MessageDefaults($messageDefaults);
        $this->managedFolders = self::validateManagedFolders($managedFolders);
        $this->provisionManagedFolders();
    }

    public static function connect(
        string $host,
        string $username,
        #[\SensitiveParameter] string $password,
        int $port = 993,
        string $draftsFolder = 'Drafts',
        string $trashFolder = 'Trash',
        EmailAddress|string|null $from = null,
        array $messageDefaults = [],
        string $mode = self::MODE_AUTOMATIC,
        string $incomingFolder = 'INBOX',
        string $sentFolder = 'Sent',
        string $junkFolder = 'Junk',
        array $managedFolders = [],
    ): self {
        Headers::validate($host); Headers::validate($username);
        if ($host === '' || $username === '' || $port < 1 || $port > 65535 || str_contains($host, '://')) { throw new InvalidArgumentException('Invalid IMAP connection settings.'); }
        new MessageDefaults($messageDefaults);
        self::validateMode($mode);
        if (is_string($from)) { $from = EmailAddress::parse($from); }
        return new self(
            new ImapTransport($host,$username,$password,$port),
            hash('sha256',strtolower($host) . ':' . $port . ':' . $username),
            $draftsFolder,
            $trashFolder,
            $from,
            $messageDefaults,
            $mode,
            $incomingFolder,
            $sentFolder,
            $junkFolder,
            $managedFolders,
        );
    }

    public function mode(): string { return $this->mode; }
    public function accountId(): string { return $this->account; }
    public function fromAddress(): ?EmailAddress { return $this->from; }
    public function folder(MailboxFolder $folder): string
    {
        return match ($folder) {
            MailboxFolder::Inbox => $this->incomingFolder,
            MailboxFolder::Sent => $this->sentFolder,
            MailboxFolder::Drafts => $this->draftsFolder,
            MailboxFolder::Trash => $this->trashFolder,
            MailboxFolder::Junk => $this->junkFolder,
        };
    }

    /** Resolve an application folder alias declared under mailbox config managedFolders. */
    public function managedFolder(string $alias): string
    {
        if (!array_key_exists($alias, $this->managedFolders)) {
            throw new InvalidArgumentException(sprintf('Unknown managed folder alias "%s". Define it in mailbox config "managedFolders".', $alias));
        }
        return $this->managedFolders[$alias];
    }

    /** @return array<string,string> Configured managed folder aliases and exact IMAP names. */
    public function managedFolders(): array { return $this->managedFolders; }

    /** Changes only future operations. Global changes reset per-action overrides. */
    public function setAutomaticMode(bool $enabled, ?string $action = null): self
    {
        if ($action === null) {
            $this->mode = $enabled ? self::MODE_AUTOMATIC : self::MODE_MANUAL;
            $this->automaticActions = [];
        } else {
            self::validateAction($action); $this->automaticActions[$action] = $enabled;
        }
        return $this;
    }

    public function isAutomatic(?string $action = null): bool
    {
        if ($action !== null) { self::validateAction($action); }
        return $action === null ? $this->mode === self::MODE_AUTOMATIC : ($this->automaticActions[$action] ?? $this->mode === self::MODE_AUTOMATIC);
    }

    private static function validateAction(string $action): void
    { if (!in_array($action,['seen','answered','forwarded'],true)) { throw new InvalidArgumentException('Unknown automatic action.'); } }

    private static function validateMode(string $mode): void
    { if (!in_array($mode,[self::MODE_AUTOMATIC,self::MODE_MANUAL],true)) { throw new InvalidArgumentException('Mode must be automatic or manual.'); } }

    /** @return array<string,string> */
    private static function validateManagedFolders(array $folders): array
    {
        if (array_is_list($folders) && $folders !== []) { throw new InvalidArgumentException('Managed folders must be an alias-to-folder mapping.'); }
        foreach ($folders as $alias => $folder) {
            if (!is_string($alias) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $alias)) { throw new InvalidArgumentException('Invalid managed folder alias: ' . (string)$alias . '.'); }
            if (!is_string($folder) || $folder === '') { throw new InvalidArgumentException('Invalid managed folder name for alias: ' . $alias . '.'); }
            Headers::validate($folder);
            if (strcasecmp($folder, 'INBOX') === 0) { $folders[$alias] = 'INBOX'; }
        }
        return $folders;
    }

    private function provisionManagedFolders(): void
    {
        foreach ($this->managedFolders as $alias => $folder) {
            try { $exists = $this->transport->folderExists($folder); }
            catch (\Throwable $error) { throw new RuntimeException(sprintf('Unable to check managed folder "%s" for alias "%s".', $folder, $alias), 0, $error); }
            if ($exists) { continue; }
            try { $this->transport->createFolder($folder); }
            catch (\Throwable $error) { throw new RuntimeException(sprintf('Unable to create managed folder "%s" for alias "%s".', $folder, $alias), 0, $error); }
        }
    }

    public function listNew(?string $after = null, int $limit = 50): EmailBatch
    {
        if ($limit < 1 || $limit > 500) { throw new InvalidArgumentException('Limit must be 1–500.'); }
        $cursor = $after === null ? null : Reference::decode($after, $this->account, true);
        if ($cursor !== null && $cursor->folder !== $this->incomingFolder) { throw new InvalidArgumentException('Cursor belongs to another folder.'); }
        $status = $this->transport->select($this->incomingFolder);
        if ($cursor !== null && $cursor->validity !== (int)$status['uidvalidity']) { throw new RuntimeException('Stale UIDVALIDITY: explicit resynchronization required.'); }
        $last = $cursor?->uid ?? 0; $emails = [];
        $ids = array_values(array_filter($this->transport->search(['after'=>$last]), static fn(int $uid): bool => $uid > $last));
        sort($ids, SORT_NUMERIC);
        foreach (array_slice($ids,0,$limit) as $uid) {
            $reference = new Reference($this->account,$this->incomingFolder,(int)$status['uidvalidity'],$uid);
            $emails[] = $this->get($reference->encode()); $last = $uid;
        }
        return new EmailBatch($emails,(new Reference($this->account,$this->incomingFolder,(int)$status['uidvalidity'],$last))->encode());
    }

    /**
     * Observe one folder without changing flags, even in automatic mode.
     * A null cursor reports all existing messages as added, in batches.
     * Persist nextCursor only after processing the complete result.
     */
    public function syncFolder(string $folder, ?string $cursor = null, int $limit = 50): FolderChanges
    {
        Headers::validate($folder);
        if ($folder === '' || $limit < 1 || $limit > 500) { throw new InvalidArgumentException('Provide a nonempty folder and a limit of 1–500.'); }
        if (strcasecmp($folder, 'INBOX') === 0) { $folder = 'INBOX'; }
        $previous = $cursor === null ? null : SyncCursor::decode($cursor, $this->account, $folder);
        if (!$this->transport instanceof SyncTransport) { throw new RuntimeException('Transport does not support folder synchronization.'); }
        $status = $this->transport->select($folder);
        $validity = (int)$status['uidvalidity'];
        if ($previous !== null && $previous->validity !== $validity) { throw new SyncResetRequired($folder); }
        $uids = $this->transport->search([]);
        if (count($uids) > SyncCursor::MAX_MESSAGES) { throw new RuntimeException('Folder synchronization supports at most 10000 messages per folder.'); }
        $current = [];
        foreach (array_chunk($uids, 100) as $chunk) {
            foreach ($this->transport->syncFlags($chunk) as $uid => $flags) {
                if (!is_int($uid) || !in_array($uid, $chunk, true)) { throw new RuntimeException('Unexpected UID in synchronization response.'); }
                $current[$uid] = SyncCursor::flags($flags);
            }
        }
        ksort($current, SORT_NUMERIC);
        $known = $previous?->flags ?? [];
        $events = [];
        foreach (array_diff_key($known, $current) as $uid => $_) { $events[] = ['removed', $uid]; }
        foreach ($current as $uid => $flags) {
            if (!array_key_exists($uid, $known)) { $events[] = ['added', $uid]; }
            elseif (!SyncCursor::sameFlags($known[$uid], $flags)) { $events[] = ['flags', $uid]; }
        }
        $added = []; $changed = []; $removed = [];
        foreach (array_slice($events, 0, $limit) as [$kind, $uid]) {
            $id = (new Reference($this->account, $folder, $validity, $uid))->encode();
            if ($kind === 'removed') { $removed[] = $id; unset($known[$uid]); }
            elseif ($kind === 'flags') {
                $changed[] = new FlagChange($id, $known[$uid], $current[$uid]);
                $known[$uid] = $current[$uid];
            } else {
                $email = $this->read($id);
                $known[$uid] = SyncCursor::flags($email->flags());
                $added[] = $email;
            }
        }
        $finalStatus = $this->transport->select($folder);
        if ((int)$finalStatus['uidvalidity'] !== $validity) { throw new SyncResetRequired($folder); }
        return new FolderChanges(
            folder: $folder, added: $added, flagsChanged: $changed, removed: $removed,
            nextCursor: (new SyncCursor($this->account, $folder, $validity, $known))->encode(),
            hasMore: count($events) > $limit, isInitialSync: $previous === null,
        );
    }

    public function get(string $id): Email
    {
        $email = $this->read($id);
        return $this->isAutomatic('seen') && !in_array('\\Seen',$email->flags(),true) ? $this->markRead($email) : $email;
    }

    /** Read one message without automatic flag changes. */
    public function peek(string $id): Email { return $this->read($id); }

    private function read(string $id): Email
    {
        $ref = Reference::decode($id,$this->account); $this->selectReference($ref);
        $meta = $this->transport->metadata($ref->uid);
        if (($meta['UID'] ?? 0) != $ref->uid) { throw new RuntimeException('Message no longer exists.'); }
        $headers = Headers::parse($this->transport->part($ref->uid,'HEADER',256_000));
        $text = null; $html = null; $attachments = []; $budget = self::MAX_BYTES;
        foreach (Mime::leaves($meta['BODYSTRUCTURE'] ?? []) as $part) {
            if ($part['attachment'] || !in_array($part['type'],['text/plain','text/html'],true)) {
                $attachments[] = Attachment::remote($part['filename'] ?? 'attachment', $part['type'], $part['size'], $id, $part['part'], $part['encoding'], $part['cid']);
                continue;
            }
            if ($part['size'] > $budget) { throw new RuntimeException('Message body exceeds byte limit.'); }
            $raw = $this->transport->part($ref->uid,$part['part'],$budget); $budget -= strlen($raw);
            $decoded = Mime::decode($raw,$part['encoding'],$part['charset']);
            if ($part['type'] === 'text/plain') { $text = ($text ?? '') . $decoded; } else { $html = ($html ?? '') . $decoded; }
        }
        return Email::received($headers,new Body($text,$html),$attachments,$id,$meta['FLAGS'] ?? []);
    }

    /** @return resource Caller owns and closes the returned stream. */
    public function openAttachment(Email $email, Attachment $attachment, int $maxBytes = 10_000_000)
    {
        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES) { throw new InvalidArgumentException('Invalid attachment byte limit.'); }
        if (!in_array($attachment,$email->attachments(),false)) { throw new InvalidArgumentException('Attachment does not belong to this Email.'); }
        $bytes = $this->attachmentBytes($attachment,$maxBytes);
        $stream = fopen('php://temp','w+b');
        if ($stream === false) { throw new RuntimeException('Cannot allocate attachment stream.'); }
        fwrite($stream,$bytes); rewind($stream); return $stream;
    }

    private function attachmentBytes(Attachment $attachment, int $maxBytes): string
    {
        if ($attachment->content !== null) { $bytes = $attachment->content; }
        else {
            $ref = Reference::decode($attachment->sourceId ?? '',$this->account); $this->selectReference($ref);
            $meta = $this->transport->metadata($ref->uid); $found = false;
            foreach (Mime::leaves($meta['BODYSTRUCTURE'] ?? []) as $part) {
                if ($part['part'] === $attachment->part && $part['size'] === $attachment->size && $part['encoding'] === $attachment->encoding && $part['type'] === $attachment->mediaType()) { $found = true; break; }
            }
            if (!$found) { throw new RuntimeException('Attachment descriptor is stale or invalid.'); }
            $wireLimit = $maxBytes * 4 + 1024;
            if ($attachment->size > $wireLimit) { throw new RuntimeException('Attachment exceeds byte limit.'); }
            $bytes = Mime::decode($this->transport->part($ref->uid,$attachment->part,$wireLimit),$attachment->encoding);
        }
        if (strlen($bytes) > $maxBytes) { throw new RuntimeException('Attachment exceeds byte limit.'); }
        return $bytes;
    }

    private function materialize(Email $email): array
    {
        $budget = self::MAX_BYTES; $files = [];
        foreach ($email->attachments() as $a) {
            $bytes = $this->attachmentBytes($a,$budget); $budget -= strlen($bytes);
            $files[] = Attachment::fromBytes($a->filename(),$a->mediaType(),$bytes,$a->contentId);
        }
        return $files;
    }

    public function saveDraft(Email $email): Email
    {
        $resolved = $email->resolve($this->from,$this->defaults);
        $action = $resolved->sourceAction() === 'reply' ? 'answered' : 'forwarded';
        $origin = $this->isAutomatic($action) && $resolved->sourceId() !== null ? $this->read($resolved->sourceId()) : null;
        if ($resolved->id() !== null) { $this->selectReference(Reference::decode($resolved->id(),$this->account)); }
        $files = $this->materialize($resolved); $mime = Mime::build($resolved,$files);
        if (strlen($mime) > self::MAX_BYTES * 2) { throw new RuntimeException('Draft exceeds byte limit.'); }
        $fingerprint = Mime::fingerprint($resolved,$files);
        $find = function () use ($resolved): array {
            $status = $this->transport->select($this->draftsFolder);
            $matches = [];
            foreach ($this->transport->search(['messageId'=>$resolved->messageId()]) as $uid) {
                $existing = $this->read((new Reference($this->account,$this->draftsFolder,(int)$status['uidvalidity'],$uid))->encode());
                if ($existing->messageId() === $resolved->messageId()) { $matches[] = $existing; }
            }
            return $matches;
        };
        try {
            $matches = $find();
            if ($matches === []) {
                $this->transport->select($this->draftsFolder,true);
            }
        } catch (\Throwable $error) {
            throw new RuntimeException(
                sprintf('Unable to access configured drafts folder "%s" while saving draft.', $this->draftsFolder),
                0,
                $error
            );
        }
        if ($matches === []) {
            $this->transport->append($this->draftsFolder,$mime);
            try {
                $matches = $find();
            } catch (\Throwable $error) {
                throw new RuntimeException(
                    sprintf('Unable to access configured drafts folder "%s" while saving draft.', $this->draftsFolder),
                    0,
                    $error
                );
            }
        }
        if (count($matches) !== 1) { throw new RuntimeException('Draft identity is ambiguous or APPEND outcome is unknown; resynchronize.'); }
        $stored = $matches[0];
        if (Mime::fingerprint($stored,$this->materialize($stored)) !== $fingerprint) { throw new RuntimeException('Message-ID content conflict; existing draft was not overwritten.'); }
        if ($origin !== null) {
            try {
                if ($resolved->sourceAction() === 'reply') { $this->markAnswered($origin); }
                elseif ($resolved->sourceAction() === 'forward') { $this->markForwarded($origin); }
            } catch (\Throwable $error) {
                throw new RuntimeException('Draft saved as ' . $stored->id() . ' but automatic source flag failed; retry the same Email.',0,$error);
            }
        }
        return $resolved->onServer($stored->id(),$stored->flags());
    }

    public function reply(Email $email, string $markdown = '', EmailAddress|string|null $from = null, array $quote = [], Signature|false|null $signature = null, ?string $signaturePosition = null): Email
    { return $email->reply($this->author($from),$markdown,array_replace($this->defaults->quote,$quote),$signature ?? $this->defaults->signatures['reply'],$signaturePosition ?? $this->defaults->position); }

    public function replyAll(Email $email, string $markdown = '', array $exclude = [], EmailAddress|string|null $from = null, array $quote = [], Signature|false|null $signature = null, ?string $signaturePosition = null): Email
    { return $email->replyAll($this->author($from),$markdown,$exclude,array_replace($this->defaults->quote,$quote),$signature ?? $this->defaults->signatures['reply'],$signaturePosition ?? $this->defaults->position); }

    public function forward(Email $email, EmailAddress|string|array $to = [], string $markdown = '', bool $includeAttachments = false, EmailAddress|string|null $from = null, array $quote = [], Signature|false|null $signature = null, ?string $signaturePosition = null): Email
    { return $email->forward($this->author($from),$to,$markdown,$includeAttachments,array_replace($this->defaults->quote,$quote),$signature ?? $this->defaults->signatures['forward'],$signaturePosition ?? $this->defaults->position); }

    private function author(EmailAddress|string|null $from): EmailAddress|string
    { return $from ?? $this->from ?? throw new InvalidArgumentException('No configured or explicit From.'); }

    public function markRead(Email $email): Email { return $this->addFlag($email,'\\Seen'); }
    public function markUnread(Email $email): Email { return $this->removeFlag($email,'\\Seen'); }
    public function markAnswered(Email $email): Email { return $this->addFlag($email,'\\Answered'); }
    public function markForwarded(Email $email): Email { return $this->addFlag($email,'$Forwarded'); }
    public function addFlag(Email $email, string $flag): Email { return $this->changeFlag($email,$flag,true); }
    public function removeFlag(Email $email, string $flag): Email { return $this->changeFlag($email,$flag,false); }

    private function changeFlag(Email $email, string $flag, bool $add): Email
    {
        if (!in_array($flag,['\\Seen','\\Answered','\\Flagged'],true) && (!preg_match('/^[A-Za-z0-9$][A-Za-z0-9$_.-]{0,63}$/D',$flag))) { throw new InvalidArgumentException('Unsupported or protocol-controlled flag.'); }
        $ref = Reference::decode($email->id() ?? '',$this->account); $this->selectReference($ref,true);
        $this->transport->metadata($ref->uid); $this->transport->flag($ref->uid,$flag,$add);
        return $this->read($email->id());
    }

    public function moveTo(Email $email, string $folder, bool $createFolder = false): Email
    {
        Headers::validate($folder);
        if ($folder === '') { throw new InvalidArgumentException('Target folder must be nonempty.'); }
        if (strcasecmp($folder, 'INBOX') === 0) { $folder = 'INBOX'; }
        $ref = Reference::decode($email->id() ?? '',$this->account);
        $senders = array_map(static fn(EmailAddress $address): string => $address->getAddress(), $email->from());
        $context = sprintf(
            'source-folder="%s", target-folder="%s", message-id="%s", from="%s", date="%s", subject="%s"',
            $ref->folder,
            $folder,
            $email->messageId() ?? 'unknown',
            $senders === [] ? 'unknown' : implode(', ', $senders),
            $email->date()?->format(DATE_ATOM) ?? 'unknown',
            $email->subject(),
        );
        try {
            $targetExists = $this->transport->folderExists($folder);
        } catch (\Throwable $error) {
            throw new RuntimeException('Unable to check target folder before moving email: ' . $context . '. Cause: ' . $error->getMessage(), previous: $error);
        }
        if (!$targetExists && !$createFolder) {
            throw new RuntimeException('Cannot move email because target folder does not exist: ' . $context . '.');
        }
        if (!$targetExists) {
            try { $this->transport->createFolder($folder); }
            catch (\Throwable $error) {
                throw new RuntimeException('Unable to create target folder before moving email: ' . $context . '. Cause: ' . $error->getMessage(), previous: $error);
            }
        }
        try {
            $this->transport->select($folder);
            $this->selectReference($ref,true); $this->transport->metadata($ref->uid);
            if ($ref->folder === $folder) { return $this->read($email->id()); }
            [$validity,$uid] = $this->transport->move($ref->uid,$folder);
            return $this->read((new Reference($this->account,$folder,$validity,$uid))->encode());
        } catch (\Throwable $error) {
            throw new RuntimeException('Failed to move email via IMAP: ' . $context . '. Cause: ' . $error->getMessage(), previous: $error);
        }
    }

    public function moveToTrash(Email $email): Email
    { return $this->moveTo($email, $this->trashFolder); }

    private function selectReference(Reference $ref, bool $write = false): void
    {
        $status = $this->transport->select($ref->folder,$write);
        if ((int)$status['uidvalidity'] !== $ref->validity) { throw new RuntimeException('Stale UIDVALIDITY: explicit resynchronization required.'); }
    }
}
