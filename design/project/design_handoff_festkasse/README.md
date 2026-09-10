# Handoff: Festkasse — Kassen-App mit kleiner Warenwirtschaft

## Overview
Touch-optimierte Kasse (POS) für ein Fest mit Speisen & Getränken, plus kleines
Warenwirtschaftssystem (Artikelpflege, Bestand, Warenzugang) und Admin-Dashboard
(Einnahmen, Kassenbewegungen, Verkäufe pro Artikel). Zielplattform: eigener Webserver,
**PHP 8.5**, **MySQL 8**, Bedienung im Browser auf einem **Tablet im Querformat**.

Alle Änderungen (Artikelpflege, Kassenbewegungen, Einstellungen, Tagesabschluss) sind
durch einen **Zugangscode** geschützt. Eine erfolgreiche Code-Eingabe schaltet für
**genau 2 Stunden** frei; danach sperrt die App automatisch und der Code muss erneut
eingegeben werden.

## About the Design Files
Die Datei `Kassen-App.dc.html` in diesem Bundle ist eine **Design-Referenz in HTML** —
ein lauffähiger Prototyp, der Aussehen und Verhalten zeigt. Sie ist **kein Produktionscode
zum Kopieren**: Zustand liegt dort in React-State und `localStorage`, es gibt kein Backend.

Aufgabe: die gezeigten Screens **im Zielstack neu umsetzen** — PHP 8.5 (Backend + JSON-API),
MySQL (Persistenz), Frontend als schlanke SPA/Server-gerenderte Seiten. Kein Build-Tool nötig,
wenn nicht gewünscht: Vanilla JS + `fetch` gegen die API reicht für diesen Umfang. Wenn im
Zielprojekt bereits ein Framework existiert (Laravel, Slim, Symfony), dessen Muster verwenden.

Der Prototyp öffnet sich in jedem Browser — zum Abgleich von Layout, Farben, Abständen und
Interaktionen während der Umsetzung offen halten.

## Fidelity
**High-fidelity.** Farben, Typografie, Abstände, Radien, Hover-/Active-States und Interaktionen
sind final gemeint. Pixelgenaue Umsetzung anhand der Werte unten und des Prototyps.

---

## Screens / Views

### 1. Kopfzeile (global)
- Höhe **62 px**, Hintergrund `#14171a`, Text `#fff`, horizontales Padding **18 px**, `display:flex; gap:18px; align-items:center`.
- Links: Kassenname (17 px / 800 / `letter-spacing:-.02em`) + Label "POS + WAWI" (11 px / 600 / `letter-spacing:.09em` / uppercase / `rgba(255,255,255,.42)`).
- Umschalter "Kasse | Verwaltung": Container `background:rgba(255,255,255,.09); border-radius:11px; padding:3px`; Buttons `padding:9px 18px; border-radius:9px; font:700 13.5px`; aktiv `background:#fff; color:#14171a`, inaktiv transparent mit `rgba(255,255,255,.6)`.
- Rechts (`margin-left:auto`, `gap:22px`): Kennzahl "Kassenbestand" und "Umsatz heute" — Label 10 px/600/uppercase/`rgba(255,255,255,.42)`, Wert 16 px/600 Mono (Umsatz heute in `#5ee0a8`); danach — nur wenn freigeschaltet — Button "Sperren · <Restzeit>" (`padding:9px 13px; border-radius:9px; background:rgba(255,255,255,.12)`; Hover `rgba(255,255,255,.2)`); zuletzt die Uhrzeit (13 px Mono, `rgba(255,255,255,.55)`).

### 2. Kasse (POS)
Zwei Spalten, `gap:14px`, `padding:14px`, füllt die restliche Höhe (`overflow:hidden`).

**Links — Artikelauswahl**
- Kategorie-Chips in einer Zeile (`gap:8px`, horizontal scrollbar): `padding:10px 18px; border-radius:99px; font:700 13.5px`; aktiv `#14171a`/weiß, inaktiv `#fff`/`rgba(0,0,0,.6)`. Erste Chip = "Alle".
- Artikelkacheln: `display:grid; grid-template-columns:repeat(auto-fill,minmax(158px,1fr)); gap:10px`. Kachel: `min-height:104px; padding:13px; border-radius:14px; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.06)`, Spalten-Flex mit `gap:10px`, `text-align:left`, `transition:transform .08s`, `:active{transform:scale(.97)}`.
  - Oben: Artikelname 15 px/700/`line-height:1.2`; rechts optionales Bestandsbadge (10.5 px/600 Mono, `padding:3px 6px; border-radius:6px`) — normal `#f4f5f3`/`rgba(0,0,0,.45)`, unter Meldebestand `#fff1e9`/`#c2410c`, ausverkauft Text "leer" `#fee2e2`/`#b91c1c`.
  - Unten: Preis 17 px/600 Mono, rechts Kategorie 11 px/600/`rgba(0,0,0,.35)`.
  - Ausverkauft: Kachel `opacity:.42` und Tap zeigt Toast "<Artikel> ist ausverkauft" statt hinzuzufügen.
  - Bestandsbadge und Ausverkauft-Sperre entfallen vollständig, wenn "Lagerbestand führen" aus ist.

