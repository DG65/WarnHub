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
    private const DOC_VERSION = '0.1.0-beta.31';
    private const NEWS_VERSION = '0.1.0-beta.31';
    private const LICENSE_URL = 'https://github.com/DG65/WarnHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/PLATZHALTER-warnhub-thread-folgt/00000';

    // Stichwörter für die automatische Schutzaktionen-Suche im Objektbaum
    // (Instanz-/Variablenname enthält eins der Wörter -> Aktionstyp-Vorschlag).
    private const DISCOVERY_KEYWORDS = [
        'raffstore' => ['raffstore', 'jalousie'],
        'markise' => ['markise', 'sonnenschutz'],
        'garage' => ['garage', 'garagentor'],
        // "fenster schließen", nicht nur "fenster" -- sonst würden auch
        // reine Fenster-OFFEN-Sensoren (keine Aktion, ohnehin durch
        // isActionableVariable() ausgefiltert, aber ambig benannt) sowie
        // fremde Geräte mit "Fenster" im Namen mit anfassen. Trifft exakt
        // Tessies eigene Standard-Variable "Fenster schließen"
        // (act_close_windows, sendet Teslas gerichteten close_windows-Befehl
        // -- sicher blind auslösbar, kein Umschalt-Risiko).
        'fenster' => ['fenster schließen', 'close_windows'],
        // Trifft Tessies eigene Aktion "Heckklappe öffnen/schließen"
        // (act_rear_trunk). Anders als bei "fenster" wird zusätzlich nach
        // einer passenden Zustands-Variable UNTER DERSELBEN INSTANZ gesucht
        // (Name enthält "klappenstatus", z. B. Tessies "Tür-/Klappenstatus")
        // -- Sonderbehandlung direkt im Suchlauf unten, siehe dortigen Kommentar.
        'kofferraum' => ['heckklappe'],
        'sirene' => ['sirene', 'hupe', 'buzzer', 'signalhorn'],
    ];

    // Namensmuster für die automatische Suche nach mobilen Standort-
    // Variablenpaaren (Lat/Lon), siehe DiscoverMobileStandorte() --
    // Dietmars Live-Fund 04.09.2026 (2 Tessie-Fahrzeuge + 3 Geofency-Profile).
    // 'prefix' filtert VORAB auf Kandidaten (z. B. "fahrzeugposition"), 'lat'/
    // 'lon' unterscheiden danach die beiden Achsen. Der Prefix ist zwingend
    // nötig, um Tessies "Fahrzeugposition – Breitengrad/Längengrad" (aktuelle
    // Position) NICHT mit "Zielposition – Breitengrad/Längengrad"
    // (Navigationsziel, live ebenfalls vorhanden) zu verwechseln -- ein reiner
    // "breitengrad"-Substring-Treffer würde beide gleichermaßen matchen.
    private const DISCOVERY_LATLON_PAIRS = [
        ['label' => 'Tessie/Fahrzeug', 'prefix' => 'fahrzeugposition', 'lat' => 'breitengrad', 'lon' => 'längengrad'],
        // "current" grenzt Geofencys aktuelle Position von dessen zusätzlicher,
        // gleichnamiger "Latitude"/"Longitude" ab (vermutlich Geofence-Zentrum,
        // nicht die Live-Position) -- live an Dietmars System geprüft.
        ['label' => 'Geofency', 'prefix' => 'current', 'lat' => 'latitude', 'lon' => 'longitude'],
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

    // Telegram (offizielles Symcon-Modul symcon/TelegramBot, Prefix TB) und
    // Pushover (Community-Modul timo-u/Symcon_Pushover, Prefix TUPO) --
    // beide GUIDs direkt gegen den module.json-Quellcode der jeweiligen
    // GitHub-Repos verifiziert (04.09.2026), nicht nur gegen deren README.
    // Anders als bei Hagelschutz Schweiz stand hier kein eigenes Testgerät
    // zur Verfügung (kein eigener Telegram-Bot/Pushover-Account) -- die
    // Funktionssignaturen selbst sind aber direkt aus dem echten
    // module.php-Quellcode entnommen, nicht geraten.
    private const TELEGRAM_BOT_GUID = '{32464EBD-4CCC-6174-4031-5AA374F7CD8D}';
    private const PUSHOVER_GUID = '{CAA4B646-5571-4B72-8897-7A3739B25C99}';

    // Froggit-Wetterstation (Modul "Froggit", Vendor HS) -- GUID live an
    // Dietmars System verifiziert (04.09.2026, Instanz #32052 "Wetterstation").
    // Für die Discovery zusätzlich Namenssuche als Fallback (andere
    // Wetterstationsmodule, gleiches Prinzip wie bei WebFront/Kachel-Visu).
    private const FROGGIT_GUID = '{499F8100-B051-E713-CEC0-499D795B2639}';

    // Zweites unterstütztes Wetterstations-Modul: Wolbolar/IPSymconWeatherStation
    // (Sainlogic/Froggit/ELV über das Wunderground-Protokoll, GUID + Idents
    // direkt gegen den echten module.json-/module.php-Quellcode auf GitHub
    // verifiziert, 04.09.2026 -- KEIN eigenes Testgerät verfügbar, wie bei
    // Telegram/Pushover also quellcode-, nicht live-verifiziert). Andere
    // Idents als Froggit: "Windgust" (Windböe) und "rainin" (aktuelle
    // Regenrate) statt "Windböe"/"Regenrate" -- deshalb per Ident, nicht per
    // Anzeigename gesucht (siehe findChildVariableByIdent()).
    private const WEATHERSTATION_WU_GUID = '{FBDB2770-0232-43D2-F40B-1240CEAF6CD4}';

    // Drittes unterstütztes Wetterstations-Modul: elueckel/Symcon_Meteobridge_Meteohub
    // (Meteobridge/Meteohub-Datenlogger, z. B. für DAVIS Vantage Vue und
    // viele weitere Marken -- ein Aggregator, deckt dadurch mit einem
    // einzigen Discovery-Pfad zusätzlich mehrere Fabrikate ab). GUID +
    // Idents direkt gegen den echten Quellcode verifiziert, 04.09.2026 --
    // ebenfalls kein eigenes Testgerät verfügbar. Ident "Wind_Gust_KmH"
    // (Profil bereits ~WindSpeed.kmh, keine Umrechnung nötig) und "Rain_Rate".
    private const METEOBRIDGE_GUID = '{24A6FC41-748D-4843-BEF9-0606DBB95CD3}';

    // Ruhephase, bevor eine durch die eigene Wetterstation ausgelöste
    // Schutzaktion automatisch zurückgestellt wird (siehe
    // checkWetterstationAutoRestore()) -- Wind/Regen müssen DURCHGEHEND so
    // lange unter der Moderate-Schwelle liegen, sonst würde eine kurze
    // Windböen-Pause mitten im Sturm die Markise fälschlich wieder
    // ausfahren. Dietmars Bestätigung 04.09.2026 (20 Minuten).
    private const WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN = 1200;

    // ISO-3166-1-alpha-2-Ländercode (wie von Nominatim reverse geliefert) ->
    // Länder-Slug der Meteoalarm-Feeds (feeds.meteoalarm.org). Live gegen
    // die tatsächliche Feed-Liste geprüft (04.09.2026, curl gegen
    // https://feeds.meteoalarm.org/), NICHT aus der ISO-Ländertabelle
    // angenommen -- Meteoalarm nutzt ausgeschriebene, teils ungewöhnlich
    // gebildete Slugs (z. B. "republic-of-north-macedonia", nicht "north-macedonia").
    private const METEOALARM_COUNTRY_SLUGS = [
        'ad' => 'andorra', 'at' => 'austria', 'be' => 'belgium', 'ba' => 'bosnia-herzegovina',
        'bg' => 'bulgaria', 'hr' => 'croatia', 'cy' => 'cyprus', 'cz' => 'czechia',
        'dk' => 'denmark', 'ee' => 'estonia', 'fi' => 'finland', 'fr' => 'france',
        'de' => 'germany', 'gr' => 'greece', 'hu' => 'hungary', 'is' => 'iceland',
        'ie' => 'ireland', 'il' => 'israel', 'it' => 'italy', 'lv' => 'latvia',
        'lt' => 'lithuania', 'lu' => 'luxembourg', 'mt' => 'malta', 'md' => 'moldova',
        'me' => 'montenegro', 'nl' => 'netherlands', 'no' => 'norway', 'pl' => 'poland',
        'pt' => 'portugal', 'mk' => 'republic-of-north-macedonia', 'ro' => 'romania',
        'rs' => 'serbia', 'sk' => 'slovakia', 'si' => 'slovenia', 'es' => 'spain',
        'se' => 'sweden', 'ch' => 'switzerland', 'ua' => 'ukraine', 'gb' => 'united-kingdom',
    ];

    // GeoSphere Austria Warn API (warnungen.zamg.at, live gegen die offizielle
    // OpenAPI-Spezifikation geprüft 04.09.2026, CC-BY-4.0, kein Zugangsschlüssel
    // nötig) -- amtliche, koordinatengenaue Warnungen für Österreich, präziser
    // als Meteoalarms Namensabgleich (siehe fetchGeosphereAt()). Codes laut
    // offizieller Doku: WarnType 1=storm,2=rain,3=snow,4=black ice,5=thunderstorm,
    // 6=heat,7=cold; WarnLevel 1=yellow,2=orange,3=red.
    private const GEOSPHERE_AT_URL = 'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords';
    private const GEOSPHERE_AT_WARNTYPE_EVENT = [
        1 => 'Sturm', 2 => 'Starkregen', 3 => 'Schnee', 4 => 'Glatteis',
        5 => 'Gewitter', 6 => 'Hitze', 7 => 'Kälte',
    ];
    private const GEOSPHERE_AT_WARNLEVEL_SEVERITY = [1 => 'Moderate', 2 => 'Severe', 3 => 'Extreme'];

    // BAFU-Hochwasserdaten über LINDAS (lindas.admin.ch, Schweizer Linked-
    // Data-Infrastruktur des Bundes) -- live geprüft 04.09.2026 (182 Stationen
    // mit numerischem dangerLevel, Query-Antwortzeit < 1s). Anders als
    // PEGELONLINE/BfS ist "dangerLevel" hier eine ECHTE amtliche Klassifikation
    // (BAFUs offizielle 5-stufige Gefahrenstufen-Skala für Hochwasser,
    // hydrodaten.admin.ch/de/die-5-gefahrenstufen-fuer-hochwasser), keine
    // Schwellwert-Eigenkonstruktion -- nur WELCHE Stufe WarnHub meldet, ist
    // einstellbar. Default-Endpunkt liefert ohne Accept-Header CSV (live
    // verifiziert), deshalb bewusst CSV statt JSON geparst -- keine Änderung
    // an der gemeinsamen httpGet()-Funktion nötig (kein Custom-Header-Support).
    private const BAFU_HYDRO_SPARQL_ENDPOINT = 'https://lindas.admin.ch/query';
    private const BAFU_HYDRO_LEVEL_LABEL = [
        1 => 'keine oder geringe Gefahr', 2 => 'mässige Gefahr', 3 => 'erhebliche Gefahr',
        4 => 'grosse Gefahr', 5 => 'sehr grosse Gefahr',
    ];
    private const BAFU_HYDRO_LEVEL_SEVERITY = [2 => 'Moderate', 3 => 'Severe', 4 => 'Severe', 5 => 'Extreme'];

    // BETA -- Hagelschutz-Signalbox der VKF (hagelschutz-einfach-automatisch.ch,
    // meteo.netitservices.com). Protokoll aus der offiziellen VKF-PDF-Doku UND
    // dem Quellcode des aktiven ioBroker-Adapters ice987987/ioBroker.hagelschutz
    // gegengeprüft 04.09.2026 (identischer Aufbau: einfacher GET, keine
    // Kopfzeilen/Auth, response.data.currentState). MANGELS eigener Signalbox
    // konnte der Live-Abruf selbst NICHT gegengeprüft werden (einzige Ausnahme
    // von der sonst in diesem Modul durchgehend befolgten "live verifizieren"-
    // Regel) -- deshalb bewusst als Beta gekennzeichnet. Die vollständige
    // Poll-URL (nicht nur deviceId/hwtypeId einzeln) wird als EIN Feld
    // gespeichert -- Vorbild ioBroker-Adapter, dessen Issue #156 ("Support new
    // API endpoint") zeigt, dass sich das URL-Format zwischen
    // Signalbox-Generationen/Zeitpunkten unterscheiden kann; ein selbst
    // zusammengebautes Template wäre dagegen nicht robust.
    private const HAGELSCHUTZ_CH_URL_PREFIX = 'https://meteo.netitservices.com/api/';

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
        $this->RegisterPropertyBoolean('QuelleMeteoalarm', false);
        $this->RegisterPropertyBoolean('QuelleGeosphereAt', false);
        $this->RegisterPropertyBoolean('QuelleBafuHydroCh', false);
        $this->RegisterPropertyInteger('BafuHydroSchwelle', 3);
        $this->RegisterPropertyString('HagelschutzPollUrl', '');
        $this->RegisterPropertyInteger('WetterstationInstanceID', 0);
        $this->RegisterPropertyInteger('WetterstationWindVariableID', 0);
        $this->RegisterPropertyInteger('WetterstationRegenVariableID', 0);
        $this->RegisterPropertyFloat('WetterstationWindSchwelleModerate', 40.0);
        $this->RegisterPropertyFloat('WetterstationWindSchwelleSevere', 65.0);
        $this->RegisterPropertyFloat('WetterstationWindSchwelleExtreme', 90.0);
        $this->RegisterPropertyFloat('WetterstationRegenSchwelleModerate', 15.0);
        $this->RegisterPropertyFloat('WetterstationRegenSchwelleSevere', 25.0);
        $this->RegisterPropertyFloat('WetterstationRegenSchwelleExtreme', 40.0);
        $this->RegisterPropertyBoolean('WetterstationAutoRueckstellung', false);
        $this->RegisterPropertyInteger('PollIntervalMinutes', 10);
        $this->RegisterPropertyBoolean('PushAktiv', true);
        $this->RegisterPropertyString('PushSound', 'alarm');
        $this->RegisterPropertyString('Schutzaktionen', '[]');
        $this->RegisterPropertyInteger('SchutzaktionVorlaufMinuten', 30);
        $this->RegisterPropertyString('WebFronts', '[]');

        $this->RegisterTimer('PollTimer', 0, 'WHUB_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SirenOffTimer', 0, 'WHUB_CheckSirenOff($_IPS[\'TARGET\']);');

        $this->RegisterAttributeString('SeenWarnings', '{}');
        $this->RegisterAttributeString('FiredActions', '{}');
        $this->RegisterAttributeString('PendingSirenOff', '[]');
        $this->RegisterAttributeInteger('LastPollTs', 0);
        $this->RegisterAttributeString('LastActiveWarningsJson', '[]');
        $this->RegisterAttributeString('WarnHistory', '[]');
        $this->RegisterAttributeInteger('PushSnoozeUntilTs', 0);
        $this->RegisterAttributeString('WetterstationRestoreState', '{}');
        $this->RegisterAttributeString('ReverseGeoCache', '{}');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        // Für IPSView & Co.: WarnHub ist ohne eigene Statusvariablen komplett
        // "headless" (nur Push + Konsolen-Statuszeile) -- IPSView baut eigene
        // Views aber ausschließlich aus VORHANDENEN Symcon-Objekt-IDs
        // zusammen (Live-Fund 04.09.2026, Dietmars Instanz #17903 hat KEINE
        // eigene Push-/Geräteregistrierung, nur einen View-Cache), es gibt
        // also keinen eigenen IPSView-Push-Mechanismus zum Andocken. Diese
        // vier Variablen sind der Anknüpfungspunkt für ein selbst gebautes
        // IPSView-Dashboard (oder jede andere Symcon-Visualisierung).
        if (!IPS_VariableProfileExists('WHUB.Schweregrad')) {
            IPS_CreateVariableProfile('WHUB.Schweregrad', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 0, 'Keine aktive Warnung', 'ℹ️', 0x00C853);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 1, 'Minor', 'ℹ️', 0x00C853);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 2, 'Moderate', '⚠️', 0xFFD600);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 3, 'Severe', '🚨', 0xFF6D00);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 4, 'Extreme', '🆘', 0xD50000);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainVariable('AktiveWarnungen', 'Aktive Warnungen', VARIABLETYPE_INTEGER, '', 1, true);
        $this->MaintainVariable('HoechsterSchweregrad', 'Höchster Schweregrad', VARIABLETYPE_INTEGER, 'WHUB.Schweregrad', 2, true);
        $this->MaintainVariable('StatusText', 'Status', VARIABLETYPE_STRING, '', 3, true);
        $this->MaintainVariable('LetztePruefung', 'Letzte Prüfung', VARIABLETYPE_INTEGER, '~UnixTimestamp', 4, true);
        $this->MaintainVariable('KachelStatus', 'Kachel (kompakt)', VARIABLETYPE_STRING, '~HTMLBox', 5, true);
        $this->MaintainVariable('KachelUebersicht', 'Kachel (Übersicht)', VARIABLETYPE_STRING, '~HTMLBox', 6, true);
        $this->refreshStatusVariables();

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

    /**
     * Aktualisiert die vier IPSView-tauglichen Statusvariablen aus dem
     * zuletzt bekannten Prüfungsstand (Attribute LastActiveWarningsJson/
     * LastPollTs) -- sowohl direkt nach ApplyChanges() (sofortiges Anzeigen
     * des letzten Standes, kein Warten auf den nächsten Poll-Zyklus) als
     * auch am Ende jedes echten Poll() (siehe dort).
     */
    private function refreshStatusVariables(): void
    {
        $active = json_decode($this->ReadAttributeString('LastActiveWarningsJson'), true) ?: [];
        $highest = 0;
        foreach ($active as $w) {
            $highest = max($highest, self::SEVERITY_RANK[$w['severity'] ?? 'Unknown'] ?? 0);
        }
        $lastTs = $this->ReadAttributeInteger('LastPollTs');
        $statusText = $lastTs === 0
            ? 'Noch keine Prüfung durchgeführt.'
            : (count($active) > 0
                ? sprintf('%d aktive Warnung(en)', count($active))
                : 'Keine aktive Warnung.');
        $snoozed = $this->isPushSnoozed();
        if ($snoozed) {
            $statusText .= sprintf(' 🔕 Push pausiert bis %s Uhr.', date('d.m. H:i', $this->ReadAttributeInteger('PushSnoozeUntilTs')));
        }
        @$this->SetValue('AktiveWarnungen', count($active));
        @$this->SetValue('HoechsterSchweregrad', $highest);
        @$this->SetValue('StatusText', $statusText);
        @$this->SetValue('LetztePruefung', $lastTs);
        @$this->SetValue('KachelStatus', $this->renderKachelStatus($active, $lastTs, $snoozed));
        @$this->SetValue('KachelUebersicht', $this->renderKachelUebersicht($active, $lastTs, $snoozed));
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
                ['type' => 'Label', 'caption' => 'Bündelt Warn- und Alarmmeldungen für Deutschland, Österreich und die Schweiz (D-A-CH) -- amtliche Quellen für Deutschland (Katastrophenschutz, Wetter, Hochwasser, Polizei, Pegel, Radioaktivität), europaweite Wetterwarnungen für 39 Länder (deckt Österreich/Schweiz mit ab) sowie optional die eigene Wetterstation -- und meldet nur, was innerhalb des selbst definierten Umkreises eines Standorts liegt (auch mobiler Standorte im Ausland).'],
                ['type' => 'Label', 'caption' => 'Datenquellen: NINA-Aggregation (offiziell von der BBK-App genutzt, warnung.bund.de, Deutschland), optional die direkten DWD-Wetterwarnungen (opendata.dwd.de, Deutschland), optional Pegelstände (PEGELONLINE/WSV, Deutschland), optional Radioaktivitäts-Messwerte (BfS Ortsdosisleistung, Deutschland), optional europaweite Wetterwarnungen (Meteoalarm, 39 Länder inkl. Österreich und Schweiz), optional koordinatengenaue Warnungen für Österreich (GeoSphere Austria/ZAMG), optional amtliche Hochwasser-Gefahrenstufen für die Schweiz (BAFU/LINDAS), optional die eigene Wetterstation als unabhängiges Sicherheitsnetz sowie -- BETA, ungetestet -- optional die eigene VKF-Hagelschutz-Signalbox (Schweiz).'],
                ['type' => 'Label', 'caption' => 'Bei PEGELONLINE, BfS ODL-Info und der eigenen Wetterstation gibt es keine amtliche Warnstufen-Klassifikation -- WarnHub meldet stattdessen einen erhöhten Pegel (über dem mittleren bzw. bisherigen Höchstwasser), eine Überschreitung des selbst eingestellten Strahlungs-Schwellwerts bzw. eine Überschreitung der selbst eingestellten Windböen-/Regenraten-Schwelle. Das ist keine amtliche Alarmstufe.'],
                ['type' => 'Label', 'caption' => 'Radius-Prüfung erfolgt geometrisch gegen die tatsächliche Warnfläche (Polygon/Kreis der Meldung), nicht gegen Postleitzahlen/Gemeindegrenzen.'],
                ['type' => 'Label', 'caption' => 'Liegt zu einer Meldung keine Geometrie vor, wird sie sicherheitshalber NICHT automatisch zugeordnet (keine geratene Präzision).'],
                ['type' => 'Label', 'caption' => 'Ein Standort kann statt fester Koordinaten auch an zwei Variablen (Lat/Lon) gebunden werden, z. B. aus Tessie oder Geofency -- WarnHub liest dann bei jeder Prüfung die aktuelle Position. Die Objektbaum-Suche im Standorte-Panel findet passende Variablenpaare automatisch und verknüpft sie direkt. Über "Push nur an" lässt sich außerdem festlegen, dass ein Standort nur bestimmte WebFronts benachrichtigt (z. B. je eine Person/ein Fahrzeug bei mehreren gleichzeitig genutzten Standorten).'],
                ['type' => 'Label', 'caption' => 'Schutzaktionen feuern NICHT schon bei Eingang einer Meldung, sondern erst kurz vor deren tatsächlichem Gültigkeitsbeginn (einstellbarer Vorlauf im Schutzaktionen-Panel) -- eine morgens eintreffende, aber erst für den Nachmittag gültige Warnung fährt die Markise also nicht schon morgens ein. Die Push-Benachrichtigung selbst bleibt davon unberührt und kommt weiterhin sofort.'],
                ['type' => 'Label', 'caption' => 'Konfigurationsverhalten bei Standorten/Push-Zielen/Schutzaktionen: WarnHub durchsucht bei der Einrichtung automatisch den Objektbaum und schlägt Treffer VORAKTIVIERT vor -- mobile Standorte (Tessie-Fahrzeugposition, Geofency), die eigene Wetterstation (Froggit), WebFront- und Kachel-Visualisierung-Instanzen, sowie Instanzen/Variablen mit "Raffstore"/"Jalousie"/"Markise"/"Garage"/"Fenster schließen"/"Heckklappe"/"Sirene" im Namen -- die beiden Letzteren passen insbesondere zu Tessies eigenen Tesla-Aktionen. Ein Kofferraum/Heckklappe-Treffer bleibt dabei ausnahmsweise INAKTIV, wenn keine passende Zustands-Variable danebengefunden wurde (Sicherheitssperre). Nicht gewünschte Treffer lassen sich einfach über die Aktiv-Spalte abwählen -- eine erneute Suche überschreibt eigene Abwahl-Entscheidungen nicht.'],
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
                    'type' => 'Button',
                    'caption' => '🔎 Fahrzeug-/Standort-Variablen suchen (mobiler Standort)',
                    'onClick' => 'echo WHUB_DiscoverMobileStandorte($id);',
                ],
                ['type' => 'Label', 'caption' => 'Durchsucht den Objektbaum nach bekannten Positions-Variablenpaaren (Tessie "Fahrzeugposition – Breitengrad/Längengrad", Geofency "Current Latitude/Longitude") und legt je Fund einen bereits mit den Live-Variablen verknüpften Standort an -- direkt aktiviert, "Live-Standort Lat/Lon" ist schon gesetzt. Nicht gewünschte Treffer einfach über die Aktiv-Spalte abwählen; Umkreis/Schweregrad danach noch prüfen. Eine erneute Suche ergänzt nur neue Funde.'],
                ['type' => 'Label', 'caption' => 'Mobiler Standort auch von Hand einrichtbar (z. B. aus Tessie- oder einer Geofency-Bridge-Variable): "Live-Standort Lat/Lon" auf die jeweilige Positions-Variable verweisen -- WarnHub liest dann bei jeder Prüfung die AKTUELLE Position daraus, Lat/Lon in der Tabelle sind dann nur der Startwert/Fallback. 0 = feste Koordinaten aus der Tabelle (bisheriges Verhalten).'],
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
                [
                    'type' => 'PopupButton',
                    'caption' => 'Was bedeutet dieser Wert? (Einordnung Dosisleistung/Verweildauer)',
                    'popup' => [
                        'caption' => 'Ortsdosisleistung -- Einordnung',
                        'items' => $this->bfsOdlReferencePopupItems(),
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'QuelleMeteoalarm', 'caption' => 'Meteoalarm (europaweite Wetterwarnungen, 39 Länder) -- wichtig für mobile Standorte im Ausland'],
                ['type' => 'Label', 'caption' => 'Meteoalarm liefert KEINE Warnfläche (Polygon/Kreis), nur benannte Verwaltungsgebiete -- der Abgleich erfolgt deshalb per Namensvergleich (Standort wird per Reverse-Geocoding einem Kreis/einer Region zugeordnet), nicht geometrisch wie bei den übrigen Quellen. Das ist ungenauer und wird in der Meldung ausdrücklich als "Namensabgleich" gekennzeichnet. Für Deutschland liefert die direkte DWD-Anbindung oben bereits die präziseren Polygone -- Meteoalarm lohnt sich vor allem für Standorte im europäischen Ausland.'],
                ['type' => 'CheckBox', 'name' => 'QuelleGeosphereAt', 'caption' => 'Zusätzlich direkte GeoSphere-Austria-Warnungen (warnungen.zamg.at) -- koordinatengenau für österreichische Standorte, präziser als Meteoalarm'],
                ['type' => 'Label', 'caption' => 'Ist diese direkte Anbindung aktiv, übernimmt sie für österreichische Standorte automatisch von Meteoalarm (koordinatengenau statt Namensabgleich) -- analog zur direkten DWD-Anbindung, die für deutsche Standorte den entsprechenden NINA-Kanal ersetzt. Amtliche Quelle (GeoSphere Austria/ZAMG), kein Zugangsschlüssel nötig.'],
                ['type' => 'CheckBox', 'name' => 'QuelleBafuHydroCh', 'caption' => 'Zusätzlich Schweizer Hochwassergefahr (BAFU/LINDAS) -- amtliche Gefahrenstufe für Fliessgewässer und Seen'],
                ['type' => 'NumberSpinner', 'name' => 'BafuHydroSchwelle', 'caption' => 'Ab Gefahrenstufe (2-5)', 'minValue' => 2, 'maxValue' => 5],
                ['type' => 'Label', 'caption' => 'Nutzt BAFUs amtliche 5-stufige Gefahrenstufen-Skala für Hochwasser (1 = keine/geringe Gefahr bis 5 = sehr große Gefahr) -- anders als PEGELONLINE, BfS und die eigene Wetterstation also KEINE Eigenkonstruktion, sondern eine echte behördliche Klassifikation. Nur die Schwelle, AB der WarnHub meldet, ist einstellbar. Deckt Schweizer Standorte ab -- für Deutschland liefert PEGELONLINE oben bereits Pegelstände.'],
                ['type' => 'Label', 'caption' => 'Eigene Wetterstation: löst UNABHÄNGIG von den übrigen Quellen aus, sobald die lokal gemessene Windböe/Regenrate den eigenen Schwellwert überschreitet -- ein Sicherheitsnetz für den Fall, dass amtliche Warnungen ein tatsächlich lokal auftretendes Ereignis nicht oder nicht rechtzeitig melden. 0 = deaktiviert.'],
                [
                    'type' => 'SelectInstance',
                    'name' => 'WetterstationInstanceID',
                    'caption' => 'Instanz der Wetterstation',
                ],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Wetterstation suchen (Froggit/Sainlogic/ELV/Meteobridge & Co.)',
                    'onClick' => 'echo WHUB_DiscoverWetterstation($id);',
                ],
                ['type' => 'Label', 'caption' => 'Findet Froggit (auch als Sainlogic/HP1000SE/WH3000SE vertriebene Ecowitt-Hardware), Sainlogic/ELV (Wunderground-Protokoll) sowie Meteobridge/Meteohub (deckt als Datenlogger-Aggregator zusätzlich weitere Marken wie DAVIS ab). Findet sich keines davon, wird zuletzt systemweit nach Variablen mit dem passenden Symcon-Standardprofil gesucht (z. B. eine bereits profilierte KNX-Wetterstation) -- nur bei einem eindeutigen Treffer übernommen.'],
                ['type' => 'Label', 'caption' => 'Andere Fabrikate/Marken (z. B. KNX-Wetterstation ohne zugewiesenes Profil, Netatmo, TFA, Bresser, Homematic): keine automatische Erkennung möglich -- KNX vergibt Variablennamen frei nach eigener ETS-Konfiguration, andere Module nutzen eigene Bezeichnungen. Unten die passenden Wind-/Regen-Variablen des eigenen Systems einfach manuell auswählen (eine reicht, beide zusammen nicht nötig).'],
                ['type' => 'SelectVariable', 'name' => 'WetterstationWindVariableID', 'caption' => 'Wind-Variable (manuell, falls keine Froggit-Instanz)'],
                ['type' => 'SelectVariable', 'name' => 'WetterstationRegenVariableID', 'caption' => 'Regen-Variable (manuell, falls keine Froggit-Instanz)'],
                ['type' => 'Label', 'caption' => 'Ist eine Variable oben manuell gesetzt, hat sie Vorrang vor der Froggit-Instanz -- eine Mischung ist möglich (z. B. Wind von einer KNX-Wetterstation, Regen vom Froggit-Gateway).'],
                ['type' => 'Label', 'caption' => 'Windböe -- drei Schwellwerte statt einem: eine Markise ist deutlich windempfindlicher als ein robustes Raffstore. Jede Schutzaktions-Zeile wählt über ihr eigenes Feld "Ab Schweregrad" selbst, ab welcher Stufe sie reagiert.'],
                [
                    'type' => 'PopupButton',
                    'caption' => 'Welchen Schwellwert wähle ich?',
                    'popup' => [
                        'caption' => 'Windböen-Schwellwerte -- Einordnung',
                        'items' => [
                            ['type' => 'Label', 'caption' => 'Die Standardwerte lehnen sich an die amtlichen DWD-Windwarnstufen an (Windböen ab 50 km/h, Sturmböen 65-89 km/h, schwere Sturmböen 90-104 km/h) -- die Moderate-Stufe liegt bewusst darunter, weil Sachschutz (Markise, Raffstore) mehr Vorlauf braucht als eine reine Personen-Warnung.'],
                            ['type' => 'Label', 'caption' => 'Moderate (Standard 40 km/h): knapp über EN-13561-Windwiderstandsklasse 2 (bis 38 km/h) -- die Grenze, ab der eine durchschnittliche Wohnhaus-Markise gefährdet ist. Empfehlung für Markisen/Sonnensegel: "Ab Schweregrad" auf Moderate setzen.'],
                            ['type' => 'Label', 'caption' => 'Severe (Standard 65 km/h): entspricht DWDs "Sturmböen". Empfehlung für Standard-Raffstore/Rollladen/Garagentor: "Ab Schweregrad" auf Severe setzen.'],
                            ['type' => 'Label', 'caption' => 'Extreme (Standard 90 km/h): entspricht DWDs "schwere Sturmböen" -- nur für besonders robuste Systeme oder als allgemeiner Auffangwert sinnvoll.'],
                            ['type' => 'Label', 'caption' => 'Die tatsächliche Windwiderstandsklasse hängt vom Produkt, den Führungsschienen und der Montage ab -- die Herstellerangabe (falls vorhanden) hat immer Vorrang vor diesen Richtwerten.'],
                        ],
                    ],
                ],
                ['type' => 'NumberSpinner', 'name' => 'WetterstationWindSchwelleModerate', 'caption' => 'Schwellwert Windböe -- Moderate (km/h)', 'digits' => 1, 'minValue' => 1],
                ['type' => 'NumberSpinner', 'name' => 'WetterstationWindSchwelleSevere', 'caption' => 'Schwellwert Windböe -- Severe (km/h)', 'digits' => 1, 'minValue' => 1],
                ['type' => 'NumberSpinner', 'name' => 'WetterstationWindSchwelleExtreme', 'caption' => 'Schwellwert Windböe -- Extreme (km/h)', 'digits' => 1, 'minValue' => 1],
                ['type' => 'Label', 'caption' => 'Regenrate -- ebenfalls drei Schwellwerte statt einem, analog zur Windböe.'],
                [
                    'type' => 'PopupButton',
                    'caption' => 'Welchen Regen-Schwellwert wähle ich?',
                    'popup' => [
                        'caption' => 'Regenraten-Schwellwerte -- Einordnung',
                        'items' => [
                            ['type' => 'Label', 'caption' => 'Die Standardwerte entsprechen DWDs eigenen amtlichen Starkregen-Warnstufen (1 Stunde): Moderate 15 mm/h ("Markante Wetterwarnung"), Severe 25 mm/h ("Unwetterwarnung"), Extreme 40 mm/h ("Warnung vor extremem Unwetter").'],
                            ['type' => 'Label', 'caption' => 'Wie bei der Windböe wählt jede Schutzaktions-Zeile über ihr eigenes Feld "Ab Schweregrad" selbst, ab welcher Stufe sie reagiert (z. B. Fenster schließen schon bei Moderate, ein robustes Garagentor erst bei Severe).'],
                        ],
                    ],
                ],
                ['type' => 'NumberSpinner', 'name' => 'WetterstationRegenSchwelleModerate', 'caption' => 'Schwellwert Regenrate -- Moderate (mm/h)', 'digits' => 1, 'minValue' => 1],
                ['type' => 'NumberSpinner', 'name' => 'WetterstationRegenSchwelleSevere', 'caption' => 'Schwellwert Regenrate -- Severe (mm/h)', 'digits' => 1, 'minValue' => 1],
                ['type' => 'NumberSpinner', 'name' => 'WetterstationRegenSchwelleExtreme', 'caption' => 'Schwellwert Regenrate -- Extreme (mm/h)', 'digits' => 1, 'minValue' => 1],
                ['type' => 'CheckBox', 'name' => 'WetterstationAutoRueckstellung', 'caption' => 'Automatische Rückstellung nach Windberuhigung (Raffstore/Markise/Garage)'],
                ['type' => 'Label', 'caption' => 'Die EINZIGE Ausnahme von "keine automatische Rückstellung" im ganzen Modul -- bewusst nur hier erlaubt, weil die eigene Wetterstation (anders als eine amtliche Warnung) einen fortlaufenden, lokalen Live-Wert liefert. Stellt eine durch die eigene Wetterstation ausgelöste Raffstore-/Markisen-/Garagentor-Aktion automatisch auf den Wert zurück, den sie unmittelbar vor dem Auslösen hatte -- aber erst, nachdem Wind UND Regen seit 20 Minuten durchgehend wieder unter der Moderate-Schwelle liegen (Ruhephase gegen kurze Windböen-Pausen mitten im Sturm). Gilt NUR für durch die eigene Wetterstation ausgelöste Aktionen, nicht für amtliche Warnungen. Fenster-/Kofferraum-/Sirenen-/Skript-Aktionen bleiben wie gehabt ohne Rückstellung.'],
                ['type' => 'NumberSpinner', 'name' => 'PollIntervalMinutes', 'caption' => 'Abfragetakt (Minuten)', 'minValue' => 1, 'maxValue' => 60],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🧪  BETA: Hagelschutz Schweiz (VKF-Signalbox)',
            'expanded' => false,
            'items' => [
                ['type' => 'Label', 'caption' => 'AUSDRÜCKLICH BETA -- ungetestet: Diese Anbindung wurde ausschließlich aus der offiziellen VKF-Dokumentation und dem Quellcode eines aktiven Community-Adapters gebaut. Ohne eigene Signalbox konnte der Live-Abruf selbst nicht gegengeprüft werden -- die sonst in diesem Modul durchgehend befolgte "live verifizieren"-Regel wird hier bewusst ausgesetzt. Rückmeldungen (funktioniert/funktioniert nicht) sind ausdrücklich willkommen, siehe Feedback-Hinweis am Ende des Formulars.'],
                ['type' => 'Label', 'caption' => 'Setzt eine physisch bei einem konkreten Schweizer Gebäude registrierte VKF-Hagelschutz-Signalbox voraus (hagelschutz-einfach-automatisch.ch) -- kein reiner Software-Zugang. Ohne eigene Signalbox einfach leer lassen, dann bleibt diese Quelle inaktiv.'],
                ['type' => 'ValidationTextBox', 'name' => 'HagelschutzPollUrl', 'caption' => 'Poll-URL der eigenen Signalbox'],
                ['type' => 'Label', 'caption' => 'Die vollständige Adresse aus der eigenen Signalbox-Konfiguration eintragen (Format https://meteo.netitservices.com/api/v1/devices/<deviceId>/poll?hwtypeId=<HID>) -- nicht selbst aus deviceId/hwtypeId zusammensetzen, da sich das Format zwischen Signalbox-Generationen unterscheiden kann. Meldet eine Hagelwarnung am Symcon-Systemstandort, sobald die Signalbox "currentState" ungleich 0 zurückgibt (inkl. Testalarm).'],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🔔  Benachrichtigung',
            'expanded' => true,
            'items' => [
                ['type' => 'CheckBox', 'name' => 'PushAktiv', 'caption' => 'Push-Benachrichtigung an aktivierte Push-Ziele (WebFront/Kachel-Visualisierung/Telegram/Pushover, auch Handy)'],
                ['type' => 'Select', 'name' => 'PushSound', 'caption' => 'Signalton (nur WebFront/Kachel-Visualisierung)', 'options' => $this->soundOptions()],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Push-Ziele suchen',
                    'onClick' => 'echo WHUB_DiscoverWebFronts($id);',
                ],
                ['type' => 'Label', 'caption' => $this->webfrontStatusLine()],
                ['type' => 'Label', 'caption' => 'Sucht WebFront-Instanzen, Kachel-Visualisierung-Instanzen (die neuere Symcon-Oberfläche, unter "Visualisierung Instanzen" im Objektbaum -- häufig die eigentlich genutzte Oberfläche), sowie -- falls installiert -- Telegram-Bot- (offizielles Symcon-Modul) und Pushover-Instanzen (Community-Modul). Gefundene Ziele sind standardmäßig aktiv (bekommen Push) -- nicht gewünschte einfach über die Aktiv-Spalte abwählen. Eine erneute Suche fügt nur neue Ziele hinzu und lässt bestehende Abwahl-Entscheidungen unangetastet.'],
                ['type' => 'Label', 'caption' => 'Telegram/Pushover: Anbindung anhand des echten Quellcodes der jeweiligen Module gebaut, aber ohne eigenen Telegram-Bot/Pushover-Account nicht selbst live gegenprüfbar -- Rückmeldungen willkommen, siehe Feedback-Hinweis am Ende des Formulars.'],
                [
                    'type' => 'List',
                    'name' => 'WebFronts',
                    'rowCount' => $this->listRowCount(count($this->decodeWebFronts()), 3),
                    'add' => false,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '220px', 'edit' => ['type' => 'ValidationTextBox', 'enabled' => false]],
                        ['caption' => 'Typ', 'name' => 'Typ', 'width' => '180px', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'WebFront', 'value' => 'webfront'],
                            ['caption' => 'Kachel-Visualisierung', 'value' => 'kachel'],
                            ['caption' => 'Telegram', 'value' => 'telegram'],
                            ['caption' => 'Pushover', 'value' => 'pushover'],
                        ]]],
                        ['caption' => 'Instanz-ID', 'name' => 'InstanceID', 'width' => '100px', 'edit' => ['type' => 'NumberSpinner', 'enabled' => false]],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '80px', 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
                ['type' => 'Label', 'caption' => 'Ruhephase: pausiert NUR die Benachrichtigung selbst -- Erkennung und Schutzaktionen laufen unverändert weiter (z. B. im Urlaub fährt die Markise bei Sturm trotzdem ein, nur das Handy bleibt still). Ein Klick auf "🧪 Testbenachrichtigung senden" oben kommt auch während einer Pause an.'],
                [
                    'type' => 'Label',
                    'name' => 'SnoozeStatusLabel',
                    'caption' => $this->snoozeStatusLine(),
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Button', 'caption' => '1 Std. pausieren', 'onClick' => 'echo WHUB_SnoozePush($id, 60);'],
                        ['type' => 'Button', 'caption' => '4 Std. pausieren', 'onClick' => 'echo WHUB_SnoozePush($id, 240);'],
                        ['type' => 'Button', 'caption' => '24 Std. pausieren', 'onClick' => 'echo WHUB_SnoozePush($id, 1440);'],
                        ['type' => 'Button', 'caption' => '🔔 Pause aufheben', 'onClick' => 'echo WHUB_CancelSnooze($id);'],
                    ],
                ],
            ],
        ];

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🛡️  Schutzaktionen (Jalousien/Raffstore, Markisen, Garagentor, Fenster, Kofferraum, Sirenen, Skripte)',
            'expanded' => false,
            'items' => [
                ['type' => 'Label', 'caption' => 'Löst bei passender Warnung automatisch eine Aktion aus -- z. B. Raffstore hochfahren, Garagentor schließen, Autofenster schließen, ein akustisches Signal schalten oder ein eigenes Skript ausführen. Jede Aktion feuert nur EINMAL je Warnung, es gibt keine automatische Rückstellung -- das bleibt bewusst Nutzerhandeln.'],
                ['type' => 'Label', 'caption' => 'Warnungen treffen oft Stunden vor ihrem eigentlichen Gültigkeitsbeginn ein -- eine Aktion feuert deshalb NICHT sofort bei Eingang der Meldung, sondern erst kurz vor dem tatsächlichen Beginn (Vorlauf unten, damit z. B. die Markise sicher fertig eingefahren ist). Warnungen ohne eigene Zeitangabe (kommt selten vor) lösen weiterhin sofort aus; bereits laufende/akute Warnungen ebenfalls.'],
                ['type' => 'NumberSpinner', 'name' => 'SchutzaktionVorlaufMinuten', 'caption' => 'Vorlauf vor Gültigkeitsbeginn (Minuten)', 'minValue' => 0, 'maxValue' => 720],
                [
                    'type' => 'PopupButton',
                    'caption' => 'Welche Felder brauche ich für welchen Aktionstyp?',
                    'popup' => [
                        'caption' => 'Felder je Aktionstyp',
                        'items' => [
                            ['type' => 'Label', 'caption' => 'Raffstore/Rollladen hochfahren, Markise einfahren, Garagentor schließen, Akustischer Alarm: Ziel-Variable (der schaltbare Wert, z. B. Rollladen-/Markisen-Position oder Torsteuerung) + Zielwert (der Wert, der beim Auslösen gesetzt wird -- je nach Hersteller unterschiedlich, z. B. 0 = offen/hochgefahren/eingefahren, bitte am eigenen Aktor prüfen).'],
                            ['type' => 'Label', 'caption' => 'Fenster schließen: nur Ziel-Variable, kein Zielwert nötig (schaltet die Aktion immer auf "Ein" -- bei Tessies eigener Fenster-schließen-Aktion löst das Teslas gerichteten Schließen-Befehl aus, sicher auch bei bereits geschlossenen Fenstern).'],
                            ['type' => 'Label', 'caption' => 'Kofferraum/Heckklappe schließen: WICHTIG -- Teslas Kofferraum-Befehl ist ein reiner Umschalter ohne Richtung, ein Auslösen bei bereits geschlossener Klappe würde sie ÖFFNEN statt schließen. Deshalb zusätzlich zur Ziel-Variable zwingend eine Zustands-Variable angeben, die aktuell offene Klappen namentlich nennt (z. B. Tessies "Tür-/Klappenstatus") -- ausgelöst wird nur, wenn "Kofferraum" oder "Heckklappe" darin vorkommt, sonst passiert nichts. Ohne gültige Zustands-Variable feuert die Aktion GAR NICHT (Sicherheitssperre, kein Raten).'],
                            ['type' => 'Label', 'caption' => 'Akustischer Alarm zusätzlich: Auto-Aus (Sekunden) -- 0 bedeutet kein automatisches Ausschalten.'],
                            ['type' => 'Label', 'caption' => 'Skript ausführen: Ziel-Skript statt Ziel-Variable/Zielwert.'],
                            ['type' => 'Label', 'caption' => 'Mehrere Auslöser gleichzeitig (z. B. Markise soll bei Sturm UND Hagel einfahren): einfach mehrere Kästchen in derselben Zeile ankreuzen -- die Aktion feuert, sobald IRGENDEINE angekreuzte Kategorie zutrifft. Kein Kästchen angekreuzt = die Aktion gilt für jede Kategorie. Die automatische Objektbaum-Suche kreuzt bei Raffstore/Markise Sturm + Hagel an, bei Fenster schließen zusätzlich Starkregen.'],
                            ['type' => 'Label', 'caption' => 'Leeres "Nur Standort" bedeutet "alle FESTEN Standorte", NICHT auch mobile (Live-Standort-gebundene, siehe Standorte-Panel) -- sonst würde z. B. ein Sturm über Hamburg, den nur der mobile Standort meldet, die zuhause verbaute Jalousie einfahren. Das gilt automatisch für jede Aktion, keine Einrichtung nötig.'],
                        ],
                    ],
                ],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Objektbaum nach Raffstore/Jalousie/Markise/Garage/Fenster schließen/Heckklappe/Sirene durchsuchen',
                    'onClick' => 'echo WHUB_DiscoverSchutzaktionen($id);',
                ],
                ['type' => 'Label', 'caption' => 'Gefundene Treffer werden vorausgefüllt und in der Regel AKTIVIERT als neue Zeile ergänzt (Schweregrad "Hoch" als vorsichtiger Standard) -- nicht gewünschte einfach über die Aktiv-Spalte abwählen. Ausnahme Kofferraum/Heckklappe: bleibt ohne automatisch gefundene Zustands-Variable INAKTIV (Sicherheitssperre, siehe Hilfe-Knopf oben). Eine erneute Suche lässt bestehende Zeilen/Abwahl-Entscheidungen unangetastet und fügt nur neue Treffer hinzu.'],
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
                        ['caption' => 'Zustands-Variable (nur Kofferraum)', 'name' => 'ZustandsVariableID', 'width' => '190px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Ziel-Skript', 'name' => 'ZielSkriptID', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectScript']],
                        ['caption' => 'Auto-Aus (s)', 'name' => 'AutoOffSekunden', 'width' => '100px', 'add' => 60, 'edit' => ['type' => 'NumberSpinner', 'minValue' => 0]],
                    ],
                ],
                ['type' => 'Label', 'caption' => 'Je Alarmtyp testen: löst SOFORT alle aktiven Schutzaktionen aus, die für den gewählten Alarmtyp gelten (angekreuzte Kategorie ODER gar keine angekreuzt) -- unabhängig von einer echten Warnung, vom Standort-Filter und vom Mindest-Schweregrad. Prüft nur, ob die Aktion tatsächlich das Richtige tut (z. B. fährt die Markise wirklich ein?), nicht die Warnungserkennung selbst. Ohne echte Warnung ist keine automatische Rückstellung geplant -- danach von Hand zurückstellen.'],
                [
                    'type' => 'RowLayout',
                    'items' => (function () {
                        $buttons = [];
                        foreach (self::CATEGORY_FIELDS as $key => $f) {
                            $buttons[] = [
                                'type' => 'Button',
                                'caption' => $f[1] . ' testen',
                                'onClick' => "echo WHUB_TestSchutzaktionen(\$id, '" . $key . "');",
                            ];
                        }
                        return $buttons;
                    })(),
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
                ['type' => 'Label', 'caption' => 'Für ein eigenes Dashboard (z. B. IPSView): dieselben Werte stehen unten im Objektbaum als vier eigene Variablen (Aktive Warnungen, Höchster Schweregrad, Status, Letzte Prüfung) -- IPSView baut Views aus vorhandenen Symcon-Variablen zusammen, nicht über einen eigenen Push-Kanal, deshalb hier keine gesonderte Einrichtung nötig.'],
                ['type' => 'Label', 'caption' => '🧊 Fertige WebFront-Kacheln: zwei weitere Variablen ("Kachel (kompakt)", "Kachel (Übersicht)") im Objektbaum enthalten fertiges, eigenständiges HTML -- kein eigenes Bauen nötig, einfach im Objektbaum in den Bereich des WebFronts verlinken. Passen sich automatisch an Hell/Dunkel an. Ohne echtes WebFront hier nicht selbst gegenprüfbar -- Rückmeldungen willkommen.'],
                ['type' => 'Label', 'caption' => 'Warnungs-Historie (auch vergangene, nicht nur aktuell aktive Warnungen/Entwarnungen -- bis zu 500 Einträge) für eigene Auswertungen/Skripte über die Funktion WHUB_GetHistory($id, $limit) abrufbar, kein eigenes Formularfeld dafür nötig.'],
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

    /**
     * Referenz-Dosisleistungen für die Einordnungshilfe (Popup + Warnungstext,
     * siehe formatDurationToPublicLimit()/bfsOdlContext() unten). Werte/
     * Quellen: natürliche ODL in Deutschland 0,05-0,2 µSv/h, Jahres-
     * Grenzwert der Bevölkerung 1 mSv (beides odlinfo.bfs.de, Stand
     * 04.09.2026) -- keine amtliche Verweildauer-Tabelle, eigene Berechnung
     * zur Orientierung (Dietmars Wunsch 04.09.2026: "wie lange man sich bei
     * welcher Dosis aufhalten darf").
     */
    private const BFS_ODL_REFERENZWERTE = [
        ['rate' => 0.1, 'label' => 'typisches natürliches Untergrundniveau in Deutschland'],
        ['rate' => 0.2, 'label' => 'obere natürliche Spanne in Deutschland'],
        ['rate' => 0.3, 'label' => 'WarnHubs Standard-Schwellwert'],
        ['rate' => 1.0, 'label' => ''],
        ['rate' => 10.0, 'label' => ''],
        ['rate' => 100.0, 'label' => ''],
        ['rate' => 1000.0, 'label' => 'entspricht 1 mSv/h'],
    ];

    /**
     * Rein rechnerische Zeitspanne bis zum Jahres-Grenzwert der Bevölkerung
     * (1 mSv, ein Vorsorge-Grenzwert -- laut BfS ausdrücklich KEINE
     * Gefahrenschwelle) bei durchgehendem Aufenthalt exakt an dieser Stelle.
     * Reine Orientierungsrechnung (1000 µSv / Dosisleistung), keine amtliche
     * Angabe -- wird deshalb überall mit "rechnerisch" formuliert.
     */
    private function formatDurationToPublicLimit(float $doseRateUSvH): string
    {
        if ($doseRateUSvH <= 0) {
            return '';
        }
        $hours = 1000.0 / $doseRateUSvH;
        if ($hours >= 24) {
            return sprintf('%s Tagen', number_format(round($hours / 24), 0, ',', '.'));
        }
        return sprintf('%s Stunden', number_format($hours, 1, ',', '.'));
    }

    /** Kurzer, wertabhängiger Einordnungssatz für den Meldungstext einer BfS-ODL-Warnung -- siehe fetchBfsOdl(). */
    private function bfsOdlContext(float $doseRateUSvH): string
    {
        if ($doseRateUSvH <= 0) {
            return '';
        }
        return sprintf(
            'Rein rechnerisch (bei durchgehendem Aufenthalt an dieser Stelle) wäre der Jahres-Vorsorgewert für die Bevölkerung (1 mSv) nach %s erreicht -- das ist ein Vorsorge-, kein Gefahrenwert.',
            $this->formatDurationToPublicLimit($doseRateUSvH)
        );
    }

    /** Gestaffelte Einordnungstabelle Dosisleistung/Verweildauer als Popup-Inhalt (siehe Datenquellen-Panel). */
    private function bfsOdlReferencePopupItems(): array
    {
        $items = [
            ['type' => 'Label', 'caption' => 'Natürliche Ortsdosisleistung in Deutschland: ca. 0,05-0,2 µSv/h (Summe aus Boden- und Höhenstrahlung, ortsabhängig). Quelle: odlinfo.bfs.de.'],
            ['type' => 'Label', 'caption' => 'Jahres-Grenzwert für die Bevölkerung: 1 Millisievert (mSv) zusätzlich zu natürlicher/medizinischer Strahlung -- laut BfS ausdrücklich ein Vorsorge-, kein Gefahrenwert: seine Überschreitung bedeutet ein statistisch erhöhtes Langzeitrisiko, keinen unmittelbaren Gesundheitsschaden.'],
            ['type' => 'Label', 'caption' => 'Akute Strahlenschäden (Strahlenkrankheit) treten laut BfS erst ab etwa 500 mSv als EINZELDOSIS auf -- weit oberhalb dessen, was Umgebungs-Messstationen realistischerweise anzeigen.'],
        ];
        foreach (self::BFS_ODL_REFERENZWERTE as $ref) {
            $caption = sprintf(
                '%s µSv/h%s -- Jahres-Vorsorgewert (1 mSv) rechnerisch nach %s bei DURCHGEHENDEM Aufenthalt.',
                number_format($ref['rate'], $ref['rate'] < 1 ? 1 : 0, ',', '.'),
                $ref['label'] !== '' ? ' (' . $ref['label'] . ')' : '',
                $this->formatDurationToPublicLimit($ref['rate'])
            );
            $items[] = ['type' => 'Label', 'caption' => $caption];
        }
        $items[] = ['type' => 'Label', 'caption' => 'Reine Orientierungsrechnung (eigene Berechnung, keine amtliche Verweildauer-Tabelle) -- in einer echten Lage zählt ausschließlich die Einschätzung der Behörden (BfS, NINA-Warn-App, Katastrophenschutz), nicht diese Tabelle.'];
        return $items;
    }

    private function actionTypeOptions(): array
    {
        return [
            ['caption' => 'Raffstore/Rollladen hochfahren', 'value' => 'raffstore'],
            ['caption' => 'Markise einfahren', 'value' => 'markise'],
            ['caption' => 'Garagentor schließen', 'value' => 'garage'],
            ['caption' => 'Fenster schließen (z. B. Tesla)', 'value' => 'fenster'],
            ['caption' => 'Kofferraum/Heckklappe schließen (nur mit Zustands-Variable)', 'value' => 'kofferraum'],
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
        foreach ($this->findInstancesByModuleNameSubstring(self::TELEGRAM_BOT_GUID, 'telegram') as $instanceID => $moduleName) {
            $out[] = ['InstanceID' => $instanceID, 'Name' => @IPS_GetName($instanceID) ?: ('#' . $instanceID), 'Typ' => 'telegram'];
        }
        foreach ($this->findInstancesByModuleNameSubstring(self::PUSHOVER_GUID, 'pushover') as $instanceID => $moduleName) {
            $out[] = ['InstanceID' => $instanceID, 'Name' => @IPS_GetName($instanceID) ?: ('#' . $instanceID), 'Typ' => 'pushover'];
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
            return '⚠️ Weder WebFront-, Kachel-Visualisierung-, Telegram-Bot- noch Pushover-Instanzen im Objektbaum gefunden.';
        }
        return sprintf('✅ %d neue(s) Push-Ziel(e) gefunden und aktiviert (insgesamt %d) -- bitte unten „Übernehmen" klicken, um zu speichern.', $added, count($rows));
    }

    private function webfrontStatusLine(): string
    {
        $rows = $this->decodeWebFronts();
        $active = count(array_filter($rows, fn ($w) => $w['Aktiv']));
        if (count($rows) === 0) {
            return 'ℹ️ Noch keine Push-Ziele gesucht -- oben "🔎 Push-Ziele suchen" klicken.';
        }
        if ($active === 0) {
            return sprintf('⚠️ %d Push-Ziel(e) gefunden, aber keines aktiviert -- Push-Benachrichtigungen kommen aktuell nirgends an.', count($rows));
        }
        return sprintf('✅ %d von %d gefundenen Push-Ziel(en) aktiv -- Push-Benachrichtigungen gehen dorthin.', $active, count($rows));
    }

    private function isPushSnoozed(): bool
    {
        return $this->ReadAttributeInteger('PushSnoozeUntilTs') > time();
    }

    private function snoozeStatusLine(): string
    {
        if (!$this->isPushSnoozed()) {
            return 'ℹ️ Push-Benachrichtigung läuft normal (nicht pausiert).';
        }
        return sprintf('🔕 Push-Benachrichtigung pausiert bis %s Uhr -- Erkennung und Schutzaktionen laufen unverändert weiter, nur die Benachrichtigung selbst ist stumm.', date('d.m. H:i', $this->ReadAttributeInteger('PushSnoozeUntilTs')));
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
                ['type' => 'Label', 'caption' => 'WarnHub bündelt amtliche Warn- und Alarmmeldungen für Deutschland, Österreich und die Schweiz -- Deutschland (Unwetter, Katastrophenschutz, Hochwasser, Polizei, Pegelstände, Radioaktivität) plus europaweite Wetterwarnungen (deckt Österreich/Schweiz mit ab) und optional die eigene Wetterstation als Sicherheitsnetz -- und meldet nur das, was tatsächlich in den von dir festgelegten Umkreis um deine Standorte fällt, auch mobile Standorte im Ausland.'],
                ['type' => 'Label', 'caption' => 'Aktive Warnungen erscheinen als Push-Benachrichtigung auf allen WebFront-, Kachel-Visualisierung-, Telegram- und Pushover-Zielen (auch Handy) und können optional Schutzaktionen auslösen -- z. B. Raffstore hochfahren oder das Garagentor schließen, bevor der Sturm da ist.'],
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
                ['type' => 'Label', 'caption' => 'Seit der Erstversion neu dazugekommen:'],
                ['type' => 'Label', 'caption' => '• Zwei neue Datenquellen: Pegelstände (PEGELONLINE/WSV) und Radioaktivität (BfS Ortsdosisleistung, mit Einordnungshilfe "Was bedeutet dieser Wert?" -- Dosisleistung/Verweildauer bis zum Jahres-Vorsorgewert)'],
                ['type' => 'Label', 'caption' => '• Meteoalarm als dritte Wetterquelle: europaweite Warnungen für 39 Länder, wichtig für mobile Standorte im Ausland'],
                ['type' => 'Label', 'caption' => '• Mobiler Standort: statt fester Koordinaten an zwei Live-Variablen bindbar (z. B. Tessie/Geofency) -- WarnHub liest bei jeder Prüfung die aktuelle Position. Objektbaum-Suche findet passende Fahrzeug-/Standort-Variablenpaare automatisch'],
                ['type' => 'Label', 'caption' => '• "Push nur an"-Filter je Standort -- bei mehreren Personen/Fahrzeugen bekommt nicht mehr automatisch jeder die Warnung der anderen Person'],
                ['type' => 'Label', 'caption' => '• Neue Schutzaktionen: Fenster schließen sowie Kofferraum/Heckklappe schließen (Letzteres mit zwingender Sicherheitsprüfung gegen ein versehentliches Öffnen), beide auch über die automatische Objektbaum-Suche auffindbar'],
                ['type' => 'Label', 'caption' => '• Schutzaktionen ohne eigenen Standort-Filter feuern jetzt automatisch nur noch von festen, nicht von mobilen Standorten aus (Sicherheitssperre)'],
                ['type' => 'Label', 'caption' => '• Eigene Wetterstation (Froggit) als unabhängige, zusätzliche Warnquelle -- löst auch aus, wenn eine amtliche Warnung ein tatsächlich lokal auftretendes Ereignis nicht meldet. Objektbaum-Suche findet eine passende Station automatisch'],
                ['type' => 'Label', 'caption' => '• Schutzaktionen feuern nicht mehr sofort bei Eingang einer Meldung, sondern erst kurz vor deren tatsächlichem Gültigkeitsbeginn (einstellbarer Vorlauf) -- eine morgens eintreffende, erst für den Nachmittag gültige Warnung fährt die Markise nicht mehr schon morgens ein. Die Push-Benachrichtigung bleibt weiterhin sofort'],
                ['type' => 'Label', 'caption' => '• Meteoalarm deckt neben Deutschland auch Österreich und die Schweiz ab -- WarnHub eignet sich damit für den gesamten deutschsprachigen Raum (D-A-CH), nicht nur für Deutschland'],
                ['type' => 'Label', 'caption' => '• Neue Datenquelle für Österreich: direkte GeoSphere-Austria-Anbindung (warnungen.zamg.at) -- koordinatengenau statt Namensabgleich, übernimmt für österreichische Standorte automatisch von Meteoalarm, sobald aktiviert'],
                ['type' => 'Label', 'caption' => '• Neue Datenquelle für die Schweiz: amtliche Hochwasser-Gefahrenstufen (BAFU/LINDAS) -- echte behördliche Klassifikation (1-5), nicht nur ein eigener Schwellwert'],
                ['type' => 'Label', 'caption' => '• BETA (ungetestet): eigene VKF-Hagelschutz-Signalbox (Schweiz) als Datenquelle -- eigenes Panel, Rückmeldungen willkommen'],
                ['type' => 'Label', 'caption' => '• Mehrkanal-Push: neben WebFront/Kachel-Visualisierung jetzt auch Telegram (offizielles Symcon-Modul) und Pushover (Community-Modul) als Push-Ziele -- einfach "🔎 Push-Ziele suchen" erneut klicken, vorhandene Telegram-Bot-/Pushover-Instanzen werden automatisch gefunden'],
                ['type' => 'Label', 'caption' => '• IPSView-tauglich: vier neue Statusvariablen (Aktive Warnungen, Höchster Schweregrad, Status, Letzte Prüfung) für ein eigenes Dashboard -- WarnHub war bisher komplett "headless" (nur Push + Konsole)'],
                ['type' => 'Label', 'caption' => '• Schutzaktionen lassen sich jetzt je Alarmtyp einzeln testen ("🌪️ Sturm testen" usw.) -- löst sofort alle passenden aktiven Aktionen aus, unabhängig von einer echten Warnung, praktisch um z. B. die Raffstore-Ansteuerung ohne Warten auf den nächsten Sturm zu prüfen'],
                ['type' => 'Label', 'caption' => '• Warnungs-Historie: bis zu 500 vergangene Warnungen/Entwarnungen über die neue Funktion WHUB_GetHistory() abrufbar -- für eigene Auswertungen/Skripte, auch wenn Push zwischenzeitlich ausgeschaltet war'],
                ['type' => 'Label', 'caption' => '• Zwei fertige WebFront-Kacheln ("Kachel (kompakt)", "Kachel (Übersicht)") -- einfach im Objektbaum in den Bereich des WebFronts verlinken, kein eigenes Bauen nötig. Hell/Dunkel-adaptiv im modernen "Liquid Glass"-Stil'],
                ['type' => 'Label', 'caption' => '• Eigene Wetterstation: zweites unterstütztes Modul (Sainlogic/ELV via Wunderground-Protokoll) sowie zwei manuelle Wind-/Regen-Auswahlfelder für JEDES andere Fabrikat (KNX, Netatmo, TFA, Homematic, ...) -- keine automatische Erkennung möglich, da diese Module/KNX keine einheitliche Benennung verwenden, aber jede beliebige Variable im System lässt sich direkt auswählen'],
                ['type' => 'Label', 'caption' => '• Eigene Wetterstation: drittes unterstütztes Modul (Meteobridge/Meteohub, deckt als Aggregator zusätzlich weitere Marken wie DAVIS ab) sowie ein letzter Rückfall bei der Suche über das Symcon-Standardprofil (findet z. B. eine bereits profilierte KNX-Wetterstation automatisch). Wichtiger Fix: Windgeschwindigkeiten in m/s (kommt bei manchen Modulen/KNX vor) werden jetzt korrekt in km/h umgerechnet -- vorher hätte eine m/s-Variable stumm gegen den km/h-Schwellwert verglichen werden können'],
                ['type' => 'Label', 'caption' => '• Windböe: drei Schwellwerte (Moderate/Severe/Extreme, Standard 40/65/90 km/h, an DWDs eigene Warnstufen angelehnt) statt einem pauschalen Wert -- eine Markise ist windempfindlicher als ein Raffstore. Jede Schutzaktions-Zeile wählt über ihr bestehendes "Ab Schweregrad"-Feld selbst, ab welcher Stufe sie reagiert; neu ins Popup "Welchen Schwellwert wähle ich?" im Datenquellen-Panel'],
                ['type' => 'Label', 'caption' => '• Push-Ruhephase: Benachrichtigung für 1/4/24 Std. pausierbar (z. B. Urlaub, Feier, Nachtruhe) -- Erkennung, Warnungs-Historie und Schutzaktionen laufen unverändert weiter, nur das Handy bleibt still. Ein manueller Testklick auf "Testbenachrichtigung senden" kommt trotzdem an'],
                ['type' => 'Label', 'caption' => '• Eine bereits gemeldete Warnung, die sich verschärft (z. B. DWD stuft von Moderate auf Severe hoch), löst jetzt eine ERNEUTE Push-Benachrichtigung aus -- vorher blieb sie nach der ersten Meldung stumm, egal wie sehr sie sich verschlimmerte. Eine Abstufung pusht bewusst nicht erneut'],
                ['type' => 'Label', 'caption' => '• Push-Text enthält jetzt auch die Handlungsempfehlung der Quelle (CAP-Feld "instruction", z. B. "Meiden Sie den Aufenthalt im Wald"), falls vorhanden -- wurde bisher eingelesen, aber nirgends angezeigt'],
                ['type' => 'Label', 'caption' => '• Regenrate der eigenen Wetterstation jetzt ebenfalls in drei Stufen (Moderate/Severe/Extreme, Standard 15/25/40 mm/h, DWDs eigene Starkregen-Warnstufen)'],
                ['type' => 'Label', 'caption' => '• NEU (optional, standardmäßig AUS): Automatische Rückstellung nach Windberuhigung für durch die eigene Wetterstation ausgelöste Raffstore-/Markisen-/Garagentor-Aktionen -- stellt nach 20 Minuten durchgehender Ruhe automatisch den Wert vor dem Auslösen wieder her, prüft vorher aber, ob der Stand seitdem von Hand verändert wurde (dann keine Überschreibung). Die einzige Ausnahme von "keine automatische Rückstellung" im ganzen Modul, siehe Datenquellen-Panel'],
                ['type' => 'Label', 'caption' => '• Eine bereits abgelaufene Warnung zählt nicht mehr als aktiv, auch wenn die Quelle sie verzögert weiterliefert -- Absicherung gegen veraltete Quelldaten'],
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

    /** @return array<int,array{Name:string,Ort:string,Lat:float,Lon:float,QuellVarLat:int,QuellVarLon:int,RadiusKm:float,MinSeverity:int,PushZielFilter:string,Aktiv:bool}> */
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

    /** @return array<int,array{Name:string,Aktiv:bool,Typ:string,Kategorien:array<int,string>,MinSeverity:int,StandortFilter:string,ZielVariableID:int,ZielWert:float,ZustandsVariableID:int,ZielSkriptID:int,AutoOffSekunden:int}> */
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
                'ZustandsVariableID' => (int) ($a['ZustandsVariableID'] ?? 0),
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
     * Sucht unter der ELTERN-Instanz von $variableID eine Geschwister-
     * Variable, deren Name $needle enthält (z. B. "klappenstatus", passend
     * zu Tessies "Tür-/Klappenstatus") -- für den Kofferraum/Heckklappe-
     * Schutzaktionstyp, der zwingend eine Zustands-Variable NEBEN der
     * Ziel-(Umschalt-)Variable braucht (siehe fireProtectiveAction()).
     */
    private function findSiblingVariableByNameSubstring(int $variableID, string $needle): ?int
    {
        $parentID = @IPS_GetParent($variableID) ?: 0;
        if ($parentID <= 0) {
            return null;
        }
        foreach (@IPS_GetChildrenIDs($parentID) ?: [] as $siblingID) {
            $obj = @IPS_GetObject($siblingID);
            if (is_array($obj) && (int) $obj['ObjectType'] === 2 && mb_stripos((string) $obj['ObjectName'], $needle) !== false) {
                return $siblingID;
            }
        }
        return null;
    }

    /**
     * Sucht unter $instanceID eine Kind-Variable, deren Name EXAKT (nicht
     * nur als Substring) $name entspricht -- nötig, weil z. B. Froggit
     * sowohl "Windböe" (aktuell) als auch "Windböe (Max.) Tag" anbietet;
     * ein reiner Substring-Treffer würde beide gleichermaßen matchen.
     */
    private function findChildVariableByExactName(int $instanceID, string $name): ?int
    {
        $needle = mb_strtolower(trim($name));
        foreach (@IPS_GetChildrenIDs($instanceID) ?: [] as $childID) {
            $obj = @IPS_GetObject($childID);
            if (is_array($obj) && (int) $obj['ObjectType'] === 2 && mb_strtolower(trim((string) $obj['ObjectName'])) === $needle) {
                return $childID;
            }
        }
        return null;
    }

    /**
     * Wie findChildVariableByExactName(), aber über den PHP-Ident
     * (IPS_SetIdent()/ObjectIdent) statt den -- je nach Sprache/Übersetzung
     * unterschiedlichen -- Anzeigenamen. Nötig für Fremdmodule mit
     * englischen Idents und übersetztem Anzeigenamen (z. B. Wolbolar/
     * IPSymconWeatherStation: Ident "Windgust", Anzeigename je nach
     * Sprachdatei "Wind gust"/"Windböe"/... -- der Ident bleibt stabil).
     */
    private function findChildVariableByIdent(int $instanceID, string $ident): ?int
    {
        foreach (@IPS_GetChildrenIDs($instanceID) ?: [] as $childID) {
            $obj = @IPS_GetObject($childID);
            if (is_array($obj) && (int) $obj['ObjectType'] === 2 && (string) $obj['ObjectIdent'] === $ident) {
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
     * "Raffstore"/"Jalousie" (Typ raffstore), "Garage" (Typ garage),
     * "Fenster schließen" (Typ fenster, z. B. Tessies eigene Tesla-Aktion)
     * oder "Sirene"/"Hupe"/"Buzzer"/"Signalhorn" (Typ sirene) enthält, und ergänzt
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
        // Markise bewusst MIT NIEDRIGEREM Schweregrad als Raffstore (2=Moderate
        // statt 3=Severe): Markisen sind windempfindlicher (meist EN-13561-
        // Windwiderstandsklasse 1-3, bis ca. 38-49 km/h) als ein robustes
        // Raffstore -- die eigene Wetterstation meldet bei Windböen jetzt
        // gestuft (Moderate/Severe/Extreme, siehe windSeverityForSpeed()),
        // Dietmars Nachfrage 04.09.2026, ob 70 km/h pauschal für beide
        // Aktionstypen sinnvoll ist.
        $typeDefaults = [
            'raffstore' => ['Kategorien' => ['sturm', 'hagel'], 'MinSeverity' => 3, 'AutoOff' => 0],
            'markise' => ['Kategorien' => ['sturm', 'hagel'], 'MinSeverity' => 2, 'AutoOff' => 0],
            'garage' => ['Kategorien' => [], 'MinSeverity' => 3, 'AutoOff' => 0], // leer = jede Kategorie
            // Auch Starkregen -- ein offenes Fenster lässt bei Dauerregen genauso
            // Wasser rein wie bei Sturm/Hagel (anders als Raffstore/Markise, die
            // primär gegen Wind-/Hagelschaden schützen).
            'fenster' => ['Kategorien' => ['sturm', 'hagel', 'starkregen'], 'MinSeverity' => 3, 'AutoOff' => 0],
            'kofferraum' => ['Kategorien' => ['sturm', 'hagel', 'starkregen'], 'MinSeverity' => 3, 'AutoOff' => 0],
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

                // Kofferraum/Heckklappe braucht zwingend eine Zustands-
                // Variable (siehe fireProtectiveAction()) -- wird unter
                // DERSELBEN Instanz gesucht wie die gefundene Ziel-Variable
                // (Name enthält "klappenstatus", trifft Tessies "Tür-/
                // Klappenstatus"). Kein Treffer = Zeile trotzdem anlegen,
                // aber inaktiv lassen statt so zu tun als sei sie sicher
                // nutzbar -- Nutzer muss die Variable dann selbst nachtragen.
                $zustandsVariableID = 0;
                $rowActive = true;
                if ($actionType === 'kofferraum') {
                    $zustandsVariableID = $this->findSiblingVariableByNameSubstring($variableID, 'klappenstatus') ?? 0;
                    $rowActive = $zustandsVariableID > 0;
                }

                $defaults = $typeDefaults[$actionType];
                $row = [
                    'Name' => $displayName,
                    'Aktiv' => $rowActive,
                    'Typ' => $actionType,
                    'MinSeverity' => $defaults['MinSeverity'],
                    'StandortFilter' => '',
                    'ZielVariableID' => $variableID,
                    'ZielWert' => 0.0,
                    'ZustandsVariableID' => $zustandsVariableID,
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
            return 'ℹ️ Keine neuen Treffer für Raffstore/Jalousie/Markise/Garage/Fenster schließen/Heckklappe/Sirene im Objektbaum gefunden.';
        }
        return sprintf(
            '✅ %d neue Schutzaktion(en) gefunden (Schweregrad "Hoch"/"Extrem" als vorsichtiger Standard) -- WICHTIG: Zielwert je Zeile prüfen (Richtung je Hersteller unterschiedlich, siehe Hilfe-Knopf oben), dann unten „Übernehmen" klicken. Kofferraum/Heckklappe-Treffer ohne automatisch gefundene Zustands-Variable bleiben aus Sicherheitsgründen INAKTIV -- Zustands-Variable von Hand ergänzen und dann aktivieren.',
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
     * Durchsucht den GESAMTEN Objektbaum nach Variablen-PAAREN (Lat+Lon
     * unter derselben Instanz), die zu einem bekannten Namensmuster passen
     * (siehe DISCOVERY_LATLON_PAIRS) -- z. B. Tessies "Fahrzeugposition –
     * Breitengrad/Längengrad" oder Geofencys "Current Latitude/Longitude" --
     * und ergänzt je Treffer einen VORAKTIVIERTEN, bereits an die Live-
     * Variablen gebundenen mobilen Standort. Dietmars Nachfrage 04.09.2026:
     * "Warum kannst du die Zuordnung nicht auch gleich ... übernehmen?" --
     * bisher musste QuellVarLat/QuellVarLon je Standort von Hand gesetzt
     * werden. Läuft wie DiscoverWebFronts()/DiscoverSchutzaktionen() nur auf
     * der offenen Formularmaske, "Übernehmen" bleibt der bewusste letzte Schritt.
     */
    public function DiscoverMobileStandorte(): string
    {
        $rows = $this->decodeStandorte();
        $knownPairs = [];
        foreach ($rows as $r) {
            if ($r['QuellVarLat'] > 0 && $r['QuellVarLon'] > 0) {
                $knownPairs[$r['QuellVarLat'] . '|' . $r['QuellVarLon']] = true;
            }
        }

        // Kandidaten je (Instanz, Musterindex) sammeln, damit z. B. Tessies
        // "Fahrzeugposition"-Muster nicht versehentlich mit Geofencys
        // "current"-Muster derselben Instanz vermischt wird.
        $latCandidates = [];
        $lonCandidates = [];
        foreach ($this->collectObjectIDsRecursive(0) as $id) {
            $obj = @IPS_GetObject($id);
            if (!is_array($obj) || (int) $obj['ObjectType'] !== 2) {
                continue;
            }
            $haystack = mb_strtolower((string) $obj['ObjectName']);
            $parentID = (int) $obj['ParentID'];
            foreach (self::DISCOVERY_LATLON_PAIRS as $pIdx => $pattern) {
                if (mb_strpos($haystack, $pattern['prefix']) === false) {
                    continue;
                }
                $key = $parentID . '|' . $pIdx;
                if (mb_strpos($haystack, $pattern['lat']) !== false) {
                    $latCandidates[$key] ??= $id;
                } elseif (mb_strpos($haystack, $pattern['lon']) !== false) {
                    $lonCandidates[$key] ??= $id;
                }
            }
        }

        $added = 0;
        foreach ($latCandidates as $key => $latID) {
            if (!isset($lonCandidates[$key])) {
                continue; // nur vollständige Paare, kein Rätselraten bei nur einer Achse
            }
            $lonID = $lonCandidates[$key];
            if (isset($knownPairs[$latID . '|' . $lonID])) {
                continue;
            }
            [$parentID] = explode('|', $key);
            $name = (string) (@IPS_GetName((int) $parentID) ?: 'Mobiler Standort');
            $lat = (float) @GetValue($latID);
            $lon = (float) @GetValue($lonID);
            $rows[] = [
                'Name' => $name,
                'Ort' => '',
                'Lat' => round($lat, 5),
                'Lon' => round($lon, 5),
                'QuellVarLat' => $latID,
                'QuellVarLon' => $lonID,
                'RadiusKm' => 20.0,
                'MinSeverity' => 2,
                'PushZielFilter' => '',
                'Aktiv' => true,
            ];
            $knownPairs[$latID . '|' . $lonID] = true;
            $added++;
        }

        $this->UpdateFormField('Standorte', 'values', json_encode($rows));
        $this->UpdateFormField('Standorte', 'rowCount', $this->listRowCount(count($rows)));
        if ($added === 0) {
            return 'ℹ️ Keine neuen Fahrzeug-/Standort-Variablenpaare gefunden (gesucht: Tessie "Fahrzeugposition", Geofency "Current Latitude/Longitude").';
        }
        return sprintf('✅ %d mobile(r) Standort(e) gefunden und mit den Live-Variablen verknüpft -- bitte Umkreis/Schweregrad prüfen, dann unten „Übernehmen" klicken.', $added);
    }

    /**
     * Sucht eine Wetterstation im Objektbaum -- unterstützt drei bekannte
     * Module: zuerst Froggit (Ecowitt-Protokoll, deckt laut Store-Angaben
     * auch als Froggit/Sainlogic/HP1000SE/WH3000SE vertriebene
     * Ecowitt-Hardware ab, Felder "Windböe"/"Regenrate" per Anzeigename),
     * dann Wolbolar/IPSymconWeatherStation (Sainlogic/Froggit/ELV über das
     * Wunderground-Protokoll, Idents "Windgust"/"rainin"), dann elueckel/
     * Symcon_Meteobridge_Meteohub (Meteobridge/Meteohub-Datenlogger, deckt
     * als Aggregator zusätzlich weitere Marken wie DAVIS ab, Idents
     * "Wind_Gust_KmH"/"Rain_Rate"). Alle drei GUIDs/Idents direkt gegen den
     * echten Quellcode verifiziert -- mangels eigenem Testgerät wie bei
     * Telegram/Pushover NICHT live gegengeprüft. Übernimmt eine gefundene
     * Instanz nur, wenn sie tatsächlich beide benötigten Felder besitzt --
     * sonst kein Treffer, statt eine ungeeignete Instanz zu übernehmen.
     * Findet keines der drei Module etwas, folgt als letzter Rückfall eine
     * systemweite Suche nach dem passenden Standard-Symcon-Profil (siehe
     * discoverWetterstationVariablesByProfile()) -- deckt z. B. eine
     * KNX-Wetterstation ab, wenn deren Variablen bereits profiliert sind.
     * Andere Fabrikate ganz ohne erkennbares Profil (KNX, Netatmo, TFA,
     * Homematic, ...) haben keine einheitliche Benennung -- dafür gibt es
     * die beiden manuellen Wind-/Regen-Auswahlfelder weiter unten im
     * Formular. Schreibt wie die übrigen Discover*()-Methoden nur in die
     * offene Formularmaske, „Übernehmen" bleibt der bewusste letzte
     * Schritt. Dietmars Wunsch 04.09.2026: "Du darfst die auch gerne selbst
     * finden."
     */
    public function DiscoverWetterstation(): string
    {
        $candidates = $this->findInstancesByModuleNameSubstring(self::FROGGIT_GUID, 'froggit');
        foreach (array_keys($candidates) as $instanceID) {
            $windboe = $this->findChildVariableByExactName($instanceID, 'Windböe');
            $regenrate = $this->findChildVariableByExactName($instanceID, 'Regenrate');
            if ($windboe === null || $regenrate === null) {
                continue; // Name passt, aber die entscheidenden Felder fehlen -- kein Treffer
            }
            $this->UpdateFormField('WetterstationInstanceID', 'value', $instanceID);
            return sprintf('✅ Wetterstation "%s" gefunden (Froggit, Windböe/Regenrate vorhanden) -- bitte unten „Übernehmen" klicken, um zu speichern.', @IPS_GetName($instanceID) ?: ('#' . $instanceID));
        }

        $candidatesWu = $this->findInstancesByModuleNameSubstring(self::WEATHERSTATION_WU_GUID, 'weatherstation');
        foreach (array_keys($candidatesWu) as $instanceID) {
            $windgust = $this->findChildVariableByIdent($instanceID, 'Windgust');
            $rainin = $this->findChildVariableByIdent($instanceID, 'rainin');
            if ($windgust === null || $rainin === null) {
                continue;
            }
            $this->UpdateFormField('WetterstationInstanceID', 'value', $instanceID);
            return sprintf('✅ Wetterstation "%s" gefunden (Sainlogic/ELV, Windgust/rainin vorhanden) -- bitte unten „Übernehmen" klicken, um zu speichern.', @IPS_GetName($instanceID) ?: ('#' . $instanceID));
        }

        $candidatesMhs = $this->findInstancesByModuleNameSubstring(self::METEOBRIDGE_GUID, 'meteobridge');
        foreach (array_keys($candidatesMhs) as $instanceID) {
            $windGust = $this->findChildVariableByIdent($instanceID, 'Wind_Gust_KmH');
            $rainRate = $this->findChildVariableByIdent($instanceID, 'Rain_Rate');
            if ($windGust === null || $rainRate === null) {
                continue;
            }
            $this->UpdateFormField('WetterstationInstanceID', 'value', $instanceID);
            return sprintf('✅ Wetterstation "%s" gefunden (Meteobridge/Meteohub, Wind_Gust_KmH/Rain_Rate vorhanden) -- bitte unten „Übernehmen" klicken, um zu speichern.', @IPS_GetName($instanceID) ?: ('#' . $instanceID));
        }

        // Letzter Rückfall: kein bekanntes Modul gefunden -- systemweit nach
        // Variablen mit dem passenden Standard-Symcon-Profil suchen (deckt
        // z. B. eine KNX-Wetterstation ab, WENN der Nutzer ihren
        // Gruppenadress-Variablen bereits "~WindSpeed.kmh"/"~WindSpeed.ms"
        // bzw. "~Rainfall" zugewiesen hat -- KNX selbst vergibt das nicht
        // automatisch). Nur bei GENAU einem eindeutigen Treffer übernommen,
        // sonst lieber der Nutzer-Auswahl überlassen als etwas zu raten;
        // ein bereits von Hand gesetztes Feld wird nie überschrieben.
        $byProfile = $this->discoverWetterstationVariablesByProfile();
        $foundWind = $byProfile['wind'] !== null && $this->ReadPropertyInteger('WetterstationWindVariableID') === 0;
        $foundRegen = $byProfile['regen'] !== null && $this->ReadPropertyInteger('WetterstationRegenVariableID') === 0;
        if ($foundWind) {
            $this->UpdateFormField('WetterstationWindVariableID', 'value', $byProfile['wind']);
        }
        if ($foundRegen) {
            $this->UpdateFormField('WetterstationRegenVariableID', 'value', $byProfile['regen']);
        }
        if ($foundWind || $foundRegen) {
            return sprintf(
                '✅ Kein bekanntes Wetterstations-Modul, aber %s über das Standard-Profil im System gefunden und in die manuelle Auswahl übernommen -- bitte prüfen und unten „Übernehmen" klicken.',
                $foundWind && $foundRegen ? 'Wind- UND Regen-Variable' : ($foundWind ? 'eine Wind-Variable' : 'eine Regen-Variable')
            );
        }

        return 'ℹ️ Keine unterstützte Wetterstations-Instanz gefunden (Froggit/Sainlogic/ELV/Meteobridge) -- bei anderen Fabrikaten (z. B. KNX, Netatmo) unten die Wind-/Regen-Variable manuell auswählen.';
    }

    /**
     * @return array{wind:?int,regen:?int}
     */
    private function discoverWetterstationVariablesByProfile(): array
    {
        $windCandidates = [];
        $regenCandidates = [];
        foreach (@IPS_GetVariableList() ?: [] as $variableID) {
            $var = @IPS_GetVariable($variableID);
            if (!is_array($var)) {
                continue;
            }
            $profile = (string) $var['VariableProfile'];
            if ($profile === '~WindSpeed.kmh' || $profile === '~WindSpeed.ms') {
                $windCandidates[] = $variableID;
            } elseif ($profile === '~Rainfall') {
                $regenCandidates[] = $variableID;
            }
        }
        return [
            'wind' => count($windCandidates) === 1 ? $windCandidates[0] : null,
            'regen' => count($regenCandidates) === 1 ? $regenCandidates[0] : null,
        ];
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

    /**
     * Ob eine Schutzaktion für diese Warnung JETZT auslösen darf --
     * Dietmars Nachfrage 04.09.2026: eine erst für 16:00 Uhr gültige
     * Warnung, die schon um 09:00 Uhr eintrifft, soll die Markise nicht
     * schon um 09:00 Uhr einfahren. Maßgeblich ist 'onset' (Beginn laut
     * CAP), ersatzweise 'effective'; ohne beides (kommt vor, v. a. bei
     * synthetischen Quellen wie der eigenen Wetterstation) wird weiterhin
     * sofort ausgelöst -- es gibt schlicht keinen Zeitpunkt zum Abwarten.
     * Der globale Vorlauf (SchutzaktionVorlaufMinuten) sorgt dafür, dass
     * die Aktion VOR dem Ereignis fertig ist, nicht erst exakt zu Beginn.
     * Bereits laufende/akute Warnungen (onset liegt schon in der
     * Vergangenheit) lösen wie bisher sofort aus.
     */
    private function isActionDueByOnset(array $w): bool
    {
        $onsetRaw = $w['onset'] ?? null;
        if ($onsetRaw === null || $onsetRaw === '') {
            $onsetRaw = $w['effective'] ?? null;
        }
        if ($onsetRaw === null || $onsetRaw === '') {
            return true;
        }
        $onsetTs = strtotime((string) $onsetRaw);
        if ($onsetTs === false) {
            return true; // nicht parsebar -- sicherheitshalber sofort statt nie auslösen
        }
        $vorlaufMinuten = max(0, $this->ReadPropertyInteger('SchutzaktionVorlaufMinuten'));
        return time() >= ($onsetTs - $vorlaufMinuten * 60);
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
                'description' => trim(sprintf(
                    '%s µSv/h an Messstelle %s (eigener Schwellwert %s µSv/h -- keine amtliche Alarmstufe, die Rohdaten kennen keine offizielle Meldeschwelle). %s',
                    number_format($value, 3, ',', '.'),
                    $name,
                    number_format($threshold, 3, ',', '.'),
                    $this->bfsOdlContext($value)
                )),
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
    //  Eigene Wetterstation (Froggit o. ä.) -- Dietmars Wunsch 04.09.2026:
    //  "die Unwetterwarnungen können sich ja auch irren". Unabhängig von
    //  NINA/DWD/Meteoalarm: löst eigenständig aus, sobald die LOKAL
    //  gemessene Windböe oder Regenrate den eigenen Schwellwert
    //  überschreitet -- als Sicherheitsnetz für Fälle, in denen die
    //  amtlichen Quellen ein tatsächlich lokal auftretendes Ereignis nicht
    //  (rechtzeitig) melden. Geokreis um den Symcon-Systemstandort (die
    //  Wetterstation selbst liefert keine eigenen Koordinaten) -- ohne
    //  konfigurierten Systemstandort keine geratene Platzierung, siehe unten.
    // ----------------------------------------------------------------

    /**
     * Ermittelt die Wind- ODER Regen-Quellvariable: eine manuell gesetzte
     * Variable (beliebiges Fabrikat, z. B. KNX) hat Vorrang; sonst wird die
     * konfigurierte Wetterstations-Instanz nach dem Froggit-Anzeigenamen
     * ODER einem der bekannten Fremdmodul-Idents durchsucht (siehe
     * DiscoverWetterstation() -- Wolbolar/WeatherStation, Meteobridge/
     * Meteohub). $identSuffix bleibt bei der bisherigen, instanzbasierten
     * Kennung ("<instanceID>"), damit sich beim Upgrade NICHTS an bereits
     * gesehenen Warnungs-Identifiern ändert -- nur der neue manuelle Pfad
     * bekommt einen eigenen, unterscheidbaren Suffix.
     *
     * @param string[] $idents
     * @return array{0:?int,1:string} [VariableID oder null, Identifier-Suffix]
     */
    private function resolveWetterstationSource(int $manualVarID, int $instanceID, string $exactName, array $idents): array
    {
        if ($manualVarID > 0 && @IPS_VariableExists($manualVarID)) {
            return [$manualVarID, 'var' . $manualVarID];
        }
        if ($instanceID > 0 && @IPS_InstanceExists($instanceID)) {
            $found = $this->findChildVariableByExactName($instanceID, $exactName);
            foreach ($idents as $ident) {
                $found ??= $this->findChildVariableByIdent($instanceID, $ident);
            }
            if ($found !== null) {
                return [$found, (string) $instanceID];
            }
        }
        return [null, ''];
    }

    /**
     * Liest eine Windgeschwindigkeits-Variable normiert in km/h -- je nach
     * Quellmodul trägt sie das offizielle Symcon-Systemprofil
     * "~WindSpeed.kmh" ODER "~WindSpeed.ms" (z. B. nutzt Wolbolar/
     * IPSymconWeatherStation durchgängig m/s). Ohne diese Umrechnung würde
     * eine m/s-Variable stumm gegen einen km/h-Schwellwert verglichen --
     * ein Sturm könnte so unbemerkt bleiben. Live-Fund 04.09.2026 beim Bau
     * der manuellen Wind-Auswahl (KNX & Co. könnten ebenfalls m/s liefern).
     */
    private function readWindSpeedKmh(int $variableID): float
    {
        $value = (float) @GetValue($variableID);
        $var = @IPS_GetVariable($variableID);
        $profile = is_array($var) ? (string) $var['VariableProfile'] : '';
        if ($profile === '~WindSpeed.ms') {
            return $value * 3.6;
        }
        return $value;
    }

    /**
     * Ordnet eine gemessene Windböe (km/h) einer der drei konfigurierbaren
     * Schwellwertstufen zu -- angelehnt an DWDs eigene Warnstufen (Windböen
     * ab 50 km/h, Sturmböen 65-89 km/h, schwere Sturmböen 90-104 km/h),
     * aber mit niedrigerem Moderate-Standardwert (40 statt 50 km/h), weil
     * Sachschutz (Markise, Raffstore) mehr Vorlauf braucht als eine reine
     * Personen-Warnung (siehe EN-13561-Windwiderstandsklassen für Markisen,
     * Klasse 2 endet bei 38 km/h). Jede Schutzaktions-Zeile kann so über
     * ihr bestehendes "Ab Schweregrad"-Feld selbst wählen, ab welcher Stufe
     * sie reagiert -- z. B. eine empfindliche Markise schon ab Moderate,
     * ein robustes Raffstore erst ab Severe/Extreme. Dietmars Frage
     * 04.09.2026, ob 70 km/h pauschal für Markise UND Jalousie sinnvoll
     * ist (Antwort: nein, siehe README/Formular-Popup). null = unter der
     * niedrigsten Stufe, keine Warnung.
     */
    private function windSeverityForSpeed(float $windboe): ?string
    {
        $extreme = $this->ReadPropertyFloat('WetterstationWindSchwelleExtreme');
        if ($extreme <= 0) {
            $extreme = 90.0;
        }
        $severe = $this->ReadPropertyFloat('WetterstationWindSchwelleSevere');
        if ($severe <= 0) {
            $severe = 65.0;
        }
        $moderate = $this->ReadPropertyFloat('WetterstationWindSchwelleModerate');
        if ($moderate <= 0) {
            $moderate = 40.0;
        }

        if ($windboe >= $extreme) {
            return 'Extreme';
        }
        if ($windboe >= $severe) {
            return 'Severe';
        }
        if ($windboe >= $moderate) {
            return 'Moderate';
        }
        return null;
    }

    /**
     * Wie windSeverityForSpeed(), aber für die Regenrate (mm/h) -- Standard-
     * werte entsprechen DWDs eigenen amtlichen Starkregen-Warnstufen (1
     * Stunde): 15/25/40 mm/h. Dietmars Bestätigung 04.09.2026, Regenrate
     * ebenfalls gestuft statt einem einzigen Wert.
     */
    private function regenSeverityForRate(float $regenrate): ?string
    {
        $extreme = $this->ReadPropertyFloat('WetterstationRegenSchwelleExtreme');
        if ($extreme <= 0) {
            $extreme = 40.0;
        }
        $severe = $this->ReadPropertyFloat('WetterstationRegenSchwelleSevere');
        if ($severe <= 0) {
            $severe = 25.0;
        }
        $moderate = $this->ReadPropertyFloat('WetterstationRegenSchwelleModerate');
        if ($moderate <= 0) {
            $moderate = 15.0;
        }

        if ($regenrate >= $extreme) {
            return 'Extreme';
        }
        if ($regenrate >= $severe) {
            return 'Severe';
        }
        if ($regenrate >= $moderate) {
            return 'Moderate';
        }
        return null;
    }

    /**
     * Merkt sich vor dem Auslösen einer Wetterstations-Schutzaktion den
     * AKTUELLEN Wert ihrer Ziel-Variable -- dorthin stellt
     * checkWetterstationAutoRestore() später zurück, statt auf einen festen
     * Wert. Überschreibt einen bereits verfolgten Eintrag NICHT (sonst würde
     * ein zweites Auslösen, während die Aktion schon ausgelöst ist, den
     * eigentlichen Ursprungswert mit dem bereits geschützten Wert
     * überschreiben).
     */
    private function rememberWetterstationRestoreState(int $idx, array $action, string $identifier): void
    {
        $restoreState = json_decode($this->ReadAttributeString('WetterstationRestoreState'), true) ?: [];
        if (isset($restoreState[$idx])) {
            return;
        }
        $restoreState[$idx] = [
            'ZielVariableID' => $action['ZielVariableID'],
            'RestoreValue' => (float) @GetValue($action['ZielVariableID']),
            'FiredValue' => (float) $action['ZielWert'],
            'Source' => str_contains($identifier, 'windboe') ? 'windboe' : 'regenrate',
            'CalmSinceTs' => null,
        ];
        $this->WriteAttributeString('WetterstationRestoreState', json_encode($restoreState));
    }

    /**
     * Stellt eine durch die eigene Wetterstation ausgelöste Schutzaktion
     * automatisch zurück, NACHDEM Wind bzw. Regen (je nachdem, was sie
     * ausgelöst hat) seit WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN
     * DURCHGEHEND wieder unter der Moderate-Schwelle liegt -- die einzige
     * Ausnahme von "keine automatische Rückstellung" im ganzen Modul,
     * bewusst nur hier erlaubt, weil die eigene Wetterstation (anders als
     * eine amtliche Warnung) einen fortlaufenden, lokalen Live-Wert
     * liefert. Wird am Ende jedes Poll() aufgerufen, unabhängig davon, ob
     * gerade eine aktive Wetterstations-Warnung vorliegt (genau dann, wenn
     * KEINE mehr vorliegt, kann zurückgestellt werden).
     */
    private function checkWetterstationAutoRestore(): void
    {
        $restoreState = json_decode($this->ReadAttributeString('WetterstationRestoreState'), true) ?: [];
        if (count($restoreState) === 0) {
            return;
        }

        $instanceID = $this->ReadPropertyInteger('WetterstationInstanceID');
        [$windboeID] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationWindVariableID'),
            $instanceID,
            'Windböe',
            ['Windgust', 'Wind_Gust_KmH']
        );
        [$regenrateID] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationRegenVariableID'),
            $instanceID,
            'Regenrate',
            ['rainin', 'Rain_Rate']
        );
        $windCalm = $windboeID === null || $this->windSeverityForSpeed($this->readWindSpeedKmh($windboeID)) === null;
        $regenCalm = $regenrateID === null || $this->regenSeverityForRate((float) @GetValue($regenrateID)) === null;

        $now = time();
        $changed = false;
        foreach ($restoreState as $idx => $entry) {
            $calm = $entry['Source'] === 'windboe' ? $windCalm : $regenCalm;
            if (!$calm) {
                if ($entry['CalmSinceTs'] !== null) {
                    $restoreState[$idx]['CalmSinceTs'] = null;
                    $changed = true;
                }
                continue;
            }
            if ($entry['CalmSinceTs'] === null) {
                $restoreState[$idx]['CalmSinceTs'] = $now;
                $changed = true;
                continue;
            }
            if ($now - $entry['CalmSinceTs'] < self::WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN) {
                continue;
            }

            $variableID = (int) $entry['ZielVariableID'];
            // Sicherheitsprüfung wie beim Kofferraum-Typ: steht die Variable
            // NICHT mehr auf dem Wert, den WIR beim Schützen gesetzt haben
            // (FiredValue), hat der Nutzer (oder eine andere Automation) sie
            // inzwischen selbst verändert -- dann NICHT überschreiben,
            // sondern die Rückstellung nur still fallen lassen. Dietmars
            // Nachfrage 04.09.2026, ob der Stand vor dem Befehl geprüft
            // werden sollte.
            if (@IPS_VariableExists($variableID) && abs((float) @GetValue($variableID) - (float) $entry['FiredValue']) < 0.5) {
                @RequestAction($variableID, $entry['RestoreValue']);
                if ($this->ReadPropertyBoolean('PushAktiv') && !$this->isPushSnoozed()) {
                    $this->pushToAllWebfronts(
                        '🔽 Automatisch zurückgestellt',
                        sprintf('Wind/Regen war seit %d Minuten wieder ruhig -- Schutzaktion zurückgestellt.', (int) round(self::WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN / 60)),
                        $this->ReadPropertyString('PushSound')
                    );
                }
            }
            unset($restoreState[$idx]);
            $changed = true;
        }
        if ($changed) {
            $this->WriteAttributeString('WetterstationRestoreState', json_encode($restoreState));
        }
    }

    private function fetchWetterstation(): array
    {
        $instanceID = $this->ReadPropertyInteger('WetterstationInstanceID');
        [$windboeID, $windIdentSuffix] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationWindVariableID'),
            $instanceID,
            'Windböe',
            ['Windgust', 'Wind_Gust_KmH']
        );
        [$regenrateID, $regenIdentSuffix] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationRegenVariableID'),
            $instanceID,
            'Regenrate',
            ['rainin', 'Rain_Rate']
        );
        if ($windboeID === null && $regenrateID === null) {
            return [];
        }
        $loc = $this->getSystemLocation();
        if ($loc === null) {
            $this->LogError('fetchWetterstation', 'Kein konfigurierter Symcon-Systemstandort -- Wetterstations-Werte können nicht platziert werden (keine geratene Position).');
            return [];
        }

        $out = [];
        $circle = ['lat' => $loc['lat'], 'lon' => $loc['lon'], 'radiusKm' => 3.0];

        if ($windboeID !== null) {
            $windboe = $this->readWindSpeedKmh($windboeID);
            $severity = $this->windSeverityForSpeed($windboe);
            if ($severity !== null) {
                $out[] = [
                    'identifier' => 'wetterstation-windboe-' . $windIdentSuffix,
                    'source' => 'wetterstation',
                    'msgType' => 'Alert',
                    'event' => 'Sturm (eigene Messung)',
                    'headline' => sprintf('Eigene Wetterstation: Windböe %s km/h', number_format($windboe, 1, ',', '.')),
                    'description' => sprintf(
                        'Lokal gemessene Windböe %s km/h, Stufe "%s" -- unabhängig von amtlichen Warnungen, eigene Schwellwerte, keine amtliche Klassifikation.',
                        number_format($windboe, 1, ',', '.'),
                        $severity
                    ),
                    'instruction' => '',
                    'severity' => $severity,
                    'effective' => null,
                    'onset' => null,
                    'expires' => null,
                    'areaDesc' => 'Eigene Wetterstation',
                    'rings' => [],
                    'circles' => [$circle],
                ];
            }
        }
        if ($regenrateID !== null) {
            $regenrate = (float) @GetValue($regenrateID);
            $regenSeverity = $this->regenSeverityForRate($regenrate);
            if ($regenSeverity !== null) {
                $out[] = [
                    'identifier' => 'wetterstation-regenrate-' . $regenIdentSuffix,
                    'source' => 'wetterstation',
                    'msgType' => 'Alert',
                    'event' => 'Starkregen (eigene Messung)',
                    'headline' => sprintf('Eigene Wetterstation: Regenrate %s mm/h', number_format($regenrate, 1, ',', '.')),
                    'description' => sprintf(
                        'Lokal gemessene Regenrate %s mm/h, Stufe "%s" -- unabhängig von amtlichen Warnungen, eigene Schwellwerte, keine amtliche Klassifikation.',
                        number_format($regenrate, 1, ',', '.'),
                        $regenSeverity
                    ),
                    'instruction' => '',
                    'severity' => $regenSeverity,
                    'effective' => null,
                    'onset' => null,
                    'expires' => null,
                    'areaDesc' => 'Eigene Wetterstation',
                    'rings' => [],
                    'circles' => [$circle],
                ];
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------
    //  Meteoalarm (feeds.meteoalarm.org -- europaweite Wetterwarnungen,
    //  39 Länder, Live gegen die echte Feed-Liste geprüft 04.09.2026).
    //  Anders als NINA/DWD/PEGELONLINE/BfS liefern die frei zugänglichen
    //  Atom-Feeds KEINE Warnfläche (kein <polygon>/<circle>, live geprüft
    //  gegen mehrere Länderfeeds inkl. der vollständigen CAP-Originalquelle
    //  hinter jedem Eintrag) -- nur benannte Verwaltungsgebiete je
    //  EMMA_ID/NUTS3-Geocode. Der Abgleich läuft deshalb NICHT über
    //  WHUB_Geo, sondern per Namensvergleich: Der Standort wird per
    //  Nominatim-Reverse-Geocoding (bereits für die Vorwärtssuche im
    //  Standorte-Panel genutzt) einmalig einem Kreis/einer Region
    //  zugeordnet (gecacht, siehe reverseGeocodeStandort()), und diese
    //  Namen werden gegen die je Meldung genannten Gebietsnamen verglichen.
    //  Bewusst als eigener, klar gekennzeichneter Pfad NEBEN dem
    //  geometrischen Matching -- keine vorgetäuschte Präzision.
    // ----------------------------------------------------------------

    /** Reverse-Geocoding eines Standorts (Land + Kreis-/Regionsname) -- gecacht (6h, gerundete Koordinaten als Schlüssel), um Nominatims Nutzungsbedingungen (max. 1 Anfrage/Sekunde, keine Massennutzung) einzuhalten. */
    private function reverseGeocodeStandort(float $lat, float $lon): array
    {
        $key = sprintf('%.1f_%.1f', $lat, $lon);
        $cache = json_decode($this->ReadAttributeString('ReverseGeoCache'), true) ?: [];
        $cached = $cache[$key] ?? null;
        if (is_array($cached) && (time() - (int) ($cached['ts'] ?? 0)) < 21600) {
            return ['countryCode' => (string) ($cached['countryCode'] ?? ''), 'names' => (array) ($cached['names'] ?? [])];
        }

        $url = sprintf('https://nominatim.openstreetmap.org/reverse?format=json&zoom=8&addressdetails=1&lat=%s&lon=%s', $lat, $lon);
        $body = $this->httpGet($url, 15, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        $json = $body !== null ? json_decode($body, true) : null;
        $address = is_array($json) ? ($json['address'] ?? []) : [];

        $countryCode = strtolower((string) ($address['country_code'] ?? ''));
        $names = [];
        foreach (['county', 'state_district', 'region', 'state', 'province', 'municipality', 'city', 'town'] as $field) {
            $v = trim((string) ($address[$field] ?? ''));
            if ($v !== '' && !in_array($v, $names, true)) {
                $names[] = $v;
            }
        }

        $cache[$key] = ['countryCode' => $countryCode, 'names' => $names, 'ts' => time()];
        $this->WriteAttributeString('ReverseGeoCache', json_encode($cache));
        return ['countryCode' => $countryCode, 'names' => $names];
    }

    /** Fallunabhängiger Namensabgleich in beide Richtungen (Substring) -- "Ortenaukreis" matcht "Kreis Ortenaukreis" und umgekehrt. */
    private function namesOverlap(array $a, array $b): bool
    {
        foreach ($a as $x) {
            $x = mb_strtolower(trim((string) $x));
            if ($x === '') {
                continue;
            }
            foreach ($b as $y) {
                $y = mb_strtolower(trim((string) $y));
                if ($y !== '' && (str_contains($x, $y) || str_contains($y, $x))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Meteoalarm-Warnungen für alle Länder, in denen mindestens ein aktiver
     * Standort liegt (per Reverse-Geocoding ermittelt) -- fragt gezielt nur
     * die tatsächlich relevanten Länderfeeds ab, nicht alle 39 auf Verdacht.
     */
    private function fetchMeteoalarm(): array
    {
        // Ist die direkte GeoSphere-Austria-Anbindung aktiv, übernimmt SIE
        // Österreich (koordinatengenau, siehe fetchGeosphereAt()) -- Meteoalarm
        // liefert für AT nur einen ungenaueren Namensabgleich. Gleiches Prinzip
        // wie "DWD direkt ersetzt den NINA-'dwd'-Kanal" oben in Poll().
        $geosphereAtActive = $this->ReadPropertyBoolean('QuelleGeosphereAt');

        $standorte = array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '');
        $slugs = [];
        foreach ($standorte as $s) {
            $coords = $this->resolveStandortCoords($s);
            $geo = $this->reverseGeocodeStandort($coords['lat'], $coords['lon']);
            $slug = self::METEOALARM_COUNTRY_SLUGS[$geo['countryCode']] ?? null;
            if ($slug === null || ($slug === 'austria' && $geosphereAtActive)) {
                continue;
            }
            $slugs[$slug] = true;
        }

        $out = [];
        foreach (array_keys($slugs) as $slug) {
            $out = array_merge($out, $this->fetchMeteoalarmCountry($slug));
        }
        return $out;
    }

    private function fetchMeteoalarmCountry(string $slug): array
    {
        $url = 'https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-' . $slug;
        $body = $this->httpGet($url, 20, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            $this->LogError('fetchMeteoalarmCountry', 'Meteoalarm-Feed für "' . $slug . '" nicht erreichbar.');
            return [];
        }
        return $this->parseMeteoalarmAtom($body, $slug);
    }

    /** Vom HTTP-Abruf getrennt (wie parseCapXml()/fetchDwdCap()), damit sich die Atom+CAP-Auswertung ohne Netzzugriff testen lässt. */
    private function parseMeteoalarmAtom(string $body, string $slug): array
    {
        $prevUseErrors = libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($body);
        libxml_use_internal_errors($prevUseErrors);
        if ($sxml === false) {
            $this->LogError('parseMeteoalarmAtom', 'Meteoalarm-Feed für "' . $slug . '" ließ sich nicht als XML parsen.');
            return [];
        }
        $entries = $sxml->entry ?? [];

        $out = [];
        foreach ($entries as $entry) {
            $capNs = $entry->children('urn:oasis:names:tc:emergency:cap:1.2');
            $identifier = (string) $capNs->identifier;
            $areaDesc = (string) $capNs->areaDesc;
            if ($identifier === '' || $areaDesc === '') {
                continue;
            }
            $msgType = (string) $capNs->message_type;
            $out[] = [
                'identifier' => $identifier . '|' . $areaDesc,
                'source' => 'meteoalarm',
                'msgType' => $msgType !== '' ? $msgType : 'Alert',
                'event' => (string) $capNs->event,
                'headline' => (string) ($entry->title ?: $capNs->event ?: 'Warnung'),
                'description' => (string) ($entry->title ?? ''),
                'instruction' => '',
                'severity' => (string) ($capNs->severity ?: 'Unknown'),
                'effective' => (string) $capNs->onset ?: null,
                'onset' => (string) $capNs->onset ?: null,
                'expires' => (string) $capNs->expires ?: null,
                'areaDesc' => $areaDesc,
                'rings' => [],
                'circles' => [],
                'nameMatch' => [$areaDesc],
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------
    //  GeoSphere Austria Warn API -- koordinatengenaue, amtliche Warnungen
    //  für Österreich (siehe Konstanten-Kommentar oben). Anders als
    //  Meteoalarm macht diese API das geometrische Matching selbst: ein
    //  Aufruf pro Standort-Koordinate liefert direkt, ob DORT eine Warnung
    //  gilt. Deshalb bekommt jede zurückgegebene Warnung einen winzigen
    //  Kreis GENAU an der abgefragten Koordinate (kein eigenes Polygon
    //  nötig) -- läuft dadurch unverändert durch das normale geometrische
    //  Matching in processWarnings(), inklusive Umkreis für benachbarte
    //  Standorte.
    // ----------------------------------------------------------------

    private function fetchGeosphereAt(): array
    {
        $standorte = array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '');
        $out = [];
        foreach ($standorte as $s) {
            $coords = $this->resolveStandortCoords($s);
            $geo = $this->reverseGeocodeStandort($coords['lat'], $coords['lon']);
            if ($geo['countryCode'] !== 'at') {
                continue;
            }
            $out = array_merge($out, $this->fetchGeosphereAtCoords($coords['lat'], $coords['lon']));
        }
        return $out;
    }

    private function fetchGeosphereAtCoords(float $lat, float $lon): array
    {
        $url = self::GEOSPHERE_AT_URL . '?' . http_build_query(['lat' => $lat, 'lon' => $lon, 'lang' => 'de']);
        $json = $this->httpGetJson($url);
        if (!is_array($json)) {
            return [];
        }
        return $this->parseGeosphereAtResponse($json, $lat, $lon);
    }

    /** Vom HTTP-Abruf getrennt (wie parseMeteoalarmAtom()/parseCapXml()), damit sich die Auswertung ohne Netzzugriff testen lässt. */
    private function parseGeosphereAtResponse(array $json, float $lat, float $lon): array
    {
        $warnings = $json['properties']['warnings'] ?? null;
        if (!is_array($warnings) || count($warnings) === 0) {
            return [];
        }
        $areaName = (string) ($json['properties']['location']['properties']['name'] ?? 'Österreich');

        $out = [];
        foreach ($warnings as $w) {
            $props = $w['properties'] ?? [];
            $warnId = $props['warnid'] ?? null;
            if ($warnId === null) {
                continue;
            }
            $wtype = (int) ($props['rawinfo']['wtype'] ?? 0);
            $wlevel = (int) ($props['rawinfo']['wlevel'] ?? 0);
            $startTs = isset($props['rawinfo']['start']) ? (int) $props['rawinfo']['start'] : null;
            $endTs = isset($props['rawinfo']['end']) ? (int) $props['rawinfo']['end'] : null;
            $event = self::GEOSPHERE_AT_WARNTYPE_EVENT[$wtype] ?? 'Warnung';
            $description = trim((string) ($props['auswirkungen'] ?? ''));
            $empfehlungen = trim((string) ($props['empfehlungen'] ?? ''));
            if ($empfehlungen !== '') {
                $description = $description !== '' ? $description . ' ' . $empfehlungen : $empfehlungen;
            }
            $out[] = [
                'identifier' => 'geosphere-at-' . $warnId,
                'source' => 'geosphere_at',
                'msgType' => 'Alert',
                'event' => $event,
                'headline' => (string) ($props['text'] ?? sprintf('%s-Warnung für %s', $event, $areaName)),
                'description' => $description,
                'instruction' => '',
                'severity' => self::GEOSPHERE_AT_WARNLEVEL_SEVERITY[$wlevel] ?? 'Moderate',
                'effective' => $startTs !== null ? date('c', $startTs) : null,
                'onset' => $startTs !== null ? date('c', $startTs) : null,
                'expires' => $endTs !== null ? date('c', $endTs) : null,
                'areaDesc' => $areaName,
                'rings' => [],
                'circles' => [['lat' => $lat, 'lon' => $lon, 'radiusKm' => 5.0]],
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------
    //  BAFU-Hochwasserdaten über LINDAS -- siehe Konstanten-Kommentar oben.
    //  Global wie PEGELONLINE: EIN Abruf für alle Schweizer Messstationen,
    //  die geometrische Umkreis-Prüfung in processWarnings() erledigt den
    //  Rest (kein Standort-Filter nötig, andere Standorte sind ohnehin nie
    //  in Kreis-Reichweite einer Schweizer Station).
    // ----------------------------------------------------------------

    private function fetchBafuHydroCh(): array
    {
        $schwelle = $this->ReadPropertyInteger('BafuHydroSchwelle');
        if ($schwelle < 2 || $schwelle > 5) {
            $schwelle = 3;
        }
        $query = 'PREFIX s: <http://schema.org/> '
            . 'PREFIX hd: <https://environment.ld.admin.ch/foen/hydro/dimension/> '
            . 'PREFIX geosparql: <http://www.opengis.net/ont/geosparql#> '
            . 'PREFIX xsd: <http://www.w3.org/2001/XMLSchema#> '
            . 'SELECT ?id ?name ?dangerLevel ?measurementTime ?wkt '
            . 'FROM <https://lindas.admin.ch/foen/hydro> '
            . 'WHERE { '
            . '  ?st a <http://example.com/HydroMeasuringStation> ; s:identifier ?id ; s:name ?name . '
            . '  ?obs hd:station ?st ; hd:measurementTime ?measurementTime ; hd:dangerLevel ?dangerLevel . '
            . '  FILTER(isNumeric(?dangerLevel) && xsd:integer(?dangerLevel) >= ' . $schwelle . ') '
            . '  OPTIONAL { ?st geosparql:hasGeometry/geosparql:asWKT ?wkt } '
            . '}';
        $url = self::BAFU_HYDRO_SPARQL_ENDPOINT . '?' . http_build_query(['query' => $query]);
        $body = $this->httpGet($url, 20, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            $this->LogError('fetchBafuHydroCh', 'LINDAS-Abfrage nicht erreichbar.');
            return [];
        }
        return $this->parseBafuHydroCsv($body);
    }

    /** Vom HTTP-Abruf getrennt, damit sich die CSV-Auswertung ohne Netzzugriff testen lässt. */
    private function parseBafuHydroCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if ($lines === false || count($lines) < 2) {
            return [];
        }
        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = array_combine($header, str_getcsv($line, ',', '"', '\\'));
            if ($row === false) {
                continue;
            }
            $level = (int) ($row['dangerLevel'] ?? 0);
            // Stufe 1 ("keine oder geringe Gefahr") ist nie berichtenswert --
            // eigener Sicherheitsnetz-Filter, unabhängig vom serverseitigen
            // SPARQL-Schwellwert (der Formular-Schwellwert erlaubt ohnehin nur 2-5).
            if ($level < 2) {
                continue;
            }
            $wkt = (string) ($row['wkt'] ?? '');
            if (!preg_match('/POINT\(([\-0-9.]+)\s+([\-0-9.]+)\)/', $wkt, $m)) {
                continue; // keine Koordinate -- keine geratene Platzierung
            }
            $lon = (float) $m[1];
            $lat = (float) $m[2];
            $name = (string) ($row['name'] ?? 'Messstation');
            $label = self::BAFU_HYDRO_LEVEL_LABEL[$level] ?? ('Stufe ' . $level);
            $out[] = [
                'identifier' => 'bafu-hydro-' . ($row['id'] ?? $name),
                'source' => 'bafu_hydro_ch',
                'msgType' => 'Alert',
                'event' => 'Hochwasser',
                'headline' => sprintf('Hochwassergefahr: %s (Gefahrenstufe %d)', $name, $level),
                'description' => sprintf(
                    'Amtliche Gefahrenstufe %d von 5 (%s) an Messstation %s -- offizielle BAFU-Skala, keine Eigenkonstruktion.',
                    $level,
                    $label,
                    $name
                ),
                'instruction' => '',
                'severity' => self::BAFU_HYDRO_LEVEL_SEVERITY[$level] ?? 'Moderate',
                'effective' => (string) ($row['measurementTime'] ?? '') ?: null,
                'onset' => null,
                'expires' => null,
                'areaDesc' => $name,
                'rings' => [],
                'circles' => [['lat' => $lat, 'lon' => $lon, 'radiusKm' => 5.0]],
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------
    //  BETA -- Hagelschutz-Signalbox (VKF, hagelschutz-einfach-automatisch.ch)
    //  siehe Konstanten-Kommentar oben. Bindet ein physisch bei einem
    //  konkreten Schweizer Gebäude registriertes Hagelwarn-Gerät ein -- die
    //  volle Poll-URL kommt 1:1 aus der Konfiguration der eigenen Signalbox,
    //  WarnHub baut sie nicht selbst zusammen.
    // ----------------------------------------------------------------

    private function fetchHagelschutzCh(): array
    {
        $url = trim($this->ReadPropertyString('HagelschutzPollUrl'));
        if ($url === '' || strpos($url, self::HAGELSCHUTZ_CH_URL_PREFIX) !== 0) {
            return []; // leer oder erkennbar keine echte Signalbox-URL -- nichts abrufen, nicht raten
        }
        $json = $this->httpGetJson($url);
        return $this->parseHagelschutzChResponse($json);
    }

    /** Vom HTTP-Abruf getrennt, damit sich die Auswertung ohne Netzzugriff testen lässt. */
    private function parseHagelschutzChResponse(?array $json): array
    {
        if ($json === null || !array_key_exists('currentState', $json)) {
            return [];
        }
        $state = (int) $json['currentState'];
        if ($state === 0) {
            return []; // 0 = kein Hagel -- laut Doku "encouraged to treat ... as zero and non-zero"
        }
        $loc = $this->getSystemLocation();
        if ($loc === null) {
            $this->LogError('fetchHagelschutzCh', 'Kein konfigurierter Symcon-Systemstandort -- Hagelwarnung kann nicht platziert werden (keine geratene Position).');
            return [];
        }
        return [[
            'identifier' => 'hagelschutz-ch-' . $this->InstanceID,
            'source' => 'hagelschutz_ch',
            'msgType' => 'Alert',
            'event' => 'Hagel',
            'headline' => 'Hagelschutz-Signalbox: Hagelwarnung aktiv' . ($state === 2 ? ' (Testalarm)' : ''),
            'description' => 'Signal der eigenen VKF-Hagelschutz-Signalbox (meteo.netitservices.com) -- amtlich in dem Sinne, dass die Prognose von SRF Meteo/VKF stammt, aber ein Gerätesignal ohne eigene Warnflächen-Geometrie.',
            'instruction' => '',
            'severity' => 'Severe',
            'effective' => null,
            'onset' => null,
            'expires' => null,
            'areaDesc' => 'Eigene Hagelschutz-Signalbox',
            'rings' => [],
            'circles' => [['lat' => $loc['lat'], 'lon' => $loc['lon'], 'radiusKm' => 3.0]],
        ]];
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
        if ($this->ReadPropertyBoolean('QuelleMeteoalarm')) {
            $warnings = array_merge($warnings, $this->fetchMeteoalarm());
        }
        if ($this->ReadPropertyBoolean('QuelleGeosphereAt')) {
            $warnings = array_merge($warnings, $this->fetchGeosphereAt());
        }
        if ($this->ReadPropertyBoolean('QuelleBafuHydroCh')) {
            $warnings = array_merge($warnings, $this->fetchBafuHydroCh());
        }
        if ($this->ReadPropertyString('HagelschutzPollUrl') !== '') {
            $warnings = array_merge($warnings, $this->fetchHagelschutzCh());
        }
        if ($this->ReadPropertyInteger('WetterstationInstanceID') > 0
            || $this->ReadPropertyInteger('WetterstationWindVariableID') > 0
            || $this->ReadPropertyInteger('WetterstationRegenVariableID') > 0) {
            $warnings = array_merge($warnings, $this->fetchWetterstation());
        }

        $result = $this->processWarnings($warnings);
        if ($this->ReadPropertyBoolean('WetterstationAutoRueckstellung')) {
            $this->checkWetterstationAutoRestore();
        }

        $this->WriteAttributeInteger('LastPollTs', time());
        $this->WriteAttributeString('LastActiveWarningsJson', json_encode($result['active']));
        $this->refreshStatusVariables();
        @$this->UpdateFormField('PollStatusLabel', 'caption', $this->getPollStatusLine());

        $icon = $result['activeCount'] > 0 ? '⚠️' : '✅';
        return sprintf(
            '%s Prüfung abgeschlossen: %d aktive Warnung(en), %d neu gemeldet, %d hochgestuft, %d Entwarnung(en), %d Schutzaktion(en) ausgelöst.',
            $icon,
            $result['activeCount'],
            $result['newlyPushed'],
            $result['escalated'],
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

    /**
     * Pausiert NUR die Push-Zustellung für die angegebene Dauer (z. B.
     * Urlaub, Feier, Nachtruhe) -- Erkennung, Warnungs-Historie und
     * Schutzaktionen laufen unverändert weiter, ein Sturm wird also
     * weiterhin z. B. die Markise einfahren, nur das Handy bleibt still.
     * WHUB_TestPush() bleibt bewusst ausgenommen (ein expliziter manueller
     * Test soll immer ankommen, auch während einer Pause). Dietmars Wunsch
     * 04.09.2026 ("Snooze/Ruhephase").
     */
    public function SnoozePush(int $minuten): string
    {
        if ($minuten <= 0) {
            return '⚠️ Dauer muss größer als 0 sein.';
        }
        $bis = time() + $minuten * 60;
        $this->WriteAttributeInteger('PushSnoozeUntilTs', $bis);
        @$this->UpdateFormField('SnoozeStatusLabel', 'caption', $this->snoozeStatusLine());
        return sprintf('🔕 Push-Benachrichtigung pausiert bis %s Uhr.', date('d.m. H:i', $bis));
    }

    public function CancelSnooze(): string
    {
        if (!$this->isPushSnoozed()) {
            return 'ℹ️ Push-Benachrichtigung war nicht pausiert.';
        }
        $this->WriteAttributeInteger('PushSnoozeUntilTs', 0);
        @$this->UpdateFormField('SnoozeStatusLabel', 'caption', $this->snoozeStatusLine());
        return '🔔 Pause aufgehoben -- Push-Benachrichtigung läuft wieder normal.';
    }

    /**
     * Löst zu Testzwecken alle AKTIVEN Schutzaktionen aus, die für den
     * angegebenen Alarmtyp gelten (angekreuzte Kategorie ODER gar keine
     * angekreuzt, siehe CATEGORY_FIELDS) -- SOFORT und unabhängig von einer
     * echten Warnung, vom Standort-Filter und vom Mindest-Schweregrad. Reiner
     * Aktor-Test ("tut die Aktion tatsächlich das Richtige?"), analog zu
     * TestPush() für den Push-Zustellweg -- prüft NICHT die
     * Warnungserkennung/-zuordnung selbst, die läuft nur über einen echten
     * Poll(). Dietmars ausdrücklicher Wunsch 04.09.2026, je Alarmtyp einzeln
     * prüfbar, nicht nur pauschal alle Schutzaktionen auf einmal.
     */
    public function TestSchutzaktionen(string $kategorie): string
    {
        if (!isset(self::CATEGORY_FIELDS[$kategorie])) {
            return '⚠️ Unbekannter Alarmtyp "' . $kategorie . '".';
        }
        $label = self::CATEGORY_FIELDS[$kategorie][1];
        $matching = array_values(array_filter(
            $this->decodeSchutzaktionen(),
            fn ($a) => $a['Aktiv'] && (count($a['Kategorien']) === 0 || in_array($kategorie, $a['Kategorien'], true))
        ));
        if (count($matching) === 0) {
            return sprintf('⚠️ Keine aktive Schutzaktion für "%s" gefunden.', $label);
        }
        $names = [];
        foreach ($matching as $action) {
            $this->fireProtectiveAction($action);
            $names[] = $action['Name'] !== '' ? $action['Name'] : ('Ziel-Variable #' . $action['ZielVariableID']);
        }
        return sprintf('✅ %d Schutzaktion(en) für "%s" ausgelöst: %s', count($matching), $label, implode(', ', $names));
    }

    private function processWarnings(array $warnings): array
    {
        $standorte = array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '');
        $seen = json_decode($this->ReadAttributeString('SeenWarnings'), true) ?: [];
        $fired = json_decode($this->ReadAttributeString('FiredActions'), true) ?: [];
        $actions = array_filter($this->decodeSchutzaktionen(), fn ($a) => $a['Aktiv']);
        $pushSound = $this->ReadPropertyString('PushSound');
        // Snooze pausiert NUR die tatsächliche Zustellung -- Erkennung,
        // Warnungs-Historie und Schutzaktionen laufen unverändert weiter
        // (Dietmars Wunsch 04.09.2026: z. B. im Urlaub weiterhin
        // automatisch schützen, nur nicht ständig benachrichtigt werden).
        $pushAktiv = $this->ReadPropertyBoolean('PushAktiv') && !$this->isPushSnoozed();

        $stillPresent = [];
        $active = [];
        $newlyPushed = 0;
        $escalated = 0;
        $cancelled = 0;
        $actionsTriggered = 0;
        $standortGeoNamesCache = [];

        foreach ($warnings as $w) {
            // Bereits abgelaufene Warnung ignorieren, auch wenn die Quelle
            // sie (verzögert oder fehlerhaft) noch weiterliefert -- zählt
            // NICHT als "still present", damit ein zuvor gepushter Zustand
            // über die bestehende Bereinigung am Ende automatisch aufgeräumt
            // wird, ohne dass die Quelle extra ein Cancel-Ereignis schicken
            // müsste. Cancel-Meldungen bleiben ausgenommen -- die haben
            // ihren eigenen, bereits bestehenden Ablauf. Live-Feeds (NINA/
            // DWD) räumen ihre Warnungen zwar zuverlässig auf, das ist aber
            // eine Absicherung gegen verzögerte/fehlerhafte Quelldaten.
            if ($w['msgType'] !== 'Cancel' && !empty($w['expires'])) {
                $expiresTs = strtotime((string) $w['expires']);
                if ($expiresTs !== false && $expiresTs < time()) {
                    continue;
                }
            }

            $stillPresent[$w['identifier']] = true;
            $category = $this->classifyEventCategory($w['event'], $w['headline']);
            // Schutzaktionen sollen erst kurz VOR dem tatsächlichen Beginn
            // einer Warnung feuern, nicht schon in dem Moment, in dem die
            // Meldung selbst eintrifft (die oft Stunden im Voraus kommt) --
            // Dietmars Nachfrage 04.09.2026: "würde ... die Markise ... auch
            // eingefahren werden, auch wenn die Meldung um 09:00 Uhr eingeht"
            // bei einer erst für 16:00 Uhr gültigen Warnung. Einmal $fired,
            // bleibt es das dauerhaft -- vorher wird JEDEN Poll neu geprüft.
            $actionDue = $this->isActionDueByOnset($w);

            foreach ($standorte as $standort) {
                $pairKey = $w['identifier'] . '|' . $standort['Name'];
                $coords = $this->resolveStandortCoords($standort);
                $pushZiele = $this->parsePushZielNames($standort['PushZielFilter']);

                $hasGeo = count($w['rings']) > 0 || count($w['circles']) > 0;
                $distanceKm = $hasGeo
                    ? WHUB_Geo::distanceToAny($coords['lat'], $coords['lon'], $w['rings'], $w['circles'])
                    : null;
                $matches = $hasGeo && $distanceKm !== null && $distanceKm <= $standort['RadiusKm'];

                // Meteoalarm liefert keine Warnfläche, nur benannte Gebiete
                // (siehe fetchMeteoalarmCountry()) -- Ersatzabgleich per Name
                // statt Geometrie, deshalb hier klar getrennt vom
                // geometrischen Pfad oben und ausdrücklich als ungenau
                // markiert ($nameMatched, siehe 'distanceKm' => null unten).
                $nameMatched = false;
                if (!$hasGeo && count($w['nameMatch'] ?? []) > 0) {
                    if (!array_key_exists($standort['Name'], $standortGeoNamesCache)) {
                        $standortGeoNamesCache[$standort['Name']] = $this->reverseGeocodeStandort($coords['lat'], $coords['lon'])['names'];
                    }
                    $nameMatched = $this->namesOverlap($w['nameMatch'], $standortGeoNamesCache[$standort['Name']]);
                }
                if ($nameMatched) {
                    $matches = true;
                }

                if (!$matches) {
                    continue;
                }
                if ($this->severityRank($w['severity']) < $standort['MinSeverity']) {
                    continue;
                }

                if ($w['msgType'] === 'Cancel') {
                    if (isset($seen[$pairKey])) {
                        unset($seen[$pairKey]);
                        $this->logHistory('entwarnung', $standort['Name'], $w, $category);
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
                    'nameMatched' => $nameMatched,
                    'effective' => $w['effective'],
                    'expires' => $w['expires'],
                ];

                // Erneut pushen, wenn die Meldung NEU ist ODER seit dem letzten
                // Push tatsächlich HOCHGESTUFT wurde (z. B. DWD verschärft eine
                // laufende Sturmwarnung von Moderate auf Severe) -- vorher blieb
                // eine bereits gesehene Warnung für immer stumm, auch wenn sie
                // sich deutlich verschlimmerte. Eine Abstufung pusht bewusst
                // NICHT erneut (keine dringende Nachricht), hält die
                // gespeicherte Severity aber aktuell, damit ein SPÄTERES
                // erneutes Ansteigen auf denselben Wert wieder zählt.
                $seenEntry = $seen[$pairKey] ?? null;
                $isNewWarning = $seenEntry === null;
                $isEscalation = !$isNewWarning && $this->severityRank($w['severity']) > $this->severityRank($seenEntry['severity'] ?? 'Unknown');
                if ($isNewWarning || $isEscalation) {
                    $seen[$pairKey] = ['msgType' => $w['msgType'], 'pushedAt' => time(), 'severity' => $w['severity']];
                    $this->logHistory($isNewWarning ? 'warnung' : 'eskalation', $standort['Name'], $w, $category);
                    if ($pushAktiv) {
                        $text = $this->buildPushText($standort['Name'], $w, $nameMatched);
                        if ($isEscalation) {
                            $text = '⬆️ Hochgestuft (' . $w['severity'] . '): ' . $text;
                        }
                        $this->pushToAllWebfronts(
                            $this->buildPushTitle($w['severity'], $w['event']),
                            $text,
                            $pushSound,
                            $pushZiele,
                            $w['severity']
                        );
                    }
                    if ($isNewWarning) {
                        $newlyPushed++;
                    } else {
                        $escalated++;
                    }
                } elseif ($seenEntry !== null) {
                    $seen[$pairKey]['severity'] = $w['severity'];
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
                    if (!$actionDue) {
                        continue; // noch vor dem Vorlauf-Fenster -- beim nächsten Poll erneut prüfen
                    }
                    $fired[$fireKey] = time();
                    // Vor dem Auslösen den aktuellen Wert merken, falls die
                    // Auto-Rückstellung aktiv ist -- NUR für durch die eigene
                    // Wetterstation ausgelöste Raffstore-/Markisen-/Garagentor-
                    // Aktionen (siehe checkWetterstationAutoRestore()).
                    if ($this->ReadPropertyBoolean('WetterstationAutoRueckstellung')
                        && str_starts_with($w['identifier'], 'wetterstation-')
                        && in_array($action['Typ'], ['raffstore', 'markise', 'garage'], true)) {
                        $this->rememberWetterstationRestoreState($idx, $action, $w['identifier']);
                    }
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
            'escalated' => $escalated,
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

    private function buildPushText(string $standortName, array $w, bool $nameMatched = false): string
    {
        $text = $standortName . ': ' . $w['headline'];
        if ($w['description'] !== '') {
            $text .= '. ' . $w['description'];
        }
        // Handlungsempfehlung der Quelle (CAP <instruction>, z. B. "Meiden Sie
        // den Aufenthalt im Wald") -- wurde bisher eingelesen, aber nirgends
        // angezeigt. Echter, unmittelbar nutzbarer Inhalt, kein Eigenwert.
        if (!empty($w['instruction'])) {
            $text .= ' ' . $w['instruction'];
        }
        if ($w['expires'] !== null) {
            $text .= ' Gültig bis ' . $this->formatDateDe($w['expires']) . ' Uhr.';
        }
        // Meteoalarm-Namensabgleich statt Geometrie -- Transparenz statt
        // vorgetäuschter Präzision (siehe fetchMeteoalarmCountry()).
        if ($nameMatched) {
            $text .= ' (Namensabgleich, keine Warnfläche verfügbar.)';
        }
        return $this->truncateBytes($text, 256);
    }

    // ----------------------------------------------------------------
    //  Kacheln (WebFront-/Kachel-Visualisierung, ~HTMLBox-Variablen)
    // ----------------------------------------------------------------

    // Apples eigene HIG-Systemfarben (macOS Tahoe/"Liquid Glass"-Optik,
    // Dietmars ausdrücklicher Wunsch 04.09.2026) -- bewusst NICHT identisch
    // mit den Material-artigen Farben des WHUB.Schweregrad-Profils (das
    // dient der Symcon-Konsole, hier geht es um die eigene HTML-Kachel).
    private const TILE_SEVERITY_COLOR = [
        'Unknown'  => '#8E8E93', // systemGray
        'Minor'    => '#0A84FF', // systemBlue
        'Moderate' => '#FFD60A', // systemYellow
        'Severe'   => '#FF9F0A', // systemOrange
        'Extreme'  => '#FF453A', // systemRed
    ];
    private const TILE_COLOR_OK = '#30D158'; // systemGreen, "keine aktive Warnung"

    private function hexToRgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return sprintf('rgba(%d,%d,%d,%s)', $r, $g, $b, $alpha);
    }

    /** @return array{rank:int,severity:string} höchster Schweregrad unter den aktiven Warnungen, 'Unknown'/-1 wenn keine aktiv. */
    private function highestActiveSeverity(array $active): array
    {
        $rank = -1;
        $severity = 'Unknown';
        foreach ($active as $w) {
            $r = self::SEVERITY_RANK[$w['severity'] ?? 'Unknown'] ?? 0;
            if ($r > $rank) {
                $rank = $r;
                $severity = $w['severity'] ?? 'Unknown';
            }
        }
        return ['rank' => $rank, 'severity' => $severity];
    }

    private function relativeMinutesText(int $ts): string
    {
        if ($ts === 0) {
            return 'noch nie geprüft';
        }
        $minutes = (int) round((time() - $ts) / 60);
        if ($minutes <= 0) {
            return 'gerade eben geprüft';
        }
        if ($minutes < 60) {
            return 'vor ' . $minutes . ' Min. geprüft';
        }
        return 'vor ' . (int) round($minutes / 60) . ' Std. geprüft';
    }

    /** Gemeinsamer <style>-Block beider Kacheln -- pro Kachel eigener Klassen-Namensraum (.whub-status/.whub-overview), damit beide unabhängig als Kachel-Visualisierung eingebunden werden können, ohne sich gegenseitig zu beeinflussen. */
    private function tileStyleBlock(): string
    {
        return <<<'CSS'
<style>
.whub-status,.whub-overview{all:initial;display:block;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","SF Pro Text","Segoe UI",Roboto,sans-serif;color:#1D1D1F;}
.whub-status *,.whub-overview *{box-sizing:border-box;}
.whub-status{padding:14px 16px;border-radius:22px;background:linear-gradient(160deg,rgba(255,255,255,0.65),rgba(255,255,255,0.30));backdrop-filter:blur(24px) saturate(180%);-webkit-backdrop-filter:blur(24px) saturate(180%);border:1px solid rgba(255,255,255,0.45);box-shadow:0 8px 28px rgba(0,0,0,0.12),inset 0 1px 0 rgba(255,255,255,0.55);}
.whub-overview{padding:16px;border-radius:22px;background:linear-gradient(160deg,rgba(255,255,255,0.55),rgba(255,255,255,0.22));backdrop-filter:blur(24px) saturate(180%);-webkit-backdrop-filter:blur(24px) saturate(180%);border:1px solid rgba(255,255,255,0.4);box-shadow:0 8px 32px rgba(0,0,0,0.12),inset 0 1px 0 rgba(255,255,255,0.5);}
@media (prefers-color-scheme:dark){
.whub-status,.whub-overview{color:#F5F5F7;}
.whub-status{background:linear-gradient(160deg,rgba(72,72,78,0.55),rgba(44,44,48,0.35));border-color:rgba(255,255,255,0.14);box-shadow:0 8px 28px rgba(0,0,0,0.45),inset 0 1px 0 rgba(255,255,255,0.08);}
.whub-overview{background:linear-gradient(160deg,rgba(60,60,67,0.5),rgba(36,36,40,0.32));border-color:rgba(255,255,255,0.12);box-shadow:0 8px 32px rgba(0,0,0,0.45),inset 0 1px 0 rgba(255,255,255,0.07);}
}
.whub-badge{display:flex;align-items:center;gap:14px;}
.whub-badge-icon{flex:0 0 auto;width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;}
.whub-badge-text{min-width:0;}
.whub-badge-title{font-size:15px;font-weight:600;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.whub-badge-sub{font-size:12px;opacity:0.6;margin-top:2px;}
.whub-header{display:flex;align-items:center;gap:8px;margin-bottom:12px;}
.whub-header-icon{font-size:16px;}
.whub-header-title{font-size:13px;font-weight:700;letter-spacing:0.2px;flex:1 1 auto;}
.whub-header-time{font-size:11px;opacity:0.55;}
.whub-card{display:flex;align-items:flex-start;gap:11px;padding:11px 13px;margin-bottom:8px;border-radius:16px;background:rgba(255,255,255,0.38);border-left:4px solid #8E8E93;}
@media (prefers-color-scheme:dark){.whub-card{background:rgba(255,255,255,0.07);}}
.whub-card:last-child{margin-bottom:0;}
.whub-card-icon{flex:0 0 auto;font-size:18px;line-height:1.3;}
.whub-card-body{min-width:0;flex:1 1 auto;}
.whub-card-title{font-size:14px;font-weight:600;line-height:1.3;}
.whub-card-sub{font-size:11.5px;opacity:0.62;margin-top:2px;line-height:1.35;}
.whub-more{font-size:11.5px;opacity:0.55;text-align:center;margin-top:4px;}
.whub-empty{display:flex;align-items:center;gap:12px;padding:2px 2px;}
.whub-empty-icon{flex:0 0 auto;width:40px;height:40px;border-radius:50%;background:rgba(48,209,88,0.16);display:flex;align-items:center;justify-content:center;font-size:19px;}
.whub-empty-text{font-size:14px;font-weight:600;}
</style>
CSS;
    }

    /**
     * Kompakte Status-Kachel (Badge: ein Farbkreis + Kurztext) -- gedacht für
     * eine kleine Kachel im Dashboard-Raster, macOS-Tahoe-"Liquid Glass"-Optik
     * (durchscheinender, weichgezeichneter Hintergrund, hell/dunkel-adaptiv
     * über prefers-color-scheme). Dietmars ausdrücklicher Wunsch 04.09.2026
     * ("eine oder auch mehrere Kacheln"). Ohne echtes WebFront nicht selbst
     * gegenprüfbar -- Rückmeldungen willkommen, siehe Feedback-Hinweis.
     */
    private function renderKachelStatus(array $active, int $lastTs, bool $snoozed = false): string
    {
        $count = count($active);
        if ($count === 0) {
            $icon = '✅';
            $color = self::TILE_COLOR_OK;
            $title = 'Keine aktive Warnung';
        } else {
            $top = $this->highestActiveSeverity($active);
            $icon = self::SEVERITY_ICON[$top['severity']] ?? '⚠️';
            $color = self::TILE_SEVERITY_COLOR[$top['severity']] ?? self::TILE_SEVERITY_COLOR['Unknown'];
            $title = $count === 1 ? '1 aktive Warnung' : $count . ' aktive Warnungen';
        }
        $sub = htmlspecialchars($this->relativeMinutesText($lastTs));
        if ($snoozed) {
            $sub .= ' · 🔕 Push pausiert';
        }

        return $this->tileStyleBlock() . <<<HTML
<div class="whub-status">
  <div class="whub-badge">
    <div class="whub-badge-icon" style="background:{$this->hexToRgba($color, 0.18)};">{$icon}</div>
    <div class="whub-badge-text">
      <div class="whub-badge-title">{$title}</div>
      <div class="whub-badge-sub">{$sub}</div>
    </div>
  </div>
</div>
HTML;
    }

    /**
     * Übersichts-Kachel: Liste der aktuell aktiven Warnungen als eigene
     * Karten (Farbe/Icon je Schweregrad), macOS-Tahoe-"Liquid Glass"-Optik.
     * Zeigt maximal 8 Karten (Dashboard-Kacheln sollen nicht ausufern) --
     * darüber ein Hinweis "+N weitere". Ohne echtes WebFront nicht selbst
     * gegenprüfbar -- Rückmeldungen willkommen.
     */
    private function renderKachelUebersicht(array $active, int $lastTs, bool $snoozed = false): string
    {
        $time = $lastTs > 0 ? htmlspecialchars(date('H:i', $lastTs)) . ' Uhr' : '--:--';
        if ($snoozed) {
            $time = '🔕 ' . $time;
        }
        $body = '';

        if (count($active) === 0) {
            $body = '<div class="whub-empty"><div class="whub-empty-icon">✅</div><div class="whub-empty-text">Keine aktive Warnung</div></div>';
        } else {
            $shown = array_slice($active, 0, 8);
            foreach ($shown as $w) {
                $severity = $w['severity'] ?? 'Unknown';
                $color = self::TILE_SEVERITY_COLOR[$severity] ?? self::TILE_SEVERITY_COLOR['Unknown'];
                $icon = self::SEVERITY_ICON[$severity] ?? 'ℹ️';
                $eventLabel = htmlspecialchars(mb_convert_case(mb_strtolower(trim($w['event'] ?? '') !== '' ? $w['event'] : 'Warnung'), MB_CASE_TITLE));
                $sub = htmlspecialchars($w['standort'] ?? '');
                if (!empty($w['expires'])) {
                    $expTs = strtotime((string) $w['expires']);
                    if ($expTs !== false) {
                        $sub .= ' · bis ' . htmlspecialchars(date('H:i', $expTs)) . ' Uhr';
                    }
                }
                $body .= <<<HTML
<div class="whub-card" style="border-left-color:{$color};">
  <div class="whub-card-icon">{$icon}</div>
  <div class="whub-card-body">
    <div class="whub-card-title">{$eventLabel}</div>
    <div class="whub-card-sub">{$sub}</div>
  </div>
</div>
HTML;
            }
            if (count($active) > 8) {
                $body .= '<div class="whub-more">+' . (count($active) - 8) . ' weitere</div>';
            }
        }

        return $this->tileStyleBlock() . <<<HTML
<div class="whub-overview">
  <div class="whub-header">
    <span class="whub-header-icon">🛡️</span>
    <span class="whub-header-title">WarnHub</span>
    <span class="whub-header-time">{$time}</span>
  </div>
  {$body}
</div>
HTML;
    }

    /**
     * Pusht an alle aktivierten Ziele -- je nach Typ per WFC_PushNotification
     * (WebFront), VISU_PostNotificationEx (Kachel-Visualisierung),
     * TB_SendMessage (Telegram) oder TUPO_SendMessage (Pushover), siehe
     * discoverPushTargets(). $onlyNames (bereits über parsePushZielNames()
     * kleingeschrieben) schränkt optional auf namentlich genannte Ziele ein
     * -- leer = an alle aktivierten Ziele (bisheriges Verhalten), genutzt für
     * Standorte mit eigenem "Push nur an ..."-Filter (z. B. ein mobiler
     * Standort soll nur das zugehörige Handy benachrichtigen, nicht auch das
     * der anderen Person). $severity steuert nur die Pushover-Priorität
     * (Severe/Extreme -> hohe Priorität) und bleibt sonst ungenutzt.
     */
    private function pushToAllWebfronts(string $title, string $text, string $sound, array $onlyNames = [], string $severity = ''): int
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
            } elseif ($w['Typ'] === 'telegram') {
                // TB_SendMessage() (offizielles Symcon-Modul symcon/TelegramBot)
                // kennt keinen separaten Titel -- Titel+Text deshalb zu einer
                // Nachricht zusammengefasst.
                if (!function_exists('TB_SendMessage')) {
                    $this->LogError('pushToAllWebfronts', 'TB_SendMessage ist nicht verfügbar (kein Telegram-Bot-Modul installiert).');
                    continue;
                }
                $ok = @TB_SendMessage($w['InstanceID'], $title . "\n" . $text);
            } elseif ($w['Typ'] === 'pushover') {
                // TUPO_SendMessage() (Community-Modul timo-u/Symcon_Pushover)
                // kennt Titel getrennt vom Text sowie eine Priorität -- Severe/
                // Extreme werden als "hohe Priorität" (1) markiert, alles
                // andere als normal (0).
                if (!function_exists('TUPO_SendMessage')) {
                    $this->LogError('pushToAllWebfronts', 'TUPO_SendMessage ist nicht verfügbar (kein Pushover-Modul installiert).');
                    continue;
                }
                $priority = in_array($severity, ['Severe', 'Extreme'], true) ? 1 : 0;
                $ok = @TUPO_SendMessage($w['InstanceID'], $title, $text, $priority);
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

            // Fenster schließen: immer "Ein" schalten, wie bei Sirene -- KEIN
            // Zielwert nötig. Anders als bei Sirene aber KEIN Auto-Aus: ein
            // automatisches "Fenster wieder öffnen" nach der Warnung wäre
            // fachlich falsch und sicherheitsrelevant unerwünscht. Teslas
            // close_windows-Befehl ist gerichtet (nicht umgeschaltet) --
            // deshalb ohne Zustandsprüfung sicher blind auslösbar, anders als
            // der Kofferraum-Typ unten.
            if ($action['Typ'] === 'fenster') {
                $ok = @RequestAction($action['ZielVariableID'], true);
                if (!$ok) {
                    $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '" (Fenster schließen): RequestAction fehlgeschlagen.');
                }
                return;
            }

            // Kofferraum/Heckklappe schließen: Teslas Kofferraum-Befehl ist
            // ein reiner UMSCHALTER ohne Richtung (kein "schließen", nur
            // "auslösen") -- ein blindes Auslösen bei bereits geschlossener
            // Klappe würde sie ÖFFNEN. Live verifiziert 04.09.2026 (an
            // Dietmars "Kohlekasten"): die Zustands-Variable listet AKTUELL
            // offene Klappen kommagetrennt (z. B. "Frunk, Kofferraum"), leer
            // = alles zu. Ohne gültige Zustands-Variable wird deshalb aus
            // Sicherheitsgründen GAR NICHT ausgelöst, statt zu raten.
            if ($action['Typ'] === 'kofferraum') {
                if ($action['ZustandsVariableID'] <= 0 || !IPS_VariableExists($action['ZustandsVariableID'])) {
                    $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '" (Kofferraum/Heckklappe): keine gültige Zustands-Variable konfiguriert -- kein automatisches Auslösen, da der Umschalt-Befehl sonst öffnen statt schließen könnte.');
                    return;
                }
                $zustand = (string) GetValue($action['ZustandsVariableID']);
                if (mb_stripos($zustand, 'kofferraum') === false && mb_stripos($zustand, 'heckklappe') === false) {
                    return; // bereits geschlossen -- nichts zu tun, kein Fehler
                }
                $ok = @RequestAction($action['ZielVariableID'], true);
                if (!$ok) {
                    $this->LogError('fireProtectiveAction', 'Schutzaktion "' . $action['Name'] . '" (Kofferraum/Heckklappe): RequestAction fehlgeschlagen.');
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

    /**
     * Warnungs-Historie: JSON-Liste der zuletzt gepushten Warnungen UND
     * Entwarnungen (nicht nur der aktuell aktiven, siehe WHUB_GetActiveWarnings())
     * -- newest first, auf $limit Einträge begrenzt (Deckel im Attribut selbst
     * bereits bei 500, analog zum EMS-Vorbild SpecialEventsLog). Für eine
     * eigene Auswertung ("wie oft hat es hier eigentlich schon Sturm
     * gegeben?") oder eine Verlaufsanzeige im eigenen Dashboard.
     */
    public function GetHistory(int $limit = 100): string
    {
        $log = json_decode($this->ReadAttributeString('WarnHistory'), true) ?: [];
        $log = array_reverse($log);
        if ($limit > 0) {
            $log = array_slice($log, 0, $limit);
        }
        return json_encode($log);
    }

    /**
     * Hängt einen Verlaufseintrag an (neue Warnung ODER Entwarnung) --
     * unabhängig davon, ob Push überhaupt aktiv ist (die Historie soll auch
     * dokumentieren, was passiert wäre, wenn Push zwischenzeitlich
     * ausgeschaltet war). Deckel bei 500 Einträgen, älteste zuerst raus
     * (identisches Prinzip wie EMS' SpecialEventsLog).
     */
    private function logHistory(string $kind, string $standortName, array $w, string $category): void
    {
        $log = json_decode($this->ReadAttributeString('WarnHistory'), true) ?: [];
        $log[] = [
            'ts' => time(),
            'kind' => $kind, // 'warnung' oder 'entwarnung'
            'standort' => $standortName,
            'event' => $w['event'],
            'headline' => $w['headline'],
            'severity' => $w['severity'],
            'category' => $category,
            'source' => $w['source'],
        ];
        if (count($log) > 500) {
            $log = array_slice($log, count($log) - 500);
        }
        $this->WriteAttributeString('WarnHistory', json_encode($log));
    }
}
