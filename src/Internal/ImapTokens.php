<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;
use RuntimeException;

/** @internal Parse one FETCH payload, preserving quoted strings and exact literals. */
final class ImapTokens
{
    private int $offset = 0;
    private function __construct(private string $input) {}
    public static function fetch(string $response, int $uid): array
    {
        $offset = 0;
        while (preg_match('/(?:^|\r?\n)\* [0-9]+ FETCH /i',$response,$match,PREG_OFFSET_CAPTURE,$offset)) {
            $start = $match[0][1] + strlen($match[0][0]);
            $parser = new self(substr($response,$start)); $tokens = $parser->value();
            if (!is_array($tokens) || count($tokens) % 2 !== 0) { throw new RuntimeException('Malformed FETCH payload.'); }
            $result = [];
            for ($i=0;$i<count($tokens);$i+=2) {
                if (!is_string($tokens[$i])) { throw new RuntimeException('Malformed FETCH field.'); }
                $result[strtoupper($tokens[$i])] = $tokens[$i+1];
            }
            if ((int)($result['UID'] ?? 0) === $uid) { return $result; }
            $offset = $start + $parser->offset;
        }
        throw new RuntimeException('Message no longer exists.');
    }
    private function value(int $depth = 0): mixed
    {
        if ($depth > 40) { throw new RuntimeException('IMAP response nesting limit exceeded.'); }
        while (isset($this->input[$this->offset]) && str_contains(" \r\n\t",$this->input[$this->offset])) { $this->offset++; }
        $char = $this->input[$this->offset] ?? throw new RuntimeException('Truncated IMAP response.');
        if ($char === '(') {
            $this->offset++; $result = [];
            while (true) {
                while (isset($this->input[$this->offset]) && str_contains(" \r\n\t",$this->input[$this->offset])) { $this->offset++; }
                if (($this->input[$this->offset] ?? '') === ')') { $this->offset++; return $result; }
                if (count($result) >= 2000) { throw new RuntimeException('IMAP response token limit exceeded.'); }
                $result[] = $this->value($depth+1);
            }
        }
        if ($char === '"') {
            $this->offset++; $text = '';
            while (isset($this->input[$this->offset])) {
                $char = $this->input[$this->offset++];
                if ($char === '"') { return $text; }
                if ($char === '\\') { $char = $this->input[$this->offset++] ?? throw new RuntimeException('Truncated quoted IMAP string.'); }
                $text .= $char;
            }
            throw new RuntimeException('Unclosed IMAP string.');
        }
        if ($char === '{') {
            if (!preg_match('/\G\{([0-9]+)\+?\}\r?\n/',$this->input,$m,0,$this->offset)) { throw new RuntimeException('Invalid IMAP literal.'); }
            $this->offset += strlen($m[0]); $length = (int)$m[1];
            if ($length > strlen($this->input) - $this->offset) { throw new RuntimeException('Truncated IMAP literal.'); }
            $text = substr($this->input,$this->offset,$length); $this->offset += $length; return $text;
        }
        if (!preg_match('/\G[^\s()]+/',$this->input,$m,0,$this->offset)) { throw new RuntimeException('Invalid IMAP token.'); }
        $this->offset += strlen($m[0]);
        return strtoupper($m[0]) === 'NIL' ? null : $m[0];
    }
}
