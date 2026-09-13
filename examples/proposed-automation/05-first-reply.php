<?php
// Wie lernt die erste Antwort eine neue Absenderadresse?
// Unabhängige Alternative zu 01: verwendet dessen $client und $database,
// ersetzt Konstruktor und Inbox-Regel; KEINE Ausgangsregel aus 04 hinzufügen.
// Sent enthält <out-1@example.org> an Anna Müller <anna@old.example>.
// Anna antwortet erstmals von anna@new.example mit In-Reply-To: <out-1@example.org>.

// Explizite Einbindung der Standardstrategie; ohne identity gilt dasselbe Verhalten.
// Die Automation verbindet den Resolver mit ihrem Client und ihren gemeinsamen Stores.
$resolver = new ReplyIdentityResolver();
$automation = new MailAutomation(
    client: $client,
    storage: $database,
    identity: $resolver,
);

// Vor matches/handle prüft der Resolver den Ausgang live in Sent und ergänzt den Kontext.
// Ein Cachetreffer allein genügt nicht zur Benutzeranlage oder Aufnahme eines Alias.
$automation->onFolder(Folder::Inbox)->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: function (Email $mail, MailContext $context): MailActions {
        if ($context->identity->needsReview() || $context->user === null) {
            return MailActions::create()->addFlag('phore_review');
        }

        // Benutzer und neue Aliase sind bereits gespeichert; diese Metadatenänderung gilt sofort.
        $context->user->setMetadata('replyLanguage', 'de');
        return MailActions::none();
    },
);
$report = $automation->run();

// Erwartete Beispieldaten, keine Bedingungen im Anwendungshandler:
// context.user.primaryEmail = anna@old.example; anna@new.example ist zusätzlicher Alias.
// context.user.id = anna-mueller-e<8 Zufallszeichen>; context.identity.aliasAdded = true.
// Bei späterer Mail von diesem Alias wird derselbe Benutzer samt Metadaten geladen.
// Fehlender Sent-Beleg / mehrere mögliche Empfänger / zwei betroffene Benutzer:
// needsReview() = true, keine neue Zuordnung. Ein bereits bekannter From kann user liefern.
// Unbekannter Absender ohne Antwortbezug: user = null, keine Anlage, hier phore_review.
