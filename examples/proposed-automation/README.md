# MailAutomation: Post sortieren und Kontakte wiedererkennen

**Entwurfsstatus:** Diese Reihe zeigt vorgeschlagene PHP-8.5-Anwendungsausschnitte.
Die Automation-Typen sind noch nicht implementiert. Die Dateien sind keine ausführbaren
Skripte und werden nicht nacheinander eingebunden; Jede PHP-Datei beginnt mit `<?php`; Imports und weiterer Dateirahmen sind
bewusst ausgelassen. Der [API-Vertrag](../../proposals/2026-09-13-mail-automation.md)
beschreibt die geplante Implementierung und ihre Grenzen.

Beginne mit [01: Post sortieren](01-overview.php). Der abgeschlossene Standardfall
verbindet den Speicher, registriert eine Inbox-Regel und verarbeitet die Post.
`MailAutomation` verwaltet Synchronisation und Zustand, `MailContext` liefert
Benutzerwissen, `MailActions` beschreibt die beabsichtigten Mailänderungen.
Die weiteren Dateien beantworten jeweils eine zusätzliche Frage.

| Datei | Leserfrage / Einbindung |
|---|---|
| [01-overview.php](01-overview.php) | Wie sortiere ich Post mit der Basiskonfiguration? Vollständiger Einstieg. |
| [02-setup.php](02-setup.php) | Wo liegen Verbindung, Ordnerkonfiguration und Zustand? Ersetzt das Setup. |
| [03-incoming.php](03-incoming.php) | Wie trenne ich Prüfbedarf, B2B und Neukontakte? Ersetzt die Inbox-Regel. |
| [04-outgoing.php](04-outgoing.php) | Wie lege ich Benutzer schon beim Ausgang an? Ergänzung; zwei alternative Sent-Regeln. |
| [05-first-reply.php](05-first-reply.php) | Wie binde ich den Resolver ein und lerne Antwortaliase? Unabhängige Alternative. |
| [06-metadata.php](06-metadata.php) | Wie pflege ich Klassifizierung und lese Historie? Ersetzt einen Handlerzweig aus 05. |
| [07-sender-rule.php](07-sender-rule.php) | Wie bearbeite ich Formularnachrichten? Ergänzt die Regeln aus 03. |
| [08-attributes.php](08-attributes.php) | Wie registriere oder pausiere ich Klassen-/Methodenregeln? Alternative Registrierung. |
| [09-custom-storage.php](09-custom-storage.php) | Wie tausche ich ID-Erzeugung oder Speicher aus? Alternative Konstruktoren. |
| [10-send-reply.php](10-send-reply.php) | Wie sende ich tatsächlich? Ersetzt Konstruktor und Formularregel. |
| [11-manual-flags.php](11-manual-flags.php) | Wie stößt ein Thunderbird-Tag die nächste Bearbeitung an? Ergänzt 03. |

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
