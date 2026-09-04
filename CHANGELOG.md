# Changelog

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