**Rechts — Warenkorb (Breite 430 px)**
Karte `background:#fff; border-radius:16px; box-shadow:0 1px 2px rgba(0,0,0,.06),0 12px 30px -18px rgba(0,0,0,.25); overflow:hidden`, vertikaler Flex mit drei Bereichen:
1. Kopf: "WARENKORB · <Stückzahl>" (13 px/700/uppercase/`letter-spacing:.06em`/`rgba(0,0,0,.5)`), rechts Textbutton "Leeren" (12 px/600/`#c2410c`, Hover `background:#fff1e9`).
2. Positionsliste — **scrollbar** (`flex:1 1 110px; min-height:64px; overflow-y:auto`). Leerzustand: zentriert Bon-Symbol 26 px + "Artikel antippen zum Hinzufügen" (13 px/600/`rgba(0,0,0,.3)`).
   - Zeile: `padding:10px 11px; border-radius:11px`; Menge "2×" (13 px/600 Mono, `min-width:30px`), Name (14 px/600, `flex:1`), Einzelpreis (11 px Mono, `rgba(0,0,0,.4)`), Zeilensumme (15 px/600 Mono, rechts, `min-width:66px`).
   - Tap auf Zeile = auswählen: `background:#f4f5f3; box-shadow:inset 0 0 0 1.5px #14171a`, darunter Aktionsleiste (`gap:6px; margin-top:9px`, alle `padding:9px; border-radius:9px; background:#eceeea`): "−" (16 px/700), "+" (16 px/700), "Preis ändern" (12 px/700, doppelte Breite), "Storno" (`#fee2e2`/`#b91c1c`).
3. Summen-/Zahlblock — **scrollbar** (`flex:0 1 auto; overflow-y:auto; border-top:1px solid rgba(0,0,0,.08); padding:12px 16px 4px; gap:9px`):
   - "Zwischensumme" 13 px/`rgba(0,0,0,.55)`, Wert Mono.
   - Rabatt-Chips "kein Rabatt / −0,50€ / −1,00€ / −2,00€" (`padding:7px 10px; border-radius:8px; font:700 11.5px`; aktiv `#14171a`/weiß, sonst `#eceeea`); rechts der aktive Rabatt als "−0,50 €" in `#c2410c`.
   - "Zu zahlen" 15 px/700, Betrag **30 px/600 Mono**, `letter-spacing:-.02em`.
   - Bar/Karte-Umschalter (nur wenn Kartenzahlung aktiviert): Container `background:#eceeea; padding:3px; border-radius:11px`; aktiver Button `background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.14)`.
   - **Nur bei Bar**: Scheine-Grid 5 Spalten (5/10/20/50/100 €) — `padding:12px 2px; border-radius:10px; background:#14171a; color:#fff; font:600 13px Mono`; Münzen-Grid 6 Spalten (5/10/20/50 ct, 1/2 €) — `background:#fff; border:1.5px solid rgba(0,0,0,.14); font:600 12px Mono`. Jeder Tap **addiert** auf den gegebenen Betrag. Darunter "Passend" (setzt gegeben = Summe) und "Zurücksetzen" (`#eceeea`, 12 px/700).
   - Zwei Anzeigen: "GEGEBEN" (`background:#f4f5f3; border-radius:11px; padding:9px 11px`, Wert 19 px/600 Mono) und "RÜCKGELD" (`flex:1.3`) — bei ausreichendem Betrag `background:<Akzent>; color:#fff`, sonst `#f4f5f3`/`rgba(0,0,0,.4)`. Unter dem Rückgeld eine Stückelungszeile (10.5 px/600, `opacity:.7`): "2×2 € · 1×50 ct" (max. 4 Stückelungen, größte zuerst) bzw. "fehlen 3,50 €" bzw. "passend".
4. Fixierter Footer (`flex:none; padding:8px 16px 14px; background:#fff; box-shadow:0 -6px 14px -12px rgba(0,0,0,.5)`): Hauptbutton `width:100%; padding:15px; border-radius:13px; font:800 16px`. Beschriftung: "Kassieren · 12,50 €", bei ausreichendem Bargeld "Kassieren · Rückgeld 2,50 €", bei Karte "Kartenzahlung abschließen · 12,50 €". Zahlbereit = `background:<Akzent>`, sonst `#14171a` (leerer Korb: `opacity:.35`).
   **Wichtig:** Dieser Footer muss auf kleinen Viewports (Tablet-Höhe 540–800 px) immer sichtbar bleiben — Scrollen passiert in Bereich 2 und 3.

