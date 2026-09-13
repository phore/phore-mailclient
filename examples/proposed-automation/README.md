# MailAutomation: Post sortieren und Kontakte wiedererkennen

**Entwurfsstatus:** Diese Reihe zeigt vorgeschlagene PHP-8.5-Anwendungsausschnitte.
Die Automation-Typen sind noch nicht implementiert. Die Dateien sind keine ausführbaren
Skripte und werden nicht nacheinander eingebunden. Jede PHP-Datei beginnt mit `<?php`; Imports und weiterer Dateirahmen sind
bewusst ausgelassen. Der [API-Vertrag](../../proposals/2026-09-13-mail-automation.md)
beschreibt die geplante Implementierung und ihre Grenzen.

Beginne mit [01: Post sortieren](01-overview.php). Der abgeschlossene Standardfall
verbindet den Speicher, registriert eine Inbox-Regel und verarbeitet die Post.
`MailAutomation` verwaltet Synchronisation und Zustand, `MailContext` liefert
Kontaktwissen, `MailActions` beschreibt die beabsichtigten Mailänderungen.
Die weiteren Dateien beantworten jeweils eine zusätzliche Frage.

| Datei | Leserfrage / Einbindung |
|---|---|
| [01-overview.php](01-overview.php) | Wie sortiere ich Post mit der Basiskonfiguration? Vollständiger Einstieg. |
| [02-setup.php](02-setup.php) | Wo liegen Verbindung, Ordnerkonfiguration und Zustand? Ersetzt das Setup. |
| [03-incoming.php](03-incoming.php) | Wie trenne ich Prüfbedarf, B2B und Neukontakte? Ersetzt die Inbox-Regel. |
| [04-outgoing.php](04-outgoing.php) | Wie lege ich Kontakte schon beim Ausgang an? Ergänzung; zwei alternative Sent-Regeln. |
| [05-first-reply.php](05-first-reply.php) | Wie binde ich den Resolver ein und lerne Antwortaliase? Unabhängige Alternative. |
| [06-metadata.php](06-metadata.php) | Wie lese ich Kontakt/Aliase und pflege Anwendungsmetadaten? Vollständiger Attribut-Handler; trennt Kontakt, Thread, Nachricht und Mailbox. |
| [07-sender-rule.php](07-sender-rule.php) | Wie bearbeite ich Formularnachrichten? Ergänzt die Regeln aus 03. |
| [08-attributes.php](08-attributes.php) | Wie registriere oder pausiere ich Klassen-/Methodenregeln? Alternative Registrierung. |
| [09-custom-storage.php](09-custom-storage.php) | Wie tausche ich ID-Erzeugung oder Speicher aus? Alternative Konstruktoren. |
| [10-send-reply.php](10-send-reply.php) | Wie sende ich tatsächlich? Ersetzt Konstruktor und Formularregel. |
| [11-manual-flags.php](11-manual-flags.php) | Wie stößt ein Thunderbird-Tag die nächste Bearbeitung an? Ergänzt 03. |
| [12-contact-management.php](12-contact-management.php) | Wie ändere ich Kontaktname, Aliasnamen, Adressen und Hauptadresse? Eigenständige, bestätigte Verwaltungsaktion. |
| [13-contact-resolution.php](13-contact-resolution.php) | Woher kommt ContactResolution und wie behandle ich alle sechs Ergebnisse? Eigenständige Inbox-Alternative. |
| [14-typed-metadata.php](14-typed-metadata.php) | Wie definiere und verbinde ich eigene Metadatenmethoden? Alternative mit CustomerMetadata und typisiertem Handler. |

## Gemeinsamer Kontext

`$client` ist ein verbundener `Phore\MailClient\MailClient` aus dem Bootstrap
der aufrufenden Anwendung. Die Automation übernimmt genau dessen eine Verbindung,
Absenderadresse und Ordnerkonfiguration. Der aktuelle Client benötigt dafür noch
Sent-Konfiguration und einen öffentlichen Konfigurationszugriff; der Entwurf
behauptet keine bereits vorhandene Initialisierung dafür (siehe 02 und Vertrags-§ 2).
Zielordner müssen existieren und eigene IMAP-Keywords unterstützen.

