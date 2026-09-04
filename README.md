# WarnHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.1.0--beta.23-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Warn- und Alarmmeldungen für Deutschland, Österreich und die Schweiz -- amtliche Quellen für
Deutschland (Katastrophenschutz, Wetter, Hochwasser, Polizei, Pegelstände, Radioaktivität),
direkte amtliche Quellen für Österreich (GeoSphere Austria) und die Schweiz
(BAFU-Hochwasser-Gefahrenstufen), europaweite Wetterwarnungen für 39 Länder sowie optional die
eigene Wetterstation als unabhängiges Sicherheitsnetz -- gefiltert auf den selbst definierten
Umkreis um eigene (auch mobile) Standorte, mit Mehrkanal-Push-Benachrichtigung an
WebFront-/Kachel-Visualisierung-Geräte, Telegram und Pushover sowie optionalen
Schutzaktionen.

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
  39 Länder **inklusive Österreich und Schweiz**, wichtig für mobile Standorte im Ausland.
  Die frei zugänglichen Feeds liefern keine Warnfläche, nur benannte Gebiete -- der Abgleich
  läuft deshalb über einen separaten, im Meldungstext klar gekennzeichneten Namensvergleich
  statt geometrischem Matching.
- **GeoSphere Austria** (`warnungen.zamg.at`) -- optional, amtliche Warnungen direkt von der
  österreichischen Wetterbehörde (ZAMG), koordinatengenau statt Namensabgleich. Übernimmt für
  österreichische Standorte automatisch von Meteoalarm, sobald aktiviert (analog dazu, wie die
  direkte DWD-Anbindung den entsprechenden NINA-Kanal für deutsche Standorte ersetzt).
- **BAFU-Hochwasserdaten** (`lindas.admin.ch`, Schweiz) -- optional, amtliche 5-stufige
  Gefahrenstufen-Skala des Bundesamts für Umwelt für Fliessgewässer und Seen. Anders als
  PEGELONLINE/BfS/eigene Wetterstation eine echte behördliche Klassifikation, keine
  Eigenkonstruktion -- nur die Schwelle, ab der WarnHub meldet, ist einstellbar.
- **Eigene Wetterstation** (aktuell Froggit) -- optional, unabhängig von allen übrigen
  Quellen: löst aus, sobald die lokal gemessene Windböe oder Regenrate den eigenen
  Schwellwert überschreitet. Ein Sicherheitsnetz für den Fall, dass amtliche Warnungen ein
  tatsächlich lokal auftretendes Ereignis nicht oder nicht rechtzeitig melden. Eine
  Objektbaum-Suche findet eine passende Station automatisch.
- **VKF-Hagelschutz-Signalbox** (Schweiz, `meteo.netitservices.com`) -- **BETA, ungetestet**:
  optional, bindet eine physisch bei einem konkreten Schweizer Gebäude registrierte
  Hagelschutz-Signalbox ein (hagelschutz-einfach-automatisch.ch). Aus der offiziellen
  VKF-Dokumentation und dem Quellcode eines aktiven Community-Adapters gebaut, mangels
  eigener Signalbox aber nicht live gegengeprüft -- Rückmeldungen willkommen.

PEGELONLINE, BfS ODL-Info und die eigene Wetterstation kennen keine amtliche
Warnstufen-Klassifikation -- WarnHub meldet stattdessen einen selbst definierten Schwellwert,
ausdrücklich als solcher gekennzeichnet.

Jeder Standort (eigener Wohnort, Zweitwohnsitz, Angehörige) bekommt einen eigenen Umkreis
und Mindest-Schweregrad. Eine Warnung löst nur aus, wenn der Standort **geometrisch**
innerhalb der tatsächlichen Warnfläche (Polygon/Kreis der Meldung) oder ihres Umkreises
liegt -- nicht anhand grober Postleitzahlen-/Gemeindegrenzen. Ein Standort kann statt fester
Koordinaten auch an zwei Live-Variablen (Lat/Lon, z. B. aus Tessie oder Geofency) gebunden
werden -- WarnHub liest dann bei jeder Prüfung die aktuelle Position. Eine eigene
Objektbaum-Suche findet passende Fahrzeug-/Standort-Variablenpaare automatisch (Tessie
"Fahrzeugposition", Geofency "Current Latitude/Longitude") und legt direkt verknüpfte,
aktivierte Standorte an -- kein manuelles Heraussuchen der Variablen-IDs nötig. Über einen
"Push nur an"-Namensfilter lässt sich außerdem festlegen, dass ein Standort nur bestimmte
Push-Ziele benachrichtigt (z. B. je eine Person/ein Fahrzeug bei mehreren gleichzeitig
genutzten Standorten).

