# Changelog

## 0.1.0-beta.30 (2026-09-04)

- Erneute Push-Benachrichtigung bei Eskalation: verschärft sich eine
  bereits gemeldete Warnung (z. B. DWD stuft von Moderate auf Severe
  hoch), kommt jetzt eine erneute Benachrichtigung -- vorher blieb eine
  einmal gepushte Warnung für immer stumm, egal wie sehr sie sich
  verschlimmerte. Eine Abstufung pusht bewusst NICHT erneut. Attribut
  `SeenWarnings` merkt sich dafür je Warnung/Standort zusätzlich die
  zuletzt gesehene Severity; ein späteres erneutes Ansteigen auf
  denselben Wert nach einer zwischenzeitlichen Abstufung zählt korrekt
  wieder als Eskalation. Neuer Zähler `escalated` in der Poll()-
  Zusammenfassung ("X neu gemeldet, Y hochgestuft, ...").
- Push-Text zeigt jetzt auch die Handlungsempfehlung der Quelle
  (CAP-Feld `instruction`, z. B. "Meiden Sie den Aufenthalt im Wald") --
  wurde bisher eingelesen, aber nirgends angezeigt.
- Beide beim Durchsehen des Codes auf Dietmars Frage "noch andere
  Ideen?" gefunden (04.09.2026), keine erfundenen Features -- echte,
  verifizierte Lücken im bestehenden Verhalten.

## 0.1.0-beta.29 (2026-09-04)

- Push-Ruhephase (Snooze): `WHUB_SnoozePush($id, $minuten)`/`WHUB_CancelSnooze($id)`
  pausieren die Push-Zustellung für eine wählbare Dauer (Formular: 1/4/24
  Std.-Schaltflächen) -- z. B. Urlaub, Feier, Nachtruhe. Pausiert bewusst
  NUR die Benachrichtigung: Erkennung, Warnungs-Historie und
  Schutzaktionen laufen unverändert weiter (ein Sturm fährt die Markise
  im Urlaub trotzdem ein, nur das Handy bleibt still). `WHUB_TestPush()`
  ist bewusst ausgenommen -- ein expliziter manueller Test soll immer
  ankommen, auch während einer Pause. Neue Statuszeile im Formular sowie
  ein 🔕-Hinweis in StatusText und beiden WebFront-Kacheln, solange eine
  Pause aktiv ist. Dietmars Wunsch 04.09.2026 ("Snooze/Ruhephase" als von
  mir vorgeschlagene, nicht sicherheitsrelevante Ergänzung).

## 0.1.0-beta.28 (2026-09-04)

- Windböe-Schwellwert der eigenen Wetterstation: drei konfigurierbare
  Stufen (Moderate/Severe/Extreme) statt einem pauschalen Wert (bisher
  fest 70 km/h, immer als "Severe" gemeldet). Standardwerte 40/65/90 km/h,
  an DWDs eigene Windwarnstufen angelehnt (Windböen ab 50, Sturmböen
  65-89, schwere Sturmböen 90-104 km/h) -- die Moderate-Stufe liegt
  bewusst darunter, weil Sachschutz mehr Vorlauf braucht als eine reine
  Personen-Warnung (EN-13561-Windwiderstandsklasse 2 für Markisen endet
  bei 38 km/h). `windSeverityForSpeed()` meldet jetzt die tatsächlich
  erreichte Stufe statt pauschal "Severe" -- jede Schutzaktions-Zeile
  nutzt dafür ihr bereits vorhandenes Feld "Ab Schweregrad", um selbst zu
  wählen, ab welcher Stufe sie reagiert. Neuer Standard für automatisch
  gefundene Markisen-Zeilen: "Ab Schweregrad" = Moderate statt Severe
  (windempfindlicher als ein Raffstore, das weiterhin bei Severe bleibt).
  Neues Popup "Welchen Schwellwert wähle ich?" im Datenquellen-Panel
  erklärt EN-13561-Windwiderstandsklassen und den DWD-Vergleich.
  Recherchiert und mit Dietmar abgestimmt 04.09.2026: der bisherige feste
  70-km/h-Wert war für Markisen (übliche Windwiderstandsklasse 1-3, bis
  28/38/49 km/h) zu hoch angesetzt.

