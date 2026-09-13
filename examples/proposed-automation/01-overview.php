<?php
declare(strict_types=1);

// API-ENTWURF: Noch nicht implementiert; flacher Ausschnitt aus einer Anwendung.
// Ziel: Ausgänge zur Benutzeranlage nutzen und Eingänge nach Kundentyp sortieren.
use Phore\MailClient\{MailClient, Email};
use Phore\MailClient\Automation\{MailAutomation, MailActions, MailContext, RunReport};

// Voraussetzung: Client mit einer Verbindung, Absender/Ordnern; B2B und Review existieren.
// MailClient übernimmt IMAP; PDO ist die von der Anwendung bereitgestellte SQLite-Verbindung.
assert($client instanceof MailClient);
assert($database instanceof PDO);

// MailAutomation übernimmt die Client-Konfiguration; storage initialisiert interne SQLite-Stores.
$automation = new MailAutomation(client: $client, storage: $database);

// onSentMessage wählt neue Sent-Beobachtungen; addAutomation registriert die Automatisierung.
// id benennt sie, priority bestimmt die Reihenfolge. matches ist eine reine Bedingung.
// Email ist die Nachricht; MailContext enthält recipientUsers (Adresse => MailUser|null).
$automation->onSentMessage()->addAutomation(
    id: 'sent.create-user', priority: 0,
    matches: fn (Email $mail, MailContext $context): bool => count($context->recipientUsers) === 1,
    // handle liefert MailActions; createUserForRecipient legt den einzigen Empfänger an
    // oder lädt ihn. classify persistiert eine Kategorie, falls classification noch null ist.
    handle: function (Email $mail, MailContext $context): MailActions {
        $user = $context->createUserForRecipient();
        if ($user->classification === null) {
            $user->classify('new_contact');
        }
        // none heißt erfolgreich ohne weitere Mailaktion; Engine setzt Ausgangsmarker.
        return MailActions::none();
    },
);

// onInboxMessage wählt die Inbox. user ist bereits aufgelöst (MailUser|null), samt Metadaten.
// Ohne Ausgangsautomatisierung erfolgt Anlage erst bei einer Antwort mit live geprüftem Sent-Beleg.
// Neue Antwortadressen werden als Aliase ergänzt; die Hauptadresse bleibt erhalten.
$automation->onInboxMessage()->addAutomation(
    id: 'inbox.classify', priority: 0,
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: function (Email $mail, MailContext $context): MailActions {
        // identity.needsReview signalisiert fehlende oder widersprüchliche Ausgangsbelege.
        // create startet MailActions; moveTo verschiebt innerhalb des Client-Kontos.
        if (!$context->identity->needsReview() && $context->user?->classification === 'b2b') {
            return MailActions::create()->moveTo('B2B');
        }
        // addFlag markiert manuellen Prüfbedarf; unbekannte Eingänge legen keinen Benutzer an.
        return MailActions::create()->addFlag('phore_review')->moveTo('Review');
    },
);

// run beobachtet Sent zuerst, verarbeitet dann Eingänge und verwaltet Cursor/processed intern.
$report = $automation->run();

assert($report instanceof RunReport); // Zähler und strukturierte Fehler des abgeschlossenen Laufs.
// Ergebnis: bekannte B2B-Absender in B2B, alle anderen zur Prüfung; normale Erfolge sind processed.
// Erststart: Sent-Altbestand wird nur indexiert, unmarkierter Eingangsbestand wird verarbeitet.
// Überblick: Client+storage verbinden; onSentMessage/onInboxMessage wählen Auslöser;
// addAutomation registriert Bedingung und Aktion; MailContext liefert Benutzer,
// MailActions beschreibt Änderungen und run führt alles für diese eine Verbindung aus.
