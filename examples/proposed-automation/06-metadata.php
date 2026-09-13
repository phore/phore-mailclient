<?php
// Wie klassifiziere ich einen erkannten Benutzer und lese seine Historie?
// Ersetzt den erfolgreichen Benutzerzweig im Handler aus 05, nach dessen Review/null-Prüfung.
// $context stammt aus genau diesem Handler; Stores sind über ihn öffentlich zugänglich.
$user = $context->user;
$users = $context->users;
$history = $context->history;

// Änderungen werden sofort persistiert und sind auch im nächsten Nachrichtenkontext sichtbar.
$user->classify('b2b');
$user->setMetadata('customerNumber', 'C-1042');

// Erneutes Lesen über die stabile ID legt keinen Benutzer an.
$storedUser = $users->findById($user->id);
$messages = $history->forUser($user->id, limit: 20);
$matches = $users->search('Anna');
return MailActions::none();

// Für Annas Antwort aus 05: storedUser.id = user.id, classification = b2b,
// metadata.customerNumber = C-1042; matches enthält Anna.
// findByEmail/findById liefern bei fehlendem Treffer null; search liefert dann [].
// forUser liefert bis zu 20 MailHistoryEntry mit Richtung, Zeitpunkt und Referenzen,
// bei fehlender verknüpfter Historie []; vollständige Mailkörper sind nicht zugesichert.
// Die nächste Mail von Anna wird mit der Regel aus 03 nach B2B einsortiert.