Aktive Warnungen erscheinen als Push-Benachrichtigung auf allen **aktivierten**
Push-Zielen -- WebFront- und Kachel-Visualisierung-Instanzen sowie, falls installiert,
Telegram-Bot- (offizielles Symcon-Modul) und Pushover-Instanzen (Community-Modul), jeweils
automatisch im Objektbaum gefunden und vorausgewählt, auch am Handy; einzelne Ziele lassen
sich abwählen -- und können optional
**Schutzaktionen** auslösen --
z. B. Raffstore/Rollladen hochfahren, Markise einfahren, Garagentor schließen, Autofenster
schließen, Kofferraum/Heckklappe schließen (mit zwingender Sicherheitsprüfung gegen ein
versehentliches Öffnen, siehe unten), ein akustisches Signal schalten oder ein eigenes
Skript ausführen. Jede Schutzaktion feuert nur einmal je Warnung; es gibt bewusst keine
automatische Rückstellung -- das bleibt Nutzerhandeln. Eine Schutzaktion ohne eigenen
Standort-Filter feuert automatisch nur noch von festen, nicht von mobilen Standorten aus.
Eine Schutzaktion feuert außerdem nicht schon bei Eingang der Meldung, sondern erst kurz vor
deren tatsächlichem Gültigkeitsbeginn (einstellbarer Vorlauf, Standard 30 Minuten) -- eine
morgens eintreffende, aber erst für den Nachmittag gültige Warnung fährt die Markise also
nicht schon morgens ein. Die Push-Benachrichtigung selbst bleibt davon unberührt und kommt
weiterhin sofort.

## Installation

Über die Symcon-Modulverwaltung als eigene Bibliotheks-URL hinzufügen:

```
https://github.com/DG65/WarnHub
```

Danach eine neue Instanz vom Typ **WarnHub** anlegen.

## Einrichtung

1. Mindestens einen Standort anlegen -- mehrere Wege stehen zur Wahl: Knopf "Standort aus
   Symcon-Systemeinstellungen übernehmen" (liest die Kern-Instanz "Standort"), Adress-/
   PLZ-Suche über OpenStreetMap Nominatim, Punkt auf der Karte auswählen, oder Knopf
   "Fahrzeug-/Standort-Variablen suchen" für einen mobilen Standort (Tessie/Geofency).
2. Datenquellen prüfen (NINA ist standardmäßig aktiv, alle anderen optional zusätzlich) --
   für eine eigene Wetterstation reicht meist der Knopf "Wetterstation suchen".
3. Push-Benachrichtigung: Knopf "Push-Ziele suchen" klicken (findet WebFront-Instanzen,
   Kachel-Visualisierung-Instanzen sowie -- falls installiert -- Telegram-Bot- und
   Pushover-Instanzen).
4. Optional: Knopf "Objektbaum nach Raffstore/Jalousie/Markise/Garage/Fenster
   schließen/Heckklappe/Sirene durchsuchen" klicken.

### Auto-Erkennung: gefunden = aktiviert, Abwahl möglich

Die Standort-, Datenquellen-, Push-Ziel- und Schutzaktionen-Suche folgen demselben Prinzip:
gefundene Treffer werden als **bereits aktivierte** Zeile vorgeschlagen (ein gefundener
mobiler Standort ist sofort mit den Live-Variablen verknüpft, eine gefundene Wetterstation
ist sofort einsatzbereit, Push geht an jedes gefundene Ziel (WebFront/Kachel-Visualisierung/
Telegram/Pushover),
jede gefundene Raffstore-/Jalousie-/Markise-/Garage-/Fenster-/Sirene-Steuerung ist ab der
nächsten passenden Warnung scharf) -- nicht gewünschte Treffer lassen sich einfach über die
Aktiv-Spalte abwählen. Eine erneute Suche
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
- `WHUB_TestSchutzaktionen($id, $kategorie): string` -- löst zu Testzwecken sofort alle
  aktiven Schutzaktionen des angegebenen Alarmtyps aus (`sturm`/`hagel`/`starkregen`/
  `gewitter`/`schnee`/`hitze`), unabhängig von einer echten Warnung, vom Standort-Filter und
  vom Mindest-Schweregrad -- reiner Aktor-Test. Im Formular als sechs Schaltflächen im
  Schutzaktionen-Panel verfügbar.
- `WHUB_GetHistory($id, $limit = 100): string` -- JSON-Liste der zuletzt gepushten
  Warnungen UND Entwarnungen (newest first, bis zu 500 Einträge gespeichert, unabhängig
  davon, ob Push gerade aktiv war) -- für eigene Auswertungen/Skripte.

## IPSView & eigene Dashboards

WarnHub legt vier eigene Statusvariablen an (Aktive Warnungen, Höchster Schweregrad, Status,
Letzte Prüfung) -- Anknüpfungspunkt für ein selbst gebautes Dashboard, z. B. mit IPSView.
IPSView kennt keinen eigenen Push-Kanal, sondern baut Views ausschließlich aus vorhandenen
Symcon-Objekt-IDs zusammen -- eine gesonderte Einrichtung in WarnHub selbst ist deshalb nicht
nötig, die Variablen stehen automatisch im Objektbaum der Instanz.

