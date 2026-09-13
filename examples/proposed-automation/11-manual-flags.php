// Wie löst eine manuelle Thunderbird-Markierung die nächste Bearbeitung aus?
// Ergänzt 03 vor run(), einschließlich dessen B2B-Zielregel.
// OnFlagAdded ist ein eigener Ereignispfad und reagiert auch auf bereits processed-Mails.
// Thunderbird-Tag muss genau das Keyword classify_b2b setzen; dessen Farbe ist Client-Konfiguration.
#[OnFlagAdded(folder: 'Customers', flag: 'classify_b2b')]
function classifyB2b(Email $mail, MailContext $context): MailActions
{
    if ($context->identity->needsReview() || $context->user === null) {
        // Manuelle Markierung allein erlaubt keine neue Benutzerzuordnung.
        return MailActions::create()->addFlag('phore_review');
    }

    $context->user->classify('b2b');
    return MailActions::create()
        ->removeFlag('classify_b2b')
        ->moveTo('B2B', reprocess: true);
}

$automation->addRules('classifyB2b');
// Funktionsname ist hier die abgeleitete ID; Ausführung durch das run() des umgebenden Ablaufs.
// Beispiel: Annas processed-Mail in Customers erhält classify_b2b.
// Erster Lauf → Anna dauerhaft b2b, Mail in B2B ohne classify_b2b/processed.
// Nächster Lauf → B2B-Zielregel setzt b2b_ready und processed.
// Unbekannter/konfliktbehafteter Absender → nur phore_review, keine Benutzeranlage.
// Manuelles Verschieben ohne Entfernen von processed startet die normale Zielkette nicht neu.
