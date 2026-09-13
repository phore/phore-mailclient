<?php
// Wie gebe ich eine Nachricht für die manuelle B2B-Klassifizierung frei?
// Ergänzt 03 vor run(), einschließlich dessen B2B-Zielregel.
// In Thunderbird: nach Customers verschieben, classify_b2b setzen, ZULETZT phore_processed entfernen.
// Ein bereits gesetztes classify_b2b genügt: die Regel prüft den aktuellen Zustand.
// flag ist eine Keyword-Bedingung der normalen Ordnerkette, kein Flagänderungs-Ereignis.
#[OnFolderAutomation(folder: 'Customers', flag: 'classify_b2b')]
function classifyB2b(Email $mail, MailContext $context): MailActions
{
    if ($context->contactResolution->needsReview() || $context->contact === null) {
        return MailActions::create()->addFlag('phore_review');
    }

    $context->contact->metadata->set('classification', 'b2b');
    return MailActions::create()
        ->removeFlag('classify_b2b')
        ->moveTo('B2B', reprocess: true);
}

$automation->addRules('classifyB2b');
// Programmgesteuert lautet dieselbe Bedingung in matches: $context->hasFlag('classify_b2b').
// Der globale processed-Check kommt von der Engine, nicht vom Handler.
//
// Beispiel: Annas markierte Mail erhält classify_b2b → noch keine Bearbeitung.
// Erst nach Entfernen von phore_processed: nächster run() klassifiziert Anna,
// entfernt classify_b2b und verschiebt nach B2B; dessen Kette wartet auf den Folgelauf.
// Folgelauf → b2b_ready + phore_processed.
// Unbekannter/konfliktbehafteter Absender → phore_review + phore_processed, keine Kontaktanlage.
// Eine kopierte Mail bleibt mit erhaltenem phore_processed gesperrt; Freigabe gilt nur für diese Kopie.
// Ohne Freigabe laufen weder dieser Handler noch andere Automatisierungen.
