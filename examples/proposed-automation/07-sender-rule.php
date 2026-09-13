<?php
// Wie bereite ich für Formularnachrichten einen Antwortentwurf vor?
// Ergänzt die Regeln aus 03 vor run(); FormRequests und der Client-Drafts-Ordner existieren.
// Priority 250 liegt nach Identitätsprüfung (300) und vor B2B (200).
// from() liefert EmailAddress-Einträge, getAddress() die Adresse ohne Anzeigenamen.
// Ein From-Vergleich routet Nachrichten, beweist aber keine Absenderechtheit.
$automation->onFolder(Folder::Inbox)->addAutomation(
    priority: 250,
    matches: fn (Email $mail, MailContext $context): bool =>
        count($mail->from()) === 1 && $mail->from()[0]->getAddress() === 'forms@example.org',
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()
            // Beim run() in Drafts speichern, noch nicht senden; Ziel ist das Antwortziel der Quellmail.
            ->createReplyDraft('Danke. Bitte prüfen Sie den Kontaktvorschlag.')
            ->addFlag('phore_review')
            ->moveTo('FormRequests'),
);

// forms@example.org sendet einen Kontaktvorschlag → Antwortentwurf in Drafts,
// Eingang mit phore_review + processed in FormRequests.
// Adressen aus dem Formularinhalt werden dadurch weder Kontakt noch Aliase.