### 3. Bon-Ansicht (Modal nach Kassieren)
Overlay `rgba(20,23,26,.6)` + `backdrop-filter:blur(3px)`; Karte 360 px breit, `border-radius:16px; padding:24px`, Einblendung `translateY(14px)→0`, 180 ms.
Kopf: Kassenname 16 px/800/uppercase/`letter-spacing:.04em`, darunter "10.09.2026, 18:42 · Bon A3F9KD2" (11.5 px/`rgba(0,0,0,.5)`). Trenner **gestrichelt** `1px dashed rgba(0,0,0,.2)`.
Positionen: "2×" (Mono, `min-width:26px`), Name, Summe (Mono) — 13 px.
Summenblock: Summe / optional Rabatt / **Zu zahlen** (17 px/800) / "Bar gegeben" bzw. "Kartenzahlung" / "Rückgeld" (14 px/700).
Fuß: "Vielen Dank für Ihren Einkauf" 11 px/`rgba(0,0,0,.4)`; Button "Weiter" `#14171a`, `padding:13px; border-radius:11px`. Klick auf Overlay schließt ebenfalls.

### 4. Verwaltung (nur mit gültigem Code)
Tab-Leiste auf `#eceeea`, `padding:12px 16px 0; gap:4px`; Tab `padding:11px 17px; border-radius:11px 11px 0 0; font:700 13.5px`; aktiv `background:#fff`, inaktiv `rgba(0,0,0,.45)`.
Tabs: **Übersicht · Artikel · Bestand · Journal · Kasse · Einstellungen** ("Bestand" fehlt, wenn Lagerbestand deaktiviert). Inhalt `padding:16px`, scrollbar.

**4a. Übersicht**
- KPI-Karten `grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px`; Karte `#fff; border-radius:14px; padding:15px 17px; box-shadow:0 1px 2px rgba(0,0,0,.05)`; Label 10.5 px/700/uppercase/`rgba(0,0,0,.42)`, Wert **26 px/600 Mono**, Unterzeile 11.5 px/600.
  KPIs: "Umsatz heute" (+ "<n> Bons"), "Umsatz gesamt" (kompakt, z. B. "1,2k €"), "Rohertrag gesamt" ("VK − EK", Akzentfarbe), "Artikel unter Meldebestand" (`#c2410c` wenn > 0, sonst Akzent) — bei deaktiviertem Lagerbestand stattdessen "Ø Bon" mit "Top: <Artikel>".
- Zwei Charts nebeneinander (`repeat(auto-fit,minmax(340px,1fr))`), Karte wie oben mit `padding:16px 18px`, Titel 13 px/700 + Unterzeile 11.5 px/`rgba(0,0,0,.45)`:
  - **Einnahmen pro Tag** (letzte 7 Tage): Balkenreihe `height:160px; gap:9px; align-items:flex-end`; Balken `border-radius:6px 6px 3px 3px`, Höhe = `Wert/Max*118px` (min 3 px), heutiger Tag in Akzentfarbe, übrige `#14171a`; über dem Balken der Wert (10.5 px/600 Mono), darunter Wochentag kurz ("Mo").
  - **Stundenverteilung** (9–23 Uhr, alle Tage): `gap:4px`, Balken `border-radius:4px`, Höhe = `Wert/Max*128px` (min 2 px), Maximum in Akzentfarbe, sonst `rgba(20,23,26,.75)`; Label = Stundenzahl 9.5 px.
- **Umsatz pro Artikel** (Top 7 nach Umsatz): pro Zeile Name (12.5 px/600) + "12× · 42,00 €" (Mono, `rgba(0,0,0,.55)`), darunter Balken `height:7px; border-radius:99px; background:#eceeea`, Füllung `#14171a`, Breite = `Umsatz/Max`.
- **Zahlungsarten**: je Zeile Label 13 px/700 + Summe 14 px/600 Mono + Prozent 12 px/`rgba(0,0,0,.4)`; Balken `height:11px` (Bar `#14171a`, Karte Akzent); Unterzeile "<n> Bons"; abschließend "Ø Bon" über `border-top:1px solid rgba(0,0,0,.08)`.

**4b. Artikel**
Tabelle in weißer Karte (max. 1300 px). Kopf: Titel "Artikel" 14 px/700 + "<n> Artikel · Marge über Einkaufspreis"; rechts Button "+ Neuer Artikel" (`#14171a`, `padding:11px 16px; border-radius:10px; font:700 13px`).
Spalten (`grid-template-columns:2.2fr 1fr .9fr .9fr .9fr .8fr .9fr 1fr; gap:10px`): Artikel · Kategorie · VK · EK · Marge · Bestand · Verkauft · Aktionen. Kopfzeile `background:#f4f5f3`, 10.5 px/700/uppercase; Datenzeilen `padding:11px 18px; border-bottom:1px solid rgba(0,0,0,.05)`, Hover `#fafbf9`; Zahlen rechtsbündig Mono; Marge in `#0f7a5f`/600 als Prozent `(VK−EK)/VK`; Bestand `#b91c1c` bei 0, `#c2410c` unter Meldebestand, sonst `#14171a` (bei deaktiviertem Lagerbestand "–" in `rgba(0,0,0,.3)`). Aktionen: "Bearbeiten" (`#eceeea`, 12 px/700) und "✕" (`#b91c1c`, Hover `#fee2e2`).

