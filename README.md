# WarnHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.1.0--beta.15-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Warn- und Alarmmeldungen -- amtlich für Deutschland (Katastrophenschutz, Wetter, Hochwasser,
Polizei, Pegelstände, Radioaktivität), optional europaweite Wetterwarnungen für 39 Länder --
gefiltert auf den selbst definierten Umkreis um eigene (auch mobile) Standorte, mit
Push-Benachrichtigung an WebFront-/Kachel-Visualisierung-Geräte und optionalen Schutzaktionen.

## Was tut dieses Modul?

WarnHub bündelt amtliche Warnmeldungen aus mehreren, einzeln zuschaltbaren Quellen:

- **NINA-Aggregation** (`warnung.bund.de`, standardmäßig aktiv) -- dieselbe API, die auch die
  offizielle BBK-Warn-App NINA nutzt. Bündelt MoWaS-Katastrophenwarnungen, KATWARN, BIWAPP,
  DWD-Wetterwarnungen, Hochwasserwarnungen (LHP) und Polizeimeldungen.
- **Direkte DWD-Wetterwarnungen** (`opendata.dwd.de`) -- optional zusätzlich, liefert
  detailliertere Handlungsempfehlungen und präzisere Gemeinde-Polygone als die
  NINA-Zusammenfassung.
- **PEGELONLINE** (`pegelonline.wsv.de`) -- optional, warnt bei Pegeln über dem mittleren
  bzw. bisherigen Höchstwasser einer Wasserstraßen-Messstelle in der Nähe.
- **BfS Ortsdosisleistung** (`imis.bfs.de`) -- optional, warnt bei Überschreitung eines
  selbst eingestellten Radioaktivitäts-Schwellwerts. Ein Formular-Popup ordnet den Wert ein
  (Dosisleistung/rechnerische Verweildauer bis zum Jahres-Vorsorgewert der Bevölkerung).
- **Meteoalarm** (`feeds.meteoalarm.org`) -- optional, europaweite Wetterwarnungen für
  39 Länder, wichtig für mobile Standorte im Ausland. Die frei zugänglichen Feeds liefern
  keine Warnfläche, nur benannte Gebiete -- der Abgleich läuft deshalb über einen separaten,
  im Meldungstext klar gekennzeichneten Namensvergleich statt geometrischem Matching.

PEGELONLINE und BfS ODL-Info kennen keine amtliche Warnstufen-Klassifikation -- WarnHub
meldet stattdessen einen selbst definierten Schwellwert, ausdrücklich als solcher gekennzeichnet.

Jeder Standort (eigener Wohnort, Zweitwohnsitz, Angehörige) bekommt einen eigenen Umkreis
und Mindest-Schweregrad. Eine Warnung löst nur aus, wenn der Standort **geometrisch**
innerhalb der tatsächlichen Warnfläche (Polygon/Kreis der Meldung) oder ihres Umkreises
liegt -- nicht anhand grober Postleitzahlen-/Gemeindegrenzen. Ein Standort kann statt fester
Koordinaten auch an zwei Live-Variablen (Lat/Lon, z. B. aus Tessie oder einer
Geofency-Bridge) gebunden werden -- WarnHub liest dann bei jeder Prüfung die aktuelle
Position. Über einen "Push nur an"-Namensfilter lässt sich außerdem festlegen, dass ein
Standort nur bestimmte Push-Ziele benachrichtigt (z. B. je eine Person/ein Fahrzeug bei
mehreren gleichzeitig genutzten Standorten).

Aktive Warnungen erscheinen als Push-Benachrichtigung auf allen **aktivierten**
WebFront- und Kachel-Visualisierung-Instanzen (automatisch im Objektbaum gefunden und
vorausgewählt, auch am Handy; einzelne Instanzen lassen sich abwählen) und können optional
**Schutzaktionen** auslösen --
z. B. Raffstore/Rollladen hochfahren, Markise einfahren, Garagentor schließen, Autofenster
schließen, Kofferraum/Heckklappe schließen (mit zwingender Sicherheitsprüfung gegen ein
versehentliches Öffnen, siehe unten), ein akustisches Signal schalten oder ein eigenes
Skript ausführen. Jede Schutzaktion feuert nur einmal je Warnung; es gibt bewusst keine
automatische Rückstellung -- das bleibt Nutzerhandeln. Eine Schutzaktion ohne eigenen
Standort-Filter feuert automatisch nur noch von festen, nicht von mobilen Standorten aus.

## Installation

