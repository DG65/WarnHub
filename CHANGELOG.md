# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](CHANGELOG-Archiv.md)

## 1.17.0 (2026-10-01)

- Neu: **Notification Control als sechster Push-Kanal.** Praxis-Fund
  tomfes (Symcon-Forum, 01.10.2026): ein Gerät, das nur über die
  IPSView-App eingeloggt ist, kann bei KEINER WebFront- oder Kachel-
  Visualisierung-Instanz als Konfigurator eingetragen sein --
  `WFC_PushNotification`/`VISU_PostNotificationEx` (WarnHubs bisherige
  Push-Kanäle) erreichen so ein Gerät strukturell nie, unabhängig davon,
  welche WebFront-Zeile aktiviert ist. Notification Control verwaltet
  Geräte dagegen unabhängig vom Konfigurator-Typ.
  "🔎 Push-Ziele suchen" findet eine vorhandene Notification-Control-
  Instanz jetzt automatisch (Typ "Notification Control" in der
  Push-Ziele-Tabelle). Push geht an jedes Gerät mit mindestens einem
  aktiven Konfigurator -- bei mehreren aktiven Konfiguratoren je Gerät
  (WebFront UND Kachel-Visualisierung UND IPSView gleichzeitig möglich)
  bewusst einmal je Konfigurator, da Notification Control keine
  geräteweite Sammeladresse kennt.
  `NC_GetDevices()`/`NC_PushNotification()` sind offiziell UNDOKUMENTIERT
  (kein Eintrag in der Symcon-Modulreferenz) -- live gegen eine echte
  Instanz getestet (01.10.2026, 12 echte Geräte, echter Testpush).
  Wichtiger Live-Fund dabei: der zweite Parameter von
  `NC_PushNotification()` ist die Konfigurator-Instanz-ID aus der
  Geräte-eigenen `Visualizations`-Zuordnung, NICHT die geräteeigene `ID`
  (Letztere lieferte im Test `false`); die Rückgabe ist außerdem keine
  Bool, sondern eine Benachrichtigungs-ID bei Erfolg -- Erfolgsprüfung
  deshalb `!== false`, nie `=== true`.
- Test: neuer Prüfstand `.tools/test-notification-control-push.php` (20
  Prüfungen, u. a. mit der wörtlichen, anonymisierten Geräteliste aus dem
  Live-Test); `.tools/test-discovery.php` um die neue Discovery-Erkennung
  erweitert.

## 1.16.0 (2026-09-22)

- Neu: **Alertswiss** (alert.swiss, Bundesamt für Bevölkerungsschutz BABS)
  als weitere Schweizer Datenquelle -- das Schweizer Pendant zu NINA:
  kantonale Feuerverbote, Waldbrand, Trockenheit, Fels-/Bergsturz und
  weitere Zivilschutz-Meldungen, nicht nur Wetter. Anders als Meteoalarm
  liefert der Feed echte Polygon-/Kreis-Geometrie je Meldung -- Abgleich
  läuft koordinatengenau über das bestehende Umkreis-Matching, kein
  Namensabgleich. Ist die Quelle aktiv, übernimmt sie Schweizer Standorte
  automatisch von Meteoalarm (analog zur direkten DWD-/GeoSphere-Austria-
  Anbindung). Endpunkt live geprüft 22.09.2026 (15 aktive Meldungen);
  `technicalTestAlert`/`testAlert` (CAP status=Test/Exercise-Äquivalent,
  im Feed real als dauerhafter "Cap Test Event" vorhanden) wird von Anfang
  an gefiltert. Die Quelle liefert kein Gültig-bis-Datum -- eine aus dem
  Feed verschwundene Meldung räumt sich wie bei BAFU/SED über das
  bestehende Verfahren automatisch auf. Landesweite Meldungen ohne eigene
  Geometrie (Feld `nationWide`) bekommen einen groben Kreis über die ganze
  Schweiz statt verworfen zu werden -- dieser Zweig ist mangels
  Live-Beispiel ausdrücklich ungetestet, Rückmeldungen willkommen.
  Anfrage baslerleckerli, Symcon-Forum, 22.09.2026.
- Test: neuer Prüfstand `.tools/test-alertswiss-ch.php` (echte Feed-Struktur
  als Fixture, inkl. Testalarm-Filterung, Polygon-/Kreis-Parsing,
  Meteoalarm-Ausschluss).

