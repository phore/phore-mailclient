<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Mit einer SQLite-Verbindung ein Postfach überwachen und einen Lauf ausführen.
use Phore\MailClient\MailClient;
use Phore\MailClient\Automation\{MailAutomation, RunReport};

// Voraussetzung: Die Anwendung hat $client bereits verbunden; MailClient führt IMAP-I/O aus.
assert($client instanceof MailClient);

// PDO öffnet SQLite; die Datei wird bei Bedarf erstellt, das Verzeichnis muss existieren.
$database = new PDO('sqlite:/var/lib/app/mail.sqlite');

// MailAutomation erkennt PDO SQLite und erstellt intern Tabellen und Stores.
// storage ist der gemeinsame Speicher für Cursor, Benutzer, Aliase und Historie.
$automation = new MailAutomation(storage: $database);

// addMailbox registriert einen stabilen Schlüssel und die tatsächlich vorhandenen Ordner.
// ownAddresses benennt unsere Adressen zur Erkennung eigener ausgehender Nachrichten.
// incomingFolder ist der Hauptordner; sentFolder liefert die überprüfbare Ausgangshistorie.
$automation->addMailbox(
    'support',
    $client,
    ownAddresses: ['support@example.org'],
    incomingFolder: 'INBOX',
    sentFolder: 'Sent',
);

// run liest zuerst Sent, dann Eingänge; ohne Regeln werden Eingänge als geprüft markiert.
// In einer Anwendung Regeln VOR diesem Aufruf registrieren. Sent allein legt keine Benutzer an.
$report = $automation->run();

// RunReport enthält Zähler und strukturierte Fehler; keine Mailinhalte werden ausgegeben.
assert($report instanceof RunReport);
// Erststart: unmarkierter Eingangsbestand wird geprüft; alter Sent-Bestand nur indexiert.
// processExistingOutgoing: true bei addMailbox aktiviert ausdrücklich Regeln für Sent-Altbestand.
