# WarnHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.1.0--beta.1-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Warn- und Alarmmeldungen für Deutschland (Katastrophenschutz, Wetter, Hochwasser, Polizei) --
gefiltert auf den selbst definierten Umkreis um eigene Standorte, mit Push-Benachrichtigung
an alle WebFront-Geräte und optionalen Schutzaktionen.

## Was tut dieses Modul?

WarnHub bündelt amtliche Warnmeldungen aus zwei Quellen:

- **NINA-Aggregation** (`warnung.bund.de`) -- dieselbe API, die auch die offizielle
  BBK-Warn-App NINA nutzt. Bündelt MoWaS-Katastrophenwarnungen, KATWARN, BIWAPP,
  DWD-Wetterwarnungen, Hochwasserwarnungen (LHP) und Polizeimeldungen.
- **Direkte DWD-Wetterwarnungen** (`opendata.dwd.de`) -- optional zusätzlich, liefert
  detailliertere Handlungsempfehlungen und präzisere Gemeinde-Polygone als die
  NINA-Zusammenfassung.

Jeder Standort (eigener Wohnort, Zweitwohnsitz, Angehörige) bekommt einen eigenen Umkreis
und Mindest-Schweregrad. Eine Warnung löst nur aus, wenn der Standort **geometrisch**
innerhalb der tatsächlichen Warnfläche (Polygon/Kreis der Meldung) oder ihres Umkreises
liegt -- nicht anhand grober Postleitzahlen-/Gemeindegrenzen.

Aktive Warnungen erscheinen als Push-Benachrichtigung auf **allen** WebFront-Instanzen
(automatisch erkannt, auch am Handy) und können optional **Schutzaktionen** auslösen --
z. B. Raffstore/Rollladen hochfahren, Garagentor schließen, ein akustisches Signal schalten
oder ein eigenes Skript ausführen. Jede Schutzaktion feuert nur einmal je Warnung; es gibt
bewusst keine automatische Rückstellung -- das bleibt Nutzerhandeln.

## Installation

Über die Symcon-Modulverwaltung als eigene Bibliotheks-URL hinzufügen:

```
https://github.com/DG65/WarnHub
```

Danach eine neue Instanz vom Typ **WarnHub** anlegen.

## Einrichtung

1. Mindestens einen Standort anlegen (Name, Koordinaten, Umkreis, Mindest-Schweregrad).
   Koordinaten lassen sich über den eingebauten Nachschlage-Knopf (OpenStreetMap Nominatim)
   ermitteln.
2. Datenquellen prüfen (NINA ist standardmäßig aktiv, DWD-Direktquelle optional zusätzlich).
3. Push-Benachrichtigung aktivieren -- das Modul findet alle vorhandenen WebFront-Instanzen
   automatisch, keine manuelle Verknüpfung nötig.
4. Optional: Schutzaktionen anlegen (Ziel-Variable/-Skript, Auslöse-Kategorie,
   Mindest-Schweregrad).

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

## Lizenz

PolyForm Noncommercial 1.0.0 -- private/nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Vollständiger Text:
[LICENSE](LICENSE). Spenden sind willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
