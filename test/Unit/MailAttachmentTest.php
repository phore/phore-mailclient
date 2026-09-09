<?php

declare(strict_types=1);

namespace Phore\MailClient\Test\Unit;

use Phore\MailClient\Domain\MailAttachment;
use Phore\MailClient\Exception\AttachmentTooLargeException;
use PHPUnit\Framework\TestCase;

final class MailAttachmentTest extends TestCase
{
    public function testAttachmentIsOpenedLazilyWithinLimit(): void
    {
        $opened = false;
        $attachment = new MailAttachment('a1', 'note.txt', 'text/plain', 5, static function () use (&$opened) {
            $opened = true;
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'hello');
            rewind($stream);
            return $stream;
        });

        self::assertFalse($opened);
        $stream = $attachment->openStream(5);
        self::assertTrue($opened);
        self::assertSame('hello', stream_get_contents($stream));
    }

    public function testAttachmentLimitIsCheckedBeforeOpening(): void
    {
        $opened = false;
        $attachment = new MailAttachment('a1', 'note.txt', 'text/plain', 6, static function () use (&$opened) {
            $opened = true;
            return fopen('php://temp', 'w+b');
        });

        try {
            $attachment->openStream(5);
            self::fail('Expected size exception.');
        } catch (AttachmentTooLargeException) {
            self::assertFalse($opened);
        }
    }
}
