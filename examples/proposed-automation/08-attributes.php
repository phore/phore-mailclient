<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Dieselben Eingangs-/Ausgangsregeln als PHP-Attribute an einer Regelklasse registrieren.
use Phore\MailClient\Email;
use Phore\MailClient\Automation\{MailActions, MailAutomation, MailContext};
use Phore\MailClient\Automation\Attributes\{OnInboxMessage, OnSentMessage, OnFolder};

// Voraussetzung: Anwendung stellt $automation mit Client bereit; Invoices existiert.
// MailAutomation entdeckt die Attribute explizit registrierter Regelobjekte.
assert($automation instanceof MailAutomation);

// Diese Klasse ist die tatsächlich registrierte API-Regel, keine Demo-Hilfsklasse.
final class InvoiceRules
{
    // OnInboxMessage prüft den Betreff im Hauptordner; subjectContains ist ein Teilstringfilter.
    // id identifiziert die Regel, priority legt die Reihenfolge fest (höher zuerst).
    #[OnInboxMessage(id: 'invoice.route', priority: 100, subjectContains: 'Rechnung')]
    public function route(Email $mail, MailContext $context): MailActions
    {
        // Email ist der Eingang; MailContext enthält user (MailUser|null) samt Metadaten.
        // MailActions.create startet die Liste; addFlag ergänzt ein Keyword,
        // moveTo verschiebt im selben Konto. Normale Fertigstellung setzt processed.
        return MailActions::create()->addFlag('invoice')->moveTo('Invoices');
    }

    // OnSentMessage reagiert auf neue Sent-Beobachtungen; hier wird Benutzeranlage aktiviert.
    #[OnSentMessage(id: 'recipient.create', priority: 0)]
    public function sent(Email $mail, MailContext $context): MailActions
    {
        // recipientUsers ordnet externe Empfängeradressen MailUser|null zu.
        if (count($context->recipientUsers) !== 1) {
            // none ist Erfolg ohne weitere Mailaktionen; hier keine Benutzeranlage.
            return MailActions::none();
        }
        // createUserForRecipient lädt/erstellt den einzigen Empfänger; Rückgabe MailUser.
        $user = $context->createUserForRecipient();
        // setMetadata persistiert einen anwendungsspezifischen Benutzerwert.
        $user->setMetadata('source', 'sent_folder');
        return MailActions::none();
    }
}

// OnFolder registriert die normale Kette eines bestehenden Ordners.
// Ein Klassentrigger bindet __invoke als Handler; hier ist die aufrufbare Klasse selbst die API.
#[OnFolder(id: 'invoice.ready', folder: 'Invoices', priority: 0)]
final class InvoiceReady
{
    public function __invoke(Email $mail, MailContext $context): MailActions
    {
        return MailActions::create()->addFlag('invoice_ready');
    }
}

// addRules liest die Attribute dieses Objekts; es führt die Regeln noch nicht aus.
$automation->addRules(new InvoiceRules());
$automation->addRules(new InvoiceReady());

// run synchronisiert und führt passende Regeln aus; Rückgabe RunReport.
$report = $automation->run();
// Erwartung: Rechnungen landen markiert in Invoices; neue Ausgänge können Empfänger anlegen.
// Alternative zu programmgesteuerter Registrierung; nicht dieselbe Fachregel doppelt registrieren.
// Die normale Eingangsverschiebung behält processed: InvoiceReady läuft erst nach bewusstem Reset.
