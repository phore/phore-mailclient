<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: B2B-Kunden weiterverarbeiten und unbekannte Absender ohne Benutzeranlage ablegen.
use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailAutomation, MailActions, MailContext};

// Voraussetzung: $automation überwacht "support"; B2B, Review, NewContacts, Customers existieren.
// MailAutomation registriert Regeln und verwaltet deren Postfachzustand.
assert($automation instanceof MailAutomation);

// onIncoming wählt den Hauptordner. add registriert eine Regel mit eindeutiger id.
// priority: höhere Zahl zuerst; matches prüft ohne Änderungen; der erste Treffer gewinnt.
// Email ist die Nachricht, MailContext enthält die bereits aufgelöste Benutzerzuordnung.
// needsReview erkennt widersprüchliche oder fehlende Ausgangsbelege.
// handle liefert MailActions: create beginnt die Aktionsliste, addFlag ergänzt ein Keyword,
// moveTo verschiebt im selben Konto; nach Erfolg setzt die Engine phore_processed.
$automation->onIncoming('support')->add(
    id: 'incoming.review', priority: 300,
    matches: fn (Email $mail, MailContext $context): bool => $context->identity->needsReview(),
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->addFlag('phore_review')->moveTo('Review'),
);

// user ist MailUser|null; classification ist die gespeicherte fachliche Kategorie.
// reprocess: true entfernt im Ziel processed und verschiebt dessen Verarbeitung in den nächsten Lauf.
$automation->onIncoming('support')->add(
    id: 'incoming.b2b', priority: 200,
    matches: fn (Email $mail, MailContext $context): bool => $context->user?->classification === 'b2b',
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->moveTo('B2B', reprocess: true),
);

// user=null bedeutet unbekannter Absender. Die Ablage erzeugt keinen Benutzer.
$automation->onIncoming('support')->add(
    id: 'incoming.unknown', priority: 100,
    matches: fn (Email $mail, MailContext $context): bool => $context->user === null,
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->addFlag('new_contact')->moveTo('NewContacts'),
);

// Die letzte Regel übernimmt bekannte Benutzer ohne B2B-Klassifizierung.
$automation->onIncoming('support')->add(
    id: 'incoming.other-known', priority: 0,
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: fn (Email $mail, MailContext $context): MailActions => MailActions::create()->moveTo('Customers'),
);

// onFolder registriert die normale Regelkette des Zielordners.
$automation->onFolder('support', 'B2B')->add(
    id: 'b2b.ready', priority: 0,
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: fn (Email $mail, MailContext $context): MailActions => MailActions::create()->addFlag('b2b_ready'),
);

// run führt die registrierten Regeln aus; Rückgabe RunReport mit Zählern und Fehlern.
$report = $automation->run();
// Erwartung: B2B-Eingang im Ziel zunächst unmarkiert, im nächsten Lauf b2b_ready + processed.
// Unbekannter Eingang liegt in NewContacts mit processed, bleibt aber ohne MailUser.
