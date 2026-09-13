<?php
// Wie ändere ich einen bestehenden Kontakt und seine Aliasnamen/-adressen?
// Eigenständige Verwaltungsaktion nach menschlicher Bestätigung, kein Incoming-Handler.
// $database ist dieselbe PDO-Verbindung aus 01; expliziter Store-Zugang wie in 09.
$storage = new SqliteStorage($database);
$contacts = $storage->contacts();
$contact = $contacts->findByEmail('anna@old.example');
if ($contact === null) {
    throw new RuntimeException('Kontakt fehlt; zuerst den Antwortablauf aus Beispiel 05 abschließen.');
}

// Voraussetzung für diesen konkreten Verwaltungsauftrag: Anna hat diese neue Adresse bestätigt.
// Alle Mutatoren speichern sofort; kein save(), run() oder MailActions erforderlich.
// Im Handler wären dieselben Aufrufe nach der Review/null-Prüfung auf context.contact möglich.
$contactId = $contact->id;
$contact->setName('Anna Schneider'); // Personenname; ID und Aliasnamen bleiben bestehen.
$contact->metadata->set('customerNumber', 'C-1042');
$contact->classify('b2b'); // Ersetzt classification; Routing liest es in Beispiel 03.

// Eine Aliasadresse hat einen eigenen optionalen Anzeigenamen.
// addAlias liefert ContactAlias; die Hauptadresse bleibt zunächst anna@old.example.
// Schon demselben Kontakt zugeordnet? Vorhandenen Alias unverändert zurückgeben.
// Gehört die Adresse jemand anderem? Fehler, keine automatische Zusammenführung.
$alias = $contact->addAlias('anna@firma.example', name: 'Anna geschäftlich');
$contact->setAliasName($alias->email, 'Anna Schneider – Einkauf');
// setAliasName(email, null) würde nur den Anzeigenamen dieses Alias entfernen.

// Hauptadresse muss bereits als Alias vorhanden sein; der Wechsel löscht keine Adresse.
$contact->setPrimaryEmail($alias->email);
// Eine falsch/veraltet zugeordnete Adresse korrigieren: bestätigte Adresse hinzufügen,
// ggf. als Hauptadresse wählen, dann alte Zuordnung entfernen. Belege nie umschreiben.
$contact->removeAlias('anna@old.example');
// Entfernen der aktuellen Hauptadresse wird abgelehnt. Alte Historienbelege bleiben erhalten.
// Neue Mails werden nicht mehr über diese Zuordnung erkannt; eine spätere verifizierte
// Antwort kann die Adresse erneut lernen. Entfernen ist keine dauerhafte Sperrliste.

// Erneutes Lesen über dieselbe ID zeigt die gespeicherten Änderungen.
$updated = $contacts->findById($contactId);
$aliasNames = [];
foreach ($updated->aliases as $address) {
    $aliasNames[$address->email] = $address->name;
}

// Ergebnis: dieselbe Kontakt-ID, name='Anna Schneider', primaryEmail=anna@firma.example,
// classification=b2b, customerNumber=C-1042 und Aliasname='Anna Schneider – Einkauf'.
// Andere bestehende Aliase bleiben erhalten; anna@old.example ist kein aktiver Alias mehr.
// Manuelles Hinzufügen hat source='manual', keine erfundenen Antwort-/Sent-Belege.
// Keine Nachricht/kein Keyword wird geändert oder erneut verarbeitet.
// Unbekannte Eingänge weiterhin nicht hiermit blind übernehmen: automatisch lernt nur 05.
