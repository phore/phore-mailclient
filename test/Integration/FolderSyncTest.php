<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Integration;

use PHPUnit\Framework\TestCase;
use Phore\MailClient\{MailClient, Email};

final class FolderSyncTest extends TestCase
{
    public function testIndependentClientsObserveFlagsAndMovesWithoutMarkingRead(): void
    {
        if (getenv('PHORE_TEST_IMAP') !== '1') { self::markTestSkipped('Disposable Dovecot is not enabled.'); }
        $writer = MailClient::connect('localhost','test','test-secret',1993,from:'fixture@example.org',mode:'manual');
        $observer = MailClient::connect('localhost','test','test-secret',1993);
        $drafts = $observer->syncFolder('Drafts');
        while ($drafts->hasMore) { $drafts = $observer->syncFolder('Drafts', $drafts->nextCursor); }
        $trash = $observer->syncFolder('Trash');
        while ($trash->hasMore) { $trash = $observer->syncFolder('Trash', $trash->nextCursor); }
        $saved = $writer->saveDraft(new Email(subject:'Folder sync fixture'));
        $added = $observer->syncFolder('Drafts', $drafts->nextCursor);
        self::assertCount(1, $added->added);
        self::assertSame($saved->id(), $added->added[0]->id());
        self::assertNotContains('\\Seen', $writer->get($saved->id())->flags());
        $writer->addFlag($saved, 'ClassInvoice');
        $flags = $observer->syncFolder('Drafts', $added->nextCursor);
        self::assertCount(1, $flags->flagsChanged);
        self::assertContains('ClassInvoice', $flags->flagsChanged[0]->newFlags);
        $moved = $writer->moveToTrash($saved);
        $removed = $observer->syncFolder('Drafts', $flags->nextCursor);
        $arrived = $observer->syncFolder('Trash', $trash->nextCursor);
        self::assertSame([$saved->id()], $removed->removed);
        self::assertCount(1, $arrived->added);
        self::assertSame($moved->id(), $arrived->added[0]->id());
        self::assertNotSame($saved->id(), $moved->id());
    }
}
