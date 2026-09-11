# Arbeitsanweisungen

## Mailbox-Konfigurationsreferenz

Wenn Felder, Typen, Pflichtangaben, Standardwerte, erlaubte Werte, Validierung oder die Passwort-/Secret-Auflösung der Mailbox-Konfiguration hinzugefügt, geändert oder entfernt werden, muss `mailbox-config.reference.json` im selben Änderungsumfang und Commit manuell angepasst werden: Alle unterstützten Felder sind mit aktueller Beschreibung und Beispiel sowie gegebenenfalls Standardwerten und Einschränkungen zu dokumentieren; entfernte Felder sind aus der Referenz zu entfernen. Der Link zur Referenz in `.ai-usage-info.md` muss erhalten und gültig bleiben, und betroffene Nutzungshinweise in README und Beispielen sind mit anzupassen. Die Referenz wird direkt gepflegt; dafür keine Generatoren, Composer-Hooks oder automatische Synchronisation einführen.
