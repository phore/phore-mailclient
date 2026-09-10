<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

use Phore\MailClient\Attachment;
use Phore\MailClient\Email;
use Phore\MailClient\EmailAddress;
use RuntimeException;

/** @internal Deterministic MIME builder and bounded BODYSTRUCTURE traversal. */
final class Mime
{
    public static function fingerprint(Email $email, array $files): string
    {
        $addresses = static fn(array $list): array => array_map(static fn(EmailAddress $a): array => [$a->getAddress(),$a->getName()], $list);
        return hash('sha256', json_encode([
            $addresses($email->from()),$addresses($email->to()),$addresses($email->cc()),$addresses($email->bcc()),$addresses($email->replyTo()),
            $email->sender()?->toString(),$email->subject(),$email->messageId(),$email->date()?->format('U'),$email->inReplyTo(),$email->references(),
            self::lf($email->body()->text()),$email->body()->html() === null ? null : self::lf($email->body()->html()),
            array_map(static fn(Attachment $a): array => [$a->filename(),$a->mediaType(),$a->contentId,hash('sha256',$a->content)], $files),
        ], JSON_THROW_ON_ERROR));
    }
    public static function build(Email $email, array $files): string
    {
        if ($email->from() === [] || Headers::messageId($email->messageId()) === null) { throw new \InvalidArgumentException('Draft requires From and valid Message-ID.'); }
        $header = [];
        foreach (['From'=>$email->from(),'To'=>$email->to(),'Cc'=>$email->cc(),'Bcc'=>$email->bcc(),'Reply-To'=>$email->replyTo(),'Sender'=>$email->sender() === null ? [] : [$email->sender()]] as $name=>$addresses) {
            if ($addresses !== []) {
                $header[] = $name . ': ' . implode(",\r\n ", array_map(static function(EmailAddress $a): string {
                    Headers::validate($a->getAddress());
                    return ($a->getName() === null ? '' : Headers::encoded($a->getName()) . ' ') . '<' . $a->getAddress() . '>';
                }, $addresses));
            }
        }
        $header[] = 'Subject: ' . Headers::encoded($email->subject());
        $header[] = 'Message-ID: ' . $email->messageId();
        if ($email->date() !== null) { $header[] = 'Date: ' . $email->date()->format(DATE_RFC2822); }
        if ($email->inReplyTo() !== null) { $header[] = 'In-Reply-To: ' . Headers::validate($email->inReplyTo()); }
        if ($email->references() !== []) { $header[] = "References:\r\n " . implode("\r\n ", array_map(Headers::validate(...), $email->references())); }
        $header[] = 'MIME-Version: 1.0';
        $seed = self::fingerprint($email, $files);
        $plain = self::textPart('plain', $email->body()->text());
        $html = $email->body()->html();
        $part = $html === null ? $plain : self::multipart('alternative', [$plain,self::textPart('html', $html)], $seed . '_a');
        $inline = []; $regular = []; $ids = [];
        foreach ($files as $file) {
            if ($file->contentId !== null) {
                if (isset($ids[$file->contentId])) { throw new \InvalidArgumentException('Duplicate Content-ID.'); }
                $ids[$file->contentId] = true; $inline[] = self::filePart($file);
            } else { $regular[] = self::filePart($file); }
        }
        if ($inline !== []) { $part = self::multipart('related', [$part,...$inline], $seed . '_r'); }
        if ($regular !== []) { $part = self::multipart('mixed', [$part,...$regular], $seed . '_m'); }
        return implode("\r\n", $header) . "\r\n" . $part;
    }
    private static function textPart(string $type, string $body): string
    { return 'Content-Type: text/' . $type . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode(self::lf($body)), 76, "\r\n")); }
    private static function multipart(string $type, array $parts, string $boundary): string
    { return 'Content-Type: multipart/' . $type . '; boundary="' . $boundary . '"' . "\r\n\r\n--" . $boundary . "\r\n" . implode("\r\n--" . $boundary . "\r\n", $parts) . "\r\n--" . $boundary . '--'; }
    private static function filePart(Attachment $file): string
    {
        Headers::validate($file->filename()); Headers::validate($file->mediaType());
        $parameters = [];
        $encoded = rawurlencode($file->filename()); $chunks = [];
        // Keep each percent-encoded octet intact across RFC2231 continuations.
        preg_match_all('/%[0-9A-F]{2}|./', $encoded, $tokens); $chunk = '';
        foreach ($tokens[0] as $token) {
            if (strlen($chunk) + strlen($token) > 42) { $chunks[] = $chunk; $chunk = ''; }
            $chunk .= $token;
        }
        $chunks[] = $chunk;
        foreach ($chunks as $i=>$value) { $parameters[] = 'filename*' . $i . '*=' . ($i === 0 ? "UTF-8''" : '') . $value; }
        $header = 'Content-Type: ' . $file->mediaType() . "\r\nContent-Disposition: " . ($file->contentId === null ? 'attachment' : 'inline') . ";\r\n " . implode(";\r\n ", $parameters);
        if ($file->contentId !== null) { $header .= "\r\nContent-ID: <" . $file->contentId . '>'; }
        return $header . "\r\nContent-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($file->content), 76, "\r\n"));
    }
    public static function decode(string $bytes, string $encoding, ?string $charset = null): string
    {
        $bytes = match (strtoupper($encoding)) {
            'BASE64' => base64_decode(preg_replace('/\s+/', '', $bytes), true),
            'QUOTED-PRINTABLE' => quoted_printable_decode($bytes),
            '7BIT','8BIT','BINARY','' => $bytes,
            default => throw new RuntimeException('Unsupported transfer encoding.'),
        };
        if ($bytes === false) { throw new RuntimeException('Invalid base64 MIME body.'); }
        if ($charset !== null) {
            try { $bytes = mb_convert_encoding($bytes, 'UTF-8', $charset); } catch (\ValueError) { throw new RuntimeException('Unsupported MIME charset.'); }
        }
        return $bytes;
    }
    public static function leaves(array $structure, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 20 || $structure === []) { throw new RuntimeException('Invalid or deeply nested MIME structure.'); }
        if (is_array($structure[0])) {
            $leaves = [];
            foreach ($structure as $i=>$part) {
                if (!is_array($part)) { break; }
                $leaves = [...$leaves,...self::leaves($part, $prefix === '' ? (string)($i+1) : $prefix . '.' . ($i+1), $depth+1)];
                if (count($leaves) > 200) { throw new RuntimeException('Too many MIME parts.'); }
            }
            return $leaves;
        }
        $type = strtolower((string)($structure[0] ?? '')); $subtype = strtolower((string)($structure[1] ?? ''));
        $params = self::parameters($structure[2] ?? []);
        $dispositionIndex = $type === 'text' ? 9 : ($type === 'message' && $subtype === 'rfc822' ? 11 : 8);
        $disposition = $structure[$dispositionIndex] ?? [];
        $dispositionParams = self::parameters(is_array($disposition) ? ($disposition[1] ?? []) : []);
        $filename = self::parameterValue($dispositionParams, 'filename') ?? self::parameterValue($params, 'name');
        $contentId = trim((string)($structure[3] ?? ''), '<>');
        return [[
            'part'=>$prefix === '' ? '1' : $prefix,'type'=>$type . '/' . $subtype,'charset'=>$params['charset'] ?? 'UTF-8',
            'encoding'=>strtoupper((string)($structure[5] ?? '7BIT')),'size'=>(int)($structure[6] ?? 0),
            'filename'=>$filename,'cid'=>$contentId === '' ? null : $contentId,
            'attachment'=>$filename !== null || strtolower((string)(is_array($disposition) ? ($disposition[0] ?? '') : '')) === 'attachment' || $type !== 'text',
        ]];
    }
    private static function parameters(mixed $values): array
    {
        if (!is_array($values)) { return []; } $result = [];
        for ($i=0; $i<count($values); $i+=2) { $result[strtolower((string)$values[$i])] = (string)($values[$i+1] ?? ''); }
        return $result;
    }
    private static function parameterValue(array $params, string $name): ?string
    {
        $value = $params[$name . '*'] ?? null;
        if (isset($params[$name . '*0*']) || isset($params[$name . '*0'])) {
            $value = ''; $i = 0;
            while (isset($params[$name . '*' . $i . '*']) || isset($params[$name . '*' . $i])) {
                $value .= $params[$name . '*' . $i . '*'] ?? $params[$name . '*' . $i]; $i++;
            }
        }
        if ($value !== null && preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $m)) {
            return Headers::validate(self::decode(rawurldecode($m[2]), 'BINARY', $m[1] ?: 'UTF-8'));
        }
        return isset($params[$name]) ? Headers::decode($params[$name]) : ($value === null ? null : Headers::validate($value));
    }
    private static function lf(string $text): string { return str_replace(["\r\n","\r"], "\n", $text); }
}