**4c. Bestand & Warenzugang**
- Warnbanner wenn Artikel ≤ Meldebestand: `background:#fff7ed; border:1px solid #fdba74; border-radius:13px; padding:13px 16px`, Titel "Niedriger Bestand" 13 px/700/`#9a3412`, darunter Liste "Bier 0,5 l (12 / 40) · …".
- Karte "Bestand & Warenzugang" mit Unterzeile "Lagerwert (EK): <Summe Bestand×EK>"; Zeilen `grid-template-columns:2fr 1fr 1fr 1.1fr 1.4fr`: Name (13.5 px/600) + "Meldebestand <n>", Bestand (17 px/600 Mono, Statusfarbe), Status-Text ("ausverkauft" / "nachbestellen" / "ausreichend"), Fortschrittsbalken `height:8px` (Breite = `Bestand/(Meldebestand×3)`, min 3 %), rechts Button "Zugang buchen" (`#14171a`).

**4d. Journal**
Karte (max. 1000 px), Kopf "Kassenbewegungen" + "<n> Einträge · Bon antippen für Belegansicht". Zeilen `grid-template-columns:88px 1fr 1.4fr 120px`: Zeit "10.09., 18:42" (12 px Mono), Typ-Badge (11 px/700, `padding:4px 9px; border-radius:7px`) — Verkauf `#e8f5f0`/`#0f7a5f`, Einlage `#eef2ff`/`#3730a3`, Entnahme `#fff1e9`/`#c2410c`, Z-Abschluss `#14171a`/weiß, Warenzugang `#f4f5f3`/`rgba(0,0,0,.6)` — Notiz (13 px), Betrag rechts (14 px/600 Mono; negativ `#c2410c`, Warenzugang "–" in `rgba(0,0,0,.3)`). Verkaufszeilen sind klickbar → Bon-Ansicht. Absteigend nach Zeit, im Prototyp auf 80 Einträge begrenzt (im Backend paginieren).

**4e. Kasse**
Zwei Karten (`repeat(auto-fit,minmax(330px,1fr))`):
- "KASSENBESTAND JETZT" (Label 10.5 px/700/uppercase), Betrag **40 px/600 Mono**, `letter-spacing:-.03em`; darunter "Barumsatz seit Abschluss: <Summe>"; Buttons "Einlage" (`#0f7a5f`/weiß) und "Entnahme" (`#eceeea`), darunter über die volle Breite "Tagesabschluss (Z-Bon)" (`#14171a`), alle `padding:13px; border-radius:11px; font:700 13px`.
- "Abschlüsse": Liste `border:1px solid rgba(0,0,0,.08); border-radius:11px; padding:11px 13px` mit "Z-3 · 08.09.2026" + Summe (Mono) und Detailzeile "27 Bons · Bar 214,50 € · Karte 88,00 €". Leerzustand: "Noch kein Tagesabschluss gebucht."

**4f. Einstellungen** (max. 720 px)
Karte mit Kopf "Einstellungen · gelten sofort für Kasse und Verwaltung", dann Zeilen `padding:15px 18px; border-bottom:1px solid rgba(0,0,0,.05)`:
- **Kassenname** — Textfeld 220 px (`padding:10px 12px; border-radius:10px; border:1.5px solid rgba(0,0,0,.14)`, Fokus `border-color:#14171a`). Erscheint in Kopfzeile und auf dem Bon.
- Toggle-Zeilen (Titel 13.5 px/600 + Hinweis 11.5 px/`rgba(0,0,0,.45)`, ganze Zeile klickbar, Hover `#fafbf9`). Switch: `width:48px; height:28px; border-radius:99px; padding:3px`, an = Akzentfarbe, aus = `rgba(0,0,0,.16)`; Knopf 22 px weiß, `margin-left` 0 → 20 px, `transition .15s`.
  1. **Lagerbestand führen** — "Bestände mitzählen, Ausverkauft sperren, Warenzugang buchen". Aus ⇒ keine Bestandsbadges, keine Ausverkauft-Sperre, kein Bestand-Tab, keine Bestandsfelder im Artikelformular, KPI-Tausch (siehe 4a). Bestandswerte bleiben in der DB erhalten und werden bei Reaktivierung weiterverwendet.
  2. **Warnung bei niedrigem Bestand** — nur sichtbar, wenn (1) an.
  3. **Kartenzahlung anbieten** — aus ⇒ Bar/Karte-Umschalter verschwindet, Zahlart immer Bar.
  4. **Änderungen mit Code schützen** — "Code 1234 · Freigabe gilt 2 Stunden, danach automatisch gesperrt".
- Zweite Karte "Daten" mit Zusammenfassung "<n> Bons · <n> Bewegungen · <n> Artikel gespeichert" und Buttons "Verkäufe & Journal löschen" (`#eceeea`) sowie "Alles zurücksetzen (Demo neu)" (`#fee2e2`/`#b91c1c`). Im Produktivsystem: nur mit gültigem Code, mit Bestätigungsdialog, und als Journal-Eintrag protokollieren.

