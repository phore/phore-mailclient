<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Integration;
use PHPUnit\Framework\TestCase;
use Phore\MailClient\{MailClient,Email,EmailAddress,Attachment,Signature};
use Phore\MailClient\Internal\{ImapTransport,Mime,Reference};

final class ImapTest extends TestCase
{
    protected function setUp(): void
    { if (getenv('PHORE_TEST_IMAP') !== '1') { self::markTestSkipped('Disposable Dovecot is not enabled.'); } }
    private function client(string $mode = 'manual', array $defaults = []): MailClient
    { return MailClient::connect('localhost','test','test-secret',1993,from:'me@example.org',messageDefaults:$defaults,mode:$mode); }
    public function testMimeDraftRoundTripRetryAndConflict(): void
    {
        $client = $this->client(defaults:['signatures'=>['new'=>Signature::fromHtml('<table><tr><td><b>Signature</b></td></tr></table>')]]);
        $email = (new Email(to:'you@example.org',bcc:'private@example.org',subject:'Prüfung & <Freigabe>'))->withMarkdown('**Body**')->attach(Attachment::fromBytes('Überblick "final".txt','text/plain','test bytes'));
        $saved = $client->saveDraft($email);
        self::assertNull($email->id()); self::assertSame([],$email->from()); self::assertNotNull($saved->id());
        self::assertSame($saved->id(),$client->saveDraft($email)->id());
        $fetched = $client->get($saved->id());
        self::assertSame($email->subject(),$fetched->subject());
        self::assertSame('private@example.org',$fetched->bcc()[0]->getAddress());
        self::assertStringContainsString('<table>',$fetched->body()->html());
        self::assertSame('Überblick "final".txt',$fetched->attachments()[0]->filename());
        $stream = $client->openAttachment($fetched,$fetched->attachments()[0]);
        try { self::assertSame('test bytes',stream_get_contents($stream)); } finally { fclose($stream); }
        self::assertSame($saved->id(),$client->saveDraft($fetched)->id());
        $this->expectException(\RuntimeException::class); $client->saveDraft($email->withMarkdown('Changed'));
    }
    public function testManualAutomaticAndPerActionOverrides(): void
    {
        $manual = $this->client();
        $source = $manual->saveDraft((new Email(from:'other@example.org',to:'me@example.org'))->withMarkdown('Source'));
        self::assertNotContains('\\Seen',$manual->get($source->id())->flags());
        $manual->saveDraft($manual->reply($source,markdown:'Manual reply'));
        self::assertNotContains('\\Answered',$manual->get($source->id())->flags());
        $auto = $this->client('automatic');
        $auto->setAutomaticMode(false,'seen');
        self::assertNotContains('\\Seen',$auto->get($source->id())->flags());
        $auto->setAutomaticMode(true,'seen');
        self::assertContains('\\Seen',$auto->get($source->id())->flags());
        $auto->markUnread($source); self::assertNotContains('\\Seen',$manual->get($source->id())->flags());
        $auto->saveDraft($auto->reply($source,markdown:'Automatic reply'));
        self::assertContains('\\Answered',$manual->get($source->id())->flags());
        $auto->setAutomaticMode(false,'forwarded');
        $auto->saveDraft($auto->forward($source,to:'third@example.org'));
        self::assertNotContains('$Forwarded',$manual->get($source->id())->flags());
        $auto->setAutomaticMode(true,'forwarded');
        $auto->saveDraft($auto->forward($source,to:'third@example.org'));
        self::assertContains('$Forwarded',$manual->get($source->id())->flags());
        $auto->setAutomaticMode(false); self::assertFalse($auto->isAutomatic('seen'));
        $starred = $auto->addFlag($source,'\\Flagged'); self::assertContains('\\Flagged',$starred->flags());
        $trashed = $auto->moveToTrash($starred); self::assertNotSame($source->id(),$trashed->id());
        self::assertSame($source->messageId(),$trashed->messageId());
    }
    public function testCursorDoesNotReplayHighestUidAndStaleReferencesFail(): void
    {
        $transport = new ImapTransport('localhost','test','test-secret',1993);
        for ($i=0;$i<3;$i++) { $transport->append('INBOX',Mime::build(new Email(from:'seed@example.org',subject:'Fixture '.$i),[])); }
        $client = $this->client(); $batch = $client->listNew(limit:2);
        self::assertCount(2,$batch->emails);
        self::assertNotContains('\\Seen',$batch->emails[0]->flags());
        $next = $client->listNew(after:$batch->nextCursor,limit:2); self::assertCount(1,$next->emails);
        self::assertCount(0,$client->listNew(after:$next->nextCursor)->emails);
        $account = hash('sha256','localhost:1993:test');
        $ref = Reference::decode($batch->emails[0]->id(),$account);
        $stale = (new Reference($account,'INBOX',$ref->validity+1,$ref->uid))->encode();
        $this->expectException(\RuntimeException::class); $client->get($stale);
    }
    public function testWrongTlsHostnameIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        MailClient::connect('127.0.0.1','test','test-secret',1993);
    }
}