## 0.1.0-beta.27 (2026-09-04)

- Drittes unterstütztes Wetterstations-Modul: elueckel/Symcon_Meteobridge_Meteohub
  (Meteobridge/Meteohub-Datenlogger, deckt als Aggregator zusätzlich weitere
  Marken wie DAVIS Vantage Vue ab) -- GUID/Idents "Wind_Gust_KmH"/"Rain_Rate"
  direkt gegen den echten Quellcode verifiziert, mangels Testgerät nicht live
  geprüft (wie zuvor die anderen beiden Module).
- Letzter Rückfall für die automatische Wetterstations-Suche: findet sich
  keines der drei bekannten Module, wird systemweit nach Variablen mit dem
  Symcon-Standardprofil ("~WindSpeed.kmh"/"~WindSpeed.ms"/"~Rainfall")
  gesucht -- deckt z. B. eine KNX-Wetterstation ab, wenn deren Variablen
  bereits profiliert sind. Nur bei GENAU einem eindeutigen Treffer je
  Wind/Regen übernommen, sonst wird nichts geraten; ein bereits manuell
  gesetztes Feld bleibt unangetastet.
- Wichtiger Korrektheits-Fix: Windgeschwindigkeiten werden jetzt unabhängig
  vom Quellprofil normiert (m/s wird automatisch mit Faktor 3.6 zu km/h
  umgerechnet, erkannt am Symcon-Variablenprofil). Live-Fund beim Bau der
  Meteobridge-Anbindung: Wolbolar/IPSymconWeatherStation liefert seine
  "Windgust"-Variable durchgängig in m/s, nicht km/h -- ohne Umrechnung
  hätte ein echter Sturm dort unbemerkt bleiben können (Rohwert stumm
  gegen den km/h-Schwellwert verglichen). Betrifft auch den manuellen
  Wind-Auswahlpfad aus beta.26 (z. B. KNX-Windsensoren liefern häufig m/s).
  Neue Funktion `readWindSpeedKmh()`, dediziert getestet.

## 0.1.0-beta.26 (2026-09-04)

- Zweites unterstütztes Wetterstations-Modul: Wolbolar/IPSymconWeatherStation
  (Sainlogic/Froggit/ELV über das Wunderground-Protokoll statt Ecowitt) --
  GUID und exakte Variablen-Idents ("Windgust"/"rainin", anders als Froggits
  Anzeigenamen "Windböe"/"Regenrate") direkt gegen den echten
  module.json-/module.php-Quellcode auf GitHub verifiziert. Neuer Helfer
  `findChildVariableByIdent()` sucht per PHP-Ident statt Anzeigename, robust
  gegen Übersetzungen. Mangels eigenem Testgerät nicht live gegengeprüft
  (wie Telegram/Pushover).
