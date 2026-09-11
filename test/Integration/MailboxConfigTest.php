<?php
declare(strict_types=1);
namespace Phore\MailClient\Test\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Phore\MailClient\{MailboxConfig,Email};

final class MailboxConfigTest extends TestCase
{
    public static function credentialSources(): iterable
    { yield [true]; yield [false]; }

    #[DataProvider('credentialSources')]
    public function testFileConfigurationConnectsToDisposableMailbox(bool $useSecret): void
    {
        if (getenv('PHORE_TEST_IMAP') !== '1') { self::markTestSkipped('Disposable Dovecot is not enabled.'); }
        $secret = 'PHORE_CONFIG_TEST_' . bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/' . $secret;
        mkdir($directory, 0700);
        try {
            file_put_contents($directory . '/mailbox.json', json_encode([
                'host'=>'localhost', 'username'=>'test',
                'port'=>1993, 'draftsFolder'=>'Drafts', 'trashFolder'=>'Trash',
                'from'=>'Config Test <config@example.org>', 'mode'=>'manual',
            ] + ($useSecret ? ['passwordFromSecretName'=>$secret] : ['password'=>'test-secret']), JSON_THROW_ON_ERROR));
            file_put_contents($directory . '/' . $secret, "test-secret\n");
            $client = MailboxConfig::fromFile($directory . '/mailbox.json')->connect($directory);
            self::assertSame('manual', $client->mode());
            $draft = $client->saveDraft((new Email(to:'you@example.org', subject:'Config connection'))->withMarkdown('Config test'));
            $fetched = $client->get($draft->id());
            self::assertSame('config@example.org', $fetched->from()[0]->getAddress());
            self::assertNotContains('\\Seen', $fetched->flags());
            self::assertNotSame($draft->id(), $client->moveToTrash($draft)->id());
        } finally {
            foreach (glob($directory . '/*') as $path) { unlink($path); }
            rmdir($directory);
        }
    }
}
