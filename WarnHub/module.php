<?php

declare(strict_types=1);

// WebFront-Modul-GUID -- offiziell verifiziert gegen den Quellcode des
// echten Symcon-Kernmoduls "Benachrichtigung"
// (github.com/symcon/Benachrichtigung/blob/main/Notification/module.php,
// Zeile mit WFC_PushNotification). NICHT verwechseln mit der
// Konfigurator-/Kachel-GUID {B5B875BB-...}, die braucht VISU_PostNotificationEx
// statt WFC_PushNotification.
define('WHUB_WEBFRONT_GUID', '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');

/**
 * Reine Geometrie-Helfer fuer das Umkreis-Matching. Eigene Implementierung,
 * da die Symcon-PHP-Sandbox kein Geo-Package mitbringt. Alle Koordinaten
 * durchgehend als [lat, lon] (Grad) -- CAP-Polygone liefern "lat,lon", waehrend
 * GeoJSON [lon,lat] liefert; die Umwandlung passiert beim Einlesen, nicht hier.
 */
class WHUB_Geo
{
    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @param array<array{0:float,1:float}> $ring Liste von [lat, lon] */
    public static function pointInPolygon(float $lat, float $lon, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        if ($n < 3) {
            return false;
        }
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $latI = $ring[$i][0];
            $lonI = $ring[$i][1];
            $latJ = $ring[$j][0];
            $lonJ = $ring[$j][1];
            $intersects = (($lonI > $lon) !== ($lonJ > $lon))
                && ($lat < ($latJ - $latI) * ($lon - $lonI) / (($lonJ - $lonI) ?: 1e-12) + $latI);
            if ($intersects) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** Minimalabstand Punkt-zu-Strecke, lokale Grad->km-Naeherung (fuer Umkreiszwecke ausreichend). */
    private static function distanceToSegmentKm(float $lat, float $lon, float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $kmPerDegLat = 111.32;
        $kmPerDegLon = 111.32 * cos(deg2rad($lat));

        $x = $lon * $kmPerDegLon;
        $y = $lat * $kmPerDegLat;
        $x1 = $lon1 * $kmPerDegLon;
        $y1 = $lat1 * $kmPerDegLat;
        $x2 = $lon2 * $kmPerDegLon;
        $y2 = $lat2 * $kmPerDegLat;

        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 < 1e-9) {
            return sqrt(($x - $x1) ** 2 + ($y - $y1) ** 2);
        }
        $t = max(0.0, min(1.0, (($x - $x1) * $dx + ($y - $y1) * $dy) / $len2));
        $projX = $x1 + $t * $dx;
        $projY = $y1 + $t * $dy;
        return sqrt(($x - $projX) ** 2 + ($y - $projY) ** 2);
    }

    /** @param array<array{0:float,1:float}> $ring */
    public static function distanceToPolygonKm(float $lat, float $lon, array $ring): float
    {
        $n = count($ring);
        if ($n < 2) {
            return INF;
        }
        $min = INF;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $d = self::distanceToSegmentKm($lat, $lon, $ring[$i][0], $ring[$i][1], $ring[$j][0], $ring[$j][1]);
            if ($d < $min) {
                $min = $d;
            }
        }
        return $min;
    }

    /**
     * Liefert den kuerzesten Abstand (km, 0 = innerhalb) eines Punkts zu einer
     * Liste von Polygon-Ringen und/oder Kreisen. null, wenn keine Geometrie
     * vorliegt (Aufrufer muss diesen Fall gesondert -- unpraezise -- behandeln).
     *
     * @param array<array<array{0:float,1:float}>> $rings
     * @param array<array{lat:float,lon:float,radiusKm:float}> $circles
     */
    public static function distanceToAny(float $lat, float $lon, array $rings, array $circles): ?float
    {
        $min = null;
        foreach ($rings as $ring) {
            if (self::pointInPolygon($lat, $lon, $ring)) {
                return 0.0;
            }
            $d = self::distanceToPolygonKm($lat, $lon, $ring);
            if ($min === null || $d < $min) {
                $min = $d;
            }
        }
        foreach ($circles as $c) {
            $d = max(0.0, self::haversineKm($lat, $lon, $c['lat'], $c['lon']) - $c['radiusKm']);
            if ($min === null || $d < $min) {
                $min = $d;
            }
        }
        return $min;
    }
}

class WarnHub extends IPSModule
{
    private const DOC_VERSION = '0.1.0-beta.11';
    private const NEWS_VERSION = '0.1.0-beta.11';
    private const LICENSE_URL = 'https://github.com/DG65/WarnHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/PLATZHALTER-warnhub-thread-folgt/00000';

    // Stichwörter für die automatische Schutzaktionen-Suche im Objektbaum
    // (Instanz-/Variablenname enthält eins der Wörter -> Aktionstyp-Vorschlag).
    private const DISCOVERY_KEYWORDS = [
        'raffstore' => ['raffstore', 'jalousie'],
        'markise' => ['markise', 'sonnenschutz'],
        'garage' => ['garage', 'garagentor'],
        'sirene' => ['sirene', 'hupe', 'buzzer', 'signalhorn'],
    ];

    private const SEVERITY_RANK = ['Unknown' => 0, 'Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4];
    private const SEVERITY_ICON = ['Unknown' => 'ℹ️', 'Minor' => 'ℹ️', 'Moderate' => '⚠️', 'Severe' => '🚨', 'Extreme' => '🆘'];

    // Kachel-Visualisierung -- Symcons zweite, neuere Push-fähige Oberfläche
    // NEBEN dem klassischen WebFront-Konfigurator. GUID gegen den offiziellen
    // Quellcode des Symcon-Kernmoduls "Benachrichtigung" verifiziert
    // (github.com/symcon/Benachrichtigung, Notification/module.php) -- dort
    // dispatcht dieselbe Stelle, die WFC_PushNotification für WHUB_WEBFRONT_GUID
    // aufruft, für DIESE GUID stattdessen an VISU_PostNotificationEx().
    private const KACHEL_VISU_GUID = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';

    // Kategorie-Zuordnung fuer Schutzaktionen: Stichwortsuche im event/headline-Text
    // (Deutsch, DWD/MoWaS-Vokabular -- siehe reale Beispiele in .tools/test-geo.php).
    private const CATEGORY_KEYWORDS = [
        'sturm'      => ['sturm', 'orkan', 'böen', 'boeen', 'windböen', 'windboeen'],
        'hagel'      => ['hagel'],
        'starkregen' => ['starkregen', 'dauerregen', 'hochwasser', 'flut', 'überflutung', 'ueberflutung'],
        'gewitter'   => ['gewitter', 'blitz'],
        'schnee'     => ['schnee', 'glätte', 'glaette', 'glatteis', 'eis'],
        'hitze'      => ['hitze'],
    ];

    // Kategorie-Kästchen der Schutzaktionen-Liste: Kategorie-Schlüssel (siehe
    // CATEGORY_KEYWORDS) -> [Property-Feldname, Spalten-Beschriftung]. Mehrere
    // Kästchen gleichzeitig ankreuzbar (Dietmars ausdrücklicher Wunsch
    // 04.09.2026 -- eine Markise soll mit EINEM Klick pro Auslöser statt
    // mehrerer Zeilen für Sturm+Hagel+... konfigurierbar sein). Kein Kästchen
    // angekreuzt = Aktion gilt für JEDE Kategorie (Ersatz für das frühere
    // "alle"-Select).
    private const CATEGORY_FIELDS = [
        'sturm'      => ['KatSturm', '🌪️ Sturm'],
        'hagel'      => ['KatHagel', '🧊 Hagel'],
        'starkregen' => ['KatStarkregen', '🌧️ Starkregen'],
        'gewitter'   => ['KatGewitter', '⚡ Gewitter'],
        'schnee'     => ['KatSchnee', '❄️ Schnee'],
        'hitze'      => ['KatHitze', '🥵 Hitze'],
    ];

    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Standorte', '[]');
        $this->RegisterPropertyBoolean('QuelleNina', true);
        $this->RegisterPropertyBoolean('QuelleDwd', true);
        $this->RegisterPropertyBoolean('QuellePegelonline', false);
        $this->RegisterPropertyBoolean('QuelleBfsOdl', false);
        $this->RegisterPropertyFloat('BfsOdlSchwellwert', 0.3);
        $this->RegisterPropertyInteger('PollIntervalMinutes', 10);
        $this->RegisterPropertyBoolean('PushAktiv', true);
        $this->RegisterPropertyString('PushSound', 'alarm');
        $this->RegisterPropertyString('Schutzaktionen', '[]');
        $this->RegisterPropertyString('WebFronts', '[]');

        $this->RegisterTimer('PollTimer', 0, 'WHUB_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SirenOffTimer', 0, 'WHUB_CheckSirenOff($_IPS[\'TARGET\']);');

        $this->RegisterAttributeString('SeenWarnings', '{}');
        $this->RegisterAttributeString('FiredActions', '{}');
        $this->RegisterAttributeString('PendingSirenOff', '[]');
        $this->RegisterAttributeInteger('LastPollTs', 0);
        $this->RegisterAttributeString('LastActiveWarningsJson', '[]');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $hasSource = $this->ReadPropertyBoolean('QuelleNina') || $this->ReadPropertyBoolean('QuelleDwd');
        if (!$hasSource) {
            $this->SetStatus(104);
            $this->SetTimerInterval('PollTimer', 0);
            return;
        }

        $minutes = max(1, $this->ReadPropertyInteger('PollIntervalMinutes'));
        $this->SetTimerInterval('PollTimer', $minutes * 60 * 1000);
        $this->SetStatus(102);
    }

    // ----------------------------------------------------------------
    //  Formular
    // ----------------------------------------------------------------

