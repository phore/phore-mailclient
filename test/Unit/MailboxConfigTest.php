<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phore\MailClient\MailboxConfig;
use Phore\MailClient\Internal\SecretResolver;

final class MailboxConfigTest extends TestCase
{
    private string $directory;
    private string $secret;
    protected function setUp(): void
    {
        $this->secret = 'PHORE_MAIL_TEST_' . bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir() . '/' . $this->secret;
        mkdir($this->directory, 0700);
    }
    protected function tearDown(): void
    {
        putenv($this->secret);
        foreach (glob($this->directory . '/*') as $path) { unlink($path); }
        rmdir($this->directory);
    }
    private function settings(): array
    { return ['host'=>'imap.example.org', 'username'=>'me@example.org', 'passwordFromSecretName'=>$this->secret]; }

    public function testDocumentedReferenceMatchesGeneratedFileAndBothExamplesLoad(): void
    {
        $reference = MailboxConfig::reference();
        $path = dirname(__DIR__, 2) . '/mailbox-config.reference.json';
        self::assertSame($reference, json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        $examples = [];
        foreach ($reference['fields'] as $name => $field) {
            self::assertNotEmpty($field['description'], $name);
            self::assertArrayHasKey('type', $field, $name);
            self::assertArrayHasKey('required', $field, $name);
            self::assertArrayHasKey('example', $field, $name);
            $examples[$name] = $field['example'];
        }
        foreach (['password', 'passwordFromSecretName'] as $omit) {
            $settings = $examples;
            unset($settings[$omit]);
            $config = MailboxConfig::fromArray($settings);
            self::assertSame('imap.example.org', $config->host);
            self::assertSame('manual', $config->mode);
        }
        $settings = $examples;
        unset($settings['password']);
        foreach ($reference['fields'] as $name => $field) {
            if (array_key_exists('default', $field)) { unset($settings[$name]); }
        }
        $config = MailboxConfig::fromArray($settings);
        foreach ($reference['fields'] as $name => $field) {
            if (array_key_exists('default', $field)) { self::assertSame($field['default'], $config->$name, $name); }
        }
    }
    public function testLoadDoesNotRequireSecretAndUsesConnectionDefaults(): void
    {
        $path = $this->directory . '/mailbox.json';
        file_put_contents($path, json_encode($this->settings(), JSON_THROW_ON_ERROR));
        $config = MailboxConfig::fromFile($path);
        self::assertEquals(MailboxConfig::fromArray($this->settings()), $config);
        self::assertSame('imap.example.org', $config->host);
        self::assertSame('me@example.org', $config->username);
        self::assertSame($this->secret, $config->passwordFromSecretName);
        self::assertSame(993, $config->port);
        self::assertSame('Drafts', $config->draftsFolder);
        self::assertSame('Trash', $config->trashFolder);
        self::assertSame('automatic', $config->mode);
        self::assertNull($config->from);
    }
    public function testLiteralPasswordLoadsWithoutSecretLookupAndIsRedacted(): void
    {
        $settings = $this->settings();
        unset($settings['passwordFromSecretName']);
        $settings['password'] = ' literal-password-value ';
        $path = $this->directory . '/mailbox.json';
        file_put_contents($path, json_encode($settings, JSON_THROW_ON_ERROR));
        $config = MailboxConfig::fromFile($path);
        self::assertNull($config->passwordFromSecretName);
        self::assertStringNotContainsString('literal-password-value', print_r($config, true));
        ob_start();
        var_dump($config);
        $dump = ob_get_clean();
        self::assertStringNotContainsString('literal-password-value', $dump);
        $this->expectException(\Exception::class);
        serialize($config);
    }
    #[DataProvider('invalidCredentials')]
    public function testExactlyOneNonemptyCredentialIsRequired(array $credentials): void
    {
        $settings = $this->settings();
        unset($settings['passwordFromSecretName']);
        $this->expectException(\InvalidArgumentException::class);
        MailboxConfig::fromArray($settings + $credentials);
    }
    public static function invalidCredentials(): iterable
    {
        foreach ([[], ['password'=>null], ['password'=>''], ['password'=>42], ['password'=>false], ['password'=>[]], ['passwordFromSecretName'=>null], ['password'=>'literal', 'passwordFromSecretName'=>null], ['password'=>null, 'passwordFromSecretName'=>'NAME'], ['passwordSecret'=>'OLD_NAME']] as $credentials) { yield [$credentials]; }
    }
    public function testExplicitOptionsAreRetained(): void
    {
        $config = MailboxConfig::fromArray($this->settings() + ['port'=>1993, 'draftsFolder'=>'Entwürfe', 'trashFolder'=>'Papierkorb', 'from'=>'Support <support@example.org>', 'mode'=>'manual']);
        self::assertSame(1993, $config->port);
        self::assertSame('Entwürfe', $config->draftsFolder);
        self::assertSame('Papierkorb', $config->trashFolder);
        self::assertSame('Support <support@example.org>', $config->from);
        self::assertSame('manual', $config->mode);
    }
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsFailDuringLoading(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailboxConfig::fromArray(array_replace($this->settings(), $changes));
    }
    public static function invalidSettings(): iterable
    {
        foreach ([['password'=>'do-not-store'], ['unknown'=>true], ['host'=>''], ['host'=>"host\n"], ['host'=>'ssl://host'], ['username'=>null], ['port'=>'993'], ['port'=>0], ['port'=>65536], ['port'=>true], ['draftsFolder'=>''], ['trashFolder'=>[]], ['mode'=>'typo'], ['from'=>42], ['from'=>'invalid'], ['passwordFromSecretName'=>''], ['passwordFromSecretName'=>'../outside'], ['passwordFromSecretName'=>'/absolute'], ['passwordFromSecretName'=>'a/b'], ['passwordFromSecretName'=>"BAD\n"], ['passwordFromSecretName'=>'a\\b']] as $changes) { yield [$changes]; }
    }
    #[DataProvider('invalidJson')]
    public function testInvalidFilesAreRejected(string $json): void
    {
        $path = $this->directory . '/mailbox.json';
        file_put_contents($path, $json);
        $this->expectException(\InvalidArgumentException::class);
        MailboxConfig::fromFile($path);
    }
    public static function invalidJson(): iterable
    {
        foreach (['', '{', 'null', '[]', '"text"', '{}', '{"host":"host"}'] as $json) { yield [$json]; }
    }
    public function testMissingConfigFileFailsClearly(): void
    {
        $this->expectException(\RuntimeException::class);
        MailboxConfig::fromFile($this->directory . '/missing.json');
    }
    public function testFileFallbackPreservesWhitespaceAndEnvironmentTakesPrecedence(): void
    {
        file_put_contents($this->directory . '/' . $this->secret, " file password \r\n");
        self::assertSame(' file password ', SecretResolver::resolve($this->secret, $this->directory));
        putenv($this->secret . '=env password');
        self::assertSame('env password', SecretResolver::resolve($this->secret, $this->directory));
        putenv($this->secret . '=rotated password');
        self::assertSame('rotated password', SecretResolver::resolve($this->secret, $this->directory));
        putenv($this->secret . '=0');
        self::assertSame('0', SecretResolver::resolve($this->secret, $this->directory));
        putenv($this->secret);
        file_put_contents($this->directory . '/' . $this->secret, "two\n\n");
        self::assertSame("two\n", SecretResolver::resolve($this->secret, $this->directory));
    }
    public function testEmptyEnvironmentDoesNotFallBackToFile(): void
    {
        file_put_contents($this->directory . '/' . $this->secret, 'file password');
        putenv($this->secret . '=');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Password secret is empty.');
        SecretResolver::resolve($this->secret, $this->directory);
    }
    public function testEmptyFileIsRejected(): void
    {
        file_put_contents($this->directory . '/' . $this->secret, "\n");
        $this->expectException(\RuntimeException::class);
        SecretResolver::resolve($this->secret, $this->directory);
    }
    public function testMissingSecretFailsBeforeConnecting(): void
    {
        $config = MailboxConfig::fromArray($this->settings());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Password secret is missing');
        $config->connect($this->directory);
    }
    public function testTraversalIsRejectedEvenIfEnvironmentContainsIt(): void
    {
        $name = '../' . $this->secret;
        putenv($name . '=do-not-use');
        try {
            $this->expectException(\InvalidArgumentException::class);
            SecretResolver::resolve($name, $this->directory);
        } finally { putenv($name); }
    }
    public function testConfigNeverRetainsResolvedPassword(): void
    {
        putenv($this->secret . '=private-password-value');
        $config = MailboxConfig::fromArray($this->settings());
        SecretResolver::resolve($config->passwordFromSecretName, $this->directory);
        self::assertStringNotContainsString('private-password-value', serialize($config));
        self::assertStringNotContainsString('private-password-value', print_r($config, true));
    }
}