### 5. Code-Dialog (Zugangsschutz)
Overlay `rgba(20,23,26,.72)` + Blur; Karte 320 px, `border-radius:18px; padding:22px`, Einblendung `scale(.96)→1`, 160 ms.
Titel "Verwaltung entsperren" 15 px/700 zentriert; Unterzeile "Code eingeben · gilt 2 Stunden".
Vier Punkte (13 px, `gap:9px`): gefüllt `#14171a`, leer `rgba(0,0,0,.13)`. Fehlerzeile fester Höhe 16 px, 12 px/600/`#b91c1c` ("Falscher Code").
Ziffernblock 3×4 (`gap:8px`): Ziffern `background:#f4f5f3; padding:16px 0; border-radius:12px; font:700 19px`, "Abbr." und "⌫" transparent mit `rgba(0,0,0,.45)`, `:active{transform:scale(.95)}`.
Vierte Ziffer löst sofort die Prüfung aus (kein OK-Button).

---

## Interactions & Behavior
- **Artikel-Tap** → Position anlegen bzw. Menge +1. Bei aktivem Lagerbestand: > Bestand ⇒ Toast "Nur noch <n>× auf Lager"; Bestand 0 ⇒ Toast "<Artikel> ist ausverkauft".
- **Positions-Tap** → Auswahl umschalten, Aktionsleiste ein/aus. "Preis ändern" öffnet das Formular-Modal (nur diese Position, kein Artikelpreis).
- Jede Korb-/Rabattänderung **setzt "gegeben" auf 0 zurück** (verhindert falsches Rückgeld).
- **Kassieren**: leerer Korb ⇒ Toast "Warenkorb ist leer"; bar und zu wenig ⇒ Toast "Betrag noch nicht ausreichend". Sonst: Verkauf + Journaleintrag schreiben, Bestände abbuchen, Korb/Rabatt/Gegeben leeren, Bon-Modal öffnen; danach ggf. Toast "Bestand niedrig: …" (300 ms verzögert).
- **Toast**: unten zentriert, `#14171a`, `padding:12px 20px; border-radius:12px; font:600 13px`, 2200 ms sichtbar, Einblendung wie Modals.
- **Tagesabschluss**: alle Verkäufe seit dem letzten Abschluss zusammenfassen (Anzahl, Bar, Karte), Z-Bericht mit laufender Nummer speichern, den kompletten Barbestand als `close`-Bewegung entnehmen (Kassenbestand danach 0). Ohne neue Verkäufe ⇒ Toast "Seit dem letzten Abschluss keine Verkäufe".
- **Entnahme** > Kassenbestand ⇒ Formularfehler "Mehr als der Kassenbestand".
- Alle Formular-Modals: Fehlerzeile in `#b91c1c` fester Höhe; Buttons "Abbrechen" (`#eceeea`) / Primär (`#14171a`, `flex:1.4`).
- Beträge werden **deutsch** eingegeben und angezeigt: Komma als Dezimaltrenner, "12,50 €". Eingaben mit Punkt oder Komma akzeptieren.
- Responsiv: Kasse ist für 1024×768 und größer gebaut; die Warenkorbspalte bleibt 430 px, die Kachelspalte reflowt. Der Kassieren-Button ist immer sichtbar.

## Zugangsschutz (Kernanforderung)
- Genau **ein Zugangscode** (4-stellig, im Prototyp `1234`). Produktiv: Code **nicht im Frontend**, sondern serverseitig als `password_hash()` in `settings` bzw. `.env`.
- Erfolgreiche Eingabe erzeugt eine Freigabe mit **TTL = 2 Stunden** (`7200 s`). Der Ablaufzeitpunkt wird serverseitig gespeichert; das Frontend zeigt die Restzeit im "Sperren"-Button ("Sperren · 1 h 48 min").
- **Nach Ablauf**: Verwaltung wird automatisch geschlossen, Ansicht springt zurück auf die Kasse, Toast "Code abgelaufen · Verwaltung gesperrt". Frontend prüft alle 10 s; der Server lehnt abgelaufene Tokens unabhängig davon ab (`401`).
- **Sperren**-Button beendet die Freigabe sofort.
- **Keine Verlängerung durch Aktivität** — die 2 Stunden laufen ab der Code-Eingabe (Fixed Window), so gewünscht.
- Ohne gültige Freigabe ist die Kasse voll bedienbar (Verkäufe buchen), aber **jede Änderung** — Artikel anlegen/ändern/löschen, Warenzugang, Einlage/Entnahme, Tagesabschluss, Einstellungen, Datenlöschung — ist gesperrt: das Frontend zeigt den Code-Dialog, das Backend antwortet `401` (Frontend-Prüfung ist nur Komfort).
- Rate-Limit gegen Durchprobieren: max. 5 Fehlversuche pro 15 min pro IP, danach `429`; Fehlversuche protokollieren.
- Wenn "Änderungen mit Code schützen" deaktiviert ist, entfällt die Prüfung — dieses Umschalten selbst erfordert eine gültige Freigabe.