    public function GetConfigurationForm(): string
    {
        $form = ['elements' => [], 'actions' => [], 'status' => []];

        // Reihenfolge nach Verbund-Konvention: 1. Wozu, 2. Neu in Version X.Y,
        // 3. Dokumentation & Hilfe -- ERST DANACH die Fachpanels.
        $intro = $this->PurposeIntro();
        if ($intro !== null) {
            $form['elements'][] = $intro;
        }
        $news = $this->NewsBanner();
        if ($news !== null) {
            $form['elements'][] = $news;
        }
        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '📖  Dokumentation & Hilfe',
            'expanded' => false,
            'items' => [
                ['type' => 'Label', 'caption' => 'WarnHub Version ' . self::DOC_VERSION],
                ['type' => 'Label', 'caption' => 'Bündelt Warn- und Alarmmeldungen für Deutschland (Katastrophenschutz, Wetter, Hochwasser, Polizei) und meldet nur, was innerhalb des selbst definierten Umkreises liegt.'],
                ['type' => 'Label', 'caption' => 'Datenquellen: NINA-Aggregation (offiziell von der BBK-App genutzt, warnung.bund.de), optional die direkten DWD-Wetterwarnungen (opendata.dwd.de), optional Pegelstände (PEGELONLINE/WSV) und optional Radioaktivitäts-Messwerte (BfS Ortsdosisleistung).'],
                ['type' => 'Label', 'caption' => 'Bei PEGELONLINE und BfS ODL-Info gibt es keine amtliche Warnstufen-Klassifikation -- WarnHub meldet stattdessen einen erhöhten Pegel (über dem mittleren bzw. bisherigen Höchstwasser) bzw. eine Überschreitung des selbst eingestellten Strahlungs-Schwellwerts. Das ist keine amtliche Alarmstufe.'],
                ['type' => 'Label', 'caption' => 'Radius-Prüfung erfolgt geometrisch gegen die tatsächliche Warnfläche (Polygon/Kreis der Meldung), nicht gegen Postleitzahlen/Gemeindegrenzen.'],
                ['type' => 'Label', 'caption' => 'Liegt zu einer Meldung keine Geometrie vor, wird sie sicherheitshalber NICHT automatisch zugeordnet (keine geratene Präzision).'],
                ['type' => 'Label', 'caption' => 'Ein Standort kann statt fester Koordinaten auch an zwei Variablen (Lat/Lon) gebunden werden, z. B. aus Tessie oder einer Geofency-Bridge -- WarnHub liest dann bei jeder Prüfung die aktuelle Position. Über "Push nur an" lässt sich außerdem festlegen, dass ein Standort nur bestimmte WebFronts benachrichtigt (z. B. je eine Person/ein Fahrzeug bei mehreren gleichzeitig genutzten Standorten).'],
                ['type' => 'Label', 'caption' => 'Konfigurationsverhalten bei Push-Zielen/Schutzaktionen: WarnHub durchsucht bei der Einrichtung automatisch den Objektbaum und schlägt Treffer VORAKTIVIERT vor (alle gefundenen WebFront- und Kachel-Visualisierung-Instanzen, sowie Instanzen/Variablen mit "Raffstore"/"Jalousie"/"Markise"/"Garage"/"Sirene" im Namen). Nicht gewünschte Treffer lassen sich einfach über die Aktiv-Spalte abwählen -- eine erneute Suche überschreibt eigene Abwahl-Entscheidungen nicht.'],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '📍  Standorte (Umkreis-Definition)',
            'expanded' => true,
            'items' => [
                ['type' => 'Label', 'caption' => 'Jeder Standort erhält Warnungen nur, wenn eine Meldung innerhalb des angegebenen Umkreises liegt und mindestens den gewählten Schweregrad erreicht. Mehrere Standorte sind möglich (z. B. eigener Wohnort + Zweitwohnsitz/Angehörige).'],
                [
                    'type' => 'Button',
                    'caption' => '📍 Standort aus Symcon-Systemeinstellungen übernehmen',
                    'onClick' => 'echo WHUB_AddStandortFromSystemLocation($id);',
                ],
                ['type' => 'Label', 'caption' => 'Übernimmt Breiten-/Längengrad aus der Symcon-Kerninstanz "Standort" (Kern-Instanzen) als neue Zeile -- fügt sie nur der offenen Tabelle hinzu, "Übernehmen" bleibt trotzdem nötig.'],
                ['type' => 'Label', 'caption' => 'Mobiler Standort (z. B. aus Tessie- oder einer Geofency-Bridge-Variable): "Live-Standort Lat/Lon" auf die jeweilige Positions-Variable verweisen -- WarnHub liest dann bei jeder Prüfung die AKTUELLE Position daraus, Lat/Lon in der Tabelle sind dann nur der Startwert/Fallback. 0 = feste Koordinaten aus der Tabelle (bisheriges Verhalten).'],
                ['type' => 'Label', 'caption' => '"Push nur an" schränkt die Benachrichtigung dieses Standorts auf einzelne, namentlich genannte Ziele aus der WebFronts-Liste weiter unten ein (Komma-getrennt, z. B. "iPhone Dietmar") -- praktisch bei mehreren Personen/Fahrzeugen, damit nicht jeder die Warnung der anderen Person bekommt. Leer = wie bisher an alle aktivierten Ziele.'],
                [
                    'type' => 'List',
                    'name' => 'Standorte',
                    'rowCount' => $this->listRowCount(count($this->decodeStandorte())),
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'PLZ/Ort (Info)', 'name' => 'Ort', 'width' => '140px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Breitengrad (Lat)', 'name' => 'Lat', 'width' => '120px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 5]],
                        ['caption' => 'Längengrad (Lon)', 'name' => 'Lon', 'width' => '120px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 5]],
                        ['caption' => 'Live-Standort Lat (0=fest)', 'name' => 'QuellVarLat', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Live-Standort Lon (0=fest)', 'name' => 'QuellVarLon', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Umkreis (km)', 'name' => 'RadiusKm', 'width' => '100px', 'add' => 10.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1, 'minValue' => 0]],
                        ['caption' => 'Ab Schweregrad', 'name' => 'MinSeverity', 'width' => '140px', 'add' => 2, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
                        ['caption' => 'Push nur an (Name, Komma; leer=alle)', 'name' => 'PushZielFilter', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
                ['type' => 'ValidationTextBox', 'name' => 'GeoLookupOrt', 'caption' => 'PLZ oder Ort'],
                [
                    'type' => 'Button',
                    'caption' => '🔍 Koordinaten nachschlagen',
                    'onClick' => 'echo WHUB_LookupCoordinates($id, $GeoLookupOrt);',
                ],
                ['type' => 'Label', 'caption' => 'Ermittelt Breiten-/Längengrad über OpenStreetMap Nominatim (kostenlos, kein Zugangsschlüssel) und zeigt sie zum Übertragen in die Tabelle oben an -- schreibt nichts automatisch in eine Zeile.'],
                [
                    'type' => 'SelectLocation',
                    'name' => 'KartenStandort',
                    'caption' => 'Oder auf der Karte auswählen',
                    'value' => $this->mapDefaultLocation(),
                ],
                [
                    'type' => 'Button',
                    'caption' => '📍 Kartenpunkt als Standort übernehmen',
                    'onClick' => 'echo WHUB_AddStandortFromMap($id, $KartenStandort);',
                ],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🌐  Datenquellen',
            'expanded' => true,
            'items' => [
                ['type' => 'CheckBox', 'name' => 'QuelleNina', 'caption' => 'NINA-Aggregation (MoWaS/Katwarn/Biwapp/DWD/Hochwasser/Polizei, warnung.bund.de)'],
                ['type' => 'CheckBox', 'name' => 'QuelleDwd', 'caption' => 'Zusätzlich direkte DWD-Wetterwarnungen (mehr Detail als die NINA-Zusammenfassung)'],
                ['type' => 'CheckBox', 'name' => 'QuellePegelonline', 'caption' => 'Pegelstände (PEGELONLINE/WSV) -- warnt bei Pegeln über dem mittleren bzw. bisherigen Höchstwasser in der Nähe eines Standorts'],
                ['type' => 'CheckBox', 'name' => 'QuelleBfsOdl', 'caption' => 'Radioaktivität (BfS Ortsdosisleistung) -- eigener Schwellwert, keine amtliche Meldestufe'],
                ['type' => 'NumberSpinner', 'name' => 'BfsOdlSchwellwert', 'caption' => 'Schwellwert Radioaktivität (µSv/h)', 'digits' => 3, 'minValue' => 0.05],
                ['type' => 'NumberSpinner', 'name' => 'PollIntervalMinutes', 'caption' => 'Abfragetakt (Minuten)', 'minValue' => 1, 'maxValue' => 60],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🔔  Benachrichtigung',
            'expanded' => true,
            'items' => [
                ['type' => 'CheckBox', 'name' => 'PushAktiv', 'caption' => 'Push-Benachrichtigung an aktivierte WebFront-/Kachel-Visualisierung-Instanzen (auch Handy)'],
                ['type' => 'Select', 'name' => 'PushSound', 'caption' => 'Signalton', 'options' => $this->soundOptions()],
                [
                    'type' => 'Button',
                    'caption' => '🔎 WebFront-Instanzen suchen',
                    'onClick' => 'echo WHUB_DiscoverWebFronts($id);',
                ],
                ['type' => 'Label', 'caption' => $this->webfrontStatusLine()],
                ['type' => 'Label', 'caption' => 'Sucht sowohl klassische WebFront-Instanzen als auch Kachel-Visualisierung-Instanzen (die neuere Symcon-Oberfläche, unter "Visualisierung Instanzen" im Objektbaum -- häufig die eigentlich genutzte Oberfläche). Gefundene Ziele sind standardmäßig aktiv (bekommen Push) -- nicht gewünschte einfach über die Aktiv-Spalte abwählen. Eine erneute Suche fügt nur neue Ziele hinzu und lässt bestehende Abwahl-Entscheidungen unangetastet.'],
                [
                    'type' => 'List',
                    'name' => 'WebFronts',
                    'rowCount' => $this->listRowCount(count($this->decodeWebFronts()), 3),
                    'add' => false,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '220px', 'edit' => ['type' => 'ValidationTextBox', 'enabled' => false]],
                        ['caption' => 'Typ', 'name' => 'Typ', 'width' => '140px', 'edit' => ['type' => 'Select', 'options' => [['caption' => 'WebFront', 'value' => 'webfront'], ['caption' => 'Kachel-Visualisierung', 'value' => 'kachel']]]],
                        ['caption' => 'Instanz-ID', 'name' => 'InstanceID', 'width' => '100px', 'edit' => ['type' => 'NumberSpinner', 'enabled' => false]],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🛡️  Schutzaktionen (Jalousien/Raffstore, Markisen, Garagentor, Sirenen, Skripte)',
            'expanded' => false,
            'items' => [
                ['type' => 'Label', 'caption' => 'Löst bei passender Warnung automatisch eine Aktion aus -- z. B. Raffstore hochfahren, Garagentor schließen, ein akustisches Signal schalten oder ein eigenes Skript ausführen. Jede Aktion feuert nur EINMAL je Warnung, es gibt keine automatische Rückstellung -- das bleibt bewusst Nutzerhandeln.'],
                [
                    'type' => 'PopupButton',
                    'caption' => 'Welche Felder brauche ich für welchen Aktionstyp?',
                    'popup' => [
                        'caption' => 'Felder je Aktionstyp',
                        'items' => [
                            ['type' => 'Label', 'caption' => 'Raffstore/Rollladen hochfahren, Markise einfahren, Garagentor schließen, Akustischer Alarm: Ziel-Variable (der schaltbare Wert, z. B. Rollladen-/Markisen-Position oder Torsteuerung) + Zielwert (der Wert, der beim Auslösen gesetzt wird -- je nach Hersteller unterschiedlich, z. B. 0 = offen/hochgefahren/eingefahren, bitte am eigenen Aktor prüfen).'],
                            ['type' => 'Label', 'caption' => 'Akustischer Alarm zusätzlich: Auto-Aus (Sekunden) -- 0 bedeutet kein automatisches Ausschalten.'],
                            ['type' => 'Label', 'caption' => 'Skript ausführen: Ziel-Skript statt Ziel-Variable/Zielwert.'],
                            ['type' => 'Label', 'caption' => 'Mehrere Auslöser gleichzeitig (z. B. Markise soll bei Sturm UND Hagel einfahren): einfach mehrere Kästchen in derselben Zeile ankreuzen -- die Aktion feuert, sobald IRGENDEINE angekreuzte Kategorie zutrifft. Kein Kästchen angekreuzt = die Aktion gilt für jede Kategorie. Die automatische Objektbaum-Suche kreuzt bei Raffstore/Markise bereits Sturm + Hagel an.'],
                            ['type' => 'Label', 'caption' => 'Leeres "Nur Standort" bedeutet "alle FESTEN Standorte", NICHT auch mobile (Live-Standort-gebundene, siehe Standorte-Panel) -- sonst würde z. B. ein Sturm über Hamburg, den nur der mobile Standort meldet, die zuhause verbaute Jalousie einfahren. Das gilt automatisch für jede Aktion, keine Einrichtung nötig.'],
                        ],
                    ],
                ],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Objektbaum nach Raffstore/Jalousie/Markise/Garage/Sirene durchsuchen',
                    'onClick' => 'echo WHUB_DiscoverSchutzaktionen($id);',
                ],
                ['type' => 'Label', 'caption' => 'Gefundene Treffer werden vorausgefüllt und AKTIVIERT als neue Zeile ergänzt (Schweregrad "Hoch" als vorsichtiger Standard) -- nicht gewünschte einfach über die Aktiv-Spalte abwählen. Eine erneute Suche lässt bestehende Zeilen/Abwahl-Entscheidungen unangetastet und fügt nur neue Treffer hinzu.'],
                [
                    'type' => 'List',
                    'name' => 'Schutzaktionen',
                    'rowCount' => $this->listRowCount(count($this->decodeSchutzaktionen())),
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Typ', 'name' => 'Typ', 'width' => '190px', 'add' => 'raffstore', 'edit' => ['type' => 'Select', 'options' => $this->actionTypeOptions()]],
                        ...array_map(fn ($f) => ['caption' => $f[1], 'name' => $f[0], 'width' => '75px', 'add' => false, 'edit' => ['type' => 'CheckBox']], array_values(self::CATEGORY_FIELDS)),
                        ['caption' => 'Ab Schweregrad', 'name' => 'MinSeverity', 'width' => '140px', 'add' => 3, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
                        ['caption' => 'Nur Standort (leer=alle festen)', 'name' => 'StandortFilter', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Ziel-Variable', 'name' => 'ZielVariableID', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Zielwert', 'name' => 'ZielWert', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ['caption' => 'Ziel-Skript', 'name' => 'ZielSkriptID', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectScript']],
                        ['caption' => 'Auto-Aus (s)', 'name' => 'AutoOffSekunden', 'width' => '100px', 'add' => 60, 'edit' => ['type' => 'NumberSpinner', 'minValue' => 0]],
                    ],
                ],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🔎  Prüfung & Status',
            'expanded' => true,
            'items' => [
                ['type' => 'Label', 'caption' => 'WarnHub fragt die oben konfigurierten Datenquellen automatisch im eingestellten Abfragetakt ab und gleicht sie gegen die Standorte/Schutzaktionen weiter oben ab. Der Knopf unten löst das Ganze zusätzlich sofort aus (z. B. um die Einrichtung direkt zu testen), ohne auf den nächsten automatischen Durchlauf zu warten.'],
                [
                    'type' => 'Label',
                    'name' => 'PollStatusLabel',
                    'caption' => $this->getPollStatusLine(),
                ],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Jetzt prüfen',
                    'onClick' => 'echo WHUB_Poll($id);',
                ],
                ['type' => 'Label', 'caption' => 'Zum Testen des Zustellwegs, unabhängig von einer echten Warnung:'],
                [
                    'type' => 'Button',
                    'caption' => '🧪 Testbenachrichtigung senden',
                    'onClick' => 'echo WHUB_TestPush($id);',
                ],
            ],
        ];

        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }
        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    /** Sichtbare Zeilenzahl einer Liste an ihren tatsächlichen Inhalt anpassen, statt fest zu scrollen -- Dietmars Fund 04.09.2026 (Schutzaktionen-Liste nach der Objektbaum-Suche). Plus 1 Luft für "Hinzufügen", nach oben gedeckelt, damit ein einzelnes Panel nicht die ganze Seite dominiert. */
    private function listRowCount(int $currentRows, int $min = 5, int $max = 20): int
    {
        return max($min, min($max, $currentRows + 1));
    }

    private function severityOptions(): array
    {
        return [
            ['caption' => 'Gering (Minor)', 'value' => 1],
            ['caption' => 'Mittel (Moderate)', 'value' => 2],
            ['caption' => 'Hoch (Severe)', 'value' => 3],
            ['caption' => 'Extrem (Extreme)', 'value' => 4],
        ];
    }

    private function actionTypeOptions(): array
    {
        return [
            ['caption' => 'Raffstore/Rollladen hochfahren', 'value' => 'raffstore'],
            ['caption' => 'Markise einfahren', 'value' => 'markise'],
            ['caption' => 'Garagentor schließen', 'value' => 'garage'],
            ['caption' => 'Akustischer Alarm', 'value' => 'sirene'],
            ['caption' => 'Skript ausführen', 'value' => 'skript'],
        ];
    }

    private function soundOptions(): array
    {
        $sounds = ['alarm', 'bell', 'boom', 'buzzer', 'connected', 'dark', 'digital', 'drums', 'duck', 'full',
            'happy', 'horn', 'inception', 'kazoo', 'roll', 'siren', 'space', 'trickling', 'turn'];
        return array_map(fn ($s) => ['caption' => $s, 'value' => $s], $sounds);
    }

    /**
     * Findet alle Instanzen eines per Namens-Teilstring gesuchten Modultyps --
     * robuster als eine fest hinterlegte GUID (siehe unten). $exactGuid wird
     * zuerst versucht (schnell, keine volle Modulliste nötig), die
     * Namenssuche greift nur als Rückfallebene.
     *
     * @return array<int,string> InstanceID => Modulname
     */
    private function findInstancesByModuleNameSubstring(string $exactGuid, string $needle): array
    {
        $out = [];
        foreach (@IPS_GetInstanceListByModuleID($exactGuid) ?: [] as $instanceID) {
            $out[$instanceID] = $needle;
        }
        if (count($out) > 0) {
            return $out;
        }
        foreach (@IPS_GetModuleList() ?: [] as $moduleID) {
            $m = @IPS_GetModule($moduleID);
            $name = is_array($m) ? (string) ($m['ModuleName'] ?? '') : '';
            if ($name === '' || stripos($name, $needle) === false) {
                continue;
            }
            foreach (@IPS_GetInstanceListByModuleID($moduleID) ?: [] as $instanceID) {
                $out[$instanceID] = $name;
            }
        }
        return $out;
    }

    /**
     * Push-Ziele: sowohl klassische WebFront-Konfigurator-Instanzen
     * (WFC_PushNotification) als auch Kachel-Visualisierung-Instanzen
     * (VISU_PostNotificationEx) -- Praxis-Fund 04.09.2026: Dietmars einzige
     * genutzte Oberfläche ist eine Kachel-Visualisierung ("Dietmar", unter
     * "Visualisierung Instanzen"), kein klassisches WebFront -- beide
     * Symcon-Oberflächen bieten eigene, INKOMPATIBLE Push-Funktionen (gegen
     * den offiziellen Quellcode des Symcon-Kernmoduls "Benachrichtigung"
     * verifiziert, siehe WHUB_WEBFRONT_GUID-Kommentar oben), deshalb werden
     * beide Typen gesucht und je nach Typ die passende Funktion aufgerufen.
     *
     * @return array<int,array{InstanceID:int,Name:string,Typ:string}>
     */
    private function discoverPushTargets(): array
    {
        $out = [];
        foreach ($this->findInstancesByModuleNameSubstring(WHUB_WEBFRONT_GUID, 'webfront') as $instanceID => $moduleName) {
            $out[] = ['InstanceID' => $instanceID, 'Name' => @IPS_GetName($instanceID) ?: ('#' . $instanceID), 'Typ' => 'webfront'];
        }
        foreach ($this->findInstancesByModuleNameSubstring(self::KACHEL_VISU_GUID, 'kachel') as $instanceID => $moduleName) {
            $out[] = ['InstanceID' => $instanceID, 'Name' => @IPS_GetName($instanceID) ?: ('#' . $instanceID), 'Typ' => 'kachel'];
        }
        return $out;
    }

    /** @return array<int,array{InstanceID:int,Name:string,Typ:string,Aktiv:bool}> */
    private function decodeWebFronts(): array
    {
        $raw = json_decode($this->ReadPropertyString('WebFronts'), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $w) {
            $out[] = [
                'InstanceID' => (int) ($w['InstanceID'] ?? 0),
                'Name' => (string) ($w['Name'] ?? ''),
                'Typ' => (string) ($w['Typ'] ?? 'webfront'),
                'Aktiv' => (bool) ($w['Aktiv'] ?? true),
            ];
        }
        return $out;
    }

    /**
     * Sucht Push-Ziele (WebFront + Kachel-Visualisierung) und ergänzt NUR neu
     * gefundene (per InstanceID abgeglichen) -- bestehende Zeilen samt
     * eigener Aktiv/Inaktiv-Entscheidung bleiben unangetastet. Schreibt wie
     * AddStandortFromSystemLocation() nur in die offene Formularmaske,
     * "Übernehmen" bleibt der bewusste letzte Schritt.
     */
    public function DiscoverWebFronts(): string
    {
        $foundTargets = $this->discoverPushTargets();
        $rows = $this->decodeWebFronts();
        $known = array_column($rows, null, 'InstanceID');
        $added = 0;
        foreach ($foundTargets as $target) {
            if (isset($known[$target['InstanceID']])) {
                continue;
            }
            $rows[] = ['InstanceID' => $target['InstanceID'], 'Name' => $target['Name'], 'Typ' => $target['Typ'], 'Aktiv' => true];
            $added++;
        }
        $this->UpdateFormField('WebFronts', 'values', json_encode($rows));
        $this->UpdateFormField('WebFronts', 'rowCount', $this->listRowCount(count($rows), 3));
        if ($added === 0 && count($rows) > 0) {
            return sprintf('ℹ️ Keine neuen Push-Ziele gefunden (%d bereits bekannt). Bitte unten „Übernehmen" klicken, falls noch nicht gespeichert.', count($rows));
        }
        if ($added === 0) {
            return '⚠️ Weder WebFront- noch Kachel-Visualisierung-Instanzen im Objektbaum gefunden.';
        }
        return sprintf('✅ %d neue(s) Push-Ziel(e) gefunden und aktiviert (insgesamt %d) -- bitte unten „Übernehmen" klicken, um zu speichern.', $added, count($rows));
    }

    private function webfrontStatusLine(): string
    {
        $rows = $this->decodeWebFronts();
        $active = count(array_filter($rows, fn ($w) => $w['Aktiv']));
        if (count($rows) === 0) {
            return 'ℹ️ Noch keine Push-Ziele gesucht -- oben "🔎 WebFront-Instanzen suchen" klicken.';
        }
        if ($active === 0) {
            return sprintf('⚠️ %d Push-Ziel(e) gefunden, aber keines aktiviert -- Push-Benachrichtigungen kommen aktuell nirgends an.', count($rows));
        }
        return sprintf('✅ %d von %d gefundenen Push-Ziel(en) aktiv -- Push-Benachrichtigungen gehen dorthin.', $active, count($rows));
    }

    private function getPollStatusLine(): string
    {
        $lastTs = $this->ReadAttributeInteger('LastPollTs');
        if ($lastTs === 0) {
            return 'ℹ️ Noch keine Prüfung durchgeführt.';
        }
        $active = json_decode($this->ReadAttributeString('LastActiveWarningsJson'), true) ?: [];
        $standorte = $this->decodeStandorte();
        $activeStandorte = count(array_filter($standorte, fn ($s) => $s['Aktiv']));
        $icon = count($active) > 0 ? '⚠️' : '✅';
        return sprintf(
            '%s %d aktive Warnung(en) an %d konfigurierten Standorten (zuletzt geprüft %s Uhr).',
            $icon,
            count($active),
            $activeStandorte,
            date('H:i:s', $lastTs)
        );
    }

    // ----------------------------------------------------------------
    //  Formular-Konventions-Panels (PurposeIntro/ForumHint/LicenseHint)
    // ----------------------------------------------------------------

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'WarnHub bündelt amtliche Warn- und Alarmmeldungen für Deutschland (Unwetter, Katastrophenschutz, Hochwasser, Polizei) und meldet nur das, was tatsächlich in den von dir festgelegten Umkreis um deine Standorte fällt.'],
                ['type' => 'Label', 'caption' => 'Aktive Warnungen erscheinen als Push-Benachrichtigung auf allen WebFront- und Kachel-Visualisierung-Geräten (auch Handy) und können optional Schutzaktionen auslösen -- z. B. Raffstore hochfahren oder das Garagentor schließen, bevor der Sturm da ist.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WHUB_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in Version ' . self::NEWS_VERSION,
            'items' => [
                ['type' => 'Label', 'caption' => 'Erste Version von WarnHub:'],
                ['type' => 'Label', 'caption' => '• Warn- und Alarmmeldungen für Deutschland (NINA-Aggregation + optionale direkte DWD-Wetterwarnungen), geometrisch auf den eigenen Umkreis gefiltert'],
                ['type' => 'Label', 'caption' => '• Beliebig viele Standorte, wahlweise aus Symcons eigenem Standort, Adress-/PLZ-Suche oder Karte übernommen'],
                ['type' => 'Label', 'caption' => '• Automatische Push-Benachrichtigung an gefundene, aktivierte WebFront- UND Kachel-Visualisierung-Instanzen'],
                ['type' => 'Label', 'caption' => '• Optionale Schutzaktionen (Raffstore/Rollladen, Garagentor, akustischer Alarm, eigenes Skript), inkl. automatischer Objektbaum-Suche nach passenden Geräten'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WHUB_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => false,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Im Forum ist noch nichts veröffentlicht -- dieser Link ist ein Platzhalter und wird beim ersten Store-Release ersetzt.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WHUB_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für Heimautomatisierung -- und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 -- privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich -- völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    // ----------------------------------------------------------------
    //  Standorte / Geocoding
    // ----------------------------------------------------------------

    /** @return array<int,array{Name:string,Ort:string,Lat:float,Lon:float,RadiusKm:float,MinSeverity:int,Aktiv:bool}> */
    private function decodeStandorte(): array
    {
        $raw = json_decode($this->ReadPropertyString('Standorte'), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $s) {
            $out[] = [
                'Name' => (string) ($s['Name'] ?? ''),
                'Ort' => (string) ($s['Ort'] ?? ''),
                'Lat' => (float) ($s['Lat'] ?? 0),
                'Lon' => (float) ($s['Lon'] ?? 0),
                'QuellVarLat' => (int) ($s['QuellVarLat'] ?? 0),
                'QuellVarLon' => (int) ($s['QuellVarLon'] ?? 0),
                'RadiusKm' => (float) ($s['RadiusKm'] ?? 10),
                'MinSeverity' => (int) ($s['MinSeverity'] ?? 2),
                'PushZielFilter' => (string) ($s['PushZielFilter'] ?? ''),
                'Aktiv' => (bool) ($s['Aktiv'] ?? true),
            ];
        }
        return $out;
    }

    /**
     * Liefert die tatsächlich zu verwendende Position eines Standorts. Sind
     * QuellVarLat/QuellVarLon gesetzt (z. B. auf eine Tessie- oder
     * Geofency-Bridge-Variable), wird deren AKTUELLER Wert gelesen statt der
     * festen Lat/Lon-Spalten -- damit "wandert" ein Standort mit dem
     * tatsächlichen Aufenthaltsort mit (Dietmars Idee 04.09.2026: zwei Autos/
     * Personen sollen ihre je eigenen, aktuellen Warnungen bekommen statt
     * einer festen Heimatkoordinate). Die Lat/Lon-Spalten bleiben dabei der
     * Fallback, falls die Variable (noch) nicht existiert.
     */
    private function resolveStandortCoords(array $standort): array
    {
        $lat = $standort['Lat'];
        $lon = $standort['Lon'];
        if ($standort['QuellVarLat'] > 0 && @IPS_VariableExists($standort['QuellVarLat'])) {
            $lat = (float) GetValue($standort['QuellVarLat']);
        }
        if ($standort['QuellVarLon'] > 0 && @IPS_VariableExists($standort['QuellVarLon'])) {
            $lon = (float) GetValue($standort['QuellVarLon']);
        }
        return ['lat' => $lat, 'lon' => $lon];
    }

    /** Ein Standort gilt als "mobil", sobald mindestens eine Live-Standort-Variable gebunden ist (siehe resolveStandortCoords()). */
    private function isStandortMobil(array $standort): bool
    {
        return $standort['QuellVarLat'] > 0 || $standort['QuellVarLon'] > 0;
    }

    /**
     * "Push nur an ..."-Filter eines Standorts (Komma-getrennte WebFront-
     * Namen) in eine vergleichbare Namensliste zerlegt -- leer = kein Filter
     * (an alle aktivierten Ziele, bisheriges Verhalten). Namensbasiert statt
     * ID-basiert, um demselben Muster wie Schutzaktionen::StandortFilter zu
     * folgen (dort ebenfalls ein Freitext-Namensabgleich, kein Auswahlfeld).
     */
    private function parsePushZielNames(string $filter): array
    {
        $names = array_filter(array_map('trim', explode(',', $filter)), fn ($n) => $n !== '');
        return array_map('mb_strtolower', $names);
    }

    /** @return array<int,array{Name:string,Aktiv:bool,Typ:string,Kategorien:array<int,string>,MinSeverity:int,StandortFilter:string,ZielVariableID:int,ZielWert:float,ZielSkriptID:int,AutoOffSekunden:int}> */
    private function decodeSchutzaktionen(): array
    {
        $raw = json_decode($this->ReadPropertyString('Schutzaktionen'), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $a) {
            $kategorien = [];
            foreach (self::CATEGORY_FIELDS as $key => [$field, $label]) {
                if ((bool) ($a[$field] ?? false)) {
                    $kategorien[] = $key;
                }
            }
            // Rückwärtskompatibilität zur alten Einzelauswahl ("Kategorie"-
            // String, vor 04.09.2026) -- nur relevant für Zeilen, die vor der
            // Umstellung auf Mehrfachauswahl gespeichert wurden.
            if (count($kategorien) === 0 && isset($a['Kategorie']) && $a['Kategorie'] !== 'alle' && $a['Kategorie'] !== '') {
                $kategorien[] = (string) $a['Kategorie'];
            }
            $out[] = [
                'Name' => (string) ($a['Name'] ?? ''),
                'Aktiv' => (bool) ($a['Aktiv'] ?? true),
                'Typ' => (string) ($a['Typ'] ?? 'raffstore'),
                'Kategorien' => $kategorien, // leer = gilt für jede Kategorie
                'MinSeverity' => (int) ($a['MinSeverity'] ?? 3),
                'StandortFilter' => (string) ($a['StandortFilter'] ?? ''),
                'ZielVariableID' => (int) ($a['ZielVariableID'] ?? 0),
                'ZielWert' => (float) ($a['ZielWert'] ?? 0),
                'ZielSkriptID' => (int) ($a['ZielSkriptID'] ?? 0),
                'AutoOffSekunden' => (int) ($a['AutoOffSekunden'] ?? 60),
            ];
        }
        return $out;
    }

    private function collectObjectIDsRecursive(int $rootID = 0): array
    {
        $out = [];
        foreach (@IPS_GetChildrenIDs($rootID) ?: [] as $id) {
            $out[] = $id;
            $out = array_merge($out, $this->collectObjectIDsRecursive($id));
        }
        return $out;
    }

    private function isActionableVariable(int $variableID): bool
    {
        $v = @IPS_GetVariable($variableID);
        return is_array($v) && (int) ($v['VariableAction'] ?? 0) !== 0;
    }

    /** Erste schaltbare (mit Aktion versehene) Kind-Variable einer gematchten Instanz. */
    private function findActionableChildVariable(int $instanceID): ?int
    {
        foreach (@IPS_GetChildrenIDs($instanceID) ?: [] as $childID) {
            $obj = @IPS_GetObject($childID);
            if (is_array($obj) && (int) $obj['ObjectType'] === 2 && $this->isActionableVariable($childID)) {
                return $childID;
            }
        }
        return null;
    }

    /**
     * Läuft vom Objekt $id aus den Baum nach oben bis zur ersten echten
     * INSTANZ (ObjectType 1) -- überspringt beliebig viele Zwischenkategorien
     * dazwischen, statt nur den direkten Elternknoten zu nehmen (der oft nur
     * eine Kategorie wie "Steuerung" ist, nicht das eigentliche Gerät).
     * Tiefenbegrenzung als Schutz gegen einen unerwartet zirkulären Baum.
     */
    private function findOwningInstanceName(int $id): string
    {
        $currentID = @IPS_GetParent($id) ?: 0;
        for ($depth = 0; $currentID > 0 && $depth < 10; $depth++) {
            $obj = @IPS_GetObject($currentID);
            if (is_array($obj) && (int) $obj['ObjectType'] === 1) {
                return (string) (@IPS_GetName($currentID) ?: '');
            }
            $currentID = @IPS_GetParent($currentID) ?: 0;
        }
        return '';
    }

    /**
     * Durchsucht den GESAMTEN Objektbaum nach Instanzen/Variablen, deren Name
     * "Raffstore"/"Jalousie" (Typ raffstore), "Garage" (Typ garage) oder
     * "Sirene"/"Hupe"/"Buzzer"/"Signalhorn" (Typ sirene) enthält, und ergänzt
     * für jeden NEUEN Treffer (nach Ziel-Variable dedupliziert) eine
     * VORAKTIVIERTE Schutzaktions-Zeile -- explizit auf Dietmars Wunsch
     * (04.09.2026): "gleich mitaufnehmen und aktivieren, deaktivieren geht
     * immer". Bestehende Zeilen (inkl. eigener Aktiv/Inaktiv-Entscheidung)
     * bleiben unangetastet. Schreibt wie DiscoverWebFronts() nur in die
     * offene Formularmaske.
     */
    public function DiscoverSchutzaktionen(): string
    {
        // Arbeitet bewusst auf dem ROHEN Property-Format (KatSturm/KatHagel/...
        // als einzelne Bool-Felder je Zeile, wie es die Formular-Checkboxen
        // erwarten) statt auf decodeSchutzaktionen()s normalisierter
        // 'Kategorien'-Liste, die nur für die interne Zuordnungslogik gedacht ist.
        $rows = json_decode($this->ReadPropertyString('Schutzaktionen'), true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $knownVarIDs = array_column($rows, 'ZielVariableID');
        $added = 0;

        // Mehrere Kategorie-Kästchen gleichzeitig je Typ -- Windauslöser
        // (Raffstore/Markise) decken beide realistischen Gefahren in EINER
        // Zeile ab (Dietmars ausdrücklicher Wunsch 04.09.2026: "abhaken"
        // statt mehrerer Zeilen).
        $typeDefaults = [
            'raffstore' => ['Kategorien' => ['sturm', 'hagel'], 'MinSeverity' => 3, 'AutoOff' => 0],
            'markise' => ['Kategorien' => ['sturm', 'hagel'], 'MinSeverity' => 3, 'AutoOff' => 0],
            'garage' => ['Kategorien' => [], 'MinSeverity' => 3, 'AutoOff' => 0], // leer = jede Kategorie
            'sirene' => ['Kategorien' => [], 'MinSeverity' => 4, 'AutoOff' => 60],
        ];

        foreach ($this->collectObjectIDsRecursive(0) as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj)) {
                continue;
            }
            $type = (int) $obj['ObjectType'];
            if ($type !== 1 && $type !== 2) {
                continue; // nur Instanzen (1) und Variablen (2)
            }
            $haystack = mb_strtolower((string) $obj['ObjectName']);

            foreach (self::DISCOVERY_KEYWORDS as $actionType => $keywords) {
                $matched = false;
                foreach ($keywords as $kw) {
                    if (mb_strpos($haystack, $kw) !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }

                $variableID = null;
                if ($type === 2 && $this->isActionableVariable($id)) {
                    $variableID = $id;
                } elseif ($type === 1) {
                    $variableID = $this->findActionableChildVariable($id);
                }
                if ($variableID === null || in_array($variableID, $knownVarIDs, true)) {
                    continue 2; // nächstes Objekt, nicht mit einer anderen Stichwortgruppe erneut versuchen
                }

                // Kommt der Treffer über eine namenlos-generische Kind-Variable
                // (z. B. "Hupe" unter mehreren Fahrzeug-Instanzen -- Dietmars
                // Live-Fund 04.09.2026: mehrere gleichnamige Zeilen ließen sich
                // nicht mehr unterscheiden), Namen der ECHTEN besitzenden
                // Instanz voranstellen -- nicht nur den direkten Elternknoten,
                // der oft nur eine Zwischenkategorie ("Steuerung" o. ä.) ist
                // (Dietmars Nachfrage 04.09.2026, zurecht: "bis zur eigentlichen
                // Instanz?").
                $displayName = (string) $obj['ObjectName'];
                if ($type === 2) {
                    $ownerName = $this->findOwningInstanceName($id);
                    if ($ownerName !== '') {
                        $displayName = $ownerName . ' – ' . $displayName;
                    }
                }

                $defaults = $typeDefaults[$actionType];
                $row = [
                    'Name' => $displayName,
                    'Aktiv' => true,
                    'Typ' => $actionType,
                    'MinSeverity' => $defaults['MinSeverity'],
                    'StandortFilter' => '',
                    'ZielVariableID' => $variableID,
                    'ZielWert' => 0.0,
                    'ZielSkriptID' => 0,
                    'AutoOffSekunden' => $defaults['AutoOff'],
                ];
                foreach (self::CATEGORY_FIELDS as $key => [$field, $label]) {
                    $row[$field] = in_array($key, $defaults['Kategorien'], true);
                }
                $rows[] = $row;
                $knownVarIDs[] = $variableID;
                $added++;
                continue 2;
            }
        }

        $this->UpdateFormField('Schutzaktionen', 'values', json_encode($rows));
        $this->UpdateFormField('Schutzaktionen', 'rowCount', $this->listRowCount(count($rows)));
        if ($added === 0) {
            return 'ℹ️ Keine neuen Treffer für Raffstore/Jalousie/Markise/Garage/Sirene im Objektbaum gefunden.';
        }
        return sprintf(
            '✅ %d neue Schutzaktion(en) gefunden und aktiviert (Schweregrad "Hoch"/"Extrem" als vorsichtiger Standard) -- WICHTIG: Zielwert je Zeile prüfen (Richtung je Hersteller unterschiedlich, siehe Hilfe-Knopf oben), dann unten „Übernehmen" klicken.',
            $added
        );
    }

    /** Einmalige Abfrage, kein Formular-Feld wird automatisch befüllt -- siehe SUITE.md-Muster "nur in die offene Maske, Übernehmen bleibt bewusster letzter Schritt" (hier: gar nicht erst schreiben, nur anzeigen). */
    public function LookupCoordinates(string $Ort): string
    {
        $ort = trim($Ort);
        if ($ort === '') {
            return 'ℹ️ Bitte zuerst eine PLZ oder einen Ort eingeben.';
        }
        $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=de&q=' . rawurlencode($ort);
        $body = $this->httpGet($url, 15, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            return '⚠️ Nominatim war nicht erreichbar -- bitte später erneut versuchen oder Koordinaten manuell eintragen.';
        }
        $json = json_decode($body, true);
        if (!is_array($json) || count($json) === 0) {
            return '⚠️ Kein Treffer für "' . $ort . '".';
        }
        $lat = round((float) $json[0]['lat'], 5);
        $lon = round((float) $json[0]['lon'], 5);
        $name = (string) ($json[0]['display_name'] ?? $ort);
        return sprintf('✅ %s → Lat %s / Lon %s -- bitte in die Standorte-Tabelle oben übertragen.', $name, $lat, $lon);
    }

    // Symcon-Kernmodul "Standort" (Kern-Instanzen > Standort) -- GUID gegen die
    // etablierte Community-Bibliothek demel42/CommonStubs verifiziert
    // (github.com/demel42/CommonStubs, common.php::GetSystemLocation()), nicht
    // angenommen. Seit Symcon 5.0 liegen Lat/Lon in einer einzigen
    // JSON-kodierten 'Location'-Property statt zwei Einzelfeldern.
    private const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    private function getSystemLocation(): ?array
    {
        $ids = @IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL_GUID) ?: [];
        if (count($ids) === 0) {
            return null;
        }
        $id = $ids[0];
        if (function_exists('IPS_GetKernelVersion') && IPS_GetKernelVersion() < 5.0) {
            $lat = (float) @IPS_GetProperty($id, 'Latitude');
            $lon = (float) @IPS_GetProperty($id, 'Longitude');
        } else {
            $loc = json_decode((string) @IPS_GetProperty($id, 'Location'), true);
            if (!is_array($loc)) {
                return null;
            }
            $lat = (float) ($loc['latitude'] ?? 0);
            $lon = (float) ($loc['longitude'] ?? 0);
        }
        if ($lat === 0.0 && $lon === 0.0) {
            return null; // Standort-Instanz existiert, ist aber nicht konfiguriert
        }
        return ['lat' => $lat, 'lon' => $lon];
    }

    /**
     * Startpunkt der Kartenauswahl -- Symcons Systemstandort, falls
     * konfiguriert (spart das sonst nötige Wegscrollen vom Atlantik/„Null
     * Island" bei 0/0, Dietmars Fund 04.09.2026). Ohne konfigurierten
     * Systemstandort geografische Mitte Deutschlands als neutraler
     * Ersatzwert -- besser als 0/0, aber keine Rätselraterei über den
     * tatsächlichen Wohnort.
     */
    private function mapDefaultLocation(): string
    {
        $loc = $this->getSystemLocation();
        if ($loc !== null) {
            return json_encode(['latitude' => $loc['lat'], 'longitude' => $loc['lon']]);
        }
        return json_encode(['latitude' => 51.1657, 'longitude' => 10.4515]);
    }

    /** Nur in die offene Formularmaske schreiben, "Übernehmen" bleibt der bewusste letzte Schritt -- Muster wie MeterHubVirtual::AddDevice(). */
    public function AddStandortFromSystemLocation(): string
    {
        $loc = $this->getSystemLocation();
        if ($loc === null) {
            return '⚠️ Kein konfigurierter Symcon-Standort gefunden (Kern-Instanzen > Standort anlegen/ausfüllen, oder Koordinaten hier manuell eintragen).';
        }
        $rows = $this->decodeStandorte();
        $rows[] = [
            'Name' => 'Mein Standort (aus Symcon)',
            'Ort' => '',
            'Lat' => round($loc['lat'], 5),
            'Lon' => round($loc['lon'], 5),
            'RadiusKm' => 20.0,
            'MinSeverity' => 2,
            'Aktiv' => true,
        ];
        $this->UpdateFormField('Standorte', 'values', json_encode($rows));
        $this->UpdateFormField('Standorte', 'rowCount', $this->listRowCount(count($rows)));
        return sprintf('✅ Standort übernommen (Lat %s / Lon %s) -- bitte unten „Übernehmen" klicken, um zu speichern.', round($loc['lat'], 5), round($loc['lon'], 5));
    }

    /**
     * $KartenStandort kommt vom 'SelectLocation'-Formularfeld als JSON-String
     * {"latitude":..,"longitude":..} (Symcon verlangt bei PREFIX_-Funktionen
     * zwingend einen der Skalar-Typen bool/int/float/string -- Live-Fund
     * 04.09.2026: ohne Typangabe meldet die Konsole "Parameter ... hat
     * keinen Datentyp" und der Aufruf schlägt fehl).
     */
    public function AddStandortFromMap(string $KartenStandort): string
    {
        $loc = json_decode($KartenStandort, true);
        if (!is_array($loc) || !isset($loc['latitude'], $loc['longitude'])) {
            return '⚠️ Kein Kartenpunkt ausgewählt.';
        }
        $lat = (float) $loc['latitude'];
        $lon = (float) $loc['longitude'];
        if ($lat === 0.0 && $lon === 0.0) {
            return '⚠️ Bitte zuerst einen Punkt auf der Karte auswählen.';
        }
        $rows = $this->decodeStandorte();
        $rows[] = [
            'Name' => 'Neuer Standort (Karte)',
            'Ort' => '',
            'Lat' => round($lat, 5),
            'Lon' => round($lon, 5),
            'RadiusKm' => 10.0,
            'MinSeverity' => 2,
            'Aktiv' => true,
        ];
        $this->UpdateFormField('Standorte', 'values', json_encode($rows));
        $this->UpdateFormField('Standorte', 'rowCount', $this->listRowCount(count($rows)));
        return sprintf('✅ Standort übernommen (Lat %s / Lon %s) -- bitte unten „Übernehmen" klicken, um zu speichern.', round($lat, 5), round($lon, 5));
    }

    // ----------------------------------------------------------------
    //  Kategorisierung / Formatierung
    // ----------------------------------------------------------------

    private function classifyEventCategory(string $event, string $headline): string
    {
        $haystack = mb_strtolower($event . ' ' . $headline);
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($haystack, $kw) !== false) {
                    return $category;
                }
            }
        }
        return 'sonstige';
    }

    private function severityRank(string $severity): int
    {
        return self::SEVERITY_RANK[$severity] ?? 0;
    }

    private function formatDateDe(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return 'unbekannt';
        }
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        return date('d.m.Y H:i', $ts);
    }

    // ----------------------------------------------------------------
    //  HTTP-Helfer
    // ----------------------------------------------------------------

    private function httpGet(string $url, int $timeout = 20, ?string $userAgent = null): ?string
    {
        if (!function_exists('curl_init')) {
            $this->LogError('httpGet', 'cURL-Erweiterung fehlt -- kann ' . $url . ' nicht abrufen.');
            return null;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent ?? ('WarnHub/' . self::DOC_VERSION));
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $this->SendDebug('httpGet', $url . ' -- cURL-Fehler: ' . $err, 0);
            return null;
        }
        if ($code >= 400) {
            $this->SendDebug('httpGet', $url . ' -- HTTP ' . $code, 0);
            return null;
        }
        return (string) $resp;
    }

    private function httpGetJson(string $url): ?array
    {
        $body = $this->httpGet($url);
        if ($body === null) {
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    private function LogError(string $context, string $message): void
    {
        $this->SendDebug($context, $message, 0);
        IPS_LogMessage('WarnHub #' . $this->InstanceID, $context . ': ' . $message);
    }

    // ----------------------------------------------------------------
    //  NINA-Anbindung (warnung.bund.de -- Aggregation von MoWaS/Katwarn/
    //  Biwapp/DWD/Hochwasser/Polizei, von der offiziellen BBK-App NINA
    //  genutzt und über bund.dev community-dokumentiert)
    // ----------------------------------------------------------------

    private const NINA_CHANNELS = ['mowas', 'katwarn', 'biwapp', 'dwd', 'lhp', 'police'];

    /**
     * @param array<int,string> $channels welche NINA-Kanäle abgefragt werden (Default: alle)
     * @return array<int,array<string,mixed>> normalisierte Warnungen
     */
    private function fetchNina(array $channels = self::NINA_CHANNELS): array
    {
        $out = [];
        foreach ($channels as $channel) {
            $list = $this->httpGetJson("https://warnung.bund.de/api31/{$channel}/mapData.json");
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $item) {
                $id = (string) ($item['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $detail = $this->fetchNinaDetail($id);
                if ($detail === null) {
                    continue;
                }
                $detail['channel'] = $channel;
                $out[] = $detail;
            }
        }
        return $out;
    }

    private function fetchNinaDetail(string $id): ?array
    {
        $meta = $this->httpGetJson('https://warnung.bund.de/api31/warnings/' . rawurlencode($id) . '.json');
        if ($meta === null || !isset($meta['info'][0])) {
            return null;
        }
        $infoDe = null;
        foreach ($meta['info'] as $info) {
            if (($info['language'] ?? '') === 'de-DE' || ($info['language'] ?? '') === 'de') {
                $infoDe = $info;
                break;
            }
        }
        $infoDe = $infoDe ?? $meta['info'][0];

        $geo = $this->httpGetJson('https://warnung.bund.de/api31/warnings/' . rawurlencode($id) . '.geojson');
        [$rings, $circles] = $this->extractGeoJsonGeometry($geo);

        $areaDesc = '';
        foreach (($infoDe['area'] ?? []) as $area) {
            if (!empty($area['areaDesc'])) {
                $areaDesc = $areaDesc === '' ? $area['areaDesc'] : $areaDesc . '; ' . $area['areaDesc'];
            }
        }

        return [
            'identifier' => (string) ($meta['identifier'] ?? $id),
            'source' => 'nina',
            'msgType' => (string) ($meta['msgType'] ?? 'Alert'),
            'event' => (string) ($infoDe['event'] ?? ''),
            'headline' => (string) ($infoDe['headline'] ?? ($infoDe['event'] ?? 'Warnung')),
            'description' => (string) ($infoDe['description'] ?? ''),
            'instruction' => (string) ($infoDe['instruction'] ?? ''),
            'severity' => (string) ($infoDe['severity'] ?? 'Unknown'),
            'effective' => $infoDe['effective'] ?? ($meta['sent'] ?? null),
            'onset' => $infoDe['onset'] ?? null,
            'expires' => $infoDe['expires'] ?? null,
            'areaDesc' => $areaDesc,
            'rings' => $rings,
            'circles' => $circles,
        ];
    }

    /** GeoJSON liefert Ringe als [lon,lat] -- hier auf das intern durchgehend genutzte [lat,lon] gedreht. */
    private function extractGeoJsonGeometry(?array $geo): array
    {
        $rings = [];
        $circles = [];
        if (!is_array($geo) || !isset($geo['features'])) {
            return [$rings, $circles];
        }
        foreach ($geo['features'] as $feature) {
            $geom = $feature['geometry'] ?? null;
            if (!is_array($geom)) {
                continue;
            }
            $type = $geom['type'] ?? '';
            if ($type === 'Polygon') {
                foreach ($geom['coordinates'] as $ring) {
                    $rings[] = $this->flipLonLat($ring);
                }
            } elseif ($type === 'MultiPolygon') {
                foreach ($geom['coordinates'] as $polygon) {
                    foreach ($polygon as $ring) {
                        $rings[] = $this->flipLonLat($ring);
                    }
                }
            } elseif ($type === 'Point' && isset($feature['properties']['radius'])) {
                $circles[] = [
                    'lat' => (float) $geom['coordinates'][1],
                    'lon' => (float) $geom['coordinates'][0],
                    'radiusKm' => (float) $feature['properties']['radius'],
                ];
            }
        }
        return [$rings, $circles];
    }

    /** @return array<array{0:float,1:float}> */
    private function flipLonLat(array $ring): array
    {
        $out = [];
        foreach ($ring as $pair) {
            if (is_array($pair) && count($pair) >= 2) {
                $out[] = [(float) $pair[1], (float) $pair[0]];
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------
    //  Direkte DWD-CAP-Anbindung (opendata.dwd.de -- reichhaltigere
    //  Wetterwarnungsdetails inkl. Instruction, ergänzend zur NINA-Quelle)
    // ----------------------------------------------------------------

    // COMMUNEUNION_CELLS = gemeindegenaue Polygone (praeziser als DISTRICT_CELLS).
    // DWD veroeffentlicht unter demselben Namen laufend ein neues ZIP (live
    // verifiziert, 04.09.2026) -- kein Verzeichnis-Scan noetig, nur bei einem
    // fehlenden LATEST-Alias faellt findLatestDwdZipUrl() auf den Scan zurueck.
    private const DWD_CAP_DIR = 'https://opendata.dwd.de/weather/alerts/cap/COMMUNEUNION_CELLS_STAT/';
    private const DWD_CAP_LATEST = self::DWD_CAP_DIR . 'Z_CAP_C_EDZW_LATEST_PVW_STATUS_PREMIUMCELLS_COMMUNEUNION_DE.zip';

    /** @return array<int,array<string,mixed>> */
    private function fetchDwdCap(): array
    {
        if (!function_exists('curl_init')) {
            $this->LogError('fetchDwdCap', 'cURL-Erweiterung fehlt -- DWD-Direktquelle übersprungen.');
            return [];
        }
        if (!class_exists('ZipArchive')) {
            $this->LogError('fetchDwdCap', 'ZipArchive-Erweiterung fehlt -- DWD-Direktquelle übersprungen (NINA liefert DWD-Warnungen weiterhin, nur ohne dieses Zusatzdetail).');
            return [];
        }

        $body = $this->httpGet(self::DWD_CAP_LATEST, 30);
        if ($body === null) {
            $zipUrl = $this->findLatestDwdZipUrl();
            if ($zipUrl === null) {
                $this->SendDebug('fetchDwdCap', 'Kein aktuelles DE-ZIP im DWD-Verzeichnis gefunden.', 0);
                return [];
            }
            $body = $this->httpGet($zipUrl, 30);
            if ($body === null) {
                return [];
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'whub_cap_');
        file_put_contents($tmpFile, $body);

        $out = [];
        $zip = new ZipArchive();
        if ($zip->open($tmpFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $xml = $zip->getFromIndex($i);
                if ($xml === false) {
                    continue;
                }
                $parsed = $this->parseCapXml($xml);
                if ($parsed !== null) {
                    $out[] = $parsed;
                }
            }
            $zip->close();
        } else {
            $this->LogError('fetchDwdCap', 'ZIP konnte nicht geöffnet werden: ' . $zipUrl);
        }
        @unlink($tmpFile);

        return $out;
    }

    private function findLatestDwdZipUrl(): ?string
    {
        $html = $this->httpGet(self::DWD_CAP_DIR, 15);
        if ($html === null) {
            return null;
        }
        if (!preg_match_all('/href="([^"]+_DE\.zip)"/', $html, $matches)) {
            return null;
        }
        $files = $matches[1];
        if (count($files) === 0) {
            return null;
        }
        sort($files); // Dateinamen tragen den Zeitstempel -- lexikografisch == chronologisch
        return self::DWD_CAP_DIR . end($files);
    }

    private function parseCapXml(string $xml): ?array
    {
        $prevUseErrors = libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($xml);
        libxml_use_internal_errors($prevUseErrors);
        if ($sxml === false) {
            return null;
        }

        $identifier = (string) $sxml->identifier;
        $msgType = (string) $sxml->msgType;
        if ($identifier === '') {
            return null;
        }

        $infoDe = null;
        foreach ($sxml->info as $info) {
            $lang = (string) $info->language;
            if ($lang === 'de-DE' || $lang === 'de') {
                $infoDe = $info;
                break;
            }
        }
        if ($infoDe === null) {
            return null;
        }

        $rings = [];
        $circles = [];
        foreach ($infoDe->area as $area) {
            foreach ($area->polygon as $polygon) {
                $rings[] = $this->parseCapPolygon((string) $polygon);
            }
            foreach ($area->circle as $circle) {
                $c = $this->parseCapCircle((string) $circle);
                if ($c !== null) {
                    $circles[] = $c;
                }
            }
        }

        $areaDesc = '';
        foreach ($infoDe->area as $area) {
            $d = (string) $area->areaDesc;
            if ($d !== '') {
                $areaDesc = $areaDesc === '' ? $d : $areaDesc . '; ' . $d;
            }
        }

        return [
            'identifier' => $identifier,
            'source' => 'dwd_direct',
            'msgType' => $msgType !== '' ? $msgType : 'Alert',
            'event' => (string) $infoDe->event,
            'headline' => (string) ($infoDe->headline ?: $infoDe->event ?: 'Warnung'),
            'description' => (string) $infoDe->description,
            'instruction' => (string) $infoDe->instruction,
            'severity' => (string) ($infoDe->severity ?: 'Unknown'),
            'effective' => (string) $infoDe->effective ?: null,
            'onset' => (string) $infoDe->onset ?: null,
            'expires' => (string) $infoDe->expires ?: null,
            'areaDesc' => $areaDesc,
            'rings' => $rings,
            'circles' => $circles,
        ];
    }

    /** CAP-Polygon: "lat,lon lat,lon ..." (LAT zuerst -- anders als GeoJSON). */
    private function parseCapPolygon(string $text): array
    {
        $ring = [];
        foreach (preg_split('/\s+/', trim($text)) as $pair) {
            $parts = explode(',', $pair);
            if (count($parts) === 2) {
                $ring[] = [(float) $parts[0], (float) $parts[1]];
            }
        }
        return $ring;
    }

    /** CAP-Circle: "lat,lon radiusKm". */
    private function parseCapCircle(string $text): ?array
    {
        $parts = preg_split('/\s+/', trim($text));
        if (count($parts) !== 2) {
            return null;
        }
        $center = explode(',', $parts[0]);
        if (count($center) !== 2) {
            return null;
        }
        return ['lat' => (float) $center[0], 'lon' => (float) $center[1], 'radiusKm' => (float) $parts[1]];
    }

    // ----------------------------------------------------------------
    //  PEGELONLINE (WSV -- Bund) -- Wasserstände aller Bundeswasserstraßen-
    //  Pegel. Live-verifiziert 04.09.2026 (786 Stationen): KEIN offizielles
    //  Meldestufen-Konzept in dieser API, nur ein Vergleich des aktuellen
    //  Werts gegen zwei charakteristische Wasserstände je Pegel: MNW/MHW
    //  (mittleres Niedrig-/Hochwasser, "normaler" Schwankungsbereich) und
    //  NSW/HSW (bisheriger Niedrigst-/Höchstwert seit Messbeginn). 'high' bei
    //  MNW/MHW ist ein alltäglicher Vorgang (Herbst-/Frühjahrshochwasser),
    //  'high' bei NSW/HSW bedeutet: aktueller Pegel auf/über dem bisherigen
    //  historischen Höchststand -- deutlich seltener und ernster.
    // ----------------------------------------------------------------

    private const PEGELONLINE_URL = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2/stations.json?includeTimeseries=true&includeCurrentMeasurement=true';

    /** @return array<int,array<string,mixed>> */
    private function fetchPegelonline(): array
    {
        $stations = $this->httpGetJson(self::PEGELONLINE_URL);
        if (!is_array($stations)) {
            return [];
        }
        $out = [];
        foreach ($stations as $station) {
            foreach (($station['timeseries'] ?? []) as $ts) {
                if (($ts['shortname'] ?? '') !== 'W') {
                    continue; // nur Wasserstand, nicht Wassertemperatur o. ä.
                }
                $cm = $ts['currentMeasurement'] ?? null;
                if (!is_array($cm)) {
                    continue;
                }
                $mnwMhw = (string) ($cm['stateMnwMhw'] ?? 'unknown');
                $nswHsw = (string) ($cm['stateNswHsw'] ?? 'unknown');
                if ($mnwMhw !== 'high' && $nswHsw !== 'high') {
                    continue; // kein erhöhter Pegel -- kein Eintrag (kein "Cancel" nötig, siehe processWarnings()-Bereinigung)
                }
                $name = (string) ($station['longname'] ?? $station['shortname'] ?? 'Pegel');
                $water = (string) ($station['water']['longname'] ?? '');
                $valueCm = (float) ($cm['value'] ?? 0);
                $severity = $nswHsw === 'high' ? 'Extreme' : 'Moderate';
                $out[] = [
                    'identifier' => 'pegelonline-' . (string) ($station['uuid'] ?? $name),
                    'source' => 'pegelonline',
                    'msgType' => 'Alert',
                    'event' => 'Hochwasser',
                    'headline' => sprintf('Erhöhter Pegel %s%s', $name, $water !== '' ? " ($water)" : ''),
                    'description' => sprintf(
                        'Aktueller Wasserstand %s cm -- %s',
                        rtrim(rtrim(number_format($valueCm, 0, ',', '.'), '0'), ','),
                        $nswHsw === 'high'
                            ? 'liegt auf/über dem bisherigen Höchstwert (HSW) seit Messbeginn.'
                            : 'liegt über dem mittleren Hochwasser (MHW) -- ein für die Jahreszeit ggf. normaler Vorgang.'
                    ),
                    'instruction' => '',
                    'severity' => $severity,
                    'effective' => $cm['timestamp'] ?? null,
                    'onset' => null,
                    'expires' => null,
                    'areaDesc' => $name,
                    'rings' => [],
                    'circles' => [['lat' => (float) ($station['latitude'] ?? 0), 'lon' => (float) ($station['longitude'] ?? 0), 'radiusKm' => 3.0]],
                ];
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------
    //  BfS ODL-Info (Ortsdosisleistung/Radioaktivität) -- 1.676 Messstellen
    //  bundesweit, stündlich. Live-verifiziert 04.09.2026: normaler
    //  Schwankungsbereich ca. 0,05-0,23 µSv/h je nach Untergrund/Höhenlage.
    //  Die API liefert NUR Rohwerte, KEINE amtliche Meldeschwelle -- der
    //  Vergleichswert ist deshalb bewusst ein einstellbarer eigener
    //  Schwellwert, nicht als amtliche Alarmstufe ausgegeben.
    // ----------------------------------------------------------------

    private const BFS_ODL_URL = 'https://www.imis.bfs.de/ogc/opendata/ows?service=WFS&version=1.1.0&request=GetFeature&typeName=opendata:odlinfo_odl_1h_latest&outputFormat=application/json';

    /** @return array<int,array<string,mixed>> */
    private function fetchBfsOdl(): array
    {
        $geo = $this->httpGetJson(self::BFS_ODL_URL);
        if (!is_array($geo) || !isset($geo['features'])) {
            return [];
        }
        $threshold = $this->ReadPropertyFloat('BfsOdlSchwellwert');
        if ($threshold <= 0) {
            $threshold = 0.3;
        }
        $out = [];
        foreach ($geo['features'] as $feature) {
            $props = $feature['properties'] ?? [];
            $value = (float) ($props['value'] ?? 0);
            if ($value < $threshold) {
                continue;
            }
            $coords = $feature['geometry']['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }
            $name = (string) ($props['name'] ?? $props['id'] ?? 'Messstelle');
            $out[] = [
                'identifier' => 'bfsodl-' . (string) ($props['id'] ?? $name),
                'source' => 'bfs_odl',
                'msgType' => 'Alert',
                'event' => 'Erhöhte Radioaktivität',
                'headline' => sprintf('Erhöhte Ortsdosisleistung: %s', $name),
                'description' => sprintf(
                    '%s µSv/h an Messstelle %s (eigener Schwellwert %s µSv/h -- keine amtliche Alarmstufe, die Rohdaten kennen keine offizielle Meldeschwelle).',
                    number_format($value, 3, ',', '.'),
                    $name,
                    number_format($threshold, 3, ',', '.')
                ),
                'instruction' => '',
                'severity' => 'Severe',
                'effective' => $props['end_measure'] ?? null,
                'onset' => null,
                'expires' => null,
                'areaDesc' => $name,
                'rings' => [],
                'circles' => [['lat' => (float) $coords[1], 'lon' => (float) $coords[0], 'radiusKm' => 10.0]],
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------
    //  Abfragezyklus: Poll, Matching, Push, Schutzaktionen
    // ----------------------------------------------------------------

    public function Poll(): string
    {
        // Live gegengeprüft (04.09.2026, .tools/test-live-fetch.php): DWD
        // veröffentlicht dieselbe Warnung als DISTRICT- und COMMUNEUNION-
        // Produkt mit JEWEILS EIGENEM CAP-Identifier -- ein Abgleich über den
        // Identifier ist deshalb nicht zuverlässig. Robuster: Ist die
        // DWD-Direktquelle aktiv, wird ihr NINA-Pendant (der "dwd"-Kanal)
        // gar nicht erst abgefragt, statt hinterher unsicher zu entdoppeln.
        $ninaChannels = self::NINA_CHANNELS;
        $dwdDirectActive = $this->ReadPropertyBoolean('QuelleDwd');
        if ($dwdDirectActive) {
            $ninaChannels = array_diff($ninaChannels, ['dwd']);
        }

        $warnings = [];
        if ($this->ReadPropertyBoolean('QuelleNina')) {
            $warnings = $this->fetchNina($ninaChannels);
        }
        if ($dwdDirectActive) {
            $warnings = array_merge($warnings, $this->fetchDwdCap());
        }
        if ($this->ReadPropertyBoolean('QuellePegelonline')) {
            $warnings = array_merge($warnings, $this->fetchPegelonline());
        }
        if ($this->ReadPropertyBoolean('QuelleBfsOdl')) {
            $warnings = array_merge($warnings, $this->fetchBfsOdl());
        }

        $result = $this->processWarnings($warnings);

        $this->WriteAttributeInteger('LastPollTs', time());
        $this->WriteAttributeString('LastActiveWarningsJson', json_encode($result['active']));
        @$this->UpdateFormField('PollStatusLabel', 'caption', $this->getPollStatusLine());

        $icon = $result['activeCount'] > 0 ? '⚠️' : '✅';
        return sprintf(
            '%s Prüfung abgeschlossen: %d aktive Warnung(en), %d neu gemeldet, %d Entwarnung(en), %d Schutzaktion(en) ausgelöst.',
            $icon,
            $result['activeCount'],
            $result['newlyPushed'],
            $result['cancelled'],
            $result['actionsTriggered']
        );
    }

    /** Schickt eine harmlose Testbenachrichtigung an alle aktivierten Push-Ziele, unabhängig von echten Warnungen -- zum Prüfen, ob der Zustellweg (WebFront/Kachel-Visualisierung, Signalton) tatsächlich ankommt. */
    public function TestPush(): string
    {
        $active = array_filter($this->decodeWebFronts(), fn ($w) => $w['Aktiv']);
        if (count($active) === 0) {
            return '⚠️ Kein aktiviertes Push-Ziel -- oben in "🔔 Benachrichtigung" mindestens eine Instanz aktivieren.';
        }
        $sent = $this->pushToAllWebfronts(
            $this->truncateBytes('🧪 WarnHub Test', 32),
            $this->truncateBytes('Wenn diese Meldung ankommt, funktioniert der Zustellweg. Keine echte Warnung.', 256),
            $this->ReadPropertyString('PushSound')
        );
        if ($sent === 0) {
            return sprintf('⚠️ Test fehlgeschlagen -- an keines der %d aktivierten Ziele konnte zugestellt werden (siehe Systemprotokoll).', count($active));
        }
        return sprintf('✅ Testbenachrichtigung an %d von %d aktivierten Ziel(en) gesendet.', $sent, count($active));
    }

    private function processWarnings(array $warnings): array
    {
        $standorte = array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '');
        $seen = json_decode($this->ReadAttributeString('SeenWarnings'), true) ?: [];
        $fired = json_decode($this->ReadAttributeString('FiredActions'), true) ?: [];
        $actions = array_filter($this->decodeSchutzaktionen(), fn ($a) => $a['Aktiv']);
        $pushSound = $this->ReadPropertyString('PushSound');
        $pushAktiv = $this->ReadPropertyBoolean('PushAktiv');

        $stillPresent = [];
        $active = [];
        $newlyPushed = 0;
        $cancelled = 0;
        $actionsTriggered = 0;

        foreach ($warnings as $w) {
            $stillPresent[$w['identifier']] = true;
            $category = $this->classifyEventCategory($w['event'], $w['headline']);

            foreach ($standorte as $standort) {
                $pairKey = $w['identifier'] . '|' . $standort['Name'];
                $coords = $this->resolveStandortCoords($standort);
                $pushZiele = $this->parsePushZielNames($standort['PushZielFilter']);

                $hasGeo = count($w['rings']) > 0 || count($w['circles']) > 0;
                $distanceKm = $hasGeo
                    ? WHUB_Geo::distanceToAny($coords['lat'], $coords['lon'], $w['rings'], $w['circles'])
                    : null;
                $matches = $hasGeo && $distanceKm !== null && $distanceKm <= $standort['RadiusKm'];
                if (!$matches) {
                    continue;
                }
                if ($this->severityRank($w['severity']) < $standort['MinSeverity']) {
                    continue;
                }

                if ($w['msgType'] === 'Cancel') {
                    if (isset($seen[$pairKey])) {
                        unset($seen[$pairKey]);
                        if ($pushAktiv) {
                            $this->pushToAllWebfronts(
                                '✅ Entwarnung',
                                $this->truncateBytes($standort['Name'] . ': ' . $w['headline'] . ' aufgehoben.', 256),
                                $pushSound,
                                $pushZiele
                            );
                        }
                        $cancelled++;
                    }
                    continue;
                }

                $active[] = [
                    'identifier' => $w['identifier'],
                    'standort' => $standort['Name'],
                    'event' => $w['event'],
                    'headline' => $w['headline'],
                    'description' => $w['description'],
                    'severity' => $w['severity'],
                    'category' => $category,
                    'source' => $w['source'],
                    'distanceKm' => $distanceKm !== null ? round($distanceKm, 1) : null,
                    'effective' => $w['effective'],
                    'expires' => $w['expires'],
                ];

                if (!isset($seen[$pairKey])) {
                    $seen[$pairKey] = ['msgType' => $w['msgType'], 'pushedAt' => time()];
                    if ($pushAktiv) {
                        $this->pushToAllWebfronts(
                            $this->buildPushTitle($w['severity'], $w['event']),
                            $this->buildPushText($standort['Name'], $w),
                            $pushSound,
                            $pushZiele
                        );
                    }
                    $newlyPushed++;
                }

                foreach ($actions as $idx => $action) {
                    if ($action['StandortFilter'] !== '' && $action['StandortFilter'] !== $standort['Name']) {
                        continue;
                    }
                    // Schutzaktionen hängen an fest verbauten Geräten (Jalousie,
                    // Markise, Garage, Sirene) -- OHNE explizit gesetzten
                    // Standort-Filter dürfen sie deshalb NUR von einem festen
                    // Standort ausgelöst werden, nie von einem mobilen
                    // (Live-Standort-gebundenen). Sonst würde z. B. ein Sturm
                    // über Hamburg -- der nur den mobilen Standort "unterwegs"
                    // trifft -- die zuhause verbaute Jalousie einfahren
                    // (Dietmars Nachfrage 04.09.2026, direkt nach Einführung
                    // des mobilen Standorts). Wer eine Aktion GEZIELT an einen
                    // mobilen Standort binden will, kann das weiterhin über den
                    // expliziten Namen im Filter tun -- nur das leere "alle"
                    // schließt mobile Standorte automatisch aus.
                    if ($action['StandortFilter'] === '' && $this->isStandortMobil($standort)) {
                        continue;
                    }
                    // Leere Kategorien-Auswahl = Aktion gilt für JEDE Kategorie.
                    if (count($action['Kategorien']) > 0 && !in_array($category, $action['Kategorien'], true)) {
                        continue;
                    }
                    if ($this->severityRank($w['severity']) < $action['MinSeverity']) {
                        continue;
                    }
                    $fireKey = $w['identifier'] . '|' . $idx;
                    if (isset($fired[$fireKey])) {
                        continue;
                    }
                    $fired[$fireKey] = time();
                    $this->fireProtectiveAction($action);
                    $actionsTriggered++;
                }
            }
        }

        // Warnungen, die im aktuellen Abruf nicht mehr auftauchen (abgelaufen/
        // aus der Quelle entfernt), still aus dem "gesehen"-Speicher nehmen --
        // kein Cancel-Ereignis vorhanden, daher keine Entwarnungs-Push, nur
        // Bereinigung, damit der Zustand nicht dauerhaft "aktiv" bleibt.
        foreach (array_keys($seen) as $pairKey) {
            $identifier = strstr($pairKey, '|', true) ?: $pairKey;
            if (!isset($stillPresent[$identifier])) {
                unset($seen[$pairKey]);
            }
        }
        foreach (array_keys($fired) as $fireKey) {
            $identifier = strstr($fireKey, '|', true) ?: $fireKey;
            if (!isset($stillPresent[$identifier])) {
                unset($fired[$fireKey]);
            }
        }

        $this->WriteAttributeString('SeenWarnings', json_encode($seen));
        $this->WriteAttributeString('FiredActions', json_encode($fired));

        return [
            'active' => $active,
            'activeCount' => count($active),
            'newlyPushed' => $newlyPushed,
            'cancelled' => $cancelled,
            'actionsTriggered' => $actionsTriggered,
        ];
    }

    /**
     * WFC_PushNotification/VISU_PostNotificationEx begrenzen Titel/Text laut
     * offizieller Doku auf 32/256 Zeichen -- ob intern in Zeichen oder BYTES
     * geprüft wird, ist nirgends dokumentiert. Kürzt deshalb vorsichtshalber
     * nach Bytes (UTF-8-sicher, schneidet nie mitten in einem Mehrbyte-
     * Zeichen ab): ein Emoji im Titel (4 Byte) plus Umlaute im Ereignisnamen
     * können sonst die Byte-Grenze überschreiten, obwohl mb_substr() nach
     * Zeichen längst darunter läge (Dietmars hartnäckiger Live-Fund
     * 04.09.2026 -- Test-Push scheiterte trotz korrektem TargetID weiter).
     */
    private function truncateBytes(string $str, int $maxBytes): string
    {
        if (strlen($str) <= $maxBytes) {
            return $str;
        }
        $result = '';
        $bytes = 0;
        foreach (mb_str_split($str) as $char) {
            $charBytes = strlen($char);
            if ($bytes + $charBytes > $maxBytes) {
                break;
            }
            $result .= $char;
            $bytes += $charBytes;
        }
        return $result;
    }

    private function buildPushTitle(string $severity, string $event): string
    {
        $icon = self::SEVERITY_ICON[$severity] ?? '⚠️';
        $label = mb_convert_case(mb_strtolower(trim($event) !== '' ? $event : 'Warnung'), MB_CASE_TITLE);
        $title = $icon . ' ' . $label;
        return $this->truncateBytes($title, 32);
    }

    private function buildPushText(string $standortName, array $w): string
    {
        $text = $standortName . ': ' . $w['headline'];
        if ($w['description'] !== '') {
            $text .= '. ' . $w['description'];
        }
        if ($w['expires'] !== null) {
            $text .= ' Gültig bis ' . $this->formatDateDe($w['expires']) . ' Uhr.';
        }
        return $this->truncateBytes($text, 256);
    }

    /**
     * Pusht an alle aktivierten Ziele -- je nach Typ per WFC_PushNotification
     * (WebFront) oder VISU_PostNotificationEx (Kachel-Visualisierung), siehe
     * discoverPushTargets(). $onlyNames (bereits über parsePushZielNames()
     * kleingeschrieben) schränkt optional auf namentlich genannte Ziele ein
     * -- leer = an alle aktivierten Ziele (bisheriges Verhalten), genutzt für
     * Standorte mit eigenem "Push nur an ..."-Filter (z. B. ein mobiler
     * Standort soll nur das zugehörige Handy benachrichtigen, nicht auch das
     * der anderen Person).
     */
    private function pushToAllWebfronts(string $title, string $text, string $sound, array $onlyNames = []): int
    {
        $sent = 0;
        foreach ($this->decodeWebFronts() as $w) {
            if (!$w['Aktiv']) {
                continue;
            }
            if (count($onlyNames) > 0 && !in_array(mb_strtolower(trim($w['Name'])), $onlyNames, true)) {
                continue;
            }
            // Kachel-Visualisierung: VISU_PostNotificationEx (Icon+Sound
            // GETRENNT) statt der einfachen VISU_PostNotification -- Live-
            // Fund 04.09.2026, zwei unabhängige reale Referenzen (Wilkware/
            // WeatherWarning-Modul + ein von Dietmar gefundenes Müllabfuhr-
            // Erinnerungsskript) nutzen beide ausschließlich die Ex-Variante
            // mit TargetID=0; jeder bisherige Versuch mit der einfachen
            // Funktion (egal welche TargetID) schlug bei Dietmar fehl. Sound-
            // Werteliste laut offizieller Doku IDENTISCH zu WFC_PushNotification
            // (alarm/bell/boom/...), Icon separat und nicht sicherheitsrelevant
            // für die Zustellung selbst.
            if ($w['Typ'] === 'kachel') {
                if (!function_exists('VISU_PostNotificationEx')) {
                    $this->LogError('pushToAllWebfronts', 'VISU_PostNotificationEx ist nicht verfügbar (keine Kachel-Visualisierung installiert).');
                    continue;
                }
                $ok = @VISU_PostNotificationEx($w['InstanceID'], $title, $text, 'Alert', $sound, 0);
            } else {
                if (!function_exists('WFC_PushNotification')) {
                    $this->LogError('pushToAllWebfronts', 'WFC_PushNotification ist nicht verfügbar (kein WebFront-Modul installiert).');
                    continue;
                }
                $ok = @WFC_PushNotification($w['InstanceID'], $title, $text, $sound, $this->InstanceID);
            }
            if ($ok) {
                $sent++;
            } else {
                $this->LogError('pushToAllWebfronts', 'Push an Instanz ' . $w['InstanceID'] . ' (' . $w['Typ'] . ') fehlgeschlagen.');
            }
        }
        return $sent;
    }

    // ----------------------------------------------------------------
    //  Schutzaktionen
    // ----------------------------------------------------------------

    private function fireProtectiveAction(array $action): void
    {
        try {
            if ($action['Typ'] === 'skript') {
                if ($action['ZielSkriptID'] > 0 && IPS_ScriptExists($action['ZielSkriptID'])) {
                    IPS_RunScriptEx($action['ZielSkriptID'], ['WARNHUB_ACTION' => $action['Name']]);
                } else {
                    $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '": Ziel-Skript fehlt/existiert nicht.');
                }
                return;
            }

            if ($action['ZielVariableID'] <= 0 || !IPS_VariableExists($action['ZielVariableID'])) {
                $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '": Ziel-Variable fehlt/existiert nicht.');
                return;
            }

            if ($action['Typ'] === 'sirene') {
                $ok = @RequestAction($action['ZielVariableID'], true);
                if (!$ok) {
                    $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '" (Sirene): RequestAction fehlgeschlagen.');
                    return;
                }
                if ($action['AutoOffSekunden'] > 0) {
                    $this->scheduleSirenOff($action['ZielVariableID'], time() + $action['AutoOffSekunden']);
                }
                return;
            }

            // raffstore / markise / garage: Zielwert exakt wie vom Nutzer angegeben setzen
            $ok = @RequestAction($action['ZielVariableID'], $action['ZielWert']);
            if (!$ok) {
                $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '": RequestAction fehlgeschlagen.');
            }
        } catch (\Throwable $e) {
            $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '" warf einen Fehler: ' . $e->getMessage());
        }
    }

    private function scheduleSirenOff(int $variableID, int $offAtTs): void
    {
        $pending = json_decode($this->ReadAttributeString('PendingSirenOff'), true) ?: [];
        $pending[] = ['variableID' => $variableID, 'offAtTs' => $offAtTs];
        $this->WriteAttributeString('PendingSirenOff', json_encode($pending));
        $this->SetTimerInterval('SirenOffTimer', 10000);
    }

    public function CheckSirenOff(): void
    {
        $pending = json_decode($this->ReadAttributeString('PendingSirenOff'), true) ?: [];
        $now = time();
        $remaining = [];
        foreach ($pending as $p) {
            if ($p['offAtTs'] <= $now) {
                $ok = @RequestAction($p['variableID'], false);
                if (!$ok) {
                    $this->LogError('CheckSirenOff', 'Auto-Aus für Variable ' . $p['variableID'] . ' fehlgeschlagen.');
                }
            } else {
                $remaining[] = $p;
            }
        }
        $this->WriteAttributeString('PendingSirenOff', json_encode($remaining));
        $this->SetTimerInterval('SirenOffTimer', count($remaining) > 0 ? 10000 : 0);
    }

    // ----------------------------------------------------------------
    //  Öffentlicher Vertrag
    // ----------------------------------------------------------------

    public function GetActiveWarnings(): string
    {
        $active = json_decode($this->ReadAttributeString('LastActiveWarningsJson'), true) ?: [];
        return json_encode([
            'generatedAt' => $this->ReadAttributeInteger('LastPollTs'),
            'warnings' => $active,
        ]);
    }
}
