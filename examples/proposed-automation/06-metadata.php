<?php
declare(strict_types=1);

// API-ENTWURF: Die Automation-Typen sind noch nicht implementiert.
// Anwendungsausschnitt mit ausdrücklich vorausgesetzten Objekten; nicht eigenständig ausführbar.

// Ziel: Benutzer über einen Alias laden, klassifizieren und Daten samt Historie lesen.
use Phore\MailClient\Automation\{AliasStore, MailHistoryStore, MailUser};

// Voraussetzung: Anwendung stellt ihre Stores bereit; Anna existiert mit anna@new.example.
// AliasStore sucht und pflegt Benutzer/Adressen; MailHistoryStore liefert Nachrichtenmetadaten.
assert($users instanceof AliasStore);
assert($history instanceof MailHistoryStore);

// findByEmail sucht Hauptadresse und Aliase, erzeugt aber keinen Benutzer.
// MailUser|null ist die Rückgabe; die Beispieldaten setzen einen Treffer voraus.
$user = $users->findByEmail('anna@new.example');
assert($user instanceof MailUser);

// classify persistiert die Kategorie; setMetadata persistiert jeweils einen Wert am Benutzer.
$user->classify('b2b');
$user->setMetadata('customerNumber', 'C-1042');

// findById lädt denselben Benutzer erneut; id ist seine unveränderliche Kennung.
$storedUser = $users->findById($user->id);
assert($storedUser instanceof MailUser);
// classification ist string|null; metadata enthält anwendungsspezifische Werte.
assert($storedUser->classification === 'b2b');
assert($storedUser->metadata['customerNumber'] === 'C-1042');

// forUser liefert list<MailHistoryEntry>; limit begrenzt die Anzahl, auch [] ist möglich.
// Historie enthält Richtung, Zeitpunkt und Referenzen, keine garantierten Mailkörper.
$messages = $history->forUser($user->id, limit: 20);

// search findet über aktuellen Namen, ID und jede Adresse; Rückgabe list<MailUser>.
$matches = $users->search('Anna');
// Bei neuen Eingängen enthält MailContext.user diese gespeicherte Klassifizierung/Metadaten.
// Ein unbekannter Absender bleibt null: erst eine erlaubte Benutzeranlage ermöglicht classify.