### Zweites Kennwort für Löschvorgänge
Löschen ist doppelt geschützt: gültige Code-Freigabe **plus** ein separates **Löschkennwort**
(im Prototyp `9999`), das bei **jedem** Löschvorgang neu eingegeben wird — es wird nicht
zwischengespeichert und läuft nicht mit der 2-Stunden-Freigabe mit.
Betroffen: Artikel löschen, "Verkäufe & Journal löschen", "Alles zurücksetzen".

Dialog: normales Formular-Modal mit Titel ("Artikel löschen · Bier 0,5 l"), Hinweiszeile
(12.5 px / `rgba(0,0,0,.5)` / `line-height:1.45`, nennt die Folgen und die Anzahl betroffener
Datensätze), einem Feld "LÖSCHKENNWORT" (Platzhalter `••••`) und den Buttons
"Abbrechen" / "Endgültig löschen". Falsche Eingabe ⇒ Fehlerzeile "Falsches Löschkennwort",
Dialog bleibt offen, es passiert nichts.

Backend: eigener Hash `delete_code_hash` in `settings`; jeder Löschendpunkt
(`DELETE /api/products/{id}`, `POST /api/maintenance/purge`, `POST /api/maintenance/reset`)
verlangt das Kennwort **im Request-Body** und prüft es mit `password_verify` bei jedem Aufruf
— niemals als Session-Flag merken. Gleiches Rate-Limit wie beim Zugangscode (5 Versuche /
15 min / IP, dann `429`). Jeder erfolgreiche Löschvorgang wird protokolliert (wer/wann/was,
z. B. Tabelle `audit_log`). Zugangscode und Löschkennwort müssen unterschiedlich sein.
Empfehlung fürs Produktivsystem: Artikel nur `archived_at` setzen (Soft Delete) und
"Alles zurücksetzen" dort ganz weglassen.

## State Management
Client-State (flüchtig): `view` (pos|admin), `adminTab`, `cat` (Kategoriefilter), `cart[]` (`{lineKey, productId, name, price, qty}`), `selectedLine`, `payment` (cash|card), `tender` (gegeben), `discount`, `receipt` (offener Bon), `form` (offenes Modal + Felder), `formError`, `toast`, `clock`, `unlockedUntil` (ms-Timestamp).
Server-State: Artikel, Verkäufe + Positionen, Kassenbewegungen, Z-Berichte, Einstellungen, Freigabe-Token.
Ableitungen (Prototyp im Client, produktiv besser in SQL): Kassenbestand = Summe aller Bewegungen; Umsatz heute; 7-Tage-Reihe; Stundenverteilung; Artikel-Ranking; Zahlungsart-Split; Ø Bon; Rohertrag = Σ Menge×(VK−EK); Lagerwert = Σ Bestand×EK.
Wichtig: Verkaufspositionen speichern den **historischen** Preis (`unit_price`) — Preisänderungen dürfen alte Bons nicht verändern.

---

## Zielarchitektur (PHP 8.5 / MySQL)

### Aufbau
```
public/            index.php (Bootstrap), app.js, styles.css   ← nur dieses Verzeichnis ist per Web erreichbar
src/               Db.php, Auth.php, Repo/*.php, Api.php
config/            config.php (aus .env), migrations/
```
API unter `/api/*`, JSON in/out, PHP-Seite: `declare(strict_types=1)`, PDO mit `ERRMODE_EXCEPTION`, prepared statements ausschließlich, `Content-Type: application/json; charset=utf-8`.

