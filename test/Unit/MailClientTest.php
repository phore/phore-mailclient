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
    public function testUnsavedFlagTargetFailsBeforeIo(): void
    {
        $transport = $this->createMock(Transport::class); $transport->expects(self::never())->method('select');
        $client = new MailClient($transport,'account');
        $this->expectException(\InvalidArgumentException::class); $client->markRead(new Email());
    }
    public function testUnknownAutomaticActionIsRejected(): void
    {
        $client = new MailClient($this->createMock(Transport::class),'account');
        $this->expectException(\InvalidArgumentException::class); $client->setAutomaticMode(true,'delete');
    }
}