## Grenzen

- Liegt zu einer Meldung keine Geometrie vor (selten, meist ältere/administrative
  Meldungstypen), wird sie sicherheitshalber **nicht** automatisch zugeordnet -- keine
  geratene Präzision.
- Die direkte DWD-Anbindung braucht die PHP-Erweiterungen `curl` und `ZipArchive`. Fehlen
  sie, liefert NINA weiterhin DWD-Wetterwarnungen, nur ohne das zusätzliche Detail. Wie PHP-
  Erweiterungen nachinstalliert werden, hängt vom Betriebssystem der Symcon-Installation ab --
  offizielle Installationsanleitungen:
  [Windows](https://www.symcon.de/de/service/dokumentation/installation/windows/),
  [macOS](https://www.symcon.de/de/service/dokumentation/installation/macos/),
  [Linux](https://www.symcon.de/de/service/dokumentation/installation/linux/),
  [Docker](https://www.symcon.de/de/service/dokumentation/installation/docker/),
  [Raspberry Pi](https://www.symcon.de/de/service/dokumentation/installation/raspberry-pi/),
  [Synology](https://www.symcon.de/de/service/dokumentation/installation/synology/),
  [QNAP](https://www.symcon.de/de/service/dokumentation/installation/qnap/). Bei SymBox oder
  einem Catan-Controller ist das nicht nötig -- dort ist PHP fest vorkonfiguriert.
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
- Die eigene Wetterstation braucht Variablen, die exakt "Windböe" bzw. "Regenrate" heißen
  (wie bei Froggit-Modulen üblich) -- andere Fabrikate lassen sich zwar per Instanz-ID manuell
  eintragen, liefern aber nur dann Werte, wenn sie entsprechend benannte Variablen besitzen.
  Die automatische Suche findet aktuell nur Froggit-Instanzen.
- Schutzaktionen warten grundsätzlich bis kurz vor dem Gültigkeitsbeginn einer Warnung (siehe
  oben) -- Meldungen ganz ohne Zeitangabe (kommt selten vor) lösen weiterhin sofort aus, da es
  dann nichts zum Abwarten gibt.
- GeoSphere Austria prüft serverseitig, ob eine Warnung an der abgefragten Koordinate gilt --
  WarnHub kennt die zugrundeliegende Gemeinde-Fläche selbst nicht und platziert das Ergebnis
  deshalb als kleinen Kreis exakt an dieser Koordinate. Ein Standort direkt an einer
  Gemeindegrenze kann eine Warnung im nur wenige hundert Meter entfernten Nachbarort dadurch
  knapp verpassen.
- BAFU-Hochwasserdaten decken nur Fliessgewässer und Seen von nationalem Interesse mit eigener
  Messstation ab (rund 180 Stationen schweizweit) -- kleinere Bäche ohne Station liefern keinen
  Wert. Die Schweiz kennt aktuell keine vergleichbare amtliche, öffentlich zugängliche API für
  allgemeine Unwetterwarnungen (Sturm/Hagel/Starkregen) -- dafür bleibt Meteoalarm vorerst die
  einzig verfügbare Quelle.
- Die VKF-Hagelschutz-Anbindung ist **BETA und ungetestet**: das Protokoll ist aus der
  offiziellen VKF-Dokumentation und dem Quellcode eines aktiven Community-Adapters gebaut,
  aber mangels eigener Signalbox nicht live gegengeprüft -- anders als jede andere Quelle in
  diesem Modul. Setzt eine physisch bei einem konkreten Schweizer Gebäude registrierte
  Signalbox voraus (hagelschutz-einfach-automatisch.ch, kein reiner Software-Zugang).
- Telegram- und Pushover-Push sind ebenfalls **ohne eigenen Bot-/Account-Zugang nicht live
  gegengeprüft** -- die aufgerufenen Funktionen (`TB_SendMessage` bzw. `TUPO_SendMessage`)
  stammen aber direkt aus dem echten Quellcode der jeweiligen, etablierten Symcon-Module
  (nicht nur deren Dokumentation), das Risiko ist damit geringer als bei der Hagelschutz-Beta.
  Voraussetzung ist jeweils eine bereits eingerichtete TelegramBot- bzw. Pushover-Instanz --
  WarnHub bindet nur an, richtet den Bot/Account selbst nicht ein. Telegram kennt in der
  aufgerufenen Funktion keinen separaten Titel; Titel und Text werden deshalb zu einer
  Nachricht zusammengefasst. Der Signalton (oben einstellbar) gilt nur für WebFront/
  Kachel-Visualisierung -- Telegram und Pushover verwenden ihre eigene, in der jeweiligen App
  konfigurierte Benachrichtigungstonauswahl.
  Rückmeldungen zur Funktionsfähigkeit sind ausdrücklich willkommen.

## Lizenz

PolyForm Noncommercial 1.0.0 -- private/nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Vollständiger Text:
[LICENSE](LICENSE). Spenden sind willkommen: [paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
