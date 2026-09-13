// Wie lege ich Kontakte bereits beim Beobachten einer gesendeten Mail an?
// Ergänzt 01 oder 03 vor run(); ohne diese Regel bleibt Anlage bei der ersten Antwort.
// Sent wird vor Inbox verarbeitet. recipientUsers ordnet externe Empfängeradressen
// vorhandenen Benutzern oder null zu; unsere eigene Adresse ist ausgeschlossen.
// Sent-Altbestand wird beim ersten Lauf nur indexiert, diese Regel läuft für neue Beobachtungen.
$automation->onFolder(Folder::Sent)->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => count($context->recipientUsers) === 1,
    handle: function (Email $mail, MailContext $context): MailActions {
        // Unmittelbarer Store-Schreibzugriff: einzigen Empfänger anlegen oder vorhandenen laden.
        $user = $context->createUserForRecipient();
        if ($user->classification === null) {
            $user->classify('new_contact');
        }
        $user->setMetadata('source', 'sent_folder');
        // Erfolgreich ohne Mailaktion; die Engine setzt phore_outgoing_processed.
        return MailActions::none();
    },
);

// Beispiel: neuer Ausgang an Anna <anna@old.example> → Benutzer mit dieser Hauptadresse.
// Bestehende Klassifizierung bleibt erhalten; source wird bei dieser Verarbeitung gesetzt.

// Alternative: ersetzt NUR die obige Sent-Regel, wenn jeder externe Empfänger angelegt werden soll.
$automation->onFolder(Folder::Sent)->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: function (Email $mail, MailContext $context): MailActions {
        foreach ($context->recipientUsers as $address => $knownUser) {
            $context->createUserForRecipient($address);
        }
        return MailActions::none();
    },
);
// An Anna und Ben gesendet → zwei adressbezogene Benutzer; kein willkürlich gewählter Hauptkontakt.
// Ohne externe Empfänger bleibt der Store unverändert. Mehrdeutige Gruppenantworten
// erlauben trotzdem kein automatisches Aliaslernen; sie benötigen Prüfung (05).
