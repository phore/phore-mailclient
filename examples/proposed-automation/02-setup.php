<?php
declare(strict_types=1);

// API-ENTWURF: Automation ist noch nicht implementiert; kein eigenständig ausführbares Skript.
// Ziel: Einen vorhandenen MailClient mit dem Speicher für seine Automatisierung verbinden.
use Phore\MailClient\MailClient;
use Phore\MailClient\Automation\MailAutomation;

// Voraussetzung: Die Anwendung stellt den Client samt einziger Verbindung bereit.
// MailClient enthält Absender, Inbox, Sent sowie Drafts/Trash; diese Angaben werden übernommen.
assert($client instanceof MailClient);

// PDO öffnet SQLite und erstellt die Datei bei Bedarf; das Verzeichnis muss existieren.
$database = new PDO('sqlite:/var/lib/app/mail.sqlite');

// MailAutomation bindet genau diesen client; storage speichert Cursor, Benutzer und Historie.
// Bei PDO SQLite erstellt die Automatisierung Tabellen und Standard-Stores automatisch.
$automation = new MailAutomation(client: $client, storage: $database);

assert($automation instanceof MailAutomation); // Regeln können nun registriert werden.
// Kein addMailbox, kein Kontoschlüssel, keine zweite Verbindung oder Ordnerkonfiguration.
// Regeln vor run() registrieren; run liefert anschließend den Verarbeitungsbericht.
// Der vorhandene Client benötigt für die spätere Implementierung noch Sent-Konfiguration
// und einen lesbaren Konfigurationsvertrag. Das ist hier Entwurf, keine bereits verfügbare API.
