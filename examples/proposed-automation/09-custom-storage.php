<?php
// Wie ersetze ich ID-Erzeugung oder Speicher ohne eigenes Standard-Setup?
// Jede Variante ersetzt NUR den Konstruktor aus 01; genau eine auswählen.
// $client und $database bleiben aus 01, danach dieselben Regeln und run() verwenden.

// Variante A: $ids ist der ContactIdGenerator aus dem Domain-Bootstrap der Anwendung.
// Er implementiert generate(?string $displayName, string $primaryEmail): string.
// Die Anwendung liefert diesen Dienst, die Bibliothek enthält keinen konkreten Custom-Generator.
$automation = new MailAutomation(client: $client, storage: $database, idGenerator: $ids);
// Ergebnis: SQLite-Stores bleiben Standard; nur neu angelegte Kontakte erhalten eigene IDs.
// Bereits gespeicherte IDs bleiben unverändert. Ohne Anpassung: anna-mueller-e<8 Zufallszeichen>.

// Variante B: ersetzt A; expliziter Speicherzugang, etwa um Kontakte außerhalb eines Handlers zu lesen.
$storage = new SqliteStorage($database);
$automation = new MailAutomation(client: $client, storage: $storage);
$contacts = $storage->contacts();
$anna = $contacts->findByEmail('anna@new.example');
// Ergebnis: derselbe persistierte Kontakt wie in 05 oder null, falls er noch nicht angelegt ist.

// Variante C: ersetzt A/B; $crmStorage ist AutomationStorage aus dem CRM-Bootstrap der Anwendung.
// Es liefert state(), contacts(), history(), threads() und metadata() über die Store-Interfaces.
$automation = new MailAutomation(client: $client, storage: $crmStorage);
// Ergebnis: dieselben Handler verwenden nun dessen Store-Implementierungen.
// Einen eigenen ID-Generator in dieser Variante am Store konfigurieren, nicht nochmals an Automation.
