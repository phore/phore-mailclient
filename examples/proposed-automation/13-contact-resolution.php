<?php
// Wie kam die Kontaktzuordnung zustande, und wie reagiere ich auf jeden Ausgang?
// Unabhängige Alternative zu 01/03: $client und $database aus 01, keine Sent-Anlageregel.
// Die Zielordner Review, NewContacts und Customers existieren im selben Konto.
$automation = new MailAutomation(
    client: $client,
    storage: $database,
    contactResolver: new ReplyContactResolver(),
);
// Explizit zum Verständnis; ohne contactResolver verwendet die Engine denselben Standard.
// run(): Sent indexieren → processed-Sperre prüfen → Kontakt auflösen → Handler aufrufen.
// Der Resolver liest bekannte From-Aliase aus dem ContactStore und prüft Antwortheader
// gegen eine aktuell vorhandene eigene Sent-Mail mit genau einem externen Empfänger.
// Kontakt/Alias sind bei erfolgreichem Lernen VOR diesem Handler bereits gespeichert.

#[OnFolderAutomation(folder: Folder::Inbox)]
function routeByContactResolution(Email $mail, MailContext $context): MailActions
{
    $resolution = $context->contactResolution; // ContactResolution: technisches Ergebnis dieser Mail.
    $contact = $context->contact; // Derselbe Contact|null wie resolution.contact; keine zweite Person.

    switch ($resolution->status) {
        case ContactResolutionStatus::Unknown:
            // Keine bekannte Adresse und kein zulässiger Antwortbeleg; contact ist null.
            // Keine automatische Anlage. Eingang zur Erstkontaktbearbeitung ablegen.
            return MailActions::create()->addFlag('new_contact')->moveTo('NewContacts');

        case ContactResolutionStatus::KnownAddress:
            // From gehört bereits zu einem Kontakt; auch ohne Antwortheader möglich.
            // Keine Anlage/neuer Alias. Mit den vorhandenen Kontaktdaten weiterarbeiten.
            return MailActions::create()->moveTo('Customers');

        case ContactResolutionStatus::ContactCreated:
            // Erste qualifizierte Antwort: ursprünglich adressierten Kontakt neu angelegt.
            // Hauptadresse bleibt der ursprüngliche Empfänger, abweichender From wird Alias.
            // contact ist gesetzt, aber fachlich noch nicht klassifiziert: Erstprüfung.
            return MailActions::create()->addFlag('new_contact')->moveTo('NewContacts');

        case ContactResolutionStatus::AliasAdded:
            // Kontakt existierte bereits; neuer From wurde durch den Sent-Beleg zugeordnet.
            // Keine zweite Anlage, keine Änderung der Hauptadresse oder Anwendungsmetadaten.
            return MailActions::create()->moveTo('Customers');

        case ContactResolutionStatus::Conflict:
            // Z.B. Antwortbeleg mit mehreren Empfängern oder From gehört einem anderen Kontakt.
            // Kein Lernen/Zusammenführen. contact kann trotzdem den bekannten From enthalten!
            // Menschen Beleg, Empfänger und Aliaszuordnung prüfen lassen; nicht normal routen.
            return MailActions::create()->addFlag('phore_review')->moveTo('Review');

        case ContactResolutionStatus::OutgoingMissing:
            // Referenzierte Ausgangsmail wurde auch bei Live-Suche nicht in Sent gefunden.
            // Cacheeintrag reicht nicht. Keine Anlage/neuer Alias; bekannten From ggf. behalten.
            // Sent-Ordner/Beleg prüfen oder wiederherstellen, dann bewusst erneut freigeben.
            return MailActions::create()->addFlag('phore_review')->moveTo('Review');
    }

    // Neue/unbehandelte Statuswerte sind ein Fehler, kein stilles pass()/complete().
    throw new LogicException('Unbehandelter ContactResolutionStatus');
}

$automation->addRules('routeByContactResolution');
$report = $automation->run();

// Jeweils unabhängige Beispieldaten → Ergebnis:
// Unknown: ben@new.example schreibt erstmals ohne Antwortbezug → kein Kontakt, NewContacts.
// KnownAddress: Anna schreibt von einem gespeicherten Alias → vorhandener Kontakt, Customers.
// ContactCreated: Sent <out-1> an anna@old.example, erster Reply von anna@new.example:
//   neuer Kontakt, primaryEmail=anna@old.example, aliasAdded=true, NewContacts.
// AliasAdded: derselbe Beleg, Kontakt für anna@old.example existierte schon:
//   dieselbe Kontakt-ID, anna@new.example ergänzt, aliasAdded=true, Customers.
// Conflict: From gehört Anna, referenzierter Ausgang ging an Ben → Review; contact kann Anna sein.
// OutgoingMissing: <out-1> ist nicht mehr in Sent → Review; aliasAdded=false, kein Lernen.
//
// resolution.needsReview() fasst Conflict und OutgoingMissing zusammen (vereinfachte Regel: 03).
// resolution.isConflict() ist nur bei Conflict true. Ein gesetzter contact hebt Review nicht auf.
// resolution.aliasAdded ist unabhängig vom Neuanlage-Status; auch ContactCreated kann true liefern.
// resolution.matchedOutgoing enthält den eindeutig live geprüften Beleg oder null.
// Seine messageId, recipientEmail und folder erklären, worauf sich das Lernen stützt;
// bei Unknown/OutgoingMissing ist er null, bei widersprüchlichen/mehrdeutigen Belegen ebenfalls.
//
// Own-From, Bounces und automatische Antworten lernen keine Kontakte/Aliase.
// Ohne vorhandenen Kontakt ergibt das Unknown; vorhandene Adresse kann KnownAddress ergeben.
// Netz-/Zugriffsfehler ergeben NICHT OutgoingMissing: kein Handler, Fehler im RunReport,
// Mail bleibt offen. Report-Fehlerzugriff ist im Entwurf noch nicht spezifiziert.
//
// Die hier gewählten erfolgreichen Aktionen setzen überall phore_processed, auch in Review.
// Review ist unser Zielordner, kein automatisches Warten/Wiederholen der Engine.
// Nach Klärung: nach Inbox verschieben, zuletzt phore_processed entfernen; nächster run() prüft neu.
// Bereits markierte Mails erreichen den Resolver/Handler gar nicht; es gibt keinen Skipped-Status.
// Zum Delegieren vor eigenen Änderungen pass() zurückgeben; Resolver-Schreibvorgänge bleiben bestehen.
// Sent verwendet Empfänger-Lookup statt From-Lernen; Einzel-/Mehrfachempfänger siehe README und 04.
// Gesprächsbezug ist keine Authentifizierung; ContactResolution ersetzt keine Berechtigungsprüfung.
