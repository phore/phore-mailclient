<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Eine Antwort ausdrücklich senden statt nur einen Entwurf zu speichern.
use Phore\MailClient\{Email, MailClient};
use Phore\MailClient\Automation\{DraftSender, MailAutomation, MailActions, MailContext};

// Voraussetzungen: SQLite-Verbindung, verbundener IMAP-Client und Versandadapter der Anwendung.
assert($database instanceof PDO); // PDO ist hier bereits mit SQLite verbunden.
assert($client instanceof MailClient); // MailClient ermöglicht IMAP-Zugriff.
assert($sender instanceof DraftSender); // DraftSender sendet und legt die finale Mail in Sent ab.

// MailAutomation erstellt die Stores aus storage; sender aktiviert den optionalen echten Versand.
$automation = new MailAutomation(storage: $database, sender: $sender);

// addMailbox benennt Konto und Ordner; ownAddresses sind unsere erlaubten Ausgangsadressen.
$automation->addMailbox(
    'support', $client,
    ownAddresses: ['support@example.org'],
    incomingFolder: 'INBOX', sentFolder: 'Sent',
);

// onIncoming wählt Eingänge. add registriert id, priority und reine matches-Bedingung.
// Email.from liefert Autoren; getAddress die nackte Adresse. MailContext enthält Benutzerkontext.
$automation->onIncoming('support')->add(
    id: 'form.send-reply', priority: 100,
    matches: fn (Email $mail, MailContext $context): bool =>
        count($mail->from()) === 1 && $mail->from()[0]->getAddress() === 'forms@example.org',
    // handle liefert MailActions; create beginnt die Liste.
    // sendReply bereitet die Antwort vor und übergibt sie dem konfigurierten DraftSender.
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->sendReply('Bitte bestätigen Sie den Kontaktvorschlag.'),
);

// run führt auch den Versand aus; Rückgabe RunReport mit Fehlern, falls der Adapter scheitert.
$report = $automation->run();
// Erwartung: Adapter sendet an das tatsächliche Antwortziel und speichert finale Mail in Sent.
// Ohne DraftSender schlägt sendReply fehl; Entwurfsspeicherung allein gilt nie als Versand.
// Kein konkreter SMTP-Adapter ist Teil dieses Entwurfs. Automatische Antworten bewusst aktivieren.
