<?php
// Wie registriere und pausiere ich Regeln über PHP-Attribute?
// Alternative zu den programmatischen Regeln: verwendet nur das Setup aus 01.
// Invoices existiert. Klassenattribute gelten für __invoke; Standard-ID ist der Klassenkurzname.
// Kein separates Mailbox-Attribut; active ist ohne Angabe true.
#[OnFolderAutomation(folder: Folder::Inbox, subjectContains: 'Rechnung')]
final class InvoiceRules
{
    public function __invoke(Email $mail, MailContext $context): MailActions
    {
        return MailActions::create()->addFlag('invoice')->moveTo('Invoices');
    }
}

// Methodenattribute gelten für die jeweilige Methode; abgeleitete ID: RecipientRules::sent.
final class RecipientRules
{
    #[OnFolderAutomation(folder: Folder::Sent)]
    public function sent(Email $mail, MailContext $context): MailActions
    {
        if (count($context->recipientUsers) === 1) {
            $context->createUserForRecipient()->setMetadata('source', 'sent_folder');
        }
        return MailActions::none();
    }
}

// automationId bleibt auch bei Umbenennung der Klasse stabil; doppelte IDs sind Registrierungsfehler.
// active: false überspringt diese Regel. Andere Regeln/No-match können trotzdem processed setzen.
// Wieder aktivieren durch true/Entfernen der Option; alte processed-Mails benötigen bewussten Reset.
#[OnFolderAutomation(folder: 'Invoices', automationId: 'invoice.ready', active: false)]
final class InvoiceReady
{
    public function __invoke(Email $mail, MailContext $context): MailActions
    {
        return MailActions::create()->addFlag('invoice_ready');
    }
}

// Nur explizit registrierte Objekte werden ausgewertet; noch keine Nachrichtenverarbeitung.
$automation->addRules(new InvoiceRules());
$automation->addRules(new RecipientRules());
$automation->addRules(new InvoiceReady());
$report = $automation->run();

// Neue Rechnung → Invoices + invoice + processed, kein invoice_ready.
// Neuer Ausgang an einzelnen unbekannten Empfänger → Benutzer wird angelegt.
// Andere Betreffzeilen treffen hier keine Inbox-Regel und werden als geprüft markiert.
