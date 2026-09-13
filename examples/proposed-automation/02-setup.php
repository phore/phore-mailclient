<?php
// Welche Verbindung und welchen Speicher verwendet die Automatisierung?
// Ersetzt bei anderem Speicherort nur das Setup aus 01; danach folgen Regeln und run().
// Derselbe $client bleibt für genau ein Konto zuständig.
$database = new PDO('sqlite:/var/lib/app/customer-mail.sqlite');
$automation = new MailAutomation(client: $client, storage: $database);

// Ergebnis: neuer Speicherort, dieselbe Verbindung und dieselben Ordner wie im Client.
// Für Folgeläufe denselben Pfad verwenden, damit Cursor, Benutzer und Historie erhalten bleiben.
// Folder::Inbox, Sent, Drafts, Trash und Junk werden auf Client-Ordner abgebildet;
// ein String wie 'Customers' bezeichnet den exakten Ordnernamen in diesem Konto.
//
// Voraussetzung des Entwurfs: Der Client muss seine Konfiguration lesbar bereitstellen.
// Im aktuellen MailClient fehlen dafür noch Sent-Konfiguration und Konfigurationszugriff.
// Diese Lücke ist vor der Implementierung zu schließen; hier wird keine neue Client-API erfunden.
// Fehlender Absender, ungültige Ordnerzuordnung oder fremder Konto-State führen zu Fehlern.
// Alternative Speicher-/ID-Implementierungen: 09-custom-storage.php.
