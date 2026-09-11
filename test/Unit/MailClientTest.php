<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Unit;
use PHPUnit\Framework\TestCase;
use Phore\MailClient\{MailClient,Email};
use Phore\MailClient\Internal\{Transport,Reference};

final class MailClientTest extends TestCase
{
    public function testAutomaticModeAndLocalCompositionNeverCallTransport(): void
    {
        $transport = $this->createMock(Transport::class);
        foreach (['select','search','metadata','part','append','flag','move'] as $method) { $transport->expects(self::never())->method($method); }
        $client = new MailClient($transport,'account',from:'me@example.org');
        self::assertTrue($client->isAutomatic());
        $client->setAutomaticMode(false,'seen');
        self::assertFalse($client->isAutomatic('seen')); self::assertTrue($client->isAutomatic('answered'));
        $client->setAutomaticMode(false)->setAutomaticMode(true,'forwarded');
        self::assertFalse($client->isAutomatic('answered')); self::assertTrue($client->isAutomatic('forwarded'));
        $client->setAutomaticMode(true); self::assertTrue($client->isAutomatic('seen'));
        $source = new Email(from:'other@example.org');
        self::assertNull($client->reply($source)->id()); self::assertNull($client->forward($source)->id());
    }
    public function testForeignReferencesFailBeforeIo(): void
    {
        $transport = $this->createMock(Transport::class); $transport->expects(self::never())->method('select');
        $client = new MailClient($transport,'account');
        $this->expectException(\InvalidArgumentException::class);
        $client->get((new Reference('foreign','INBOX',1,1))->encode());
    }
    public function testMissingIdentityFailsBeforeDraftIo(): void
    {
        $transport = $this->createMock(Transport::class); $transport->expects(self::never())->method('append'); $transport->expects(self::never())->method('select');
        $client = new MailClient($transport,'account');
        $this->expectException(\InvalidArgumentException::class); $client->saveDraft(new Email());
    }
    public function testDraftFolderFailureHasOperationAndFolderContext(): void
    {
        $cause = new \RuntimeException('Empty response');
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())->method('select')->with('Drafts')->willThrowException($cause);
        $client = new MailClient($transport,'account',from:'me@example.org');

        try {
            $client->saveDraft(new Email(to:'recipient@example.org',subject:'Test'));
            self::fail('Draft folder failure was not propagated.');
        } catch (\RuntimeException $error) {
            self::assertSame('Unable to access configured drafts folder "Drafts" while saving draft.',$error->getMessage());
            self::assertSame($cause,$error->getPrevious());
        }
    }
    public function testUnsavedFlagTargetFailsBeforeIo(): void
    {
        $transport = $this->createMock(Transport::class); $transport->expects(self::never())->method('select');
        $client = new MailClient($transport,'account');
        $this->expectException(\InvalidArgumentException::class); $client->markRead(new Email());
    }
    public function testUnknownAutomaticActionIsRejected(): void
    {
        $client = new MailClient($this->createStub(Transport::class),'account');
        $this->expectException(\InvalidArgumentException::class); $client->setAutomaticMode(true,'delete');
    }
    public function testInvalidLimitsFailBeforeIo(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::never())->method('select');
        $client = new MailClient($transport,'account');
        foreach ([0,501,-1] as $limit) {
            try { $client->listNew(limit:$limit); self::fail('Invalid limit accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testFolderCursorAndMalformedCursorFailBeforeIo(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::never())->method('select');
        $client = new MailClient($transport,'account');
        foreach (['not-a-cursor',(new Reference('account','Drafts',1,0))->encode()] as $cursor) {
            try { $client->listNew(after:$cursor); self::fail('Invalid cursor accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testProtocolControlledFlagsCannotBeSetExplicitly(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::never())->method('select');
        $client = new MailClient($transport,'account');
        $email = (new Email())->onServer((new Reference('account','INBOX',1,1))->encode(),[]);
        foreach (['\\Deleted','\\Recent',"bad flag","x\r\nSTORE"] as $flag) {
            try { $client->addFlag($email,$flag); self::fail('Invalid flag accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testProviderProbeReportsSkippedMessageChecksOnEmptyInbox(): void
    {
        require_once dirname(__DIR__) . '/Provider/ReadOnlyProbe.php';
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::exactly(2))->method('select')->with('INBOX')->willReturn(['uidvalidity'=>7]);
        $transport->expects(self::exactly(2))->method('search')->with(['after'=>0])->willReturn([]);
        foreach (['metadata','part','append','flag','move'] as $method) { $transport->expects(self::never())->method($method); }
        $results = \Phore\MailClient\Test\Provider\ReadOnlyProbe::run($transport,'account');
        self::assertContains('PASS: Empty INBOX cursor.',$results);
        self::assertSame('SKIP: MIME reads, pagination and flag checks require at least one message.',end($results));
    }
}
