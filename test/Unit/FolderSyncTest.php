<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Unit;

use PHPUnit\Framework\TestCase;
use Phore\MailClient\{MailClient, SyncResetRequired};
use Phore\MailClient\Internal\{Reference, SyncCursor, SyncTransport};

final class FolderSyncTest extends TestCase
{
    private function fixture(): SyncTransport
    {
        return new class implements SyncTransport {
            public string $folder = 'INBOX';
            public int $validity = 7;
            public array $messages = ['INBOX'=>[1=>[], 2=>['\\Seen']], 'Archive'=>[]];
            public int $reads = 0;
            public bool $failRead = false;
            public bool $failSnapshot = false;
            public function select(string $folder, bool $write = false): array {
                if ($write) { throw new \LogicException('Sync must never select writable.'); }
                if (!isset($this->messages[$folder])) { throw new \RuntimeException('Missing folder.'); }
                $this->folder = $folder; return ['uidvalidity'=>$this->validity];
            }
            public function search(array $criteria): array { return array_keys($this->messages[$this->folder]); }
            public function syncFlags(array $uids): array {
                if ($this->failSnapshot) { throw new \RuntimeException('Connection lost.'); }
                return array_intersect_key($this->messages[$this->folder], array_flip($uids));
            }
            public function metadata(int $uid): array {
                $this->reads++;
                if ($this->failRead) { throw new \RuntimeException('Message no longer exists.'); }
                return ['UID'=>$uid, 'FLAGS'=>$this->messages[$this->folder][$uid], 'BODYSTRUCTURE'=>['TEXT','PLAIN',['CHARSET','UTF-8'],null,null,'7BIT',0,0]];
            }
            public function part(int $uid, string $section, int $maxBytes): string {
                if ($section !== 'HEADER') { return ''; }
                return "From: sender@example.org\r\nSubject: Fixture $uid\r\nMessage-ID: <$uid@example.org>\r\n\r\n";
            }
            public function append(string $folder, string $mime): void { throw new \LogicException('Unexpected write.'); }
            public function flag(int $uid, string $flag, bool $add): void { throw new \LogicException('Unexpected write.'); }
            public function move(int $uid, string $folder): array { throw new \LogicException('Unexpected write.'); }
        };
    }

    public function testFirstSyncIsReadOnlyAndResumesAcrossClientInstances(): void
    {
        $transport = $this->fixture();
        $first = (new MailClient($transport, 'account'))->syncFolder('inbox', limit:1);
        self::assertTrue($first->isInitialSync);
        self::assertSame('INBOX', $first->folder);
        self::assertCount(1, $first->added);
        self::assertTrue($first->hasMore);
        self::assertSame([], $first->added[0]->flags());
        $next = (new MailClient($transport, 'account'))->syncFolder('INBOX', $first->nextCursor, limit:1);
        self::assertFalse($next->isInitialSync);
        self::assertFalse($next->hasMore);
        self::assertCount(1, $next->added);
        self::assertNotSame($first->added[0]->id(), $next->added[0]->id());
        $empty = (new MailClient($transport, 'account'))->syncFolder('INBOX', $next->nextCursor);
        self::assertSame([], $empty->added);
        self::assertSame([], $empty->flagsChanged);
        self::assertSame([], $empty->removed);
        self::assertSame($next->nextCursor, $empty->nextCursor);
        self::assertSame(2, $transport->reads);
    }

    public function testFlagsAndRemovalAreNetChangesAndDoNotFetchContent(): void
    {
        $transport = $this->fixture(); $client = new MailClient($transport, 'account');
        $initial = $client->syncFolder('INBOX');
        $transport->messages['INBOX'] = [1=>['ClassInvoice']];
        $delta = $client->syncFolder('INBOX', $initial->nextCursor);
        self::assertSame([$initial->added[1]->id()], $delta->removed);
        self::assertCount(1, $delta->flagsChanged);
        self::assertSame($initial->added[0]->id(), $delta->flagsChanged[0]->id);
        self::assertSame([], $delta->flagsChanged[0]->oldFlags);
        self::assertSame(['ClassInvoice'], $delta->flagsChanged[0]->newFlags);
        self::assertSame(2, $transport->reads);
        // Retry from the uncommitted cursor returns the same observations.
        self::assertEquals($delta, $client->syncFolder('INBOX', $initial->nextCursor));
    }

