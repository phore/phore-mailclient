<?php

declare(strict_types=1);

namespace Phore\MailClient\Test\Unit;

use Phore\MailClient\Domain\MailAddress;
use Phore\MailClient\Domain\MailDraft;
use Phore\MailClient\Support\MimeDraftBuilder;
use PHPUnit\Framework\TestCase;

final class MimeDraftBuilderTest extends TestCase
{
    public function testBuildsPlainTextMimeMessageFromMarkdown(): void
    {
        $draft = new MailDraft(
            from: new MailAddress('sender@example.org'),
            to: [new MailAddress('recipient@example.org')],
            cc: [],
            subject: 'Update',
            messageId: '<draft@example.org>',
            authoredMarkdown: "# Update\n\nDone.",
        );

        $mime = MimeDraftBuilder::build($draft);
        self::assertStringContainsString("Content-Type: text/plain; charset=UTF-8", $mime);
        self::assertStringContainsString("Message-ID: <draft@example.org>", $mime);
        self::assertStringContainsString("# Update", quoted_printable_decode($mime));
    }
}
