<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: SQLite-Standard und austauschbare Speicher-/ID-Implementierung gegenüberstellen.
use Phore\MailClient\MailClient;
use Phore\MailClient\Automation\{AutomationStorage, MailAutomation, SqliteStorage, UserIdGenerator};

// MailClient enthält die einzige Verbindung samt Absender und Ordnerkonfiguration.
assert($client instanceof MailClient);

// Voraussetzungen aus der Anwendung; keines dieser Objekte entsteht durch assert.
// PDO ist eine bestehende SQLite-Verbindung, UserIdGenerator erzeugt dauerhafte Benutzer-IDs.
assert($database instanceof PDO);
assert($ids instanceof UserIdGenerator);
// AutomationStorage stellt state(), users() und history() über Store-Interfaces bereit.
assert($storage instanceof AutomationStorage);

// MailAutomation erkennt PDO SQLite über storage und erstellt die Standard-Stores intern.
$standard = new MailAutomation(client: $client, storage: $database);

// Alternative: idGenerator ersetzt nur die ID-Bildung, ohne eigene Stores zu verlangen.
$customIds = new MailAutomation(client: $client, storage: $database, idGenerator: $ids);

// Alternative: SqliteStorage ist die explizite Implementierung desselben Speichervertrags.
$sqliteStorage = new SqliteStorage($database, idGenerator: $ids);
$explicit = new MailAutomation(client: $client, storage: $sqliteStorage);

// Alternative: vorhandene Gesamtimplementierung, beispielsweise mit CRM-Benutzern.
$custom = new MailAutomation(client: $client, storage: $storage);

// Alle Rückgaben sind MailAutomation, an denselben Client gebunden, noch ohne Regeln.
// Varianten zur Auswahl: in der echten Anwendung nur eine davon instanziieren.
// Standard-ID: anna-mueller-e<8 Zufallszeichen>; fehlender Name nutzt den E-Mail-Slug.
// Ein eigener Generator implementiert generate(?string $displayName, string $primaryEmail).
