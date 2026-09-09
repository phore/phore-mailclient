<?php

declare(strict_types=1);

namespace Phore\MailClient\Support;

use Phore\MailClient\Domain\MailMessage;

final class MarkdownQuote
{
    public static function reply(MailMessage $message): string
    {
        $intro = sprintf('On %s, %s wrote:', $message->date->format('Y-m-d H:i'), (string) $message->from);
        return $intro . "\n\n" . self::quote($message->body->markdown);
    }

    public static function forward(MailMessage $message): string
    {
        $to = implode(', ', array_map(static fn ($address): string => (string) $address, $message->to));
        $header = implode("\n", [
            'Forwarded message',
            'From: ' . $message->from,
            'Date: ' . $message->date->format(DATE_RFC2822),
            'Subject: ' . $message->subject,
            'To: ' . $to,
        ]);
        return self::quote($header . "\n\n" . $message->body->markdown);
    }

    private static function quote(string $markdown): string
    {
        return implode("\n", array_map(static fn (string $line): string => '> ' . $line, explode("\n", $markdown)));
    }
}
