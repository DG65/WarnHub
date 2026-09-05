# WarnHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-1.0.3-informational)
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
- **Eigene Wetterstation** -- optional, unabhängig von allen übrigen Quellen: löst aus,
  sobald die lokal gemessene Windböe oder Regenrate den eigenen Schwellwert überschreitet.
  Beide gestuft in je drei Schwellwerten (Moderate/Severe/Extreme -- Wind Standard 40/65/90
  km/h, Regen Standard 15/25/40 mm/h, beide an DWDs eigene Warnstufen angelehnt) -- eine
  Markise ist windempfindlicher als ein Raffstore, jede Schutzaktions-Zeile wählt über ihr
  "Ab Schweregrad"-Feld selbst, ab welcher Stufe sie reagiert (Popups "Welchen Schwellwert
  wähle ich?" helfen bei der Wahl). Sendet -- anders als bei einer amtlichen Warnung nicht
  über ein Cancel der Quelle, sondern anhand des eigenen Verlaufs erkannt -- auch eine echte
  "✅ Entwarnung"-Push, sobald Windböe/Regenrate wieder unter dem Schwellwert liegen. Ein
  Sicherheitsnetz für den Fall, dass amtliche Warnungen ein tatsächlich lokal auftretendes
  Ereignis nicht oder nicht rechtzeitig melden. Eine Objektbaum-Suche findet eine Froggit-
  (Ecowitt-Protokoll, deckt auch als Sainlogic/HP1000SE/WH3000SE vertriebene Ecowitt-
  Hardware ab), Sainlogic/ELV- (Wolbolar/
  IPSymconWeatherStation, Wunderground-Protokoll) oder Meteobridge/Meteohub-Instanz
  (Datenlogger-Aggregator, deckt zusätzlich weitere Marken wie DAVIS ab) automatisch --
  Windgeschwindigkeiten werden dabei unabhängig vom Quellprofil (km/h oder m/s) korrekt
  normiert. Findet sich keines der drei Module, hilft als letzter Rückfall eine systemweite
  Suche nach dem passenden Symcon-Standardprofil (z. B. eine bereits profilierte
  KNX-Variable). Für jedes andere Fabrikat (KNX ohne zugewiesenes Profil, Netatmo, TFA,
  Homematic, ...) lassen sich Wind- und Regen-Variable stattdessen manuell auswählen, auch
  gemischt (z. B. Wind von einer KNX-Wetterstation, Regen vom Froggit-Gateway). Optional
  (standardmäßig AUS) stellt WarnHub eine durch die eigene Wetterstation ausgelöste
  Raffstore-/Markisen-/Garagentor-Aktion automatisch zurück, sobald Wind UND Regen seit 20
  Minuten durchgehend wieder unter der Moderate-Schwelle liegen -- die einzige Ausnahme von
  "keine automatische Rückstellung" im ganzen Modul, weil nur die eigene Wetterstation
  einen fortlaufenden, lokalen Live-Wert liefert. Prüft vor dem Zurückstellen, ob der Stand
  seitdem von Hand verändert wurde, und überschreibt in dem Fall nicht. Jede Rückstellung
  wird in der Warnungs-Historie protokolliert; eine noch anstehende Rückstellung steht als
  eigene Statuszeile im Panel "Prüfung & Status" (nur sichtbar, wenn tatsächlich etwas
  ansteht).
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

Verschärft sich eine bereits gemeldete Warnung (z. B. DWD stuft von Moderate auf Severe
hoch), kommt eine erneute Push-Benachrichtigung -- eine Abstufung dagegen nicht, um nicht
unnötig zu beunruhigen. Der Push-Text enthält, falls vorhanden, auch die Handlungsempfehlung
der Quelle (CAP-Feld `instruction`, z. B. "Meiden Sie den Aufenthalt im Wald"). Die
Benachrichtigung lässt sich außerdem für eine Weile pausieren ("Ruhephase", 1/4/24 Stunden --
z. B. Urlaub, Feier, Nachtruhe): Erkennung, Warnungs-Historie und Schutzaktionen laufen dabei
unverändert weiter, nur die Zustellung selbst ist stumm; ein manueller Testklick kommt
trotzdem an. Eine bereits abgelaufene Warnung zählt außerdem nicht mehr als aktiv, auch
wenn die Quelle sie verzögert oder fehlerhaft weiterliefert.

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
- `WHUB_SnoozePush($id, $minuten): string` -- pausiert die Push-Benachrichtigung für die
  angegebene Dauer (z. B. Urlaub, Feier, Nachtruhe). Erkennung, Warnungs-Historie und
  Schutzaktionen laufen unverändert weiter -- nur die Zustellung selbst pausiert.
  `WHUB_TestPush()` bleibt davon ausgenommen. Im Formular als Schaltflächen (1/4/24 Std.)
  verfügbar.
- `WHUB_CancelSnooze($id): string` -- hebt eine laufende Push-Pause vorzeitig auf.

## IPSView & eigene Dashboards

WarnHub legt vier eigene Statusvariablen an (Aktive Warnungen, Höchster Schweregrad, Status,
Letzte Prüfung) -- Anknüpfungspunkt für ein selbst gebautes Dashboard, z. B. mit IPSView.
IPSView kennt keinen eigenen Push-Kanal, sondern baut Views ausschließlich aus vorhandenen
Symcon-Objekt-IDs zusammen -- eine gesonderte Einrichtung in WarnHub selbst ist deshalb nicht
nötig, die Variablen stehen automatisch im Objektbaum der Instanz.

## Fertige WebFront-Kacheln

Zwei weitere Variablen enthalten fertiges, eigenständiges HTML -- kein eigenes Bauen nötig,
einfach im Objektbaum in den Bereich des WebFronts verlinken:

- **Kachel (kompakt)** -- ein Badge mit Farbkreis (Signalfarbe nach höchstem aktivem
  Schweregrad, grün = keine aktive Warnung), Anzahl und "zuletzt geprüft"-Zeitangabe. Für ein
  kleines Kachel-Raster.
- **Kachel (Übersicht)** -- Liste der aktuell aktiven Warnungen als eigene Karten (Icon,
  Ereignis, Standort, Gültigkeitsende), bis zu 8 gleichzeitig, darüber ein "+N weitere"-Hinweis.

Beide im modernen, durchscheinenden "Liquid Glass"-Stil (macOS Tahoe), hell/dunkel-adaptiv
über `prefers-color-scheme` -- komplett eigenständiges HTML/CSS, keine externen
Abhängigkeiten. Werden automatisch nach jeder Prüfung aktualisiert, ohne eigene Einrichtung.
Ohne echtes WebFront nicht selbst gegenprüfbar -- Rückmeldungen willkommen.

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
- Die automatische Wetterstations-Suche erkennt drei Module: Froggit (Anzeigename "Windböe"/
  "Regenrate"), Wolbolar/IPSymconWeatherStation für Sainlogic/ELV (Ident "Windgust"/"rainin")
  und Meteobridge/Meteohub (Ident "Wind_Gust_KmH"/"Rain_Rate") -- alle drei quellcodeverifiziert,
  mangels eigenem Testgerät aber nicht live gegengeprüft, wie bei Telegram/Pushover. Findet
  sich keines davon, folgt als letzter Rückfall eine systemweite Suche nach dem Symcon-
  Standardprofil ("~WindSpeed.kmh"/"~WindSpeed.ms"/"~Rainfall") -- nur bei GENAU einem
  eindeutigen Treffer übernommen, sonst wird nichts geraten. Für jedes andere Modul/Fabrikat
  ganz ohne erkennbares Profil (KNX, Netatmo, TFA, Homematic, eigene MQTT-Wetterstation, ...)
  gibt es keine automatische Erkennung. Stattdessen lassen sich Wind- und Regen-Variable
  manuell auswählen (beliebige Symcon-Variable, keine Beschränkung auf ein bestimmtes Modul);
  eine gesetzte manuelle Variable hat Vorrang vor der Instanz-Erkennung. Windgeschwindigkeiten
  werden dabei immer normiert (m/s wird automatisch zu km/h umgerechnet, erkannt am
  Variablenprofil) -- eine Variable ganz ohne Profil wird als bereits km/h angenommen.
  KNX-Wetterstationen insbesondere: Symcons KNX-Integration kennt keine vordefinierte
  Gerätevorlage für Wetterstationen, Gruppenadressen werden frei benannt -- eine automatische
  Verbindung ist hier nur möglich, wenn der eigenen KNX-Variable manuell ein passendes Profil
  zugewiesen wurde, sonst funktioniert nur die manuelle Auswahl.
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

## Danksagung

Gewidmet Sepp Lausch, der zeitgleich an einer eigenen Symcon-Warnmeldungs-Anbindung
arbeitet -- der freundschaftliche Wetteifer hat WarnHub sichtlich gutgetan. 😉
