<?php
// Wie kapsle ich eigene Fachlogik in typisierten Metadaten?
// Unabhängige Alternative: $client und $database aus 01; B2B existiert.
// CustomerMetadata gehört zur Anwendung. Die Bibliothek kennt keinen B2B-Kundentyp.
final class CustomerMetadata extends MetadataBag
{
    public function classifyAsB2b(): void
    {
        $this->set('classification', 'b2b');
    }

    public function isB2b(): bool
    {
        return $this->get('classification') === 'b2b';
    }
}

$automation = new MailAutomation(
    client: $client,
    storage: $database,
    contactMetadata: CustomerMetadata::class,
);
// Klassenname statt gemeinsamem Prototyp: jede Kontakt-ID erhält ihren eigenen Speicherzugriff.
// Vorhandene Werte werden unter derselben ID gelesen, neue Kontakte beginnen mit leeren Metadaten.
// Nur der Kontakttyp wird angepasst; Nachricht/Thread/Mailbox behalten die normale MetadataBag.

/** @param MailContext<CustomerMetadata, MetadataBag, MetadataBag, MetadataBag> $context */
#[OnFolderAutomation(folder: Folder::Inbox)]
function routeBusinessContact(Email $mail, MailContext $context): MailActions
{
    if ($context->contactResolution->needsReview() || $context->contact === null) {
        return MailActions::pass();
    }

    $metadata = $context->contact->metadata; // CustomerMetadata durch Konfiguration + PHPDoc.
    // review_complete bedeutet hier: ein Mensch hat B2B bestätigt (Freigabeablauf in 06).
    if ($context->hasFlag('review_complete')) {
        $metadata->classifyAsB2b(); // Eigene Methode; set() speichert sofort.
        return MailActions::create()->removeFlag('review_complete')->moveTo('B2B');
    }

    if (!$metadata->isB2b()) {
        return MailActions::pass();
    }

    return MailActions::create()->moveTo('B2B');
}

$automation->addRules('routeBusinessContact');
$report = $automation->run();
// Bekannte Anna mit classification=b2b → B2B; bestätigte Anna erhält denselben Metadatenwert.
// Ein anderer Kontakt erbt diese Werte nicht. Die Engine interpretiert sie niemals.
// pass() hier ohne weitere Regel → als geprüft markieren; für Review eine Folgeregel registrieren.
// PHPDoc beschreibt Generics für statische Analyse; der native Parameter bleibt MailContext.
// Die Annotation allein konfiguriert nichts: contactMetadata oben erzeugt den tatsächlichen Typ.
// Methoden verwenden get()/set(); zusätzliche öffentliche Properties werden nicht automatisch gespeichert.
