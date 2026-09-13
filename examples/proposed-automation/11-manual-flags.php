<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Ein Thunderbird-Schlagwort klassifiziert einen bekannten Benutzer als B2B.
use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailActions, MailAutomation, MailContext};
use Phore\MailClient\Automation\Attributes\{OnFlagAdded};

// Voraussetzung: $automation kennt Customers und die normale B2B-Zielregel.
assert($automation instanceof MailAutomation); // Registriert Regeln und überwacht Flagänderungen.

// OnFlagAdded reagiert auf dieses neue Keyword
// im genannten folder, auch wenn die Nachricht schon processed ist. id ist die Regelkennung.
// Dies ist die registrierte Callback-Funktion selbst, keine umschließende Demo-Funktion.
#[OnFlagAdded(id: 'manual.b2b', folder: 'Customers', flag: 'classify_b2b')]
function classifyB2b(Email $mail, MailContext $context): MailActions
{
    // Email ist die betroffene Nachricht; MailContext.user ist MailUser|null.
    if ($context->user === null) {
        // MailActions.create beginnt die Liste; addFlag kennzeichnet manuellen Prüfbedarf.
        // Ein Schlagwort allein berechtigt nicht zur automatischen Benutzeranlage.
        return MailActions::create()->addFlag('phore_review');
    }

    // classify persistiert die Benutzerkategorie für künftige Nachrichten.
    $context->user->classify('b2b');

    // removeFlag entfernt nur dieses Keyword; moveTo verschiebt im selben Konto.
    // reprocess entfernt processed im Ziel und plant dessen Regeln für den nächsten Lauf.
    return MailActions::create()
        ->removeFlag('classify_b2b')
        ->moveTo('B2B', reprocess: true);
}

// addRules liest die Attribute dieses benannten Callables; keine versteckte automatische Suche.
$automation->addRules('classifyB2b');

// run synchronisiert Flags und führt den Treffer aus; Rückgabe RunReport.
$report = $automation->run();
// Erwartung: bekannter Benutzer ist b2b; Nachricht wartet in B2B auf den nächsten Lauf.
// Manuelles Verschieben allein behält processed. Entfernen dieses Tags startet die Zielregel neu.
