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
    private ?\SensitiveParameterValue $password;

    private function __construct(
        public string $host,
        public string $username,
        public ?string $passwordFromSecretName,
        #[\SensitiveParameter] ?string $password,
        public int $port,
        public string $draftsFolder,
        public string $trashFolder,
        public string $incomingFolder,
        public string $sentFolder,
        public string $junkFolder,
        public array $managedFolders,
        public array $automationFlags,
        public ?string $from,
        public ?Signature $signature,
        public string $mode,
    ) { $this->password = $password === null ? null : new \SensitiveParameterValue($password); }

    /** Load one mailbox from a local JSON or YAML mapping, without resolving secrets or connecting. */
    public static function fromFile(string $path): self
    {
        if (str_contains($path, '://') || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Mailbox config must be a readable local file: ' . $path . '.');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) { throw new RuntimeException('Cannot read mailbox config file: ' . $path . '.'); }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['yaml', 'yml'], true)) {
            if (!function_exists('yaml_parse')) {
                throw new RuntimeException('Cannot parse YAML mailbox config because yaml_parse() is unavailable: ' . $path . '.');
            }
            $data = @yaml_parse($contents);
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('Mailbox config must contain a YAML mapping: ' . $path . '.');
            }
            return self::fromArray($data);
        }

        try { $data = json_decode($contents, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new InvalidArgumentException('Mailbox config must contain valid JSON: ' . $path . '.'); }
        if (!$data instanceof \stdClass) { throw new InvalidArgumentException('Mailbox config must be a JSON object: ' . $path . '.'); }
        return self::fromArray((array) $data);
    }

    /** Accept the same settings from an application's own config parser. */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        if (array_diff(array_keys($data), ['host','username','password','passwordFromSecretName','port','draftsFolder','trashFolder','incomingFolder','sentFolder','junkFolder','managedFolders','automationFlags','from','signature','mode']) !== []) {
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
        $data += [
            'password'=>null,
            'passwordFromSecretName'=>null,
            'port'=>993,
            'draftsFolder'=>'Drafts',
            'trashFolder'=>'Trash',
            'incomingFolder'=>'INBOX',
            'sentFolder'=>'Sent',
            'junkFolder'=>'Junk',
            'managedFolders'=>[],
            'automationFlags'=>[],
            'from'=>null,
            'signature'=>null,
            'mode'=>MailClient::MODE_AUTOMATIC,
        ];
        foreach (['host','username','draftsFolder','trashFolder','incomingFolder','sentFolder','junkFolder','mode'] as $key) {
            if (!is_string($data[$key]) || $data[$key] === '') { throw new InvalidArgumentException('Invalid mailbox setting: ' . $key . '.'); }
            Headers::validate($data[$key]);
        }
        if (!is_array($data['managedFolders']) || ($data['managedFolders'] !== [] && array_is_list($data['managedFolders']))) {
            throw new InvalidArgumentException('Mailbox managedFolders must be an alias-to-folder mapping.');
        }
        foreach ($data['managedFolders'] as $alias => $folder) {
            if (!is_string($alias) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $alias)) {
                throw new InvalidArgumentException('Invalid managed folder alias: ' . (string)$alias . '.');
            }
            if (!is_string($folder) || $folder === '') {
                throw new InvalidArgumentException('Invalid managed folder name for alias: ' . $alias . '.');
            }
            Headers::validate($folder);
            if (strcasecmp($folder, 'INBOX') === 0) { $data['managedFolders'][$alias] = 'INBOX'; }
        }
        if (!is_array($data['automationFlags']) || ($data['automationFlags'] !== [] && array_is_list($data['automationFlags']))) {
            throw new InvalidArgumentException('Mailbox automationFlags must be a name-to-IMAP-keyword mapping.');
        }
        $unknownAutomationFlags = array_diff(array_keys($data['automationFlags']), ['processed','error','actionRequired']);
        if ($unknownAutomationFlags !== []) {
            throw new InvalidArgumentException('Unknown mailbox automation flag: ' . (string)reset($unknownAutomationFlags) . '.');
        }
        $data['automationFlags'] = array_replace([
            'processed'=>'lack_processed',
            'error'=>'lack_error',
            'actionRequired'=>'lack_action_required',
        ], $data['automationFlags']);
        foreach ($data['automationFlags'] as $name => $flag) {
            if (!is_string($flag) || !preg_match('/^[A-Za-z0-9$][A-Za-z0-9$_.-]{0,63}$/D', $flag)) {
                throw new InvalidArgumentException('Invalid IMAP keyword for automation flag: ' . $name . '.');
            }
        }
        if (str_contains($data['host'], '://') || !is_int($data['port']) || $data['port'] < 1 || $data['port'] > 65535) {
            throw new InvalidArgumentException('Invalid IMAP host or port.');
        }
        if (!in_array($data['mode'], [MailClient::MODE_AUTOMATIC, MailClient::MODE_MANUAL], true)) { throw new InvalidArgumentException('Mode must be automatic or manual.'); }
        if ($data['from'] !== null) {
            if (!is_string($data['from'])) { throw new InvalidArgumentException('Mailbox from must be an address string or null.'); }
            EmailAddress::parse($data['from']);
        }
        if ($data['signature'] !== null && (!is_string($data['signature']) || $data['signature'] === '')) {
            throw new InvalidArgumentException('Mailbox signature must be a nonempty Markdown string or null.');
        }
        if ($hasReference) { SecretResolver::validateName($data['passwordFromSecretName']); }
        return new self(
            $data['host'],
            $data['username'],
            $data['passwordFromSecretName'],
            $data['password'],
            $data['port'],
            $data['draftsFolder'],
            $data['trashFolder'],
            $data['incomingFolder'],
            $data['sentFolder'],
            $data['junkFolder'],
            $data['managedFolders'],
            $data['automationFlags'],
            $data['from'],
            $data['signature'] === null ? null : Signature::fromMarkdown($data['signature']),
            $data['mode'],
        );
    }

    /** Resolve the secret afresh for each connection. TLS remains certificate-verified. */
    public function connect(string $secretsDirectory = '/var/run/secrets', array $messageDefaults = []): MailClient
    {
        if ($this->signature !== null) {
            $signatures = $messageDefaults['signatures'] ?? [];
            foreach (['new','reply','forward'] as $type) {
                if (!array_key_exists($type, $signatures)) { $signatures[$type] = $this->signature; }
            }
            $messageDefaults['signatures'] = $signatures;
        }
        return MailClient::connect(
            host: $this->host,
            username: $this->username,
            password: $this->password !== null ? $this->password->getValue() : SecretResolver::resolve($this->passwordFromSecretName, $secretsDirectory),
            port: $this->port,
            draftsFolder: $this->draftsFolder,
            trashFolder: $this->trashFolder,
            from: $this->from,
            messageDefaults: $messageDefaults,
            mode: $this->mode,
            incomingFolder: $this->incomingFolder,
            sentFolder: $this->sentFolder,
            junkFolder: $this->junkFolder,
            managedFolders: $this->managedFolders,
            automationFlags: $this->automationFlags,
        );
    }
}
