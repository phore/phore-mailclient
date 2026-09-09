<?php

declare(strict_types=1);

namespace Phore\MailClient\Test\Unit;

use DateTimeImmutable;
use Phore\MailClient\Credential\StaticCredential;
use Phore\MailClient\Domain\MailAccount;
use Phore\MailClient\Domain\MailAddress;
use Phore\MailClient\Domain\MailBody;
use Phore\MailClient\Domain\MailMessage;
use Phore\MailClient\Domain\MailReference;
use Phore\MailClient\Domain\SyncCursor;
use Phore\MailClient\MailClient;
use Phore\MailClient\State\InMemoryMailState;
use Phore\MailClient\Test\Support\FakeMailboxConnector;
use PHPUnit\Framework\TestCase;

final class MailClientTest extends TestCase
{
    public function testListNewUsesAndAdvancesPersistentCursor(): void
    {
        $message = new MailMessage(
            new MailReference('support', 'INBOX', '42'),
            new MailAddress('customer@example.org'),
            [new MailAddress('support@example.org')],
            [],
            'Question',
            new DateTimeImmutable(),
            new MailBody('Hello'),
        );
        $connector = new FakeMailboxConnector([$message]);
        $state = new InMemoryMailState();
        $state->saveCursor('support', new SyncCursor('41'));
        $account = MailAccount::imap('support', 'imap.example.org', 'support@example.org', new StaticCredential('secret'));
        $client = MailClient::connect($account, $connector, state: $state);

        $batch = $client->listNew();

        self::assertSame('41', $connector->receivedCursor?->value);
        self::assertSame('42', $state->cursor('support')?->value);
        self::assertSame('support@example.org', $batch->messages[0]->reply()->from->address);
    }
}
