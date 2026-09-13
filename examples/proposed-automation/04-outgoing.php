<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Beim Beobachten neuer Gesendet-Nachrichten den einzelnen Empfänger anlegen.
use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailAutomation, MailActions, MailContext};

// Voraussetzung: $automation verwendet den bereits konfigurierten Client.
// MailAutomation synchronisiert Sent vor den Eingängen.
assert($automation instanceof MailAutomation);

// onSentMessage wählt neue beobachtete Ausgänge; addAutomation registriert die erste passende Regel.
// id identifiziert sie, priority steuert die Reihenfolge, matches ist eine reine Bedingung.
// Email ist der Ausgang; MailContext liefert recipientUsers (Adresse => MailUser|null).
// handle wird erst nach einem Treffer aufgerufen und gibt MailActions zurück.
$automation->onSentMessage()->addAutomation(
    id: 'outgoing.create-recipient', priority: 0,
    matches: fn (Email $mail, MailContext $context): bool => count($context->recipientUsers) === 1,
    handle: function (Email $mail, MailContext $context): MailActions {
        // createUserForRecipient erstellt den einzigen externen Empfänger oder lädt ihn.
        // Rückgabe MailUser; niemals wird unsere eigene Absenderadresse zum Kunden.
        $user = $context->createUserForRecipient();

        // classification ist string|null. classify speichert die fachliche Kategorie.
        if ($user->classification === null) {
            $user->classify('new_contact');
        }
        // setMetadata speichert einen JSON-kompatiblen Wert am Benutzer.
        $user->setMetadata('source', 'sent_folder');

        // none bedeutet Erfolg ohne weitere Mailaktion; Engine setzt phore_outgoing_processed.
        return MailActions::none();
    },
);

// run verarbeitet beobachtete Ausgänge; Rückgabe RunReport, kein SMTP-Versand.
$report = $automation->run();
// Erwartung: unbekannter Empfänger wird angelegt; bekannte Benutzer behalten ihre ID/Hauptadresse.
// Ohne diese Regel wird nur Ausgangsevidenz gespeichert: Benutzeranlage erst bei verifizierter Antwort.
// Mehrere Empfänger: createUserForRecipient($address) verlangt jeweils eine echte Empfängeradresse;
// die Regel oben überspringt diesen Fall bewusst, statt einen einzelnen Benutzer zu erraten.
