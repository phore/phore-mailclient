<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Erste Antwort zuordnen und eine abweichende From-Adresse als Alias aufnehmen.
use Phore\MailClient\Email;
use Phore\MailClient\Automation\{IdentityResult, MailUser, ReplyIdentityResolver};

// Voraussetzung: Anwendung stellt den postfachgebundenen Resolver und eine gelesene Antwort bereit.
// ReplyIdentityResolver prüft Antwortheader gegen live vorhandene Gesendet-Nachrichten.
assert($resolver instanceof ReplyIdentityResolver);
// Email enthält From anna@new.example und In-Reply-To <out-1@example.org>.
assert($reply instanceof Email);
// Sent enthält eindeutig <out-1@example.org> an "Anna Müller <anna@old.example>".
// Noch kein Benutzer vorhanden; kein Ausgangsfilter hat ihn vorab angelegt.

// learnAliasFromReply prüft den Ausgang in "support"/Sent, legt den Empfänger an
// und lernt die abweichende Antwortadresse. MailAutomation ruft dies normalerweise intern auf.
$result = $resolver->learnAliasFromReply('support', $reply);

// IdentityResult erklärt die Zuordnung; user ist MailUser|null, aliasAdded ein bool.
assert($result instanceof IdentityResult);
assert($result->user instanceof MailUser);
assert($result->aliasAdded === true);

// primaryEmail bleibt die ursprünglich angeschriebene Adresse (string).
assert($result->user->primaryEmail === 'anna@old.example');
// id ist dauerhaft: Name-Slug plus "-e" und acht zufällige Zeichen; kein fester Testwert.
assert(str_starts_with($result->user->id, 'anna-mueller-e'));
// aliases enthält anna@new.example mit firstSeenAt/lastSeenAt und beiden Message-IDs als Beleg.
// Fehlt der Ausgang inzwischen in Sent: kein neuer Benutzer/Alias, Status OutgoingMissing.
// Eine unbekannte Mail ohne Antwortbezug liefert user=null; ein bloßes "Re:" genügt nicht.
// Eine spätere Antwort über denselben Alias aktualisiert lastSeenAt, nicht die Hauptadresse.
