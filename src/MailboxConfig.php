<?php
declare(strict_types=1);
namespace Phore\MailClient;

use InvalidArgumentException;
use RuntimeException;
use Phore\MailClient\Internal\Headers;
use Phore\MailClient\Internal\SecretResolver;

/** Connection settings only; resolved passwords are never stored in this object. */
final readonly class MailboxConfig
{
    private function __construct(
        public string $host,
        public string $username,
        public string $passwordSecret,
        public int $port,
        public string $draftsFolder,
        public string $trashFolder,
        public ?string $from,
        public string $mode,
    ) {}

    /** Load one mailbox from a local JSON object, without resolving secrets or connecting. */
    public static function fromFile(string $path): self
    {
        if (str_contains($path, '://') || !is_file($path)) { throw new RuntimeException('Mailbox config must be a readable local file.'); }
        $json = @file_get_contents($path);
        if ($json === false) { throw new RuntimeException('Cannot read mailbox config file.'); }
        try { $data = json_decode($json, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('Mailbox config must contain valid JSON.'); }
        if (!$data instanceof \stdClass) { throw new InvalidArgumentException('Mailbox config must be a JSON object.'); }
        return self::fromArray((array) $data);
    }

    /** Accept the same settings from an application's own config parser. */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        if (array_diff(array_keys($data), ['host','username','passwordSecret','port','draftsFolder','trashFolder','from','mode']) !== []) {
            throw new InvalidArgumentException('Unknown mailbox setting; use passwordSecret instead of an inline password.');
        }
        foreach (['host','username','passwordSecret'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') { throw new InvalidArgumentException('Missing or invalid mailbox setting: ' . $key . '.'); }
        }
        $data += ['port'=>993, 'draftsFolder'=>'Drafts', 'trashFolder'=>'Trash', 'from'=>null, 'mode'=>MailClient::MODE_AUTOMATIC];
        foreach (['host','username','draftsFolder','trashFolder','mode'] as $key) {
            if (!is_string($data[$key]) || $data[$key] === '') { throw new InvalidArgumentException('Invalid mailbox setting: ' . $key . '.'); }
            Headers::validate($data[$key]);
        }
        if (str_contains($data['host'], '://') || !is_int($data['port']) || $data['port'] < 1 || $data['port'] > 65535) {
            throw new InvalidArgumentException('Invalid IMAP host or port.');
        }
        if (!in_array($data['mode'], [MailClient::MODE_AUTOMATIC, MailClient::MODE_MANUAL], true)) { throw new InvalidArgumentException('Mode must be automatic or manual.'); }
        if ($data['from'] !== null) {
            if (!is_string($data['from'])) { throw new InvalidArgumentException('Mailbox from must be an address string or null.'); }
            EmailAddress::parse($data['from']);
        }
        SecretResolver::validateName($data['passwordSecret']);
        return new self($data['host'], $data['username'], $data['passwordSecret'], $data['port'], $data['draftsFolder'], $data['trashFolder'], $data['from'], $data['mode']);
    }

    /** Resolve the secret afresh for each connection. TLS remains certificate-verified. */
    public function connect(string $secretsDirectory = '/var/run/secrets', array $messageDefaults = []): MailClient
    {
        return MailClient::connect(
            host: $this->host, username: $this->username,
            password: SecretResolver::resolve($this->passwordSecret, $secretsDirectory),
            port: $this->port, draftsFolder: $this->draftsFolder, trashFolder: $this->trashFolder,
            from: $this->from, messageDefaults: $messageDefaults, mode: $this->mode,
        );
    }
}