### Datenmodell (MySQL 8, utf8mb4_general_ci, InnoDB)
```sql
CREATE TABLE products (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120)   NOT NULL,
  category      VARCHAR(60)    NOT NULL DEFAULT 'Speisen',
  price_cents   INT UNSIGNED   NOT NULL,
  cost_cents    INT UNSIGNED   NOT NULL DEFAULT 0,
  stock         INT            NOT NULL DEFAULT 0,
  stock_min     INT            NOT NULL DEFAULT 0,
  sort_order    INT            NOT NULL DEFAULT 0,
  archived_at   DATETIME       NULL,
  created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (category), INDEX (archived_at)
) ENGINE=InnoDB;

CREATE TABLE sales (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_no     VARCHAR(20)  NOT NULL UNIQUE,          -- z.B. B-2026-000123
  sold_at        DATETIME     NOT NULL,
  subtotal_cents INT UNSIGNED NOT NULL,
  discount_cents INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents    INT UNSIGNED NOT NULL,
  payment        ENUM('cash','card') NOT NULL,
  given_cents    INT UNSIGNED NOT NULL DEFAULT 0,
  change_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  voided_at      DATETIME     NULL,
  INDEX (sold_at), INDEX (payment)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id      INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NULL,                        -- NULL, wenn Artikel später gelöscht
  name         VARCHAR(120) NOT NULL,                    -- Snapshot
  unit_cents   INT UNSIGNED NOT NULL,                    -- Snapshot (inkl. manueller Preisänderung)
  cost_cents   INT UNSIGNED NOT NULL DEFAULT 0,          -- Snapshot für Rohertrag
  qty          INT UNSIGNED NOT NULL,
  FOREIGN KEY (sale_id)    REFERENCES sales(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  INDEX (product_id)
) ENGINE=InnoDB;

CREATE TABLE cash_movements (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  occurred_at  DATETIME NOT NULL,
  type         ENUM('sale','in','out','close','delivery') NOT NULL,
  amount_cents INT      NOT NULL,                        -- vorzeichenbehaftet, 0 bei delivery
  note         VARCHAR(255) NOT NULL DEFAULT '',
  sale_id      INT UNSIGNED NULL,
  product_id   INT UNSIGNED NULL,
  qty          INT NULL,                                 -- bei delivery
  FOREIGN KEY (sale_id)    REFERENCES sales(id)    ON DELETE SET NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  INDEX (occurred_at), INDEX (type)
) ENGINE=InnoDB;

CREATE TABLE z_reports (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  no           INT UNSIGNED NOT NULL UNIQUE,
  closed_at    DATETIME     NOT NULL,
  from_sale_at DATETIME     NULL,
  sales_count  INT UNSIGNED NOT NULL,
  cash_cents   INT UNSIGNED NOT NULL,
  card_cents   INT UNSIGNED NOT NULL,
  total_cents  INT UNSIGNED NOT NULL,
  drawer_cents INT          NOT NULL                     -- entnommener Barbestand
) ENGINE=InnoDB;

CREATE TABLE settings (
  \`key\`   VARCHAR(60) PRIMARY KEY,
  value   TEXT NOT NULL
) ENGINE=InnoDB;
-- Zeilen: shop_name, track_stock, warn_low, card_enabled, require_code, access_code_hash

CREATE TABLE access_sessions (
  token_hash  CHAR(64)  PRIMARY KEY,                     -- sha256(Token)
  created_at  DATETIME  NOT NULL,
  expires_at  DATETIME  NOT NULL,                        -- created_at + 2 h
  revoked_at  DATETIME  NULL,
  ip          VARBINARY(16) NULL,
  INDEX (expires_at)
) ENGINE=InnoDB;

CREATE TABLE access_attempts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tried_at    DATETIME NOT NULL,
  ip          VARBINARY(16) NULL,
  ok          TINYINT(1) NOT NULL,
  INDEX (tried_at)
) ENGINE=InnoDB;
```
**Alle Geldbeträge in Cent als `INT`** — kein `FLOAT`. Formatierung (Komma, "€") ausschließlich in der Anzeige.

### Endpunkte
Öffentlich (Kasse):
- `GET  /api/bootstrap` → Artikel, Einstellungen, Kassenbestand, Freigabe-Status (`{unlocked, expiresAt}`)
- `POST /api/sales` → `{items:[{productId, qty, unitCents}], discountCents, payment, givenCents}`; in **einer Transaktion**: `sales` + `sale_items` + `cash_movements(type=sale)` + Bestände (`UPDATE ... SET stock = GREATEST(0, stock - :qty)`, nur wenn `track_stock`); Antwort = fertiger Bon inkl. `receipt_no` und `change_cents`. Idempotenz über mitgesendete Client-UUID (`UNIQUE`), damit ein Doppel-Tap keinen zweiten Bon erzeugt.
- `GET  /api/receipts/{no}`

Code:
- `POST /api/access` → `{code}`; `password_verify` gegen `access_code_hash`; bei Erfolg 32-Byte-Token (`random_bytes`), `sha256` in `access_sessions` mit `expires_at = NOW() + INTERVAL 2 HOUR`, Token als `HttpOnly; Secure; SameSite=Strict`-Cookie; Antwort `{expiresAt}`. Fehlversuch → `401`, Zähler; > 5 in 15 min → `429`.
- `DELETE /api/access` → `revoked_at = NOW()` (Sperren-Button)
- `GET  /api/access` → `{unlocked, expiresAt}`

Geschützt (`Auth::require()`: Token gültig **und** `expires_at > NOW()` **und** `revoked_at IS NULL`, sonst `401`):
- `POST|PATCH|DELETE /api/products` (löschen = `archived_at` setzen, damit alte Bons intakt bleiben)
- `POST /api/deliveries` → Bestand erhöhen + `cash_movements(type=delivery)`
- `POST /api/cash-movements` → `in`/`out` (bei `out` gegen Kassenbestand prüfen)
- `POST /api/z-reports` → Tagesabschluss (Transaktion, Kassenbestand als `close`-Bewegung entnehmen)
- `GET  /api/reports/*` → `daily`, `hourly`, `products`, `payments`, `kpis` (SQL-Aggregate, kein Laden aller Verkäufe)
- `GET  /api/journal?limit=&before=` (Cursor-Pagination)
- `PATCH /api/settings`
- `POST /api/maintenance/reset` (Datenlöschung, protokolliert)

