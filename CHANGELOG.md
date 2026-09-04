# Changelog

## 0.1.0-beta.12 (2026-09-04)

- Neue, optionale Datenquelle: **Meteoalarm** (feeds.meteoalarm.org,
  europaweite Wetterwarnungen für 39 Länder) -- wichtig für mobile
  Standorte im Ausland. WarnHub fragt automatisch nur die Länderfeeds ab,
  in denen tatsächlich ein aktiver Standort liegt (per Reverse-Geocoding
  über OpenStreetMap Nominatim ermittelt, 6h gecacht).
- Wichtige Einschränkung, live gegen mehrere Länderfeeds UND die
  vollständigen CAP-Originaldokumente dahinter geprüft (04.09.2026): Die
  frei zugänglichen Meteoalarm-Feeds liefern KEINE Warnfläche (kein
  Polygon/Kreis wie bei NINA/DWD/PEGELONLINE/BfS), nur benannte
  Verwaltungsgebiete. Der Abgleich läuft deshalb über einen separaten,
  klar als "Namensabgleich" gekennzeichneten Pfad statt über das
  geometrische Umkreis-Matching -- in der Meldung selbst sichtbar markiert,
  keine vorgetäuschte Präzision. Für deutsche Standorte bleibt die direkte
  DWD-Anbindung die präzisere Quelle, Meteoalarm ergänzt vor allem das
  europäische Ausland.

## 0.1.0-beta.11 (2026-09-04)

- Fix/Absicherung (automatisch, keine Einrichtung nötig): Eine Schutzaktion
  ohne explizit gesetzten "Nur Standort"-Filter feuert jetzt NUR noch von
  einem festen Standort aus, nie von einem mobilen (Live-Standort-
  gebundenen). Ohne diese Sperre hätte z. B. ein Sturm über Hamburg, der
  nur den mobilen Standort "unterwegs" trifft, die zuhause verbaute
  Jalousie eingefahren -- Dietmars Nachfrage direkt nach Einführung des
  mobilen Standorts in beta.10. Wer eine Aktion ausdrücklich an einen
  mobilen Standort binden will, kann das weiterhin per Namen im Filter tun.

## 0.1.0-beta.10 (2026-09-04)

- Standorte können jetzt an zwei Variablen (Lat/Lon) gebunden werden, statt
  fester Koordinaten -- z. B. an eine Tessie- oder eine Geofency-Bridge-
  Variable. WarnHub liest bei jeder Prüfung die dann AKTUELLE Position; die
  Tabellenspalten Lat/Lon bleiben Startwert/Fallback, falls die Variable
  (noch) nicht existiert. Grundlage für "immer die richtige Warnung am
  tatsächlichen Aufenthaltsort", unabhängig von einer festen Heimatkoordinate.
- Neuer Spaltenfilter "Push nur an" je Standort (Komma-getrennte WebFront-
  Namen, leer = wie bisher an alle aktivierten Ziele): löst den Fall von
  mehreren gleichzeitig genutzten Standorten/Personen/Fahrzeugen (z. B. 2
  Teslas + 2 Geofency-Instanzen + 2 WebFronts), bei dem bislang JEDE
  Warnung an ALLE WebFronts ging, unabhängig davon, wen sie eigentlich
  betraf.

## 0.1.0-beta.9 (2026-09-04)

- Zwei neue, optionale Datenquellen (beide standardmäßig AUS):
  - **PEGELONLINE (WSV)** -- warnt bei Pegeln über dem mittleren Hochwasser (MHW) bzw. dem
    bisherigen historischen Höchstwert (HSW) in der Nähe eines Standorts. Klassifiziert
    automatisch als Kategorie "Starkregen/Hochwasser".
  - **BfS Ortsdosisleistung (Radioaktivität)** -- warnt bei Überschreitung eines selbst
    einstellbaren Schwellwerts (Standard 0,3 µSv/h) an einer Messstelle in der Nähe. Beide
    Quellen liefern nur Rohdaten, keine amtliche Warnstufen-Klassifikation -- das wird im
    Formular und im Meldungstext explizit so benannt.
  - Waldbrandgefahrenindex (DWD) und Sturmflutvorhersage (BSH) bewusst noch nicht
    eingebaut -- beide APIs sind deutlich komplexer (gezippte CSV je Station bzw.
    mehrstufige OGC-Features-API), folgen als nächster Schritt.

