<?php
// Wie lege ich Kontakte bereits beim Beobachten einer gesendeten Mail an?
// Ergänzt 01 oder 03 vor run(); ohne diese Regel bleibt Anlage bei der ersten Antwort.
// Sent wird vor Inbox verarbeitet. recipientContacts ordnet externe Empfängeradressen
// vorhandenen Kontakten oder null zu; unsere eigene Adresse ist ausgeschlossen.
// Bereits phore_processed-markierte Ausgänge werden nur indexiert; diese Regeln bleiben gesperrt.
// Sent-Altbestand wird beim ersten Lauf indexiert und als phore_processed abgeschlossen,
// ohne Kontaktanlage; diese Regel läuft für neue unmarkierte Beobachtungen.
$automation->onFolder(Folder::Sent)->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => count($context->recipientContacts) === 1,
    handle: function (Email $mail, MailContext $context): MailActions {
        // Unmittelbarer Store-Schreibzugriff: einzigen Empfänger anlegen oder vorhandenen laden.
        $contact = $context->createContactForRecipient();
        if ($contact->metadata->get('classification') === null) {
            $contact->metadata->set('classification', 'new_contact');
        }
        $contact->metadata->set('source', 'sent_folder');
        // complete() beendet die Kette ohne weitere Mailaktion; die Engine setzt phore_processed.
        return MailActions::complete();
    },
);

// Beispiel: neuer Ausgang an Anna <anna@old.example> → Kontakt mit dieser Hauptadresse.
// Bestehende Klassifizierung bleibt erhalten; source wird bei dieser Verarbeitung gesetzt.

// Alternative: ersetzt NUR die obige Sent-Regel, wenn jeder externe Empfänger angelegt werden soll.
$automation->onFolder(Folder::Sent)->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: function (Email $mail, MailContext $context): MailActions {
        foreach ($context->recipientContacts as $address => $knownContact) {
            $context->createContactForRecipient($address);
        }
        return MailActions::complete();
    },
);
// An Anna und Ben gesendet → zwei adressbezogene Kontakte; kein willkürlich gewählter Hauptkontakt.
// Ohne externe Empfänger bleibt der Store unverändert. Mehrdeutige Gruppenantworten
// erlauben trotzdem kein automatisches Aliaslernen; sie benötigen Prüfung (05).