### Hinweise zur Umsetzung
- **Bestandsabbuchung** und Bonschreibung immer in einer Transaktion; `SELECT ... FOR UPDATE` auf die betroffenen Artikel, wenn mehrere Kassen parallel laufen.
- Belegnummern über `z_reports`/Zählertabelle, nicht über `MAX(id)+1` im PHP.
- Server-Zeitzone `Europe/Berlin` (MySQL `time_zone`, PHP `date_default_timezone_set`) — die Tages- und Stundenauswertungen hängen daran.
- Offline-Robustheit auf dem Fest: Verkäufe im Frontend in eine Queue (`localStorage`) legen und bei Verbindungsverlust nachsenden; die Idempotenz-UUID verhindert Doppelbuchungen.
- Betrieb: HTTPS erzwingen, `/src` und `/config` außerhalb des DocumentRoot, DB-Zugang per `.env` (nicht im Repo), tägliches `mysqldump`.
- Prüfliste zum Abschluss: Rückgeld bei genau passendem Betrag, Rückgeld > 50 €, Rabatt größer als Summe, Artikel während offenem Korb gelöscht, Bestand 0 mit Artikel im Korb, Code-Ablauf mitten im Formular (muss `401` + Dialog geben), zwei Tabs gleichzeitig.

---

## Design Tokens
**Farben**
- Ink / Primärfläche `#14171a` · App-Hintergrund `#eceeea` · Flächen `#fff` · Füllung/Sekundärbutton `#eceeea` · zarte Fläche `#f4f5f3` · Zeilen-Hover `#fafbf9`
- Akzent (Standard) `#0f7a5f`, Alternativen `#2563eb`, `#c2410c`, `#7c3aed` — Akzent färbt: Rückgeld-Feld bei ausreichendem Betrag, Kassieren-Button (zahlbereit), Toggle-Switch an, "heute"-Balken, Chart-Maximum, Karten-Balken, Bestand "ausreichend", Rohertrag-KPI
- Erfolg im Kopf `#5ee0a8` · Warnung Text `#c2410c`, Fläche `#fff1e9`, Banner `#fff7ed` mit Rand `#fdba74` und Text `#9a3412` · Fehler Text `#b91c1c`, Fläche `#fee2e2` · Info `#eef2ff`/`#3730a3` · Verkauf-Badge `#e8f5f0`/`#0f7a5f`
- Text: primär `#14171a`, sekundär `rgba(0,0,0,.5)`, tertiär `rgba(0,0,0,.45)`, schwach `rgba(0,0,0,.3)`; Rahmen `rgba(0,0,0,.08)` / Felder `rgba(0,0,0,.14)`

**Typografie** — UI: **Figtree** (400/500/600/700/800), Fallback `system-ui, sans-serif`. Zahlen/Beträge: **IBM Plex Mono** (500/600).
Skala: 40 (Kassenbestand) · 30 (Zu zahlen) · 26 (KPI) · 19 (Gegeben/Rückgeld) · 17 (Kachelpreis, Bon-Summe) · 16 (Hauptbutton, Bonkopf) · 15 (Titel, Zeilensumme) · 14 (Karten-/Modaltitel, Positionsname) · 13.5 (Tabs, Chips, Toggle-Titel) · 13 (Standardtext) · 12.5 / 12 / 11.5 (Hilfstext) · 11 / 10.5 (Badges, Label uppercase mit `letter-spacing:.07–.09em`) · 9.5 (Stunden-Achse).

**Abstände** 2 · 3 · 4 · 5 · 6 · 8 · 9 · 10 · 11 · 12 · 13 · 14 · 16 · 18 · 22 · 24 px (`gap` bevorzugt, nicht Margins)
**Radien** 6 · 7 · 8 · 9 · 10 · 11 · 12 · 13 · 14 · 16 · 18 · 99 px (Pill)
**Schatten** Karte `0 1px 2px rgba(0,0,0,.05)` · Kachel `0 1px 2px rgba(0,0,0,.06)` · Warenkorb `0 1px 2px rgba(0,0,0,.06), 0 12px 30px -18px rgba(0,0,0,.25)` · Footer `0 -6px 14px -12px rgba(0,0,0,.5)` · Toast `0 12px 30px -10px rgba(0,0,0,.5)`
**Bewegung** `transform .08s` (Tap-Feedback `scale(.95–.99)`), `.15s` (Toggle), `.16s` pop `scale(.96)→1`, `.18s` slide-up `translateY(14px)→0`
**Feste Maße** Kopfzeile 62 px · Warenkorb 430 px · Kachel min. 158 px / min-height 104 px · Tageschart 160 px (Balken max. 118 px) · Stundenchart 160 px (max. 128 px) · Touch-Ziele ≥ 44 px

## Assets
Keine Bilder oder Icon-Dateien. Verwendete Glyphen: 🧾 (Leerzustand Warenkorb), ⌫ (Ziffernblock), − / + / ✕ als Textzeichen. Schriften über Google Fonts (Figtree, IBM Plex Mono) — für den Offline-Betrieb auf dem Fest lokal ausliefern.

## Files
- `Kassen-App.dc.html` — vollständiger interaktiver Prototyp aller Screens (im Browser öffnen; Demo-Code **1234**, Löschkennwort **9999**)
- `schema.sql` — Startschema für MySQL (identisch zum Abschnitt Datenmodell, plus Seed-Einstellungen)
