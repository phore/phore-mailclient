<?php
// Wie sende ich die Formularantwort tatsächlich?
// Ersetzt den Konstruktor aus 01 UND die Entwurfsregel aus 07.
// Die übrigen Regeln aus 03 bleiben; anschließend erfolgt deren gemeinsamer run().
// $sender ist ein DraftSender aus dem Versand-Bootstrap der Anwendung:
// send(Email $draft): void übernimmt Transport UND Ablage der finalen Nachricht in Sent.
// Ein konkreter SMTP-Adapter gehört nicht zum Entwurf; ohne Anwendungadapter ist diese Variante offen.
$automation = new MailAutomation(client: $client, storage: $database, sender: $sender);

$automation->onFolder(Folder::Inbox)->addAutomation(
    priority: 250,
    matches: fn (Email $mail, MailContext $context): bool =>
        count($mail->from()) === 1 && $mail->from()[0]->getAddress() === 'forms@example.org',
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()
            // Erst beim run() über den Adapter senden, an das tatsächliche Antwortziel der Quellmail.
            ->sendReply('Bitte bestätigen Sie den Kontaktvorschlag.')
            ->addFlag('phore_review')
            ->moveTo('FormRequests'),
);

// Nach Registrierung der übrigen Regeln aus 03:
$report = $automation->run();
// Formularnachricht → Antwort versendet und finale Mail in Sent, Eingang in FormRequests.
// Diese Sent-Mail wird bei einer nachfolgenden Sent-Synchronisierung beobachtet.
// Fehlender/fehlschlagender Adapter → Fehler im RunReport, Nachricht bleibt zur Verarbeitung offen.
// Eine Entwurfsspeicherung allein zählt nicht als erfolgreicher Versand.
