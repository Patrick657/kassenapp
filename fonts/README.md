# Schriften für die PDFs

Statische TrueType-Dateien (normal + fett) von Google Fonts, auswählbar unter
Verwaltung → Einstellungen → PDF-Gestaltung. Alle stehen unter offenen Lizenzen
(SIL Open Font License bzw. Apache 2.0), die das Einbetten in PDFs erlauben.

Weitere Schrift hinzufügen:
1. `https://fonts.googleapis.com/css2?family=<Name>:wght@400;700` mit einem alten User-Agent
   abrufen (z. B. `curl -A "Mozilla/4.0" ...`) — die Antwort enthält die .ttf-Links.
2. Als `<Schlüssel>-Regular.ttf` und `<Schlüssel>-Bold.ttf` hier ablegen.
3. Schlüssel und Anzeigenamen in `TtfFont::AVAILABLE` (src/TtfFont.php) ergänzen.

Nur statische Schriften verwenden, keine variablen Fonts.
