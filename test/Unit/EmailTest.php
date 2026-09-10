<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Unit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phore\MailClient\{Email,EmailAddress,Attachment,Signature};
use Phore\MailClient\Internal\{MessageDefaults,Mime};

final class EmailTest extends TestCase
{
    public function testAddressParsingAndRejection(): void
    {
        $list = EmailAddress::parseList('"Example, Anna" <Anna+tag@EXAMPLE.org>, =?UTF-8?Q?J=C3=B6rg?= <j@example.org>');
        self::assertCount(2,$list);
        self::assertSame('Anna+tag@example.org',$list[0]->getAddress());
        self::assertSame('Example, Anna',$list[0]->getName());
        self::assertSame('Jörg',$list[1]->getName());
        self::assertSame('"Example, Anna" <Anna+tag@example.org>',$list[0]->toString());
        self::assertSame([],EmailAddress::parseList(''));
        foreach (["a@example.org\r\nBcc: b@example.org",'a@example.org,','Group: a@example.org;','"unclosed <a@example.org>','a@example.org, broken','=?UTF-8?Q?Bad=0AName?= <a@example.org>'] as $input) {
            try { EmailAddress::parseList($input); self::fail('Accepted invalid address'); } catch (InvalidArgumentException) {}
        }
        foreach (["bad\nvalue","bad\0value","bad\x7fvalue","bad\x01value","bad\xffvalue"] as $value) {
            try { new Email(subject:$value); self::fail('Accepted unsafe header'); } catch (InvalidArgumentException) {}
        }
        $this->expectException(InvalidArgumentException::class); EmailAddress::parse('a@example.org, b@example.org');
    }
    public function testCompositionIsImmutableAndDefaultsAppliedOnce(): void
    {
        $original = new Email(subject:'Prüfung & <Freigabe>'); $updated = $original->withMarkdown('**Ready**');
        self::assertSame([],$original->from()); self::assertSame('',$original->body()->text());
        self::assertSame($original->messageId(),$updated->messageId());
        $defaults = new MessageDefaults(['signatures'=>['new'=>Signature::fromHtml('<b>Signature</b>')]]);
        $saved = $updated->resolve(new EmailAddress('me@example.org'),$defaults);
        self::assertSame([],$updated->from()); self::assertStringContainsString('<b>Signature</b>',$saved->body()->html());
        self::assertSame($saved->body()->html(),$saved->resolve(null,$defaults)->body()->html());
        self::assertStringNotContainsString('Signature',$updated->withSignature(false)->resolve(new EmailAddress('me@example.org'),$defaults)->body()->text());
        self::assertSame('Prüfung & <Freigabe>',$saved->subject());
    }
    public function testEmptyFromRejected(): void
    { $this->expectException(InvalidArgumentException::class); new Email(from:[]); }
    public function testMultipleAuthorsRequireSender(): void
    { $this->expectException(InvalidArgumentException::class); new Email(from:['a@example.org','b@example.org']); }
    public function testReplyAllAndForwardPrivacy(): void
    {
        $source = (new Email(from:'author@example.org',to:['me@example.org','other@example.org'],cc:['other@example.org','alias@example.org'],bcc:'secret@example.org',replyTo:['support@example.org','backup@example.org'],subject:'Topic'))->withMarkdown('Original signature');
        $reply = $source->replyAll(from:'me@example.org',markdown:'Answer',exclude:['alias@example.org']);
        self::assertSame(['support@example.org','backup@example.org'],array_map(fn($a)=>$a->getAddress(),$reply->to()));
        self::assertSame(['other@example.org'],array_map(fn($a)=>$a->getAddress(),$reply->cc()));
        self::assertSame([],$reply->bcc()); self::assertNull($reply->id());
        self::assertSame($source->messageId(),$reply->inReplyTo()); self::assertNotSame($source->messageId(),$reply->messageId());
        self::assertStringContainsString('Original signature',$reply->body()->text());
        self::assertStringNotContainsString('secret@example.org',$reply->body()->text());
        $forward = $source->forward(from:'me@example.org');
        self::assertNull($forward->inReplyTo()); self::assertSame([],$forward->references());
        self::assertStringNotContainsString('secret@example.org',$forward->body()->text());
    }
    public function testTemplatesAreLiteralAndSinglePass(): void
    {
        $source = new Email(from:new EmailAddress('a@example.org','{{subject}} **Evil** <script>'),subject:'SECRET');
        $reply = $source->reply('me@example.org',quote:['reply'=>'{{from.name}}']);
        self::assertStringContainsString('{{subject}} **Evil** &lt;script&gt;',$reply->body()->html());
        self::assertStringNotContainsString('<strong>Evil</strong>',$reply->body()->html());
        $this->expectException(InvalidArgumentException::class); $source->reply('me@example.org',quote:['reply'=>'{{bcc}}']);
    }
    public function testSignatureSanitizationAndImageSnapshot(): void
    {
        $path = tempnam(sys_get_temp_dir(),'logo');
        file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        try {
            $signature = Signature::fromHtml('<script>bad()</script><p onclick="bad()" style="color:red;background:url(file:///etc/passwd)">Hi <img src="cid:logo" alt="Logo"><a href="javascript:bad()">Link</a></p>',inlineImages:['logo'=>$path]);
            $recreated = Signature::fromHtml('<img src="cid:logo">',inlineImages:['logo'=>$path]);
            self::assertSame($signature->images[0]->contentId,$recreated->images[0]->contentId);
            file_put_contents($path,'changed');
            foreach (['<script','onclick','file:','javascript:'] as $bad) { self::assertStringNotContainsString($bad,$signature->body->html()); }
            self::assertStringContainsString('color:red',$signature->body->html());
            self::assertStringContainsString('Logo',$signature->body->text());
            self::assertNotSame('changed',$signature->images[0]->content);
            $email = (new Email(from:'a@example.org'))->withSignature($signature);
            $mime = Mime::build($email,$email->attachments());
            self::assertStringContainsString('multipart/related',$mime);
            self::assertSame($mime,Mime::build($email,$email->attachments()));
        } finally { unlink($path); }
    }
    public function testMimeUtf8AndFilenameFolding(): void
    {
        $email = (new Email(from:'a@example.org',bcc:'private@example.org',subject:str_repeat('Prüfung ',40)))->attach(Attachment::fromBytes('Überblick "final".txt','text/plain','payload'));
        $mime = Mime::build($email,$email->attachments());
        self::assertStringContainsString('Bcc: <private@example.org>',$mime);
        self::assertStringContainsString("filename*0*=UTF-8''",$mime);
        foreach (explode("\r\n",$mime) as $line) { self::assertLessThanOrEqual(998,strlen($line)); }
    }
}