## 0.1.0-beta.8 (2026-09-04)

- Fix (zweiter, vermutlich entscheidender Baustein): Kachel-Visualisierung-Push nutzt jetzt
  `VISU_PostNotificationEx` (Icon und Sound als getrennte Parameter) statt der einfachen
  `VISU_PostNotification` -- zwei unabhängige reale Referenzen (Wilkware/WeatherWarning-Modul,
  ein von Dietmar gefundenes Müllabfuhr-Erinnerungsskript) nutzen ausschließlich die
  Ex-Variante, jeder bisherige Versuch mit der einfachen Funktion schlug bei Dietmar fehl.
- Fix: Titel-/Text-Kürzung auf die von Symcon dokumentierten 32/256 Zeichen war
  zeichenbasiert (`mb_substr`) -- ob Symcons eigene Längenprüfung intern in Zeichen oder
  BYTES rechnet, ist nirgends dokumentiert. Ein Emoji im Titel (4 Byte in UTF-8) plus
  Umlaute im Ereignisnamen konnten die Byte-Grenze überschreiten, obwohl die Zeichenzahl
  längst darunter lag -- neue `truncateBytes()` kürzt jetzt byte-sicher (UTF-8-Grenzen
  respektierend). Betraf auch den Test-Push-Knopf selbst (33 statt erlaubter 32 Byte).

## 0.1.0-beta.7 (2026-09-04)

- Fix (hoffentlich final): Push an Kachel-Visualisierung-Instanzen schlug trotz des
  TargetID-Fixes aus beta.5 weiterhin fehl. Live-verifiziert an Dietmars Installation über
  Symcons eigenen "Instanzfunktionen ausführen"-Dialog: `VISU_PostNotification` braucht als
  TargetID die ZIEL-VISUALISIERUNG SELBST (dieselbe ID wie der Push-Empfänger), nicht
  WarnHubs eigene Instanz-ID -- anders als beim offiziellen Kernmodul-Vorbild, das für
  `WFC_PushNotification` funktioniert (dort unverändert).

## 0.1.0-beta.6 (2026-09-04)

- Schutzaktionen: „Auslöser" ist jetzt eine Mehrfachauswahl zum Ankreuzen (🌪️ Sturm, 🧊 Hagel,
  🌧️ Starkregen, ⚡ Gewitter, ❄️ Schnee, 🥵 Hitze) statt einer Einzelauswahl -- eine Markise kann
  jetzt in EINER Zeile bei Sturm UND Hagel einfahren, statt mehrerer Zeilen zu brauchen
  (Dietmars ausdrücklicher Wunsch). Kein Kästchen angekreuzt = gilt weiterhin für jede
  Kategorie. Die automatische Objektbaum-Suche kreuzt bei Raffstore/Markise bereits Sturm +
  Hagel an.
- Fix: Treffer über eine Kind-Variable (z. B. "Hupe" unter mehreren Fahrzeug-Instanzen) waren
  in der Liste nicht mehr unterscheidbar, weil nur der Variablenname übernommen wurde. Der
  Name der tatsächlich besitzenden Instanz wird jetzt vorangestellt ("Schneeflocke – Hupe"),
  auch wenn beliebig viele Zwischenkategorien dazwischenliegen.

## 0.1.0-beta.5 (2026-09-04)

- Fix: Push an Kachel-Visualisierung-Instanzen schlug immer fehl (Live-Test, Dietmar). Ursache:
  TargetID wurde als `0` übergeben -- die offizielle Symcon-Referenzimplementierung
  (Kernmodul "Benachrichtigung") übergibt dort die eigene Instanz-ID. Betrifft
  VISU_PostNotification UND WFC_PushNotification gleichermaßen, beide korrigiert.
- Fix: "Standort per Karte übernehmen" schlug mit "Parameter KartenStandort hat keinen
  Datentyp" fehl -- Symcon verlangt bei jeder öffentlichen Funktion zwingend einen
  Skalar-Typ je Parameter, jetzt `string $KartenStandort` statt untypisiert.

