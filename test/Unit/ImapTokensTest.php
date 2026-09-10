<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Unit;
use PHPUnit\Framework\TestCase;
use Phore\MailClient\Internal\{ImapTokens,Mime};
final class ImapTokensTest extends TestCase
{
    public function testBodyStructureStringsAdjacentToParentheses(): void
    {
        $response = '* 1 FETCH (UID 7 FLAGS () BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "BASE64" 4 1 NIL NIL NIL NIL))' . "\r\nTAG1 OK done\r\n";
        $meta = ImapTokens::fetch($response,7); $parts = Mime::leaves($meta['BODYSTRUCTURE']);
        self::assertSame('UTF-8',$parts[0]['charset']); self::assertNull($parts[0]['cid']);
        self::assertSame('text/plain',$parts[0]['type']); self::assertSame([],$meta['FLAGS']);
    }
    public function testBinaryLiteralAndEmptyQuotedString(): void
    {
        $bytes = "a\0b\r\nc";
        $response = '* 1 FETCH (UID 7 BODY[2]<0> {' . strlen($bytes) . "}\r\n" . $bytes . ")\r\nTAG1 OK done\r\n";
        self::assertSame($bytes,ImapTokens::fetch($response,7)['BODY[2]<0>']);
        self::assertSame('',ImapTokens::fetch('* 1 FETCH (UID 7 BODY[1] "")',7)['BODY[1]']);
    }
}
