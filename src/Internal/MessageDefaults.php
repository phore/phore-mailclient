<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

use DateTimeZone;
use InvalidArgumentException;
use Phore\MailClient\Email;
use Phore\MailClient\EmailAddress;
use Phore\MailClient\Signature;

/** @internal */
final class MessageDefaults
{
    private const QUOTE = [
        'reply' => 'On {{date}}, {{from}} wrote:',
        'forward' => "-------- Forwarded message --------\nFrom: {{from}}\nDate: {{date}}\nSubject: {{subject}}\nTo: {{to}}\nCc: {{cc}}",
        'dateFormat' => 'd M Y H:i T', 'timezone' => 'UTC', 'missingDate' => 'unknown date', 'missingAuthor' => 'unknown author',
    ];
    public readonly array $quote;
    public readonly array $signatures;
    public readonly string $position;
    public function __construct(array $options = [])
    {
        self::keys($options, ['quote','signatures','signaturePosition']);
        $this->quote = self::quoteOptions($options['quote'] ?? []);
        $signatures = $options['signatures'] ?? [];
        self::keys($signatures, ['new','reply','forward']);
        foreach ($signatures as $value) {
            if ($value !== null && !$value instanceof Signature) { throw new InvalidArgumentException('Expected a Signature or null.'); }
        }
        $this->signatures = $signatures + ['new' => null, 'reply' => null, 'forward' => null];
        $this->position = self::position($options['signaturePosition'] ?? 'above-quote');
    }
    public static function position(string $position): string
    {
        if (!in_array($position, ['above-quote','below-quote'], true)) { throw new InvalidArgumentException('Unknown signature position.'); }
        return $position;
    }
    public static function quoteOptions(array $options): array
    {
        self::keys($options, array_keys(self::QUOTE));
        $options += self::QUOTE;
        foreach ($options as $v) { if (!is_string($v) || preg_match('//u', $v) !== 1) { throw new InvalidArgumentException('Invalid quotation setting.'); } }
        try { new DateTimeZone($options['timezone']); } catch (\Exception) { throw new InvalidArgumentException('Invalid quotation timezone.'); }
        if ($options['dateFormat'] === '' || strlen($options['dateFormat']) > 128 || preg_match('/[\x00-\x1f\x7f]/', $options['dateFormat'])) { throw new InvalidArgumentException('Invalid date format.'); }
        foreach (['reply','forward'] as $key) {
            preg_match_all('/\{\{(.*?)\}\}/s', $options[$key], $matches);
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, ['date','from','from.name','from.address','subject','to','cc'], true)) { throw new InvalidArgumentException('Unknown quotation placeholder.'); }
            }
            if (str_contains(preg_replace('/\{\{.*?\}\}/s', '', $options[$key]), '{{')) { throw new InvalidArgumentException('Unclosed quotation placeholder.'); }
        }
        return $options;
    }
    public static function introduction(Email $email, string $type, array $options): string
    {
        $options = self::quoteOptions($options);
        $join = static fn(array $addresses, string $method): string => implode(', ', array_map(static fn(EmailAddress $a): string => $a->$method(), $addresses));
        return strtr($options[$type], [
            '{{date}}' => $email->date()?->setTimezone(new DateTimeZone($options['timezone']))->format($options['dateFormat']) ?? $options['missingDate'],
            '{{from}}' => $email->from() === [] ? $options['missingAuthor'] : $join($email->from(), 'toString'),
            '{{from.name}}' => $email->from() === [] ? $options['missingAuthor'] : $join($email->from(), 'getDisplayName'),
            '{{from.address}}' => $email->from() === [] ? $options['missingAuthor'] : $join($email->from(), 'getAddress'),
            '{{subject}}' => $email->subject(), '{{to}}' => $join($email->to(), 'toString'), '{{cc}}' => $join($email->cc(), 'toString'),
        ]);
    }
    private static function keys(array $input, array $allowed): void
    { if (array_diff(array_keys($input), $allowed) !== []) { throw new InvalidArgumentException('Unknown message setting.'); } }
}