01 erzeugt `$database` (PDO SQLite) und `$automation`. Spätere Ausschnitte nennen
ihre Einfügestelle und verwenden diese Namen weiter. Externe Dienste in 09/10 kommen
aus den jeweils benannten Anwendungs-Bootstraps; ihre Bibliotheksanbindung ist vollständig gezeigt.
Die Varianten setzen sich nicht gegenseitig automatisch voraus.

`Email` steht für `Phore\MailClient\Email`. Die übrigen Automation-Typen liegen
unter `Phore\MailClient\Automation`; `OnFolderAutomation`
unter dessen `Attributes`-Namespace. `PDO` ist der PHP-Standardtyp.
Callbacks bekommen `Email` und `MailContext` von der Engine, nicht aus selbst erzeugten Testobjekten.

## Betriebsverhalten beim Ausprobieren

Beim ersten Lauf werden alle unmarkierten Eingänge verarbeitet. Sent-Altbestand
wird zunächst indexiert und mit `phore_processed` abgeschlossen, ohne Ausgangsregeln; `run(processExistingOutgoing: true)` aktiviert
ausdrücklich auch seine Ausgangsregeln. Spätere Läufe verwenden denselben Speicher.
Eine fehlgeschlagene Verarbeitung bleibt offen, auch wenn der Synchronisationscursor weiterläuft.

`run()` liefert einen `RunReport` mit Zählern und strukturierten Fehlern.
**Offener API-Punkt:** Die konkreten öffentlichen Report-Felder und der Zugriff auf
einzelne Fehler sind noch nicht festgelegt. Deshalb zeigen die Ausschnitte keine
erfundenen Report-Methoden; dieser Vertrag muss vor ausführbaren Beispielen ergänzt werden.

Single Instance ist vereinbart; Absturzsicherung und Doppelverarbeitungsgarantien
sind nicht Teil dieses Entwurfs. Manuelle Migrationen, Identitätskonflikte und
Reprocessing-Grenzen stehen im Vertrags-§§ 4–6, ID-Fallbacks in § 7.

`phore_processed` sperrt sämtliche Automatisierungen einschließlich Sent-Regeln und
Identitätslernen. Lesen/Indexieren von Sent-Belegen bleibt möglich. Zum Wiederaufnehmen
zuerst verschieben und gewünschte Keywords setzen, dann `phore_processed` entfernen.
Keyword-Regeln prüfen den aktuellen Zustand (Beispiel 11); Kopien bleiben bei erhaltenem
Keyword gesperrt. `moveTo(..., reprocess: true)` gibt das Ziel erst für den nächsten Lauf frei.

## Handler abschließen oder weiterreichen

Jeder Handler liefert `MailActions` für die aktuelle Nachricht:

| Rückgabe | Wirkung |
|---|---|
| `MailActions::complete()` | Ohne weitere Mailaktion abschließen, phore_processed setzen, Kette beenden. |
| `MailActions::pass()` | Nächste passende Automatisierung im selben Lauf versuchen; noch kein Bearbeitet-Keyword. |
| `MailActions::create()->moveTo('B2B')` | Aktionsliste ausführen und abschließen. |

`matches=false` überspringt den Handler bereits vor dessen Aufruf. Beispiel 03 zeigt
die spätere Entscheidung im Handler mit `pass()`, Beispiel 08 dieselbe Rückgabe bei Attributregeln.
Vor `pass()` darf der Handler keine Änderungen vornehmen; sofort gespeicherte Metadaten
werden nicht zurückgerollt. Wenn alle Regeln ablehnen, markiert die Engine die Mail als
geprüft. Fehler stoppen die Kette und lassen Arbeit offen. Die bestehende
`reprocess: true`-Variante gibt das Ziel für den nächsten Lauf frei.

## Kontext und Metadaten

| Zugriff | Bedeutung |
|---|---|
| `$context->contactResolution` | Zuordnungsergebnis für diese Mail: Status, Kontakt und Belege; kein Benutzerkonto |
| `$context->contact` | Zugeordnete externe Person (Contact oder null), mit stabiler ID und Aliasadressen |
| `$context->thread` | Gespräch dieser Mail; Nachrichten und Thread-Metadaten |
| `$context->metadata` | Gespeicherte Zusatzwerte genau dieser Nachricht |
| `$context->mailbox` | Gemeinsame Dienste und Metadaten des angebundenen Kontos |
| `$context->mailbox->contacts` | ContactStore für globale Kontaktsuche/-verwaltung |
| `$context->mailbox->mailHistory` | Nachrichtenhistorie über mehrere Gespräche, etwa forContact(id) |

