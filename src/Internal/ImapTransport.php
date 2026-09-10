<?php
declare(strict_types=1);
namespace Phore\MailClient\Internal;

use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/** @internal Uses Webklex's pure PHP protocol with explicit UID and PEEK commands. */
final class ImapTransport implements Transport
{
    private Client $client;
    private ImapProtocol $protocol;
    private array $permanentFlags = [];
    public function __construct(string $host, string $username, #[\SensitiveParameter] string $password, int $port)
    {
        $this->client = (new ClientManager())->make([
            'host' => $host, 'port' => $port, 'username' => $username, 'password' => $password,
            'protocol' => 'imap', 'encryption' => 'ssl', 'validate_cert' => true, 'timeout' => 30,
        ]);
        try {
            $this->client->connect(); $protocol = $this->client->getConnection();
            if (!$protocol instanceof ImapProtocol) { throw new RuntimeException('Pure PHP IMAP required.'); }
            $this->protocol = $protocol;
        } catch (\Throwable $error) {
            // Keep diagnostic types, never server text, credentials or a sensitive trace.
            $types = [$error::class]; $cause = $error->getPrevious();
            while ($cause !== null && count($types) < 4) { $types[] = $cause::class; $cause = $cause->getPrevious(); }
            throw new RuntimeException('IMAP connection failed: ' . implode(' / ',$types));
        }
    }
    public function select(string $folder, bool $write = false): array
    {
        Headers::validate($folder);
        $wire = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        $response = $write ? $this->protocol->selectFolder($wire) : $this->protocol->examineFolder($wire);
        $status = $response->validatedData();
        if (!isset($status['uidvalidity'])) { throw new RuntimeException('Server omitted UIDVALIDITY.'); }
        $this->permanentFlags = [];
        if (preg_match('/\[PERMANENTFLAGS\s+\(([^)]*)\)\]/i', self::lines($response), $m)) {
            $this->permanentFlags = preg_split('/\s+/', trim($m[1]));
        }
        return $status;
    }
    public function search(array $criteria): array
    {
        $tokens = [];
        foreach ($criteria as $key => $value) {
            if ($key === 'after') { $tokens[] = 'UID'; $tokens[] = ((int)$value + 1) . ':*'; }
            elseif ($key === 'messageId') { $tokens[] = 'HEADER'; $tokens[] = 'Message-ID'; $tokens[] = $this->protocol->escapeString(Headers::validate($value)); }
            else { throw new \InvalidArgumentException('Unsupported search criterion.'); }
        }
        $ids = array_map('intval', $this->protocol->search($tokens ?: ['ALL'])->validatedData());
        sort($ids, SORT_NUMERIC);
        // n:* can include the current highest UID when n exceeds it.
        return array_values(array_filter($ids, static fn(int $id): bool => $id > ($criteria['after'] ?? 0)));
    }
    public function metadata(int $uid): array
    {
        // Webklex 6.2's scalar-UID path reads beyond the tagged completion.
        $rows = $this->protocol->fetch(['UID','FLAGS','RFC822.SIZE','BODYSTRUCTURE'], [$uid])->validatedData();
        return $rows[$uid] ?? throw new RuntimeException('Message no longer exists.');
    }
    public function part(int $uid, string $section, int $maxBytes): string
    {
        if ($maxBytes < 1 || !preg_match('/^(HEADER|[1-9][0-9]*(?:\.[1-9][0-9]*)*)$/D', $section)) { throw new \InvalidArgumentException('Invalid part request.'); }
        $rows = $this->protocol->fetch(['UID', 'BODY.PEEK[' . $section . ']<0.' . ($maxBytes + 1) . '>'], [$uid])->validatedData();
        $data = $rows[$uid] ?? throw new RuntimeException('Message no longer exists.');
        foreach ($data as $key => $value) {
            if (str_starts_with(strtoupper((string)$key), 'BODY[')) {
                if (!is_string($value) || strlen($value) > $maxBytes) { throw new RuntimeException('MIME part exceeds byte limit.'); }
                return $value;
            }
        }
        throw new RuntimeException('Server did not return the requested MIME part.');
    }
    public function append(string $folder, string $mime): void
    { $this->protocol->appendMessage(mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8'), $mime, ['\\Draft'])->validate(); }
    public function flag(int $uid, string $flag, bool $add): void
    {
        $known = in_array(strtolower($flag), array_map('strtolower', $this->permanentFlags), true);
        if (!$known && (str_starts_with($flag, '\\') || !in_array('\\*', $this->permanentFlags, true))) { throw new RuntimeException('Server does not support this permanent flag.'); }
        $this->protocol->store([$flag], $uid, mode: $add ? '+' : '-')->validate();
    }
    public function move(int $uid, string $folder): array
    {
        $capabilities = array_map('strtoupper', $this->protocol->getCapabilities()->validatedData());
        if (!in_array('MOVE', $capabilities, true) || !in_array('UIDPLUS', $capabilities, true)) { throw new RuntimeException('MOVE and UIDPLUS are required for safe trash operations.'); }
        // Do NOT use Webklex::moveMessage(), which falls back to COPY/DELETE/EXPUNGE.
        $response = $this->protocol->requestAndResponse('UID MOVE', [(string)$uid, $this->protocol->escapeString(mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8'))], true);
        $response->validate();
        if (!preg_match('/\[COPYUID\s+(\d+)\s+' . $uid . '\s+(\d+)\]/i', self::lines($response), $m)) {
            throw new RuntimeException('MOVE completed without a usable destination reference; resynchronize the folder.');
        }
        return [(int)$m[1], (int)$m[2]];
    }
    private static function lines(Response $response): string
    {
        $strings = []; $walk = static function ($value) use (&$walk, &$strings): void {
            if (is_array($value)) { foreach ($value as $v) { $walk($v); } } elseif (is_string($value)) { $strings[] = $value; }
        };
        $walk($response->getResponse()); $walk($response->data());
        return implode("\n", $strings);
    }
}
