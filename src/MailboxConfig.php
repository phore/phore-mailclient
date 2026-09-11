<?php
declare(strict_types=1);
namespace Phore\MailClient;

use InvalidArgumentException;
use RuntimeException;
use Phore\MailClient\Internal\Headers;
use Phore\MailClient\Internal\SecretResolver;

/** Connection settings with either a literal password or a named secret reference. */
final readonly class MailboxConfig
{
    // Add every new file setting here, including its documentation and example.
    // The loader and generated JSON reference both use this definition.
    private const FIELDS = [
        'host' => ['type'=>'string', 'required'=>true, 'description'=>'IMAP hostname without a URL scheme. Implicit TLS and certificate verification are always enabled.', 'example'=>'imap.example.org'],
        'username' => ['type'=>'string', 'required'=>true, 'description'=>'Nonempty IMAP login name, independent of the optional From identity.', 'example'=>'support@example.org'],
        'password' => ['type'=>'string', 'required'=>false, 'description'=>'Literal nonempty password, used exactly as supplied. Specify exactly one of password or passwordFromSecretName. The file contains plaintext when using this option.', 'example'=>'example-literal-password'],
        'passwordFromSecretName' => ['type'=>'string', 'required'=>false, 'description'=>'Load the password by this name: process environment first; only an unset variable falls back to /var/run/secrets/<name>. Empty values fail. Names start with a letter or underscore and contain only letters, digits, underscores, dots or hyphens. One trailing LF or CRLF is removed from file contents. Mutually exclusive with password.', 'example'=>'SUPPORT_MAIL_PASSWORD'],
        'port' => ['type'=>'integer', 'required'=>false, 'default'=>993, 'minimum'=>1, 'maximum'=>65535, 'description'=>'IMAP implicit-TLS port.', 'example'=>993],
        'draftsFolder' => ['type'=>'string', 'required'=>false, 'default'=>'Drafts', 'description'=>'Exact nonempty server folder name used for storing drafts.', 'example'=>'Drafts'],
        'trashFolder' => ['type'=>'string', 'required'=>false, 'default'=>'Trash', 'description'=>'Exact nonempty server trash folder name. Moving requires native IMAP MOVE and UIDPLUS.', 'example'=>'Trash'],
        'from' => ['type'=>['string', 'null'], 'required'=>false, 'default'=>null, 'description'=>'Default author address, optionally with a display name. Null leaves the identity unspecified; an explicit message identity takes precedence.', 'example'=>'Support <support@example.org>'],
        'mode' => ['type'=>'string', 'required'=>false, 'default'=>'automatic', 'enum'=>['automatic', 'manual'], 'description'=>'Automatic mode enables automatic Seen and source Answered/Forwarded flags. Manual mode requires explicit flag actions.', 'example'=>'manual'],
    ];
    private ?\SensitiveParameterValue $password;

    /** Machine-readable documentation; this is a reference tree, not a mailbox config. */
    public static function reference(): array
    {
        return [
            'description'=>'Mailbox configuration reference. Use field examples to build a flat config object; do not pass this reference tree to fromFile().',
            'format'=>'JSON; fromArray() also accepts settings from an application parser.',
            'credentialRule'=>'Exactly one of password or passwordFromSecretName must be present and nonempty. Both, neither, and null credentials are rejected.',
            'unknownFields'=>'Rejected.',
            'fields'=>self::FIELDS,
        ];
    }


    private function __construct(
        public string $host,
        public string $username,
        public ?string $passwordFromSecretName,
        #[\SensitiveParameter] ?string $password,
        public int $port,
        public string $draftsFolder,
        public string $trashFolder,
        public ?string $from,
        public string $mode,
    ) { $this->password = $password === null ? null : new \SensitiveParameterValue($password); }

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
        if (array_diff(array_keys($data), array_keys(self::FIELDS)) !== []) {
            throw new InvalidArgumentException('Unknown mailbox setting.');
        }
        foreach (['host','username'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') { throw new InvalidArgumentException('Missing or invalid mailbox setting: ' . $key . '.'); }
        }
        $hasPassword = array_key_exists('password', $data);
        $hasReference = array_key_exists('passwordFromSecretName', $data);
        if ($hasPassword === $hasReference) { throw new InvalidArgumentException('Specify exactly one of password or passwordFromSecretName.'); }
        $credentialKey = $hasPassword ? 'password' : 'passwordFromSecretName';
        if (!is_string($data[$credentialKey]) || $data[$credentialKey] === '') { throw new InvalidArgumentException('Password or secret name must be a nonempty string.'); }
        $data += array_map(static fn(array $field): mixed => $field['default'] ?? null, self::FIELDS);
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
        if ($hasReference) { SecretResolver::validateName($data['passwordFromSecretName']); }
        return new self($data['host'], $data['username'], $data['passwordFromSecretName'], $data['password'], $data['port'], $data['draftsFolder'], $data['trashFolder'], $data['from'], $data['mode']);
    }

    /** Resolve the secret afresh for each connection. TLS remains certificate-verified. */
    public function connect(string $secretsDirectory = '/var/run/secrets', array $messageDefaults = []): MailClient
    {
        return MailClient::connect(
            host: $this->host, username: $this->username,
            password: $this->password !== null ? $this->password->getValue() : SecretResolver::resolve($this->passwordFromSecretName, $secretsDirectory),
            port: $this->port, draftsFolder: $this->draftsFolder, trashFolder: $this->trashFolder,
            from: $this->from, messageDefaults: $messageDefaults, mode: $this->mode,
        );
    }
}
