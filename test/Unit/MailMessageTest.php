<?php

declare(strict_types=1);

namespace Phore\MailClient\Test\Unit;

use DateTimeImmutable;
use Phore\MailClient\Domain\DraftRelationType;
use Phore\MailClient\Domain\MailAddress;
use Phore\MailClient\Domain\MailAttachment;
use Phore\MailClient\Domain\MailBody;
use Phore\MailClient\Domain\MailMessage;
use Phore\MailClient\Domain\MailReference;
use PHPUnit\Framework\TestCase;

final class MailMessageTest extends TestCase
{
    public function testReplyAddsThreadHeadersAndMarkdownQuote(): void
    {
        $message = $this->message()->bindDrafts(new MailAddress('support@example.org'), null);
        $draft = $message->reply()->withMarkdown('My answer');

        self::assertSame('Re: Question', $draft->subject);
        self::assertSame('<source@example.org>', $draft->headers['In-Reply-To']);
        self::assertSame('<older@example.org> <source@example.org>', $draft->headers['References']);
        self::assertSame(DraftRelationType::Reply, $draft->relation?->type);
        self::assertStringStartsWith("My answer\n\nOn 2026-09-09 10:00, Customer <customer@example.org> wrote:", $draft->markdown());
        self::assertStringContainsString("\n> First line\n> \n> Second line", $draft->markdown());
    }

    public function testReplyAllExcludesAccountAndDeduplicatesRecipients(): void
    {
        $draft = $this->message()->bindDrafts(new MailAddress('support@example.org'), null)->replyAll();

        self::assertSame(['customer@example.org', 'other@example.org'], array_map(static fn (MailAddress $a): string => $a->address, $draft->to));
        self::assertSame(['copy@example.org'], array_map(static fn (MailAddress $a): string => $a->address, $draft->cc));
    }

    public function testForwardQuotesEnvelopeAndCarriesAttachments(): void
    {
        $draft = $this->message()->forward(['office@example.org']);

        self::assertSame('Fwd: Question', $draft->subject);
        self::assertCount(1, $draft->attachments);
        self::assertStringContainsString('> Forwarded message', $draft->markdown());
        self::assertSame(DraftRelationType::Forward, $draft->relation?->type);
        self::assertArrayNotHasKey('In-Reply-To', $draft->headers);
    }

    private function message(): MailMessage
    {
        $attachment = new MailAttachment('a1', 'invoice.pdf', 'application/pdf', 3, static function () {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'pdf');
            rewind($stream);
            return $stream;
        });

        return new MailMessage(
            new MailReference('support', 'INBOX', '42', '<source@example.org>'),
            new MailAddress('customer@example.org', 'Customer'),
            [new MailAddress('support@example.org'), new MailAddress('other@example.org')],
            [new MailAddress('other@example.org'), new MailAddress('copy@example.org')],
            'Question',
            new DateTimeImmutable('2026-09-09 10:00:00+00:00'),
            new MailBody("First line\n\nSecond line"),
            [$attachment],
            ['<older@example.org>'],
        );
    }
}