## 1.15.1 (2026-09-21)

- Die Verbindungs-Statuszeilen aus 1.15.0 folgen jetzt der Farbregel des
  Verbunds (SUITE.md 21.09.2026): eine automatisch übernommene,
  funktionierende Verbindung (✅ mit 🔗) ist grün (`0x2E8B3D`), ⛔ rot
  (`0xFF0000`), alles andere (✏️ von Hand, ℹ️, ⚠️) Standardfarbe (`-1`).
  Ein ⚠️ mit 🔗-Teil bleibt bewusst ungefärbt. Caption UND Farbe kommen aus
  einer Stelle (`statusLineColor()`/`statusLabelItem()`/`setStatusLabel()`),
  im Formular wie bei jedem `UpdateFormField()` -- die Farbe wechselt also
  beim Umwählen im `onChange` der Wetterstation mit. Die Systemstandort-Zeile
  ist als automatische Übernahme (🔗) gekennzeichnet und damit grün.
- Test: `.tools/test-verbindungs-status.php` auf 79 Prüfungen erweitert
  (Farbe je Zustand, im Formular und beim Aktualisieren).

## 1.15.0 (2026-09-21)

- Neu: **Verbindungs-Statuszeilen** nach der neuen Verbund-Formularregel
  (SUITE.md 21.09.2026: eine automatisch aufgebaute Verbindung zeigt live,
  ob sie steht). Vier Zeilen im Konfigurationsformular, alle mit den
  Zuständen ✅ / ⚠️ / ℹ️ / ⛔ und dem Hinweis, ob ein Wert automatisch
  (🔗) oder von Hand (✏️) gewählt wurde:
  - **Push-Ziele** (`WebFrontStatusLabel`): nennt die tatsächlich
    funktionierenden Ziele. ⛔ bei aktivem E-Mail-Ziel ohne Zieladresse,
    ⚠️ bei aktivem Ziel, dessen Instanz nicht mehr existiert, oder wenn
    keines aktiviert ist -- bisher zeigte die Zeile in all diesen Fällen
    ein "✅", obwohl dort nichts ankam.
  - **Systemstandort** (`SystemLocationStatusLabel`): Instanz, Koordinaten
    und Land der Symcon-Kerninstanz "Standort"; ⛔, wenn eine Quelle ihn
    braucht (eigene Wetterstation, Hagelschutz Schweiz), aber keiner
    eingetragen ist.
  - **Eigene Wetterstation** (`WetterstationStatusLabel`): Variablenname,
    -ID, aktueller Wert und Herkunft je Größe (Wind/Regen), über dieselbe
    `resolveWetterstationSource()`-Logik wie der Abruf. ⚠️ bei nur einer
    gefundenen Größe oder gelöschter Instanz. Die drei Auswahlfelder
    führen die Zeile per `onChange` (`WHUB_OnChangeWetterstation()`) der
    AUSWAHL nach, nicht erst dem Speicherstand.
  - **Mobile Live-Standorte** (`MobileStandorteStatusLabel`): aktuelle
    Live-Position und Quelle je mobilem Standort; ⚠️, wenn eine
    Live-Variable fehlt oder nur eine Achse gesetzt ist (WarnHub fiel
    dann bisher stumm auf die festen Koordinaten zurück).
  Alle vier werden im Hintergrund-Abruf (`Poll()`) und nach den
  jeweiligen Such-Knöpfen aufgefrischt.
- Intern: die Wind-/Regen-Idents der Wetterstation stehen jetzt einmal in
  den Konstanten `WETTERSTATION_WIND_IDENTS`/`WETTERSTATION_REGEN_IDENTS`
  (vorher doppelt in Abruf und Auto-Rückstellung); `getSystemLocation()`
  liefert zusätzlich die Instanz-ID; `hasWetterstationConfigured()` ersetzt
  die dreifach kopierte Bedingung in `Poll()`/`hasAnyActiveSource()`.
- Unverändert bewusst: die Wetterstations-Suche schreibt ihr Ergebnis
  weiterhin per `UpdateFormField('value')` in die Auswahlfelder -- eine
  ausdrücklich ausgelöste, sicherheitsrelevante Vorbelegung, die erst mit
  "Übernehmen" wirksam wird, kein still ersetzter Eingabewert.
