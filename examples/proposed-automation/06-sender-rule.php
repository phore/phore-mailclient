<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Für Formularnachrichten eines bestimmten Absenders einen Antwortentwurf vorbereiten.
use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailAutomation, MailActions, MailContext};

// Voraussetzung: Anwendung stellt $automation für "support"; FormRequests und Drafts existieren.
// MailAutomation führt die registrierten Regeln aus und hält den Cursor intern.
assert($automation instanceof MailAutomation);

// onIncoming wählt den Hauptordner; add registriert id und priority (höher zuerst).
// matches ist die Bedingung; Email.from liefert eine Liste von EmailAddress-Werten.
// getAddress liefert die Adresse ohne Anzeigenamen. Genau ein passender From wird verlangt.
// MailContext liefert die aufgelöste Identität; Absendervergleich ist kein Echtheitsnachweis.
$automation->onIncoming('support')->add(
    id: 'incoming.form-proposal', priority: 250,
    matches: fn (Email $mail, MailContext $context): bool =>
        count($mail->from()) === 1 && $mail->from()[0]->getAddress() === 'forms@example.org',
    // handle liefert MailActions. create beginnt die Liste; createReplyDraft speichert
    // einen Antwortentwurf am tatsächlichen Antwortziel, sendet ihn jedoch nicht.
    // addFlag markiert Prüfbedarf; moveTo verschiebt innerhalb desselben Kontos.
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()
            ->createReplyDraft('Danke. Bitte prüfen Sie den Kontaktvorschlag.')
            ->addFlag('phore_review')
            ->moveTo('FormRequests'),
);

// run führt die Verarbeitung aus; Rückgabe RunReport mit Zählern und Fehlern.
$report = $automation->run();
// Erwartung: Antwortentwurf in Drafts; Eingang in FormRequests mit review + processed.
// Namen/Adressen im Formularinhalt erzeugen keine Benutzer oder Aliase.
// Tatsächlicher Versand ist eine eigene Variante in 09-send-reply.php.