## 0.1.0-beta.4 (2026-09-04)

- Fix: Push-Ziele-Liste zeigte Name/Instanz-ID nicht an (fehlendes `edit` je Spalte bei
  `type: List`).
- Neuer Knopf "🧪 Testbenachrichtigung senden" (Panel "Prüfung & Status") -- schickt eine
  harmlose Testmeldung an alle aktivierten Push-Ziele, unabhängig von einer echten Warnung.

## 0.1.0-beta.3 (2026-09-04)

- Fix: Konfigurationsformular ließ sich nach dem letzten Update nicht mehr öffnen
  (Kartenfeld-Vorbelegung falsch kodiert).
- Push-Ziele-Suche erkennt jetzt zusätzlich zu klassischen WebFront-Instanzen auch
  **Kachel-Visualisierung**-Instanzen (Symcons neuere, kachelbasierte Oberfläche unter
  "Visualisierung Instanzen") und pusht über die dafür richtige Funktion
  (`VISU_PostNotification` statt `WFC_PushNotification`) -- Live-Fund: viele Installationen
  nutzen ausschließlich Kacheln, keine klassischen WebFront-Instanzen.
- Standorte-/WebFronts-/Schutzaktionen-Listen passen ihre sichtbare Höhe jetzt an die
  tatsächliche Anzahl an Einträgen an.

## 0.1.0-beta.2 (2026-09-04)

- Formular-Reihenfolge korrigiert: "Wozu dieses Modul?" → "Neu in Version X.Y" →
  "Dokumentation & Hilfe" stehen jetzt VOR den Fachpanels (Standorte/Datenquellen/
  Benachrichtigung/Schutzaktionen), wie in der übrigen DG65-Modulfamilie üblich. Die
  Prüfung-&-Status-Anzeige ("Jetzt prüfen") ist jetzt ein eigenes, erklärtes Panel ganz am
  Ende der Konfiguration (nach den Fachpanels, vor Feedback/Über dieses Modul).
- Standort zusätzlich per Karte auswählbar (`SelectLocation`-Formularelement).
- WebFront-Erkennung überarbeitet: löst die WebFront-Modul-GUID jetzt zur Laufzeit über den
  Modulnamen auf (robuster als eine fest hinterlegte GUID) und zeigt gefundene Instanzen als
  bearbeitbare, standardmäßig aktivierte Liste -- einzelne Instanzen lassen sich gezielt
  abwählen, eine erneute Suche ergänzt nur neue Funde.
- Schutzaktionen: neuer Objektbaum-Suchlauf findet automatisch Instanzen/Variablen mit
  "Raffstore"/"Jalousie"/"Garage"/"Sirene" im Namen und schlägt sie voraktiviert vor
  (Schweregrad "Hoch"/"Extrem" als vorsichtiger Standard) -- ebenfalls per Abwahl statt
  manueller Neuanlage bedienbar.

## 0.1.0-beta.1 (2026-09-04)

- Erste Version: Warn- und Alarmmeldungen für Deutschland über NINA-Aggregation
  (MoWaS/KATWARN/BIWAPP/DWD/Hochwasser/Polizei) und optionale direkte DWD-CAP-Anbindung.
- Geometrisches Umkreis-Matching (Polygon/Kreis der Meldung, keine Postleitzahlen-Näherung)
  gegen beliebig viele selbst definierte Standorte, je mit eigenem Radius und
  Mindest-Schweregrad.
- Automatische Push-Benachrichtigung an alle gefundenen WebFront-Instanzen (inkl. Handy),
  ohne manuelle Verknüpfung.
- Schutzaktionen: Raffstore/Rollladen hochfahren, Garagentor schließen, akustischer Alarm
  (mit Auto-Aus), eigenes Skript -- je nach Auslöse-Kategorie (Sturm/Hagel/Starkregen/
  Gewitter/Schnee/Hitze) und Mindest-Schweregrad, optional auf einzelne Standorte beschränkt.
- Öffentlicher Vertrag `WHUB_GetActiveWarnings()` für eigene Auswertungen/Kacheln.