- Zwei neue manuelle Auswahlfelder ("Wind-Variable"/"Regen-Variable") für
  JEDES andere Fabrikat -- KNX, Netatmo, TFA, Homematic, eigene
  MQTT-Wetterstation etc. Recherche ergab: eine automatische Erkennung ist
  für KNX-Wetterstationen technisch nicht möglich (Symcons KNX-Integration
  kennt keine vordefinierte Gerätevorlage, Gruppenadressen werden in der
  ETS frei benannt) und für die übrigen genannten Module mangels
  einheitlicher Variablenbenennung ebenfalls nicht sinnvoll automatisierbar
  -- die manuelle Auswahl ist hier der einzig robuste Weg. Eine gesetzte
  manuelle Variable hat Vorrang vor der automatisch erkannten Instanz, Wind
  und Regen sind unabhängig voneinander wähl-/mischbar (z. B. Wind von
  einer KNX-Wetterstation, Regen vom Froggit-Gateway). Identifier des
  bestehenden Froggit-/instanzbasierten Pfads bewusst unverändert gelassen
  (kein Re-Push bereits gesehener Warnungen nach dem Update) -- nur der
  neue manuelle Pfad bekommt ein eigenes, unterscheidbares Format.
  Dietmars Fragen 04.09.2026 ("Welche Wetterstationsmodule gibt es...",
  "gibt es hierbei eine Chance die [KNX-Wetterstationen] automatisch zu
  verbinden?").

## 0.1.0-beta.25 (2026-09-04)

- Doku-Korrektur zur Installation der beiden WebFront-Kacheln (Formular,
  README, Forum-Entwurf): richtig ist "im Objektbaum in den Bereich des
  WebFronts verlinken", nicht "per Drag & Drop in ein WebFront/eine
  Kachel-Visualisierung ziehen" -- von Dietmar korrigiert, der das an
  seinem eigenen System tatsächlich so einrichtet.

## 0.1.0-beta.24 (2026-09-04)

- Zwei fertige WebFront-Kacheln (`~HTMLBox`-Variablen "Kachel (kompakt)" und
  "Kachel (Übersicht)") -- kein eigenes Bauen nötig, einfach per Drag & Drop
  ins WebFront ziehen. "Kachel (kompakt)" zeigt einen Farbkreis-Badge
  (Signalfarbe nach höchstem aktivem Schweregrad) + Anzahl + "zuletzt
  geprüft". "Kachel (Übersicht)" listet die aktiven Warnungen als eigene
  Karten (bis zu 8, darüber "+N weitere"). Modernes, durchscheinendes
  "Liquid Glass"-Design (macOS Tahoe), hell/dunkel-adaptiv über
  `prefers-color-scheme`, komplett eigenständiges HTML/CSS ohne externe
  Abhängigkeiten. Werden wie die IPSView-Statusvariablen direkt nach
  "Übernehmen" und nach jedem Poll() aktualisiert. Dietmars ausdrücklicher
  Wunsch 04.09.2026 ("eine oder auch mehrere Kacheln... im macOS Tahoe
  Stil"), aufgegriffen aus meiner eigenen früheren Einschätzung, dass dem
  Modul noch eine Visualisierungs-Kachel fehlt. Ohne echtes WebFront nicht
  selbst gegenprüfbar (Design lokal als Vorschau verifiziert, siehe
  .tools/test-tiles.php) -- Rückmeldungen willkommen.

## 0.1.0-beta.23 (2026-09-04)

- Warnungs-Historie: neues Attribut `WarnHistory` protokolliert jede neue
  Warnung UND jede Entwarnung (nicht nur die aktuell aktiven, siehe
  `WHUB_GetActiveWarnings()`) -- unabhängig davon, ob Push zum jeweiligen
  Zeitpunkt überhaupt aktiv war. Deckel bei 500 Einträgen, älteste zuerst
  raus (identisches Prinzip wie EMS' bewährtes `SpecialEventsLog`). Neue
  Funktion `WHUB_GetHistory($id, $limit = 100)` liefert die Liste
  newest-first als JSON, für eigene Auswertungen/Skripte -- bewusst kein
  eigenes Formularfeld dafür (analog zu EMS' `GetSpecialEvents()`, das
  ebenfalls rein als Funktionsvertrag existiert). Dritter und letzter Teil
  von Dietmars Wunsch 04.09.2026 "IPSView, Mehrkanal-Push und eine
  Warnungs-Historie packen wir nun an."

## 0.1.0-beta.22 (2026-09-04)

- IPSView-taugliche Statusvariablen: WarnHub war bisher komplett "headless"
  (nur Push + Konsolen-Statuszeile) -- vier neue Variablen (Aktive
  Warnungen, Höchster Schweregrad mit eigenem WHUB.Schweregrad-Profil,
  Status, Letzte Prüfung) sind jetzt der Anknüpfungspunkt für ein selbst
  gebautes Dashboard. Live-Fund 04.09.2026 (Dietmars Instanz #17903):
  IPSViewConnect hat keinen eigenen Push-/Geräteregistrierungs-Mechanismus,
  nur einen View-Cache -- IPSView baut Views ausschließlich aus
  vorhandenen Symcon-Objekt-IDs zusammen, eine gesonderte Einrichtung in
  WarnHub selbst ist deshalb nicht nötig. Werden sowohl direkt nach
  "Übernehmen" (letzter bekannter Stand, kein Warten auf den nächsten
  Poll-Zyklus) als auch nach jedem echten Poll() aktualisiert.
- `WHUB_TestSchutzaktionen($id, $kategorie)`: Schutzaktionen lassen sich
  jetzt je Alarmtyp einzeln testen (sechs Schaltflächen im
  Schutzaktionen-Panel, z. B. "🌪️ Sturm testen") -- löst SOFORT alle
  aktiven, zum Alarmtyp passenden Aktionen aus, unabhängig von einer
  echten Warnung, vom Standort-Filter und vom Mindest-Schweregrad. Reiner
  Aktor-Test ("tut die Aktion tatsächlich das Richtige?"), analog zu
  `WHUB_TestPush()` für den Push-Zustellweg. Dietmars ausdrücklicher
  Wunsch 04.09.2026, direkt im Anschluss an die IPSView-Arbeit -- zweiter
  und dritter Teil von "IPSView, Mehrkanal-Push und eine
  Warnungs-Historie packen wir nun an."

## 0.1.0-beta.21 (2026-09-04)

- Mehrkanal-Push: neben WebFront und Kachel-Visualisierung jetzt auch
  Telegram (offizielles Symcon-Modul symcon/TelegramBot, `TB_SendMessage`)
  und Pushover (Community-Modul timo-u/Symcon_Pushover, `TUPO_SendMessage`)
  als Push-Ziele. Beide Funktionssignaturen direkt gegen den echten
  module.php-Quellcode der jeweiligen GitHub-Repos verifiziert, nicht nur
  gegen deren README. Discovery/Formular/Aktivierung nutzen dieselbe,
  bereits bestehende WebFronts-Liste + denselben Such-Mechanismus wie
  WebFront/Kachel-Visualisierung -- nur zwei zusätzliche GUIDs und zwei
  zusätzliche Typ-Optionen, keine neue Infrastruktur. Telegram fasst
  Titel+Text zu einer Nachricht zusammen (kennt keinen separaten Titel);
  Pushover erhält Titel und Text getrennt sowie eine aus dem Schweregrad
  abgeleitete Priorität (Severe/Extreme -> hoch). Ohne eigenen Telegram-Bot/
  Pushover-Account nicht selbst live gegenprüfbar -- geringeres Risiko als
  bei der Hagelschutz-Beta, da beide Module etabliert und der Quellcode
  direkt eingesehen wurde, aber ausdrücklich als "ungetestet" in der Doku
  vermerkt. Dietmars ausdrücklicher Wunsch 04.09.2026 ("IPSView,
  Mehrkanal-Push und eine Warnungs-Historie packen wir nun an."), erster
  von drei Teilen.

## 0.1.0-beta.20 (2026-09-04)

- BETA, ausdrücklich als ungetestet gekennzeichnet: eigene VKF-Hagelschutz-
  Signalbox (Schweiz, hagelschutz-einfach-automatisch.ch) als Datenquelle,
  eigenes Panel. Protokoll aus der offiziellen VKF-PDF-Dokumentation UND
  dem Quellcode des aktiven ioBroker-Adapters ice987987/ioBroker.hagelschutz
  gegengeprüft (identischer Aufbau bestätigt) -- mangels eigener Signalbox
  konnte der Live-Abruf selbst aber nicht verifiziert werden, einzige
  Ausnahme von der sonst im Modul durchgehend befolgten "live
  verifizieren"-Regel. Die vollständige Poll-URL wird als ein Feld
  gespeichert statt selbst aus deviceId/hwtypeId zusammengesetzt --
  Vorbild ioBroker-Adapter, dessen offenes Issue #156 zeigt, dass sich das
  URL-Format zwischen Signalbox-Generationen unterscheiden kann. Dietmars
  ausdrücklicher Wunsch 04.09.2026, um auch Schweizer Symcon-Nutzern ohne
  eigenes Testgerät einen Weg zu bieten.
- README: Links zu den offiziellen Symcon-Installationsanleitungen aller
  gängigen Betriebssysteme (Windows/macOS/Linux/Docker/Raspberry
  Pi/Synology/QNAP) bei den PHP-Erweiterungen curl/ZipArchive ergänzt.

## 0.1.0-beta.19 (2026-09-04)

- Neue Datenquelle für die Schweiz: amtliche BAFU-Hochwasser-Gefahrenstufen
  über LINDAS (lindas.admin.ch, Linked-Data-Infrastruktur des Bundes, live
  gegen ~180 echte Messstationen geprüft). Anders als PEGELONLINE/BfS/
  eigene Wetterstation eine ECHTE behördliche Klassifikation (BAFUs
  offizielle 5-stufige Gefahrenstufen-Skala), keine Eigenkonstruktion --
  nur die Meldeschwelle ist einstellbar. Global wie PEGELONLINE: ein
  Abruf für alle Stationen, das bestehende geometrische Umkreis-Matching
  erledigt den Rest. Dietmars ausdrücklicher Wunsch 04.09.2026.
- Recherchiert, aber bewusst nicht gebaut: der Hagelschutz-Warndienst der
  VKF (hagelschutz-einfach-automatisch.ch) hat zwar eine gut dokumentierte
  REST-API (Poll-Endpunkt ohne Login, nur deviceId+hwtypeId), ist aber an
  eine physische, bei einem konkreten Schweizer Gebäude registrierte
  Hardware ("Signalbox") gebunden -- kein Software-Zugang, den man sich
  einfach beschaffen kann. unwetter.ch (privater Warndienst) hat gar keine
  Entwickler-Schnittstelle, nur SMS/E-Mail an Endkunden.

## 0.1.0-beta.18 (2026-09-04)

- Neue Datenquelle für Österreich: direkte Anbindung der GeoSphere Austria
  Warn API (warnungen.zamg.at, amtliche Quelle der österreichischen
  Wetterbehörde ZAMG, CC-BY-4.0, kein Zugangsschlüssel nötig). Anders als
  Meteoalarm liefert diese API koordinatengenaue Treffer direkt je
  Standort statt nur benannter Verwaltungsgebiete -- präziser, ohne
  eigenen Namensabgleich. Übernimmt für österreichische Standorte
  automatisch von Meteoalarm, sobald aktiviert (analog zur direkten
  DWD-Anbindung, die den entsprechenden NINA-Kanal für deutsche Standorte
  ersetzt). Vorausgegangen ist eine Recherche über Home Assistant, FHEM,
  ioBroker, openHAB und Loxone: keines dieser Systeme hat bisher eine
  Schweiz-Lösung, Österreich wird dort überwiegend über denselben ZAMG-
  bzw. den privaten UWZ-Aggregator gelöst. Dietmars Nachfrage 04.09.2026
  ("haben Österreich und die Schweiz keine eigenen Warn-APIs?").

## 0.1.0-beta.17 (2026-09-04)

- Neue, unabhängige Datenquelle: eigene Wetterstation (aktuell Froggit).
  Löst eigenständig aus, sobald die lokal gemessene Windböe oder Regenrate
  einen eigenen Schwellwert überschreitet -- ein Sicherheitsnetz für den
  Fall, dass amtliche Warnungen ein tatsächlich lokal auftretendes Ereignis
  nicht oder nicht rechtzeitig melden (Dietmars Wunsch 04.09.2026: "die
  Unwetterwarnungen können sich ja auch irren"). Neuer Knopf "Wetterstation
  suchen" -- findet eine passende Instanz automatisch, übernimmt sie aber
  NUR, wenn sie tatsächlich die benötigten Felder "Windböe" und
  "Regenrate" besitzt.
- Fix (Timing): Schutzaktionen feuerten bisher sofort bei Eingang einer
  Meldung, unabhängig davon, wann die Warnung laut CAP-Daten (`onset`)
  eigentlich beginnen sollte -- eine morgens eintreffende, aber erst für
  den Nachmittag gültige Sturmwarnung fuhr die Markise bereits morgens
  ein. Neue globale Einstellung "Vorlauf vor Gültigkeitsbeginn" (Standard
  30 Minuten): eine Aktion wartet jetzt bis kurz vor den tatsächlichen
  Beginn der Warnung. Warnungen ohne Zeitangabe sowie bereits akute/
  laufende Warnungen lösen weiterhin sofort aus. Die Push-Benachrichtigung
  selbst ist davon unberührt und bleibt sofort. Dietmars Nachfrage
  04.09.2026.
- Positionierung auf Deutschland, Österreich und Schweiz (D-A-CH)
  ausgeweitet: Meteoalarm deckte Österreich und die Schweiz technisch
  schon seit beta.12 mit ab, das war in Doku/README bisher nicht klar
  benannt -- jetzt in Kurzbeschreibung, Doku-Panel, README und
  Forum-Entwurf durchgängig als D-A-CH-Modul positioniert statt als
  reines Deutschland-Modul mit optionalem Europa-Zusatz.

## 0.1.0-beta.16 (2026-09-04)

- Neuer Knopf "Fahrzeug-/Standort-Variablen suchen" im Standorte-Panel:
  durchsucht den Objektbaum nach bekannten Positions-Variablenpaaren
  (Tessie "Fahrzeugposition – Breitengrad/Längengrad", Geofency "Current
  Latitude/Longitude") und legt je Fund einen bereits mit den
  Live-Variablen verknüpften, aktivierten mobilen Standort an -- kein
  manuelles Heraussuchen der Variablen-IDs mehr nötig. Erkennt zuverlässig
  mehrere Fahrzeuge gleichzeitig und unterscheidet die Fahrzeugposition
  sauber von Teslas Navigationsziel ("Zielposition", gleiche Wortendung,
  andere Bedeutung) sowie von Geofencys zusätzlicher, gleichnamiger
  Latitude/Longitude ohne "Current" (vermutlich Geofence-Zentrum statt
  Live-Position). Dietmars Nachfrage 04.09.2026.

## 0.1.0-beta.15 (2026-09-04)

- Radioaktivität-Einordnung: neues Popup "Was bedeutet dieser Wert?" im
  Datenquellen-Panel (gestaffelte Dosisleistung/Verweildauer-Tabelle, u. a.
  natürliches Untergrundniveau, WarnHubs Standard-Schwellwert, Jahres-
  Vorsorgewert der Bevölkerung, akute Schwelle) sowie eine wertabhängige
  Einordnung direkt im Meldungstext jeder BfS-ODL-Warnung ("Jahres-
  Vorsorgewert rechnerisch nach X Tagen erreicht"). Werte/Quellen:
  odlinfo.bfs.de -- eigene Orientierungsrechnung, ausdrücklich als solche
  gekennzeichnet, keine amtliche Tabelle. Dietmars Wunsch 04.09.2026.
- Dokumentations-Review nach den zahlreichen Erweiterungen seit beta.9:
  Der "Neu in Version X"-Banner zeigte noch den Text der allerersten
  Version -- jetzt aktualisiert auf die tatsächlichen Neuerungen (mobiler
  Standort, Push-Ziel-Filter, PEGELONLINE/BfS/Meteoalarm, neue
  Schutzaktionstypen). "Wozu dieses Modul?" und die Doku-Kurzbeschreibung
  erwähnten weiterhin nur Deutschland, obwohl Meteoalarm und mobile
  Standorte längst europaweite Nutzung ermöglichen -- korrigiert. Ein
  Hinweistext im Schutzaktionen-Panel behauptete weiterhin uneingeschränkt
  "wird aktiviert", obwohl Kofferraum/Heckklappe-Treffer seit beta.14
  ausnahmsweise inaktiv bleiben -- korrigiert. README.md komplett
  überarbeitet (fehlten: alle vier neuen Datenquellen, mobiler Standort,
  Push-Ziel-Filter, neue Schutzaktionstypen, deren Grenzen).

## 0.1.0-beta.14 (2026-09-04)

- Neuer Schutzaktionstyp "Kofferraum/Heckklappe schließen": löst jetzt doch
  sicher aus, nachdem die Semantik von Tessies "Tür-/Klappenstatus" live an
  Dietmars Fahrzeug verifiziert wurde (kommagetrennte Liste aktuell offener
  Klappen, z. B. "Frunk, Kofferraum", leer = alles zu). Die Aktion prüft vor
  jedem Auslösen zwingend eine zusätzliche Zustands-Variable und schaltet
  NUR, wenn "Kofferraum"/"Heckklappe" aktuell darin vorkommt -- ohne
  gültige Zustands-Variable wird gar nicht erst ausgelöst (Sicherheitssperre
  statt Raten), da Teslas Kofferraum-Befehl weiterhin ein reiner Umschalter
  ohne Richtung ist.
- Auto-Discovery findet "Heckklappe"-Treffer jetzt ebenfalls und verlinkt
  automatisch eine passende Zustands-Variable unter derselben Instanz
  (Namensbestandteil "klappenstatus"). Ohne automatisch gefundene Zustands-
  Variable bleibt die vorgeschlagene Zeile ausnahmsweise INAKTIV, statt sich
  unsicher scharf zu stellen.

## 0.1.0-beta.13 (2026-09-04)

- Neuer Schutzaktionstyp "Fenster schließen (z. B. Tesla)": schaltet eine
  Ziel-Variable auf "Ein" -- passt insbesondere zu Tessies eigener Aktion
  "Fenster schließen" (löst Teslas gerichteten `close_windows`-Befehl aus,
  sicher auch bei bereits geschlossenen Fenstern). Die automatische
  Objektbaum-Suche findet und aktiviert sie jetzt ebenfalls (Stichwort
  "Fenster schließen", bewusst NICHT nur "Fenster" -- sonst wären auch
  reine Fenster-offen-Sensoren betroffen). Voreingestellte Auslöser: Sturm,
  Hagel UND Starkregen (offene Fenster lassen bei Dauerregen genauso
  Wasser rein wie bei Sturm/Hagel).
- Bewusst NICHT unterstützt, mit Begründung im Formular: die Heckklappe/der
  Kofferraum lässt sich nicht automatisiert absichern. Recherche ergab:
  Teslas Kofferraum-Befehl (`actuate_trunk`) ist ein reiner Umschalter ohne
  Richtungsangabe -- ein automatisches Auslösen bei bereits geschlossener
  Klappe würde sie ÖFFNEN statt schließen. Dafür müsste zuerst der aktuelle
  Öffnungszustand bekannt sein (aktuell keine Symcon-Variable dafür,
  Live-geprüft an Dietmars beiden Tessie-Instanzen: 0 Telemetrie-Variablen
  vorhanden) -- das wäre eine Erweiterung des Tessie-Moduls, nicht von WarnHub.

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
