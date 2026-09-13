// Wie unterscheiden wir Prüfbedarf, B2B, Neukontakte und sonstige Kunden?
// Ersetzt die Inbox-Regel aus 01, nach dessen Setup und vor dessen run().
// Zusätzlich existieren Review, B2B, NewContacts und Customers.
// Höhere priority zuerst; nur der erste Treffer pro Ordnerkette wird ausgeführt.
$automation->onFolder(Folder::Inbox)->addAutomation(
    priority: 300,
    matches: fn (Email $mail, MailContext $context): bool => $context->identity->needsReview(),
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->addFlag('phore_review')->moveTo('Review'),
);

// reprocess: true entfernt processed im Ziel und plant dessen Kette für den NÄCHSTEN Lauf.
// Deshalb gehört die B2B-Zielregel unten zu dieser Variante.
$automation->onFolder(Folder::Inbox)->addAutomation(
    priority: 200,
    matches: fn (Email $mail, MailContext $context): bool => $context->user?->classification === 'b2b',
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->moveTo('B2B', reprocess: true),
);

$automation->onFolder(Folder::Inbox)->addAutomation(
    priority: 100,
    matches: fn (Email $mail, MailContext $context): bool => $context->user === null,
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->addFlag('new_contact')->moveTo('NewContacts'),
);

$automation->onFolder(Folder::Inbox)->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->moveTo('Customers'),
);

$automation->onFolder('B2B')->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: fn (Email $mail, MailContext $context): MailActions =>
        MailActions::create()->addFlag('b2b_ready'),
);

// Ergebnis nach dem run() aus 01:
// Annas B2B-Mail liegt zunächst ohne processed in B2B; erst der nächste run() setzt b2b_ready.
// Unbekannte unverknüpfte Mail → NewContacts + new_contact + processed, weiterhin kein Benutzer.
// Bekannter Privatkunde → Customers; widersprüchliche Antwortzuordnung → Review.