Über die Symcon-Modulverwaltung als eigene Bibliotheks-URL hinzufügen:

```
https://github.com/DG65/WarnHub
```

Danach eine neue Instanz vom Typ **WarnHub** anlegen.

## Einrichtung

1. Mindestens einen Standort anlegen -- drei Wege stehen zur Wahl: Knopf "Standort aus
   Symcon-Systemeinstellungen übernehmen" (liest die Kern-Instanz "Standort"), Adress-/
   PLZ-Suche über OpenStreetMap Nominatim, oder Punkt auf der Karte auswählen.
2. Datenquellen prüfen (NINA ist standardmäßig aktiv, alle anderen optional zusätzlich).
3. Push-Benachrichtigung: Knopf "WebFront-Instanzen suchen" klicken (findet sowohl
   klassische WebFront-Instanzen als auch Kachel-Visualisierung-Instanzen).
4. Optional: Knopf "Objektbaum nach Raffstore/Jalousie/Markise/Garage/Fenster
   schließen/Heckklappe/Sirene durchsuchen" klicken.

### Auto-Erkennung: gefunden = aktiviert, Abwahl möglich

Sowohl die Push-Ziel-Suche als auch die Schutzaktionen-Suche folgen demselben Prinzip:
gefundene Treffer werden als **bereits aktivierte** Zeile vorgeschlagen (Push geht sofort an
jede gefundene WebFront-/Kachel-Visualisierung-Instanz, jede gefundene
Raffstore-/Jalousie-/Markise-/Garage-/Fenster-/Sirene-Steuerung löst sofort aus) -- nicht
gewünschte Treffer lassen sich einfach über die Aktiv-Spalte abwählen. Eine erneute Suche
ergänzt nur neu hinzugekommene Treffer und lässt bereits getroffene Aktiv/Inaktiv-
Entscheidungen unangetastet. Bei Schutzaktionen unbedingt den **Zielwert** jeder gefundenen
Zeile prüfen, bevor eine echte Warnung eintritt -- welcher Wert "offen"/"hochgefahren"
bedeutet, ist herstellerabhängig und wird nicht geraten. Ausnahme Kofferraum/Heckklappe:
diese Zeilen bleiben ohne automatisch gefundene Zustands-Variable ausnahmsweise **inaktiv**
(Sicherheitssperre, siehe "Grenzen" unten).

## Öffentliche Funktionen

- `WHUB_Poll($id): string` -- löst eine sofortige Prüfung aus, liefert eine
  menschenlesbare Zusammenfassung.
- `WHUB_GetActiveWarnings($id): string` -- JSON-Liste der aktuell zutreffenden Warnungen je
  Standort (für eigene Auswertungen/Kacheln).

## Grenzen

- Liegt zu einer Meldung keine Geometrie vor (selten, meist ältere/administrative
  Meldungstypen), wird sie sicherheitshalber **nicht** automatisch zugeordnet -- keine
  geratene Präzision.
- Die direkte DWD-Anbindung braucht die PHP-Erweiterungen `curl` und `ZipArchive`. Fehlen
  sie, liefert NINA weiterhin DWD-Wetterwarnungen, nur ohne das zusätzliche Detail.
- Meteoalarm liefert keine Warnfläche, nur benannte Verwaltungsgebiete -- der Abgleich läuft
  über einen Namensvergleich (Standort per Reverse-Geocoding einem Kreis/einer Region
  zugeordnet), nicht geometrisch. Ungenauer als die übrigen Quellen, im Meldungstext
  ausdrücklich als "Namensabgleich" gekennzeichnet. Für Deutschland liefert die direkte
  DWD-Anbindung bereits die präziseren Polygone.
- Kofferraum/Heckklappe schließen löst **nur** aus, wenn eine zusätzliche Zustands-Variable
  bestätigt, dass die Klappe aktuell offen ist -- der zugrundeliegende Tesla-Befehl ist ein
  reiner Umschalter ohne Richtung, ein blindes Auslösen könnte eine bereits geschlossene
  Klappe öffnen statt schließen. Ohne gültige Zustands-Variable wird deshalb gar nicht erst
  ausgelöst. Der vordere Kofferraum (Frunk) lässt sich über die Tesla-API grundsätzlich nicht
  schließen, nur öffnen, und wird deshalb von WarnHub nicht automatisiert.

## Lizenz

PolyForm Noncommercial 1.0.0 -- private/nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Vollständiger Text:
[LICENSE](LICENSE). Spenden sind willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
