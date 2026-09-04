<?php

declare(strict_types=1);

// WebFront-Modul-GUID -- offiziell verifiziert gegen den Quellcode des
// echten Symcon-Kernmoduls "Benachrichtigung"
// (github.com/symcon/Benachrichtigung/blob/main/Notification/module.php,
// Zeile mit WFC_PushNotification). NICHT verwechseln mit der
// Konfigurator-/Kachel-GUID {B5B875BB-...}, die braucht VISU_PostNotification
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
    private const DOC_VERSION = '0.1.0-beta.2';
    private const NEWS_VERSION = '0.1.0-beta.2';
    private const LICENSE_URL = 'https://github.com/DG65/WarnHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/PLATZHALTER-warnhub-thread-folgt/00000';

    // Stichwörter für die automatische Schutzaktionen-Suche im Objektbaum
    // (Instanz-/Variablenname enthält eins der Wörter -> Aktionstyp-Vorschlag).
    private const DISCOVERY_KEYWORDS = [
        'raffstore' => ['raffstore', 'jalousie'],
        'garage' => ['garage', 'garagentor'],
        'sirene' => ['sirene', 'hupe', 'buzzer', 'signalhorn'],
    ];

    private const SEVERITY_RANK = ['Unknown' => 0, 'Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4];
    private const SEVERITY_ICON = ['Unknown' => 'ℹ️', 'Minor' => 'ℹ️', 'Moderate' => '⚠️', 'Severe' => '🚨', 'Extreme' => '🆘'];

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

    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Standorte', '[]');
        $this->RegisterPropertyBoolean('QuelleNina', true);
        $this->RegisterPropertyBoolean('QuelleDwd', true);
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
                ['type' => 'Label', 'caption' => 'Datenquellen: NINA-Aggregation (offiziell von der BBK-App genutzt, warnung.bund.de) und optional die direkten DWD-Wetterwarnungen (opendata.dwd.de).'],
                ['type' => 'Label', 'caption' => 'Radius-Prüfung erfolgt geometrisch gegen die tatsächliche Warnfläche (Polygon/Kreis der Meldung), nicht gegen Postleitzahlen/Gemeindegrenzen.'],
                ['type' => 'Label', 'caption' => 'Liegt zu einer Meldung keine Geometrie vor, wird sie sicherheitshalber NICHT automatisch zugeordnet (keine geratene Präzision).'],
                ['type' => 'Label', 'caption' => 'Konfigurationsverhalten bei WebFronts/Schutzaktionen: WarnHub durchsucht bei der Einrichtung automatisch den Objektbaum und schlägt Treffer VORAKTIVIERT vor (alle gefundenen WebFront-Instanzen, sowie Instanzen/Variablen mit "Raffstore"/"Jalousie"/"Garage"/"Sirene" im Namen). Nicht gewünschte Treffer lassen sich einfach über die Aktiv-Spalte abwählen -- eine erneute Suche überschreibt eigene Abwahl-Entscheidungen nicht.'],
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
                [
                    'type' => 'List',
                    'name' => 'Standorte',
                    'rowCount' => 5,
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'PLZ/Ort (Info)', 'name' => 'Ort', 'width' => '180px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Breitengrad (Lat)', 'name' => 'Lat', 'width' => '130px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 5]],
                        ['caption' => 'Längengrad (Lon)', 'name' => 'Lon', 'width' => '130px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 5]],
                        ['caption' => 'Umkreis (km)', 'name' => 'RadiusKm', 'width' => '110px', 'add' => 10.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1, 'minValue' => 0]],
                        ['caption' => 'Ab Schweregrad', 'name' => 'MinSeverity', 'width' => '150px', 'add' => 2, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
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
                ['type' => 'NumberSpinner', 'name' => 'PollIntervalMinutes', 'caption' => 'Abfragetakt (Minuten)', 'minValue' => 1, 'maxValue' => 60],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🔔  Benachrichtigung',
            'expanded' => true,
            'items' => [
                ['type' => 'CheckBox', 'name' => 'PushAktiv', 'caption' => 'Push-Benachrichtigung an aktivierte WebFront-Instanzen (auch Handy)'],
                ['type' => 'Select', 'name' => 'PushSound', 'caption' => 'Signalton', 'options' => $this->soundOptions()],
                [
                    'type' => 'Button',
                    'caption' => '🔎 WebFront-Instanzen suchen',
                    'onClick' => 'echo WHUB_DiscoverWebFronts($id);',
                ],
                ['type' => 'Label', 'caption' => $this->webfrontStatusLine()],
                ['type' => 'Label', 'caption' => 'Gefundene WebFront-Instanzen sind standardmäßig aktiv (bekommen Push) -- nicht gewünschte einfach über die Aktiv-Spalte abwählen. Eine erneute Suche fügt nur neue Instanzen hinzu und lässt bestehende Abwahl-Entscheidungen unangetastet.'],
                [
                    'type' => 'List',
                    'name' => 'WebFronts',
                    'rowCount' => 4,
                    'add' => false,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '260px'],
                        ['caption' => 'Instanz-ID', 'name' => 'InstanceID', 'width' => '100px'],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🛡️  Schutzaktionen (Jalousien/Raffstore, Garagentor, Sirenen, Skripte)',
            'expanded' => false,
            'items' => [
                ['type' => 'Label', 'caption' => 'Löst bei passender Warnung automatisch eine Aktion aus -- z. B. Raffstore hochfahren, Garagentor schließen, ein akustisches Signal schalten oder ein eigenes Skript ausführen. Jede Aktion feuert nur EINMAL je Warnung, es gibt keine automatische Rückstellung -- das bleibt bewusst Nutzerhandeln.'],
                [
                    'type' => 'PopupButton',
                    'caption' => 'Welche Felder brauche ich für welchen Aktionstyp?',
                    'popup' => [
                        'caption' => 'Felder je Aktionstyp',
                        'items' => [
                            ['type' => 'Label', 'caption' => 'Raffstore/Rollladen hochfahren, Garagentor schließen, Akustischer Alarm: Ziel-Variable (der schaltbare Wert, z. B. Rollladen-Position oder Torsteuerung) + Zielwert (der Wert, der beim Auslösen gesetzt wird -- je nach Hersteller unterschiedlich, z. B. 0 = offen/hochgefahren, bitte am eigenen Aktor prüfen).'],
                            ['type' => 'Label', 'caption' => 'Akustischer Alarm zusätzlich: Auto-Aus (Sekunden) -- 0 bedeutet kein automatisches Ausschalten.'],
                            ['type' => 'Label', 'caption' => 'Skript ausführen: Ziel-Skript statt Ziel-Variable/Zielwert.'],
                        ],
                    ],
                ],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Objektbaum nach Raffstore/Jalousie/Garage/Sirene durchsuchen',
                    'onClick' => 'echo WHUB_DiscoverSchutzaktionen($id);',
                ],
                ['type' => 'Label', 'caption' => 'Gefundene Treffer werden vorausgefüllt und AKTIVIERT als neue Zeile ergänzt (Schweregrad "Hoch" als vorsichtiger Standard) -- nicht gewünschte einfach über die Aktiv-Spalte abwählen. Eine erneute Suche lässt bestehende Zeilen/Abwahl-Entscheidungen unangetastet und fügt nur neue Treffer hinzu.'],
                [
                    'type' => 'List',
                    'name' => 'Schutzaktionen',
                    'rowCount' => 5,
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Typ', 'name' => 'Typ', 'width' => '190px', 'add' => 'raffstore', 'edit' => ['type' => 'Select', 'options' => $this->actionTypeOptions()]],
                        ['caption' => 'Auslöser', 'name' => 'Kategorie', 'width' => '150px', 'add' => 'alle', 'edit' => ['type' => 'Select', 'options' => $this->categoryOptions()]],
                        ['caption' => 'Ab Schweregrad', 'name' => 'MinSeverity', 'width' => '140px', 'add' => 3, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
                        ['caption' => 'Nur Standort (leer=alle)', 'name' => 'StandortFilter', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
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
            ],
        ];

        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }
        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
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

    private function categoryOptions(): array
    {
        return [
            ['caption' => 'Alle Kategorien', 'value' => 'alle'],
            ['caption' => 'Sturm/Wind', 'value' => 'sturm'],
            ['caption' => 'Hagel', 'value' => 'hagel'],
            ['caption' => 'Starkregen/Hochwasser', 'value' => 'starkregen'],
            ['caption' => 'Gewitter', 'value' => 'gewitter'],
            ['caption' => 'Schnee/Glätte', 'value' => 'schnee'],
            ['caption' => 'Hitze', 'value' => 'hitze'],
        ];
    }

    private function actionTypeOptions(): array
    {
        return [
            ['caption' => 'Raffstore/Rollladen hochfahren', 'value' => 'raffstore'],
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
     * Löst die WebFront-Modul-GUID zur LAUFZEIT über den Modulnamen auf, statt
     * sich allein auf eine hart hinterlegte GUID zu verlassen. Hintergrund
     * (Praxis-Fund 04.09.2026): die aus dem offiziellen Symcon-Kernmodul
     * "Benachrichtigung" entnommene GUID {3565B1F2-...} lieferte auf einer
     * echten Installation trotz vorhandener, aktiv genutzter WebFront-Instanz
     * null Treffer -- Ursache nicht abschließend geklärt (evtl. Versions-
     * unterschied), aber die Namenssuche ist robuster als jede feste GUID und
     * bleibt auch bei einer künftigen Symcon-Änderung korrekt.
     */
    private function resolveWebFrontModuleGuid(): ?string
    {
        foreach (@IPS_GetModuleList() ?: [] as $moduleID) {
            $m = @IPS_GetModule($moduleID);
            if (is_array($m) && strcasecmp((string) ($m['ModuleName'] ?? ''), 'WebFront') === 0) {
                return $moduleID;
            }
        }
        // Fallback auf die verifizierte, aber ggf. versionsabhängige GUID.
        if (count(@IPS_GetInstanceListByModuleID(WHUB_WEBFRONT_GUID) ?: []) > 0) {
            return WHUB_WEBFRONT_GUID;
        }
        return null;
    }

    /** @return array<int,array{InstanceID:int,Name:string,Aktiv:bool}> */
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
                'Aktiv' => (bool) ($w['Aktiv'] ?? true),
            ];
        }
        return $out;
    }

    /**
     * Sucht WebFront-Instanzen und ergänzt NUR neu gefundene (per
     * InstanceID abgeglichen) -- bestehende Zeilen samt eigener
     * Aktiv/Inaktiv-Entscheidung bleiben unangetastet. Schreibt wie
     * AddStandortFromSystemLocation() nur in die offene Formularmaske,
     * "Übernehmen" bleibt der bewusste letzte Schritt.
     */
    public function DiscoverWebFronts(): string
    {
        $guid = $this->resolveWebFrontModuleGuid();
        if ($guid === null) {
            return '⚠️ Keine WebFront-Instanz im Objektbaum gefunden.';
        }
        $found = @IPS_GetInstanceListByModuleID($guid) ?: [];
        $rows = $this->decodeWebFronts();
        $known = array_column($rows, null, 'InstanceID');
        $added = 0;
        foreach ($found as $instanceID) {
            if (isset($known[$instanceID])) {
                continue;
            }
            $rows[] = ['InstanceID' => $instanceID, 'Name' => @IPS_GetName($instanceID) ?: ('#' . $instanceID), 'Aktiv' => true];
            $added++;
        }
        $this->UpdateFormField('WebFronts', 'values', json_encode($rows));
        if ($added === 0 && count($rows) > 0) {
            return sprintf('ℹ️ Keine neuen WebFront-Instanzen gefunden (%d bereits bekannt). Bitte unten „Übernehmen" klicken, falls noch nicht gespeichert.', count($rows));
        }
        if ($added === 0) {
            return '⚠️ Keine WebFront-Instanz im Objektbaum gefunden.';
        }
        return sprintf('✅ %d neue WebFront-Instanz(en) gefunden und aktiviert (insgesamt %d) -- bitte unten „Übernehmen" klicken, um zu speichern.', $added, count($rows));
    }

    private function webfrontStatusLine(): string
    {
        $rows = $this->decodeWebFronts();
        $active = count(array_filter($rows, fn ($w) => $w['Aktiv']));
        if (count($rows) === 0) {
            return 'ℹ️ Noch keine WebFront-Instanz gesucht -- oben "🔎 WebFront-Instanzen suchen" klicken.';
        }
        if ($active === 0) {
            return sprintf('⚠️ %d WebFront-Instanz(en) gefunden, aber keine aktiviert -- Push-Benachrichtigungen kommen aktuell nirgends an.', count($rows));
        }
        return sprintf('✅ %d von %d gefundenen WebFront-Instanz(en) aktiv -- Push-Benachrichtigungen gehen dorthin.', $active, count($rows));
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
                ['type' => 'Label', 'caption' => 'Aktive Warnungen erscheinen als Push-Benachrichtigung auf allen WebFront-Geräten (auch Handy) und können optional Schutzaktionen auslösen -- z. B. Raffstore hochfahren oder das Garagentor schließen, bevor der Sturm da ist.'],
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
                ['type' => 'Label', 'caption' => '• Automatische Push-Benachrichtigung an gefundene, aktivierte WebFront-Instanzen'],
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
                'RadiusKm' => (float) ($s['RadiusKm'] ?? 10),
                'MinSeverity' => (int) ($s['MinSeverity'] ?? 2),
                'Aktiv' => (bool) ($s['Aktiv'] ?? true),
            ];
        }
        return $out;
    }

    /** @return array<int,array{Name:string,Aktiv:bool,Typ:string,Kategorie:string,MinSeverity:int,StandortFilter:string,ZielVariableID:int,ZielWert:float,ZielSkriptID:int,AutoOffSekunden:int}> */
    private function decodeSchutzaktionen(): array
    {
        $raw = json_decode($this->ReadPropertyString('Schutzaktionen'), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $a) {
            $out[] = [
                'Name' => (string) ($a['Name'] ?? ''),
                'Aktiv' => (bool) ($a['Aktiv'] ?? true),
                'Typ' => (string) ($a['Typ'] ?? 'raffstore'),
                'Kategorie' => (string) ($a['Kategorie'] ?? 'alle'),
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
        $rows = $this->decodeSchutzaktionen();
        $knownVarIDs = array_column($rows, 'ZielVariableID');
        $added = 0;

        $typeDefaults = [
            'raffstore' => ['Kategorie' => 'sturm', 'MinSeverity' => 3, 'AutoOff' => 0],
            'garage' => ['Kategorie' => 'alle', 'MinSeverity' => 3, 'AutoOff' => 0],
            'sirene' => ['Kategorie' => 'alle', 'MinSeverity' => 4, 'AutoOff' => 60],
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

                $defaults = $typeDefaults[$actionType];
                $rows[] = [
                    'Name' => (string) $obj['ObjectName'],
                    'Aktiv' => true,
                    'Typ' => $actionType,
                    'Kategorie' => $defaults['Kategorie'],
                    'MinSeverity' => $defaults['MinSeverity'],
                    'StandortFilter' => '',
                    'ZielVariableID' => $variableID,
                    'ZielWert' => 0.0,
                    'ZielSkriptID' => 0,
                    'AutoOffSekunden' => $defaults['AutoOff'],
                ];
                $knownVarIDs[] = $variableID;
                $added++;
                continue 2;
            }
        }

        $this->UpdateFormField('Schutzaktionen', 'values', json_encode($rows));
        if ($added === 0) {
            return 'ℹ️ Keine neuen Treffer für Raffstore/Jalousie/Garage/Sirene im Objektbaum gefunden.';
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
    private function mapDefaultLocation(): array
    {
        $loc = $this->getSystemLocation();
        if ($loc !== null) {
            return ['latitude' => $loc['lat'], 'longitude' => $loc['lon']];
        }
        return ['latitude' => 51.1657, 'longitude' => 10.4515];
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
        return sprintf('✅ Standort übernommen (Lat %s / Lon %s) -- bitte unten „Übernehmen" klicken, um zu speichern.', round($loc['lat'], 5), round($loc['lon'], 5));
    }

    /** $KartenStandort kommt vom 'SelectLocation'-Formularfeld -- laut SDK-Doku ein JSON-Objekt {latitude, longitude}, hier defensiv sowohl als String als auch als bereits dekodiertes Array akzeptiert. */
    public function AddStandortFromMap($KartenStandort): string
    {
        $loc = is_array($KartenStandort) ? $KartenStandort : json_decode((string) $KartenStandort, true);
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

                $hasGeo = count($w['rings']) > 0 || count($w['circles']) > 0;
                $distanceKm = $hasGeo
                    ? WHUB_Geo::distanceToAny($standort['Lat'], $standort['Lon'], $w['rings'], $w['circles'])
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
                                mb_substr($standort['Name'] . ': ' . $w['headline'] . ' aufgehoben.', 0, 256),
                                $pushSound
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
                            $pushSound
                        );
                    }
                    $newlyPushed++;
                }

                foreach ($actions as $idx => $action) {
                    if ($action['StandortFilter'] !== '' && $action['StandortFilter'] !== $standort['Name']) {
                        continue;
                    }
                    if ($action['Kategorie'] !== 'alle' && $action['Kategorie'] !== $category) {
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

    private function buildPushTitle(string $severity, string $event): string
    {
        $icon = self::SEVERITY_ICON[$severity] ?? '⚠️';
        $label = mb_convert_case(mb_strtolower(trim($event) !== '' ? $event : 'Warnung'), MB_CASE_TITLE);
        $title = $icon . ' ' . $label;
        return mb_substr($title, 0, 32);
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
        return mb_substr($text, 0, 256);
    }

    /** Pusht an alle in der (nutzerbearbeitbaren) WebFronts-Liste aktivierten Instanzen -- siehe DiscoverWebFronts(). */
    private function pushToAllWebfronts(string $title, string $text, string $sound): int
    {
        if (!function_exists('WFC_PushNotification')) {
            $this->LogError('pushToAllWebfronts', 'WFC_PushNotification ist nicht verfügbar (kein WebFront-Modul installiert).');
            return 0;
        }
        $sent = 0;
        foreach ($this->decodeWebFronts() as $w) {
            if (!$w['Aktiv']) {
                continue;
            }
            $ok = @WFC_PushNotification($w['InstanceID'], $title, $text, $sound, 0);
            if ($ok) {
                $sent++;
            } else {
                $this->LogError('pushToAllWebfronts', 'Push an WebFront-Instanz ' . $w['InstanceID'] . ' fehlgeschlagen.');
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

            // raffstore / garage: Zielwert exakt wie vom Nutzer angegeben setzen
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