Kontakt, Thread und Mailbox besitzen ebenfalls `metadata`. Überall liefert
`get(key)` bei fehlendem Schlüssel null; `set(key, value)` speichert sofort.
Metadaten sind reine Anwendungsdaten: Die Engine interpretiert weder `reviewed` noch
andere Schlüssel. Nur eigene Filter/Individuallogik geben ihnen eine Bedeutung;
`phore_processed` bleibt unabhängig davon die Verarbeitungssperre.
Die Ebenen vererben oder kopieren keine Werte untereinander. Threads können mehrere
Kontakte enthalten; eine Thread-Zuordnung beweist keine Identität. Beispiel 06 zeigt
Registrierung, Zugriff und Ergebnis vollständig.

Bekannte, verifizierte Verschiebungen erhalten die Nachrichtenmetadaten.
Eine Kopie bekommt eigene, zunächst leere Nachrichtenmetadaten; ein sicher zugeordneter
Thread und Kontakt können geteilt bleiben. Fehlende/mehrdeutige Zuordnung nach manuellem
Verschieben oder Migration wird nicht anhand der Message-ID geraten (Vertrags-§ 7.3).

## Kontaktauflösung verstehen

`contactResolution` beantwortet, wie diese Nachricht einem Kontakt zugeordnet wurde.
`ReplyContactResolver` wird über `contactResolver:` eingebunden; ohne Angabe ist er Standard.
Er arbeitet vor dem Handler mit dem ContactStore, Antwortheadern und live geprüften Sent-Belegen.
`contact` liefert die Person, `contactResolution` erklärt Zuordnung und Prüfbedarf.

| Status | Herkunft | Was die Anwendung tun sollte |
|---|---|---|
| Unknown | Absender unbekannt, kein zulässiger Antwortbeleg | Erstkontaktbearbeitung; keinen Kontakt blind anlegen |
| KnownAddress | From ist ein gespeicherter Alias | Vorhandene Kontaktdaten für die Regeln verwenden |
| ContactCreated | Erste verifizierte Antwort hat Kontakt angelegt | Fachlich einordnen; keine zweite Anlage |
| AliasAdded | Verifizierte Antwort ergänzt bestehenden Kontakt | Denselben Kontakt weiterverwenden; Hauptadresse behalten |
| Conflict | Mehrdeutige Belege/Empfänger oder verschiedene Kontakte | Manuell prüfen; nicht automatisch zusammenführen |
| OutgoingMissing | Referenzierter Ausgang nach Live-Suche nicht in Sent | Beleg/Ordner prüfen; nach Klärung ausdrücklich erneut freigeben |

Bei den letzten beiden Fällen kann `contact` trotzdem gesetzt sein; zuerst
`needsReview()` beachten. Technische Zugriffsfehler sind Fehler im RunReport und kein
OutgoingMissing. Beispiel 13 zeigt alle Zweige, konkrete Ergebnisse und Wiederaufnahme.
Für Sent gilt Empfänger-Lookup: ein bekannter Einzeladressat ergibt KnownAddress,
ein unbekannter oder kein eindeutiger Einzeladressat Unknown; mehrere Empfänger stehen
in `recipientContacts` (Beispiel 04). Die vollständigen Feldregeln stehen in Vertrags-§ 6.1.

## Eigene Metadatentypen

Klassifizierung ist vollständig Anwendungslogik: `metadata->set('classification', 'b2b')`
oder eine eigene Methode auf `CustomerMetadata`. Contact hat kein eingebautes classify().
Beispiel 14 zeigt Definition, `contactMetadata: CustomerMetadata::class`, Handlerannotation
und Verwendung zusammen. Für Nachrichten, Threads und Mailboxen gelten entsprechend
`messageMetadata`, `threadMetadata` und `mailboxMetadata`; Standard bleibt MetadataBag.
PHPDoc-Typen unterstützen statische Analyse; die tatsächliche Klasse kommt aus der Konfiguration.
