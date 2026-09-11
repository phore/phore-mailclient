<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Provider;

use Phore\MailClient\MailClient;
use Phore\MailClient\Internal\{Transport,Reference};
use RuntimeException;

/** Shared by disposable-server CI and the live provider runner. Never returns mail data. */
final class ReadOnlyProbe
{
    /** @return list<string> Public, content-free check results. */
    public static function run(Transport $inner, string $account): array
    {
        // Fail closed even if a future client regression attempts an automatic write.
        $transport = new class($inner) implements Transport {
            public function __construct(private Transport $inner) {}
            public function select(string $folder, bool $write = false): array {
                if ($write) { throw new RuntimeException('Provider probe attempted write selection.'); }
                return $this->inner->select($folder);
            }
            public function search(array $criteria): array { return $this->inner->search($criteria); }
            public function metadata(int $uid): array { return $this->inner->metadata($uid); }
            public function part(int $uid, string $section, int $maxBytes): string { return $this->inner->part($uid,$section,$maxBytes); }
            public function append(string $folder, string $mime): void { throw new RuntimeException('Provider probe attempted APPEND.'); }
            public function flag(int $uid, string $flag, bool $add): void { throw new RuntimeException('Provider probe attempted STORE.'); }
            public function move(int $uid, string $folder): array { throw new RuntimeException('Provider probe attempted MOVE.'); }
        };
        $status = $transport->select('INBOX');
        self::check((int)($status['uidvalidity'] ?? 0) > 0,'INBOX UIDVALIDITY');
        $results = ['PASS: INBOX opened read-only with UIDVALIDITY.'];
        $ids = $transport->search(['after'=>0]);
        self::check(count($ids) === count(array_unique($ids)), 'Unique search UIDs');
        foreach ($ids as $uid) { self::check(is_int($uid) && $uid > 0, 'Positive search UIDs'); }
        sort($ids,SORT_NUMERIC);
        $results[] = 'PASS: UID search.';
        $client = new MailClient($transport,$account,mode:MailClient::MODE_MANUAL);
        if ($ids === []) {
            $batch = $client->listNew(limit:1);
            self::check($batch->emails === [],'Empty INBOX batch');
            $cursor = Reference::decode($batch->nextCursor,$account,true);
            self::check($cursor->uid === 0 && $cursor->validity === (int)$status['uidvalidity'],'Empty INBOX cursor');
            $results[] = 'PASS: Empty INBOX cursor.';
            $results[] = 'SKIP: MIME reads, pagination and flag checks require at least one message.';
            return $results;
        }
        // Read at most the three newest messages; never download attachment content.
        $sample = array_slice($ids,-3);
        $before = []; $references = [];
        foreach ($sample as $uid) {
            $before[$uid] = self::flags($transport->metadata($uid));
            $id = (new Reference($account,'INBOX',(int)$status['uidvalidity'],$uid))->encode();
            $references[] = $id;
            $email = $client->get($id);
            self::check($email->id() === $id,'Message reference round trip');
            self::check(self::flags(['FLAGS'=>$email->flags()]) === $before[$uid],'Manual read flags');
            foreach ($email->attachments() as $attachment) {
                self::check($attachment->content === null && $attachment->sourceId === $id,'Lazy attachment descriptor');
            }
        }
        $results[] = 'PASS: Bounded manual MIME reads and lazy attachment descriptors.';
        // Exercise the public per-action switch too, without allowing any STORE.
        $client->setAutomaticMode(true)->setAutomaticMode(false,'seen');
        $cursor = (new Reference($account,'INBOX',(int)$status['uidvalidity'],$sample[0]-1))->encode();
        foreach ($references as $id) {
            $batch = $client->listNew(after:$cursor,limit:1);
            self::check(count($batch->emails) === 1 && $batch->emails[0]->id() === $id,'Single-message cursor page');
            self::check($batch->nextCursor === $id,'Cursor advances to returned UID');
            $cursor = $batch->nextCursor;
        }
        // New arrivals are allowed, but the last UID must never replay (IMAP n:* edge).
        foreach ($transport->search(['after'=>end($sample)]) as $uid) {
            self::check($uid > end($sample),'No UID replay');
        }
        $results[] = 'PASS: Cursor pagination, no replay and automatic Seen disabled.';
        foreach ($sample as $uid) {
            self::check(self::flags($transport->metadata($uid)) === $before[$uid],'Persistent flags unchanged');
        }
        $results[] = 'PASS: Persistent flags unchanged after reads.';
        return $results;
    }
    private static function flags(array $metadata): array
    {
        // Recent is session-specific, not a persistent user flag.
        $flags = array_values(array_filter(array_map('strtolower',$metadata['FLAGS'] ?? []),static fn(string $flag): bool => $flag !== '\\recent'));
        sort($flags,SORT_STRING); return $flags;
    }
    private static function check(bool $condition, string $label): void
    { if (!$condition) { throw new RuntimeException('Provider check failed: ' . $label); } }
}
