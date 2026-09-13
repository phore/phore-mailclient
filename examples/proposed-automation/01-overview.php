<?php
// Neue Post nach Kundentyp ablegen
// $client: verbundener MailClient aus dem Bootstrap der Anwendung.
// Er liefert Absender und Ordnernamen; B2B und Review existieren im selben Konto.
// Das Verzeichnis /var/lib/app existiert, PDO SQLite ist installiert.
$database = new PDO('sqlite:/var/lib/app/mail.sqlite');
$automation = new MailAutomation(client: $client, storage: $database);
// SQLite-Tabellen für Cursor, Kontakt/Aliase und Historie entstehen automatisch.

// Folder::Inbox verwendet den im Client festgelegten Eingangsordner.
// Registrierung führt noch nichts aus. matches prüft, handle beschreibt Mailaktionen.
// MailContext enthält bereits contact (Contact|null) und das Ergebnis der Identitätsprüfung.
$automation->onFolder(Folder::Inbox)->addAutomation(
    matches: fn (Email $mail, MailContext $context): bool => true,
    handle: function (Email $mail, MailContext $context): MailActions {
        // Fehlende/widersprüchliche Antwortbelege gehören zur manuellen Prüfung.
        if (!$context->identity->needsReview() && $context->contact?->classification === 'b2b') {
            return MailActions::create()->moveTo('B2B');
        }
        return MailActions::create()->addFlag('phore_review')->moveTo('Review');
    },
);

// Erst hier: Sent indexieren, Identität auflösen, Eingänge verarbeiten und Cursor speichern.
// phore_processed sperrt vorab ALLE Regeln und das Aliaslernen, auch in Sent.
// Erfolg setzt dieses Keyword; Verschieben/Kopieren mit erhaltenem Keyword bleibt gesperrt.
$report = $automation->run();

// Beispieldaten → Ergebnis:
// Anna ist als B2B-Kundin gespeichert; ihre neue Mail liegt anschließend in B2B.
// Unbekannter Absender ohne Antwortbezug → Review + phore_review, kein neuer Kontakt.
// Standard: Kontaktanlage erst bei verifizierter Antwort auf eine noch vorhandene Sent-Mail.