- Test: neuer Prüfstand `.tools/test-verbindungs-status.php` (57
  Prüfungen, alle Zustände je Zeile plus Formular-Verdrahtung); ältere
  Prüfstände um die nötigen Symcon-Attrappen ergänzt.

## 1.14.2 (2026-09-16)

- Fix: eine Übungs-/Testmeldung einer Quelle kam bisher unverändert wie
  eine echte Warnung durch ("ACHTUNG! TEST TEST ... Heute scheint der
  Mond."). WarnHub prüfte das CAP-Standardfeld `status`
  (Actual/Exercise/System/Test/Draft -- eigens für genau diese
  Unterscheidung Teil der CAP-1.2-Spezifikation) bisher an keiner Stelle.
  Neue `isCapStatusActual()` wird jetzt in `fetchNinaDetail()` (NINA),
  `parseCapXml()` (direkte DWD-Anbindung) und `parseMeteoalarmAtom()`
  (Meteoalarm) angewendet -- alles außer `Actual` wird verworfen, ein
  fehlendes Feld (nicht jede Quelle liefert es) weiterhin wie bisher als
  echte Meldung behandelt. Live gegen NINA (`"status":"Actual"` im JSON)
  und Meteoalarm (`<cap:status>Actual</cap:status>` im Atom-Feed)
  verifiziert, dass das Feld tatsächlich vorhanden ist. GeoSphere Austria
  nutzt keine CAP-Struktur und liefert kein vergleichbares Feld, bleibt
  deshalb unverändert. Praxis-Fund ralf, Symcon-Forum, 16.09.2026.

## 1.14.1 (2026-09-10)

- Härtung: Text aus externen Quellen (`headline`/`description`/
  `instruction`, gilt für alle Datenquellen gleichermaßen) wird jetzt
  zentral am Eingang von `processWarnings()` von HTML-Resten bereinigt
  (`sanitizeCapText()`) -- `<br>`-Varianten werden zu echten
  Zeilenumbrüchen, übrige Tags entfernt, HTML-Entities aufgelöst. Kein
  WarnHub-Bug: die amtliche NINA-Quelle lieferte beim bundesweiten
  Warntag 2026 ein rohes `<br/>` mitten im Beschreibungstext, das z. B.
  in `WFC_PushNotification` (kein HTML-Rendering) wörtlich sichtbar
  wurde -- unabhängig über zwei verschiedene Push-Kanäle (Symcon-App,
  Pushover) bestätigt. Praxis-Fund kronos/ralf, Symcon-Forum,
  10.09.2026. Behebt NICHT fehlenden Leerraum zwischen aneinander-
  gereihten Quelltextabschnitten (z. B. "...umfrage.deBeginn: ...") --
  das ließe sich nicht sicher von echtem, gewolltem Text ohne
  Leerzeichen unterscheiden.

## 1.14.0 (2026-09-09)

- NEU: die Kartenlinks am Ende jeder Kachel decken jetzt 17 europäische
  Länder ab (bisher nur D/A/CH) und richten sich automatisch nach dem
  Land JEDES aktiven Standorts -- auch mobiler. Neue Klassenkonstante
  `COUNTRY_MAP_LINKS` (Frankreich, Italien, Spanien, Niederlande, Belgien,
  Polen, Tschechien, Dänemark, Norwegen, Schweden, Finnland, UK, Irland,
  Portugal, zusätzlich zu D/A/CH), neues Attribut `StandortLaenderCodes`
  (JSON-Array der Ländercodes aller aktiven Standorte, in `Poll()` im
  Hintergrund über `refreshStandortLaenderCodes()` aktualisiert -- exakt
  dasselbe Cache-/Netzwerk-Muster wie schon `HeimLandCode` aus 1.13.0,
  KEIN Netzwerkaufruf beim Öffnen der Konsole). Reist ein mobiler
  Standort ins Ausland, taucht dessen Land automatisch mit auf, sobald
  der nächste Abgleich gelaufen ist. `officialMapLinksHtml()` bildet die
  Vereinigung aus Standort-Ländern und Ländern mit aktiver direkter
  Quelle; ist gar kein bekanntes Land ermittelbar, bleiben sicherheitshalber
  weiterhin D/A/CH als Auffangwert. Die 14 neuen Länder-URLs wurden
  bewusst NICHT einzeln live gegen jede Webseite verifiziert (anders als
  sonst in diesem Modul durchgehend praktiziert) -- reine
  Informationslinks ohne eingebetteten/geparsten Inhalt, Rückmeldungen zu
  falschen/veralteten Links willkommen. Dietmars Recherchewunsch
  09.09.2026: "wenn man in Europa auf Reisen ist ... auch im Ausland".

## 1.13.3 (2026-09-09)

- Fix: die Kartenlinks am Ende jeder Kachel (🇩🇪 DWD/🇦🇹 ZAMG/🇨🇭
  MeteoSchweiz) zeigten bisher immer alle drei fest, unabhängig davon,
  welche Länder-Quellen tatsächlich aktiviert waren -- z. B. der
  DWD-Link auch für einen Nutzer, der ausschließlich GeoSphere Austria
  aktiviert hat. `officialMapLinksHtml()` zeigt jetzt nur noch den Link
  zu einem Land, dessen direkte Quelle (NINA/DWD, GeoSphere Austria,
  BAFU/SED-Erdbeben/Hagelschutz-CH) tatsächlich aktiv ist. Ist gar keine
  davon aktiv (z. B. nur Meteoalarm/eigene Wetterstation), erscheinen
  weiterhin sicherheitshalber alle drei, statt eines leer wirkenden
  Bereichs. Praxis-Wunsch hfichtinger, Symcon-Forum, 09.09.2026.

## 1.13.2 (2026-09-09)

- NEU: "Kachel (Alle Warnungen)" kann jetzt wahlweise alle Karten von
  vornherein aufgeklappt zeigen -- neuer Schalter "AlleWarnungenAufgeklappt"
  im Panel "Prüfung & Status" (Standard: eingeklappt wie bisher). Das
  Ein-/Ausklappen per Klick bleibt trotzdem für jede Karte einzeln
  verfügbar, nur der Startzustand ändert sich. Praxis-Wunsch ruan,
  Symcon-Forum, 09.09.2026: "alles sofort sehen und lesen zu können".

## 1.13.1 (2026-09-09)

- Fix "Kachel (Alle Warnungen)": derselbe DWD-Reissue-Effekt, der bereits
  in 1.12.1 zu Push-/Schutzaktions-Schwärmen führte (der DWD vergibt bei
  seinen "Vorabinformationen vor Unwetter" für dieselbe andauernde Gefahr
  alle 15-30 Minuten eine neue Meldungs-ID statt eines Updates der alten),
  betraf bislang auch die Anzeige: mehrere, gleichzeitig im selben Poll
  vorliegende Reissues erschienen als mehrere fast identische, leicht
  unterschiedlich formulierte Karten für ein und dasselbe Ereignis --
  sowohl der offizielle DWD-Auftritt als auch ein zum Vergleich
  herangezogenes drittes Symcon-Modul zeigen dafür nur einen Eintrag.
  `processWarnings()` dedupliziert die `active`-Liste jetzt je Episode
  (Quelle+Ereignistyp+Standort) und behält nur die jeweils aktuellste
  Fassung (spätestes `effective`, sonst höherer Schweregrad) -- betrifft
  automatisch auch "Kachel (Übersicht)", "Kachel (Karte)" und
  `WHUB_GetActiveWarnings()`, da alle dieselbe Liste konsumieren.
  Push-Zustellung selbst war bereits seit 1.12.1 korrekt (nur ein Push je
  Episode), nur die Anzeige war betroffen. Praxis-Fund ruan, Symcon-Forum,
  09.09.2026.

## 1.13.0 (2026-09-09)

- NEU: "Datenquellen" nach D-A-CH gruppiert. "Allgemein" (grenzüberschreitend:
  Meteoalarm, eigene Wetterstation, Abfragetakt) bleibt immer offen, darunter
  je ein zuklappbares Länder-Panel (🇩🇪 Deutschland: NINA/DWD/PEGELONLINE/
  BfS-ODL/Waldbrand/Ozon; 🇦🇹 Österreich: GeoSphere Austria; 🇨🇭 Schweiz:
  BAFU/SED-Erdbeben/Hagelschutz-CH-BETA, Letzteres jetzt darin verschachtelt
  statt eigenes Top-Level-Panel). Dietmars Vorschlag 09.09.2026, angeregt
  durch hfichtingers Rückmeldung, dass ihn als österreichischen Nutzer die
  deutschen Quellen gar nicht interessieren.
- NEU: das Länder-Panel des über den Symcon-Systemstandort erkannten
  Heimatlands (reverseGeocodeStandort(), im Hintergrund über Poll()
  aktualisiert -- KEIN Netzwerkaufruf beim Öffnen der Konsole) klappt beim
  allerersten Öffnen automatisch auf. Eine spätere manuelle Wahl hat immer
  Vorrang.
- NEU: alle Panels merken sich jetzt selbst (PanelExpandedState), ob sie
  zuletzt auf- oder zugeklappt waren, und stellen das nach jedem Speichern
  wieder her -- kein "Formular scrollt sich von vorne auf" mehr. Der
  Sicherheitshinweis zu Fenster/Kofferraum bleibt bewusst davon
  ausgenommen (fest immer aufgeklappt). Dietmars Wunsch 09.09.2026.
- Fix: `hasAnyActiveSource()` prüfte die eigene Wetterstation nur über
  `WetterstationInstanceID`, nicht über die beiden manuellen Wind-/
  Regen-Variablen -- eine Instanz, die NUR über die manuellen Variablen
  (z. B. KNX/Netatmo/TFA) läuft, konnte bei abgeschalteten NINA/DWD
  ebenfalls fälschlich lahmgelegt werden. Beim Bau der D-A-CH-Gruppierung
  gefunden.

## 1.12.4 (2026-09-09)

- Fix: die 256-Byte-Kürzung (`truncateBytes()`) galt bisher zentral für
  ALLE Push-Kanäle gleichermaßen, wurde in `buildPushText()`/
  `buildPushTitle()` angewendet, bevor der Text überhaupt an den
  jeweiligen Kanal ging. Diese Grenze ist aber nachweislich nur eine
  Vorgabe von `WFC_PushNotification`/`VISU_PostNotificationEx`
  (WebFront/Kachel-Visualisierung) laut offizieller Symcon-Doku. Telegram
  (~4096 Zeichen erlaubt), Pushover (~1024 Zeichen) und vor allem E-Mail
  (keine bekannte Längenbeschränkung) bekamen bisher denselben
  künstlich gekappten Text. Die Kürzung passiert jetzt erst unmittelbar
  vor den beiden betroffenen Aufrufen in `pushToAllWebfronts()`, alle
  anderen Kanäle erhalten den vollen Text. Praxis-Fund hfichtinger,
  Symcon-Forum, 09.09.2026: "Ist das auch den 256 Zeichen geschuldet?
  [...] Mail kann auch mehr".

## 1.12.3 (2026-09-09)

- Fix: schaltet man NINA-Aggregation UND die direkten DWD-Wetterwarnungen
  bewusst ab (z. B. in Österreich/der Schweiz, wo andere Quellen die
  amtlichen Warnungen abdecken), legte die bisherige
  "gibt es überhaupt eine aktive Datenquelle"-Prüfung
  (`QuelleNina || QuelleDwd`, unverändert seit der allerersten Version)
  die KOMPLETTE Instanz lahm (`SetStatus(104)`, Poll-Timer auf 0) --
  obwohl z. B. GeoSphere Austria oder eine der sieben anderen seither
  dazugekommenen Quellen aktiv war. Neue, vollständige Prüfung
  (`hasAnyActiveSource()`) über alle zehn Ein/Aus-Datenquellen plus
  Hagelschutz-CH (URL-Property) und die eigene Wetterstation
  (Instanz-ID-Property), mit einem Drift-Schutztest, der künftig
  automatisch fehlschlägt, falls eine neue Datenquelle wieder vergessen
  wird. Praxis-Fund hfichtinger, Symcon-Forum, 09.09.2026.
- E-Mail-Push: eine Zieladresse kann jetzt mehrere Empfänger enthalten,
  mit Komma oder Semikolon getrennt -- WarnHub trennt die Adressen selbst
  auf und ruft `SMTP_SendMailEx()` für jede einzeln auf (das offizielle
  SMTP-Modul ist Symcon-intern/closed-source, sein Verhalten bei einem
  kombinierten Mehrfach-Empfänger-String war am Quellcode nicht
  verifizierbar). Ein Fehlschlag bei einer Adresse verhindert nicht den
  Versand an die übrigen. Praxis-Wunsch hfichtinger, Symcon-Forum,
  09.09.2026.

## 1.12.2 (2026-09-09)

- "Kachel (Alle Warnungen)": jede Karte lässt sich jetzt per Klick
  aufklappen und zeigt dann die vollständige Handlungsempfehlung, den
  genauen Gültigkeitszeitraum (Beginn + Ende) sowie die komplette amtliche
  Beschreibung -- unabgekürzt, XSS-sicher escaped, zeilenumbruch-sicher.
  Klick auf den vorhandenen "✕"-Ausblenden-Button klappt dabei bewusst
  NICHT gleichzeitig den Detailbereich auf (`stopPropagation`).
- Push-Text-Reihenfolge geändert: Handlungsempfehlung (CAP `instruction`)
  und Gültigkeitszeitraum stehen jetzt VOR der (oft langen) amtlichen
  Beschreibung, nicht mehr danach. `WFC_PushNotification`/
  `VISU_PostNotificationEx` kappen Text laut Symcon-Doku hart auf 256 Byte
  -- vorher konnte eine lange Beschreibung (bei DWD häufig) dieses Budget
  komplett aufbrauchen, sodass die eigentlich handlungsrelevante
  Empfehlung und die Gültigkeit im Push praktisch nie ankamen. Beide
  Änderungen zusammen: Praxis-Wunsch kronos, Symcon-Forum, 09.09.2026
  ("Push-Meldungen werden abgeschnitten" / "Detail-Text auch im Webfront
  abrufbar"). Der 256-Byte-Deckel selbst ist eine Plattformgrenze der
  genannten Symcon-Funktionen und lässt sich nicht umgehen -- die volle
  Information steht dafür jetzt vollständig in der Kachel.

## 1.12.1 (2026-09-08)

- Fix Push-/Schutzaktions-Schwall: der DWD vergibt bei seinen
  "Vorabinformationen vor Unwetter" (PVW) für dieselbe andauernde Gefahr
  alle 15-30 Minuten eine NEUE CAP-`identifier` statt eines Updates der
  alten (live beobachtet: 6 verschiedene Identifier für "Starkes Gewitter"
  binnen einer Stunde). Push-Dedup UND Schutzaktions-Auslösung hingen
  bisher ausschließlich an dieser rohen `identifier`, dadurch löste jedes
  Reissue erneut aus -- bei mehreren betroffenen Standorten (Zuhause +
  mobile Standorte) ein Schwall mehrerer Pushes für ein und dasselbe
  Ereignis. Dietmars Meldung 08.09.2026: "Mit jedem Push 10 Meldungen auf
  einmal". Neuer, stabiler Episoden-Schlüssel aus Quelle+Ereignistyp+
  Standort (`warningEpisodeKey()`) statt der wechselnden `identifier`:
  Reissues mit fortlaufender/überlappender Gültigkeit aktualisieren den
  Zustand nur still, ohne erneut zu pushen oder eine Schutzaktion erneut
  auszulösen. Eine ECHTE Lücke (die vorige Episode war bereits abgelaufen,
  bevor die neue beginnt) sowie eine Eskalation im Schweregrad lösen
  weiterhin wie gewohnt erneut aus.
- Fix Sprachauswahl bei NINA-aggregierten Meldungen: eine Gewitterwarnung
  (Ortenaukreis) erschien komplett auf Englisch, weil ihr deutschsprachiger
  `info`-Eintrag nicht exakt als `de-DE`/`de` geschrieben war und der
  bisherige exakte Stringvergleich sie deshalb verfehlte -- stillschweigender
  Rückfall auf den ersten, englischsprachigen Eintrag. Erkennung jetzt
  toleranter (Groß-/Kleinschreibung, umgebender Leerraum). Live an Dietmars
  eigener Instanz gefunden, nicht aus dem Forum.

## 1.12.0 (2026-09-07)

- NEU: mobiler Standort erkennt jetzt auch OVMS-native-Fahrzeuge
  (community Modul lorbetzki/net.lorbetzki.native.ovms, für OVMS-Boxen
  mit der älteren API V2). Dietmars eigener Fund 07.09.2026: bei der
  Recherche nach der Stellantis-Anbindung auf "OVMS native" gestoßen.
  Idents "location_latitude"/"location_longitude" sind im Quellcode FEST
  vorregistriert (kein dynamischer Fallback wie beim Rest der
  OVMS-Datenpunkte) -- verifiziert. Bewusst OHNE Schutzaktions-Anbindung
  wie bei den vier anderen Fahrzeug-Modulen: `RequestAction()` ist im
  Quellcode ein reiner Logging-Stub ohne echten Befehlsversand, trotz
  vorhandener Trunk-STATUS-Variable ("status_bt_open"/"status_tr_open")
  keine tatsächliche Fernsteuerung, kein Fenster überhaupt als Datenpunkt
  vorgesehen.

## 1.11.1 (2026-09-07)

- Dokumentation nachgezogen: drei Formular-Labels im Standorte-Panel
  nannten nach 1.11.0 noch immer nur "Tessie oder Geofency" statt aller
  sechs unterstützten mobilen-Standort-Quellen (waren zuvor nur in
  Code-Kommentaren/CHANGELOG erfasst, nicht im sichtbaren Formulartext).
  Neues Label nennt jetzt zusätzlich explizit, um welche Module es sich
  handelt und wo man sie findet (Store-Suchname bzw. GitHub-Repo) --
  Dietmars Nachfrage 07.09.2026: "um welche Module es sich handelt die
  wir angebunden haben".

## 1.11.0 (2026-09-07)

- NEU: mobiler Standort erkennt jetzt zusätzlich zu Stellantis auch
  Smartcar- (community Modul mb-stern/Smartcar, Symcon-Store-gelistet,
  40+ Marken), BMW ConnectedDrive- (community Modul, GUID identisch
  zwischen demel42-Original und Wolbolar-Fork) und Hyundai/Kia-Bluelink-
  Fahrzeuge (community Modul da8ter/Bluelink) automatisch. Nachtrag zur
  Forum-Recherche aus 1.10.0: bei genauerem Hinsehen liefern alle drei
  zusätzlich untersuchten Module (die schon für die Fenster-/Kofferraum-
  Recherche geprüft wurden) tatsächlich einen echten Standort-Datenpunkt
  mit stabilem Ident -- Smartcar/Bluelink nutzen dieselbe "Latitude"/
  "Longitude"-Konvention wie Stellantis, BMW eigene, markenspezifische
  Idents ("bmw_current_latitude"/"...longitude"). Alle drei bewusst NUR
  für den mobilen Standort genutzt, aus denselben Gründen wie bei
  Stellantis keine Schutzaktions-Anbindung (kein EnableAction() auf einer
  Fenster-/Kofferraum-Variable in irgendeinem der vier Module).
- Der bisherige Stellantis-spezifische Suchpfad in
  `DiscoverMobileStandorte()` wurde zu einer generischen, tabellengetriebenen
  GUID+Ident-Suche (`DISCOVERY_LATLON_IDENTS`) verallgemeinert, damit
  weitere Fahrzeug-/Fernzugriffs-Module künftig durch einen einzigen neuen
  Tabelleneintrag ergänzt werden können, statt vier nahezu identische
  Codeblöcke zu pflegen.

## 1.10.0 (2026-09-07)

- NEU: mobiler Standort erkennt jetzt auch Stellantis-Fahrzeuge (Opel u. a.
  ehemalige PSA-Marken, community Modul slausch/Symcon-Stellantis-Vehicles,
  GUID `{55719996-CD7E-4825-8B64-294601469EB5}`) automatisch. Anders als
  bei Tessie über die STABILEN Idents "Latitude"/"Longitude" statt über
  einen Namensabgleich -- robuster, da "Breitengrad"/"Längengrad" hier
  ohne unterscheidenden Namens-Prefix direkt unter der Fahrzeuginstanz
  liegen. GUID und Idents gegen den echten Quellcode verifiziert.
- Bewusst OHNE Schutzaktions-Anbindung (Fenster/Kofferraum schließen):
  das Stellantis-Modul ist Stand Version 0.4 ein reiner Auslese-Prototyp
  ohne jede Fernbefehls-Funktion (kein einziges `EnableAction()` im
  Quellcode) und warnt selbst ausdrücklich vor unbeaufsichtigten/
  sicherheitskritischen Automationen.
- Auf Dietmars Bitte zusätzlich im Symcon-Forum recherchiert, ob es
  ANDERE Fahrzeug-Module analog zu Tessie gibt, die Fenster/Kofferraum
  tatsächlich schließen können: vier reale Module wurden im Quellcode
  geprüft (Stellantis, Smartcar -- Store-gelistet, 40+ Marken, aktiv
  gepflegt bis v4.8, BMW ConnectedDrive, Hyundai/Kia Bluelink). Keines
  davon bietet eine echte Fenster-/Kofferraum-Schließfunktion -- überall
  nur Status (offen/zu), keine Aktion. Fenster-/Kofferraum-Fernsteuerung
  bleibt damit ein Tesla-/Tessie-Spezifikum, keine Symcon-Modul-Lücke.

## 1.9.0 (2026-09-07)

- Schutzaktionen-Panel: neuer, unübersehbarer Sicherheitshinweis zu
  "Fenster schließen" und "Kofferraum/Heckklappe schließen" -- als eigenes,
  standardmäßig aufgeklapptes Panel ganz oben, statt wie bisher nur
  versteckt im "Welche Felder brauche ich für welchen Aktionstyp?"-Popup,
  das ein Nutzer erst aktiv anklicken musste. Dietmars ausdrücklicher
  Wunsch 07.09.2026: "Da weder das Fahrzeug noch das SmartHome erkennen
  kann, ob sich jemand im Gefahrenbereich des Schliessmechanismusses
  befindet, müssen wir diese Stelle im Formular so stark hervorheben, dass
  der Fokus des Nutzers auf diese Einstellungen gelenkt wird." Der Text
  beschreibt die konkrete Gefahr (weder Fahrzeug noch WarnHub können eine
  Person/Körperteil im Bewegungsbereich der schließenden Scheibe/Klappe
  erkennen, keine Einklemmschutz-/Hinderniserkennung wie bei vielen
  Garagentoren) und rät ausdrücklich, die Funktion im Zweifel nicht zu
  nutzen. Der bestehende Hinweis im Hilfe-Popup (Teslas Kofferraum-Befehl
  als reiner Umschalter ohne Richtung) bleibt zusätzlich bestehen und
  verweist jetzt auf das neue Panel.

## 1.8.3 (2026-09-07)

- Fix eigene Wetterstation (Froggit/Ecowitt): neuere Gateway-Generationen
  mit Piezo-Regensensor (z. B. WS90) senden gar kein "rainratein"-Feld
  mehr, nur noch die "*_piezo"-Feldfamilie -- der Froggit-Quellcode legt
  für jedes Feld, dessen Name "rain" enthält, per generischem Catch-all
  eine eigene Variable an (Ident UND Anzeigename = roher, unübersetzter
  Gateway-Feldname). "rrain_piezo" ist darunter laut Ecowitts eigener
  Protokolldokumentation die aktuelle Regenrate (die anderen --
  erain/hrain/drain/wrain/mrain/yrain_piezo -- sind kumulierte Zeiträume,
  kein Rate-Wert, deshalb bewusst nicht mitgeprüft). Praxis-Fund ralf,
  Symcon-Forum, 07.09.2026: hatte trotz vollständiger Froggit-Instanz
  (Windböe korrekt erkannt, aber keine "Regenrate"-Variable) weiterhin
  eine "Instanz gefunden, aber ohne die benötigten Felder"-Meldung
  gesehen.
- Dabei einen zweiten, unabhängigen Fund behoben: `fetchWetterstation()`
  und `checkWetterstationAutoRestore()` verließen sich für eine Froggit-
  Instanz beim eigentlichen Auslesen (nicht nur bei der Suche) bisher
  AUSSCHLIESSLICH auf den (sprachabhängigen, umbenennbaren) Anzeigenamen
  "Windböe"/"Regenrate" -- die robusteren Idents "windgustmph"/
  "rainratein" fehlten dort im Ident-Rückfall komplett, obwohl
  `DiscoverWetterstation()` schon seit dem 05.09.2026 genau deshalb auf
  Ident-Abgleich umgestellt worden war. Beide Stellen sind jetzt
  konsistent.
- Telegram-Push (`TB_SendMessage`) auf Nachfrage im Forum noch einmal
  geprüft: keine Code-Änderung, der Aufruf ist korrekt (identisch mit dem
  offiziellen TelegramBot-Modul selbst). Ein per Community-Modul
  (Fremdmodul, nicht das offizielle symcon/TelegramBot) eingebundener
  Telegram-Bot wird von WarnHub nicht unterstützt.

