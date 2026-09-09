<?php

declare(strict_types=1);

namespace Phore\MailClient\Test\Unit;

use Phore\MailClient\Domain\MailBody;
use PHPUnit\Framework\TestCase;

final class MailBodyTest extends TestCase
{
    public function testPlainTextIsCanonicalMarkdownWhenAvailable(): void
    {
        $body = new MailBody(text: "Hello\nworld", html: '<p>ignored</p>');
        self::assertSame("Hello\nworld", $body->asText());
        self::assertSame("Hello\nworld", $body->asMarkdown());
        self::assertSame('<p>ignored</p>', $body->asHtml());
    }

    public function testHtmlOnlyBodyGetsMarkdownAndTextFallbacks(): void
    {
        $body = new MailBody(html: '<h1>Hello</h1><p>A <strong>bold</strong> text.</p><script>bad()</script>');
        self::assertSame("# Hello\n\nA **bold** text.", $body->asMarkdown());
        self::assertSame('HelloA bold text.', $body->asText());
        self::assertStringContainsString('<script>bad()</script>', $body->asHtml());
        self::assertStringNotContainsString('bad()', $body->asMarkdown());
    }
}
