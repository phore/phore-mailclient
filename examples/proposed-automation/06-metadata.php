<?php
// Wo gehören Zusatzdaten hin: Kontakt, Gespräch, Nachricht oder Mailbox?
// Eigenständige Alternative: $client und $database stammen aus dem Setup in 01.
// Die Engine bereitet Kontaktzuordnung und Thread vor dem Handler vor.
$automation = new MailAutomation(client: $client, storage: $database);

// Manuelle Prüfung: review_complete setzen, dann phore_processed entfernen.
// Das Attribut registriert den echten Handler; $mail und $context liefert die Engine.
#[OnFolderAutomation(folder: Folder::Inbox)]
function recordReview(Email $mail, MailContext $context): MailActions
{
    if (!$context->hasFlag('review_complete')) {
        return MailActions::pass();
    }

    $contact = $context->contact; // Contact|null: externe Person, kein lokales Benutzerkonto.
    if ($context->identity->needsReview() || $contact === null) {
        return MailActions::create()->addFlag('phore_review');
    }

    $contactId = $contact->id; // Dauerhafte ID, gleich für alle Aliase dieses Kontakts.
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

    // Nur diese Nachricht ist geprüft. Eine spätere Antwort hat eigene, zunächst leere Metadaten.
    $context->metadata->set('reviewed', true);

    // Kontaktweit sofort gespeichert; nach dem ersten set()-Aufruf darf kein pass() mehr folgen.
    $contact->metadata->set('lastReviewedCase', $caseId);
    $contact->classify('b2b');

    // Optionaler globaler Zugriff innerhalb dieses einen Kontos:
    // contacts ist ein ContactStore, keine Liste; mailHistory sucht über alle Gespräche dieses Kontos.
    $sameContact = $mailbox->contacts->findById($contactId);
    $contactMessages = $mailbox->mailHistory->forContact($contactId, limit: 20);
    $mailbox->metadata->set('lastReviewedThread', $thread->id);

    return MailActions::create()->removeFlag('review_complete');
}

$automation->addRules('recordReview');
$report = $automation->run();

// Beispiel: Anna (C-1042), Thread T17 ohne Vorgangsnummer → case-T17.
// Kontakt: lastReviewedCase=case-T17, classification=b2b; customerNumber bleibt C-1042.
// Thread: caseId=case-T17, status=reviewed. Nachricht: reviewed=true und phore_processed.
// Mailbox: lastReviewedThread=T17. sameContact.id entspricht contactId.
// $messages enthält nur Mitglieder dieses Threads; $contactMessages auch andere Gespräche mit Anna.
// Eine neue Antwort in T17 sieht denselben Kontakt/Thread, aber kein eigenes reviewed.
// Eine unverknüpfte neue Mail startet einen eigenen Thread mit leeren Thread-Metadaten.
// Alle set()-Aufrufe speichern sofort im gemeinsamen Storage; sie erzeugen keine Header oder Keywords.
