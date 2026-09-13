<?php
// Wo gehören Zusatzdaten hin: Kontakt, Gespräch, Nachricht oder Mailbox?
// Eigenständige Alternative: $client und $database stammen aus dem Setup in 01.
// Der standardmäßige ReplyContactResolver läuft vor dem Handler (explizite Einbindung: 05).
// Bekannte From-Aliasadresse → vorhandener Contact, auch ohne Antwortheader.
// Unbekannter From + verifizierte Antwort auf eigene Sent-Mail → Contact anlegen/finden,
// ursprünglichen Empfänger als Hauptadresse behalten und From als Alias ergänzen.
// Unbekannte Mail ohne solchen Beleg → contact=null; weder Eingang noch Review legt ihn an.
$automation = new MailAutomation(client: $client, storage: $database);

// Fachliche Voraussetzung hier: Ein Mensch hat den Kontakt als B2B bestätigt.
// Dafür review_complete setzen, dann phore_processed entfernen.
// Das Attribut registriert den echten Handler; $mail und $context liefert die Engine.
#[OnFolderAutomation(folder: Folder::Inbox)]
function recordReview(Email $mail, MailContext $context): MailActions
{
    if (!$context->hasFlag('review_complete')) {
        return MailActions::pass();
    }

    // contactResolution liefert für diese Mail Ergebnis und Belege der Kontaktzuordnung,
    // kein zweiter Kontakt und kein Login-Nachweis. contact ist der gespeicherte Contact.
    $resolution = $context->contactResolution;
    $contact = $context->contact;
    // Conflict / OutgoingMissing verlangen Prüfung, auch bei bereits bekanntem From.
    if ($resolution->needsReview()) {
        return MailActions::create()->addFlag('phore_review');
    }
    if ($contact === null) {
        // Unbekannter Erstkontakt bleibt ohne Datensatz; späterer Antwortablauf: 05.
        return MailActions::create()->addFlag('phore_review');
    }

    $contactId = $contact->id; // Dauerhafte ID, gleich für alle Aliase dieses Kontakts.
    $primaryEmail = $contact->primaryEmail;
    // Liste von ContactAlias inklusive Hauptadresse, jede Adresse genau einmal.
    // name ist der optionale Anzeigename dieser Adresse; contact.name gilt für die Person.
    $aliasNames = [];
    foreach ($contact->aliases as $alias) {
        $aliasNames[$alias->email] = $alias->name;
    }
    // Bewusst bestätigte Änderungen an Namen/Adressen zeigt 12-contact-management.php.
    $thread = $context->thread; // MailThread: dieses Gespräch, auch über Folder hinweg.
    $mailbox = $context->mailbox; // MailboxContext: genau das angebundene Konto.

    // Kontaktweit; get() liefert null, wenn der Schlüssel fehlt.
    $customerNumber = $contact->metadata->get('customerNumber');

    // Threadweit; Nachrichten eines anderen Gesprächs teilen diese Daten nicht.
    // Eine fehlende Vorgangsnummer wird einmalig aus der stabilen Thread-ID abgeleitet.
    $caseId = $thread->metadata->get('caseId');
    if ($caseId === null) {
        $caseId = 'case-' . $thread->id;
        $thread->metadata->set('caseId', $caseId);
    }
    $thread->metadata->set('status', 'reviewed');
    $messages = $thread->messages(limit: 20); // Beobachtete Gesprächsnachrichten inkl. aktueller Mail.

    // reviewed ist ausschließlich ein Ergebnis unserer Anwendungslogik, kein Engine-Status.
    // Die Engine liest dieses Feld nicht: keine Sperre, Freigabe oder erneute Verarbeitung.
    // Eigene Filter können es später mit metadata->get('reviewed') auswerten.
    // Eine spätere Antwort hat eigene, zunächst leere Metadaten.
    $context->metadata->set('reviewed', true);

    // Kontaktweit sofort gespeichert; nach dem ersten set()-Aufruf darf kein pass() mehr folgen.
    $contact->metadata->set('lastReviewedCase', $caseId);
    // classification ist ein frei gewählter Metadatenschlüssel dieser Anwendung.
    // Kein Contact::classify() und kein eingebauter Kundentyp; eigene Methoden zeigt 14.
    $contact->metadata->set('classification', 'b2b');
    $classification = $contact->metadata->get('classification'); // Jetzt 'b2b', auch für künftige Mails aller Aliase.
    // Die B2B-Regel in 03 liest genau diesen Metadatenschlüssel und verschiebt neue Mails nach B2B.
    // Dieser Handler endet dagegen unten; die Änderung startet keine weitere Regel.

    // Optionaler globaler Zugriff innerhalb dieses einen Kontos:
    // contacts ist ein ContactStore, keine Liste; mailHistory sucht über alle Gespräche dieses Kontos.
    $sameContact = $mailbox->contacts->findById($contactId);
    $contactMessages = $mailbox->mailHistory->forContact($contactId, limit: 20);
    $mailbox->metadata->set('lastReviewedThread', $thread->id);

    return MailActions::create()->removeFlag('review_complete');
}

$automation->addRules('recordReview');
$report = $automation->run();

// Beispiel: Anna (C-1042), primaryEmail=anna@old.example, Thread T17 → case-T17.
// aliasNames: anna@old.example => 'Anna Müller', anna@new.example => null.
// contactResolution.status: KnownAddress bei bekanntem From ohne widersprüchlichen Beleg;
// ContactCreated bei erster bestätigter Antwort; AliasAdded bei neu gelerntem Alias.
// contactResolution.aliasAdded kann auch bei ContactCreated true sein (anderer Antwort-From).
// Kontakt: lastReviewedCase=case-T17, metadata.classification=b2b; customerNumber bleibt C-1042.
// Thread: caseId=case-T17, status=reviewed. Nachricht: reviewed=true und phore_processed.
// Mailbox: lastReviewedThread=T17. sameContact.id entspricht contactId.
// $messages enthält nur Mitglieder dieses Threads; $contactMessages auch andere Gespräche mit Anna.
// Eine neue Antwort in T17 sieht denselben Kontakt/Thread, aber kein eigenes reviewed.
// Eine unverknüpfte neue Mail startet einen eigenen Thread mit leeren Thread-Metadaten.
// Alle set()-Aufrufe speichern nur Anwendungsdaten; keine internen Engine-Wirkungen,
// Header oder Keywords. Nur eigene Filter/Individuallogik werten diese Schlüssel aus.