    public function testMoveIsRemovalAndAdditionWithIndependentFolderCursors(): void
    {
        $transport = $this->fixture(); $client = new MailClient($transport, 'account');
        $inbox = $client->syncFolder('INBOX'); $archive = $client->syncFolder('Archive');
        unset($transport->messages['INBOX'][1]);
        $transport->messages['Archive'][40] = [];
        $source = $client->syncFolder('INBOX', $inbox->nextCursor);
        $destination = $client->syncFolder('Archive', $archive->nextCursor);
        self::assertSame([$inbox->added[0]->id()], $source->removed);
        self::assertCount(1, $destination->added);
        self::assertNotSame($source->removed[0], $destination->added[0]->id());
    }

    public function testAllKindsShareTheBatchLimitWithoutLosingChanges(): void
    {
        $transport = $this->fixture(); $client = new MailClient($transport, 'account');
        $cursor = $client->syncFolder('INBOX')->nextCursor;
        $transport->messages['INBOX'] = [2=>['NeedsReview'], 3=>[]];
        $counts = [0,0,0];
        do {
            $batch = $client->syncFolder('INBOX', $cursor, limit:1);
            self::assertSame(1, count($batch->added)+count($batch->flagsChanged)+count($batch->removed));
            $counts[0] += count($batch->added); $counts[1] += count($batch->flagsChanged); $counts[2] += count($batch->removed);
            $cursor = $batch->nextCursor;
        } while ($batch->hasMore);
        self::assertSame([1,1,1], $counts);
    }

    public function testTransientRecentOrderingAndCaseDoNotCreateChanges(): void
    {
        $transport = $this->fixture();
        $transport->messages['INBOX'] = [1=>['ClassInvoice','\\Seen','\\Recent']];
        $client = new MailClient($transport, 'account');
        $cursor = $client->syncFolder('INBOX')->nextCursor;
        $transport->messages['INBOX'][1] = ['\\seen','classinvoice'];
        $batch = $client->syncFolder('INBOX', $cursor);
        self::assertSame([], $batch->flagsChanged);
        self::assertSame($cursor, $batch->nextCursor);
    }

    public function testInvalidForeignAndLegacyCursorsFailBeforeIo(): void
    {
        $transport = $this->createMock(SyncTransport::class);
        $transport->expects(self::never())->method('select');
        $client = new MailClient($transport, 'account');
        $invalid = [
            'bad!',
            (new SyncCursor('other', 'INBOX', 7, []))->encode(),
            (new SyncCursor('account', 'Archive', 7, []))->encode(),
            (new Reference('account', 'INBOX', 7, 0))->encode(),
            (new SyncCursor('account', 'INBOX', 7, [0=>[]]))->encode(),
        ];
        foreach ($invalid as $cursor) {
            try { $client->syncFolder('INBOX', $cursor); self::fail('Invalid cursor accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        foreach ([0,501] as $limit) {
            try { $client->syncFolder('INBOX', limit:$limit); self::fail('Invalid limit accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testUidValidityChangeRequiresAnExplicitReset(): void
    {
        $transport = $this->fixture(); $client = new MailClient($transport, 'account');
        $cursor = $client->syncFolder('INBOX')->nextCursor;
        $transport->validity++;
        try { $client->syncFolder('INBOX', $cursor); self::fail('Stale state accepted'); }
        catch (SyncResetRequired $error) { self::assertSame('INBOX', $error->folder); }
        self::assertCount(2, $client->syncFolder('INBOX', cursor:null)->added);
    }

    public function testFailedReadDoesNotAdvanceStateAndRetryCanRecover(): void
    {
        $transport = $this->fixture(); $client = new MailClient($transport, 'account');
        $transport->messages['INBOX'] = [];
        $cursor = $client->syncFolder('INBOX')->nextCursor;
        $transport->messages['INBOX'][5] = [];
        $transport->failRead = true;
        try { $client->syncFolder('INBOX', $cursor); self::fail('Failure swallowed'); }
        catch (\RuntimeException $error) { self::assertSame('Message no longer exists.', $error->getMessage()); }
        $transport->failRead = false;
        self::assertCount(1, $client->syncFolder('INBOX', $cursor)->added);
    }

    public function testFolderLimitFailsInsteadOfReturningPartialState(): void
    {
        $transport = $this->fixture();
        $transport->messages['INBOX'] = array_fill(1, 10001, []);
        $this->expectException(\RuntimeException::class);
        (new MailClient($transport, 'account'))->syncFolder('INBOX');
    }

    public function testConnectionFailureDoesNotTurnKnownMessagesIntoRemovals(): void
    {
        $transport = $this->fixture(); $client = new MailClient($transport, 'account');
        $cursor = $client->syncFolder('INBOX')->nextCursor;
        $transport->failSnapshot = true;
        $this->expectException(\RuntimeException::class);
        $client->syncFolder('INBOX', $cursor);
    }
}
