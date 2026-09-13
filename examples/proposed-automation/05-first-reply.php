<?php
declare(strict_types=1);

// API-ENTWURF: Noch nicht implementiert; Ausschnitt aus einer Anwendung.
// automationId ist optional; active (Standard true) kann diese Regel vorübergehend deaktivieren.
// Ziel: Antwortidentitäten automatisch auflösen und im Handler den Benutzer erhalten.
use Phore\MailClient\{MailClient, Email};
use Phore\MailClient\Automation\{MailAutomation, ReplyIdentityResolver, Folder,
    MailContext, MailActions, MailUser, IdentityResult, RunReport};

// Voraussetzung: Die Anwendung stellt einen verbundenen Client und eine SQLite-Verbindung.
// MailClient enthält Absender und Ordnerkonfiguration; PDO dient als gemeinsamer Speicher.
assert($client instanceof MailClient);
assert($database instanceof PDO);

// Beispieldaten: Sent enthält <out-1@example.org> an "Anna Müller <anna@old.example>".
// Der einzige unmarkierte Inbox-Eingang ist eine Antwort von anna@new.example mit passendem In-Reply-To.
// Noch kein Benutzer vorhanden; kein Ausgangsfilter legt den Empfänger vorab an.

// ReplyIdentityResolver aktiviert bekannte Absenderzuordnung und Lernen aus verifizierten Antworten.
// Die Standardstrategie erstellt Benutzer bei der ersten Antwort und ergänzt neue From-Aliase.
$resolver = new ReplyIdentityResolver();

// identity bindet die Strategie an die Automatisierung. Client, Benutzer-/Alias-Store und
// Historie kommen aus derselben Automation; kein zweiter Store und kein eigener Resolver-Setup nötig.
// storage erkennt PDO SQLite; automatische User-IDs sind Name-Slug + "-e" + acht Zufallszeichen.
$automation = new MailAutomation(
    client: $client,
    storage: $database,
    identity: $resolver,
);

// onFolder wählt den Ordner; Folder::Inbox wird über den Client aufgelöst.
// addAutomation registriert automationId, priority (höher zuerst), matches und handle.
// Email ist die Nachricht; MailContext ist VOR matches/handle um die Identität angereichert.
$automation->onFolder(Folder::Inbox)->addAutomation(
    automationId: 'reply.classify', priority: 0,
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: function (Email $mail, MailContext $context): MailActions {
        // identity ist IdentityResult: Zuordnungsgrund und Ergebnis des Resolvers.
        assert($context->identity instanceof IdentityResult);

        // needsReview zeigt Konflikte/fehlende Ausgangsevidenz, user=null einen unbekannten Absender.
        // MailActions.create startet die Liste; addFlag markiert manuellen Prüfbedarf.
        if ($context->identity->needsReview() || $context->user === null) {
            return MailActions::create()->addFlag('phore_review');
        }

        // user ist jetzt MailUser; Benutzeranlage und Aliaslernen sind bereits erfolgt.
        $user = $context->user;
        assert($user instanceof MailUser);
        // primaryEmail bleibt die ursprünglich angeschriebene Adresse (string).
        assert($user->primaryEmail === 'anna@old.example'); // Gilt für die obigen Beispieldaten.
        // aliasAdded ist bool: hier wurde anna@new.example neu als Alias gespeichert.
        assert($context->identity->aliasAdded === true); // Erste Verarbeitung dieser Beispielantwort.
        // id ist die stabile Benutzerkennung; der zufällige Teil wird nicht durch das Beispiel festgelegt.
        assert(str_starts_with($user->id, 'anna-mueller-e'));

        // setMetadata speichert Anwendungsdaten, die auch bei späteren Eingängen verfügbar sind.
        $user->setMetadata('replyLanguage', 'de');
        // none bedeutet Erfolg ohne zusätzliche Mailaktion; die Engine setzt processed.
        return MailActions::none();
    },
);

// run liest Sent, prüft den Antwortbezug live, erzeugt IDs/Aliase und ruft erst dann Regeln auf.
$report = $automation->run();
assert($report instanceof RunReport); // Zähler und strukturierte Fehler dieses Laufs.

// Ergebnis: Anna ist angelegt, anna@old.example bleibt primär, anna@new.example ist Alias.
// Ein fehlender/mehrdeutiger Ausgang erzeugt keinen neuen Benutzer/Alias, sondern Prüfbedarf.
// Unbekannte Mail ohne Antwortbezug bleibt user=null; bestehende Aliase werden wiedererkannt.
// Der Handler ruft learnAliasFromReply nicht selbst auf und erzeugt keine IDs.
// identity darf entfallen: dieselbe Standardstrategie wird dann intern verwendet.
// Ein optionaler idGenerator gehört an Automation/Storage, nicht in den Nachrichtenhandler.
