<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Folder benennt Standardordner; ihre tatsächlichen Namen stammen aus dem Client.
// Ziel: Dieselben Eingangs-/Ausgangsregeln als PHP-Attribute an einer Regelklasse registrieren.
use Phore\MailClient\Email;
use Phore\MailClient\Automation\{Folder, MailActions, MailAutomation, MailContext};
use Phore\MailClient\Automation\Attributes\{OnFolderAutomation};

// Voraussetzung: Anwendung stellt $automation mit Client bereit; Invoices existiert.
// MailAutomation entdeckt die Attribute explizit registrierter Regelobjekte.
assert($automation instanceof MailAutomation);

// Diese Klasse ist die tatsächlich registrierte API-Regel, keine Demo-Hilfsklasse.
final class InvoiceRules
{
    // OnFolderAutomation mit Inbox prüft den Betreff im Hauptordner; subjectContains ist ein Teilstringfilter.
    // priority legt die Reihenfolge fest (höher zuerst); active ist standardmäßig true.
    // Ohne automationId wird hier InvoiceRules::route verwendet, da das Attribut an einer Methode sitzt.
    #[OnFolderAutomation(folder: Folder::Inbox, priority: 100, subjectContains: 'Rechnung')]
    public function route(Email $mail, MailContext $context): MailActions
    {
        // Email ist der Eingang; MailContext enthält user (MailUser|null) samt Metadaten.
        // MailActions.create startet die Liste; addFlag ergänzt ein Keyword,
        // moveTo verschiebt im selben Konto. Normale Fertigstellung setzt processed.
        return MailActions::create()->addFlag('invoice')->moveTo('Invoices');
    }

    // Ohne automationId lautet die Methodenkennung InvoiceRules::sent.
    // OnFolderAutomation mit Sent reagiert auf neue Sent-Beobachtungen; hier wird Benutzeranlage aktiviert.
    #[OnFolderAutomation(folder: Folder::Sent, priority: 0)]
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

// OnFolderAutomation registriert die normale Kette eines bestehenden Ordners.
// Ein Klassentrigger bindet __invoke als Handler; Standard-ID ist der Shortname InvoiceReady.
// active: false pausiert die Regel, ohne ihre Registrierung/ID zu entfernen.
// Optional automationId: 'invoice.ready' vergibt eine explizite, auch bei Umbenennung stabile ID.
#[OnFolderAutomation(folder: 'Invoices', priority: 0, active: false)]
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
// InvoiceReady ist pausiert. Für spätere Verarbeitung active: true setzen UND processed bewusst entfernen.
// Pausieren hält Nachrichten nicht zurück: andere Regeln bzw. No-match können processed setzen.
