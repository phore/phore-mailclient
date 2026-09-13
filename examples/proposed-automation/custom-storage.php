<?php
declare(strict_types=1);

// DESIGN EXAMPLE ONLY: proposed Automation API, not implemented or runnable yet.
namespace Examples\ProposedAutomation;

use PDO;
use Phore\MailClient\Automation\{AutomationStorage, MailAutomation, SqliteStorage,
    UserIdGenerator};

function minimalStorage(PDO $sqlite): MailAutomation
{
    return new MailAutomation(storage: $sqlite); // Usual setup; internal factory.
}

function customIds(PDO $sqlite, UserIdGenerator $ids): MailAutomation
{
    return new MailAutomation(storage: $sqlite, idGenerator: $ids);
}

function explicitSqlite(PDO $sqlite, UserIdGenerator $ids): MailAutomation
{
    return new MailAutomation(
        storage: new SqliteStorage($sqlite, idGenerator: $ids),
    );
}

function customBackend(AutomationStorage $storage): MailAutomation
{
    return new MailAutomation(storage: $storage);
}
// AutomationStorage provides state(), users(), history() through their interfaces.
// A CRM adapter can expose user metadata/classification through AliasStore.
// No application needs to implement any of these interfaces for the PDO default.
// Default generator: name slug + "-e" + 8 random characters; never changes IDs later.
// Custom generator implements generate(?string $displayName, string $primaryEmail).
