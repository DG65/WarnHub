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
    private const DOC_VERSION = '1.13.1';
    private const NEWS_VERSION = '1.13.1';
    private const LICENSE_URL = 'https://github.com/DG65/WarnHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-warnhub-warn-und-alarmmeldungen-fuer-deutschland-oesterreich-und-die-schweiz-mit-umkreis-filter-push-und-schutzaktionen/144349';

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

    /**
     * Alle einfachen Ein/Aus-Datenquellen -- für ApplyChanges()' Prüfung
     * "hat diese Instanz überhaupt eine aktive Quelle konfiguriert?". Diese
     * Prüfung hing bis 1.12.2 hart-codiert NUR an QuelleNina/QuelleDwd (Rest
     * aus der allerersten Version) und wurde bei KEINER der seither
     * dazugekommenen acht Datenquellen mitgezogen -- ein Nutzer, der NINA+DWD
     * bewusst abschaltet (z. B. hfichtinger, Österreich, nutzt stattdessen
     * GeoSphere Austria), bekam die komplette Instanz lahmgelegt
     * (SetStatus(104), Poll-Timer 0), obwohl eine echte Quelle lief.
     * Praxis-Fund hfichtinger, Symcon-Forum, 09.09.2026. WICHTIG: eine NEUE
     * einfache Ein/Aus-Datenquelle IMMER hier mit ergänzen, sonst wiederholt
     * sich exakt dieser Fehler. Hagelschutz-CH (URL-Property statt Bool) und
     * die eigene Wetterstation (Instanz-ID statt Bool) sind bewusst NICHT
     * hier drin -- die werden in hasAnyActiveSource() separat geprüft.
     */
    private const SOURCE_TOGGLE_PROPERTIES = [
        'QuelleNina', 'QuelleDwd', 'QuellePegelonline', 'QuelleBfsOdl', 'QuelleMeteoalarm',
        'QuelleGeosphereAt', 'QuelleBafuHydroCh', 'QuelleSedErdbebenCh', 'QuelleWaldbrandDe', 'QuelleOzonDe',
    ];

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
    // Symcon-Kernmodul "SMTP" -- live gegen Dietmars eigene IP-Symcon-Instanz
    // verifiziert (IPS_GetModuleList(), 06.09.2026), da kein öffentliches
    // GitHub-Repo existiert (Kernmodul, kein Community-/Store-Modul).
    // SMTP_SendMailEx($InstanzID, $Empfänger, $Betreff, $Inhalt) erkennt HTML
    // automatisch am <html>...</html>-Wrapper (seit Symcon 7.0, offizielle Doku).
    private const SMTP_GUID = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

    // Froggit-Wetterstation (Modul "Froggit", Vendor HS) -- GUID live an
    // Dietmars System verifiziert (04.09.2026, Instanz #32052 "Wetterstation").
    // Für die Discovery zusätzlich Namenssuche als Fallback (andere
    // Wetterstationsmodule, gleiches Prinzip wie bei WebFront/Kachel-Visu).
    private const FROGGIT_GUID = '{499F8100-B051-E713-CEC0-499D795B2639}';
    // Fahrzeug-/Fernzugriffs-Module für den mobilen Standort -- alle vier
    // GUIDs UND Idents gegen den echten Quellcode verifiziert, 07.09.2026,
    // auf Dietmars Bitte im Symcon-Forum recherchiert. Bewusst NUR für den
    // mobilen Standort genutzt, NICHT für Schutzaktionen (Fenster/
    // Kofferraum schließen): keines der vier Module hat dafür überhaupt
    // eine Fernbefehls-Funktion im Quellcode (kein EnableAction() auf
    // einer Fenster-/Kofferraum-Variable) -- Fenster-/Kofferraum-
    // Fernsteuerung bleibt ein Tesla-/Tessie-Spezifikum, siehe
    // DISCOVERY_KEYWORDS-Kommentar. Stellantis warnt zusätzlich selbst
    // ausdrücklich vor unbeaufsichtigten/sicherheitskritischen
    // Automationen (Version 0.4, reiner Auslese-Prototyp).
    private const STELLANTIS_VEHICLE_GUID = '{55719996-CD7E-4825-8B64-294601469EB5}';
    // Smartcar (community Modul mb-stern/Smartcar, im Symcon Module Store
    // gelistet, 40+ Fahrzeugmarken, aktiv gepflegt bis v4.8/03.09.2026) --
    // Standort nur, wenn der Nutzer bei Smartcar den Zugriff "location"
    // freigegeben hat (dynamische Rechte-Erkennung des Moduls selbst).
    private const SMARTCAR_VEHICLE_GUID = '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}';
    // BMW ConnectedDrive (community Modul, GUID identisch zwischen dem
    // ursprünglichen demel42- und dem Wolbolar-Fork -- dieselbe
    // Modul-Identität für Symcon, unabhängig vom installierten Git-Remote).
    private const BMW_CONNECTEDDRIVE_VEHICLE_GUID = '{8FD2A163-E07A-A2A2-58CC-974155FAEE33}';
    // Hyundai/Kia Bluelink (community Modul da8ter/Bluelink, Beta).
    private const BLUELINK_VEHICLE_GUID = '{C3D4E5F6-789A-BCDE-F012-3456789ABCDE}';
    // OVMS native (community Modul lorbetzki/net.lorbetzki.native.ovms,
    // greift auf OVMS' eigene, als veraltet deklarierte API V2 zu -- für
    // OVMS-Boxen ohne V3/MQTT). "location_latitude"/"location_longitude"
    // sind FEST vorregistrierte Idents (kein dynamischer Fallback wie beim
    // Rest der Datenpunkte), Dietmars Fund 07.09.2026. RequestAction() ist
    // im Quellcode ein reiner Logging-Stub ohne echten Befehlsversand --
    // trotz vorhandener Trunk-STATUS-Variable ("status_bt_open"/
    // "status_tr_open") keine tatsächliche Fernsteuerung, kein Fenster
    // überhaupt als Datenpunkt vorgesehen.
    private const OVMS_NATIVE_VEHICLE_GUID = '{D8B35502-1B61-A6C7-25B5-0BC775116AC6}';

    // Ident-Paare je Fahrzeugmodul für die GUID-gescopte mobile-Standort-
    // Suche in DiscoverMobileStandorte() -- robuster als ein Namensabgleich
    // (siehe DISCOVERY_LATLON_PAIRS für die älteren, namensbasierten
    // Quellen Tessie/Geofency, die keine so stabilen Idents liefern).
    private const DISCOVERY_LATLON_IDENTS = [
        ['label' => 'Stellantis', 'guid' => self::STELLANTIS_VEHICLE_GUID, 'lat' => 'Latitude', 'lon' => 'Longitude'],
        ['label' => 'Smartcar', 'guid' => self::SMARTCAR_VEHICLE_GUID, 'lat' => 'Latitude', 'lon' => 'Longitude'],
        ['label' => 'BMW ConnectedDrive', 'guid' => self::BMW_CONNECTEDDRIVE_VEHICLE_GUID, 'lat' => 'bmw_current_latitude', 'lon' => 'bmw_current_longitude'],
        ['label' => 'OVMS native', 'guid' => self::OVMS_NATIVE_VEHICLE_GUID, 'lat' => 'location_latitude', 'lon' => 'location_longitude'],
        ['label' => 'Hyundai/Kia Bluelink', 'guid' => self::BLUELINK_VEHICLE_GUID, 'lat' => 'Latitude', 'lon' => 'Longitude'],
    ];

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

    // Amtliche Warnkarten zum Verlinken/Einbetten (live geprüft 06.09.2026,
    // alle drei HTTP 200). DWD und MeteoSchweiz senden `X-Frame-Options:
    // SAMEORIGIN` -- lassen sich deshalb NICHT in ein iframe einbetten,
    // nur extern verlinken. Die ZAMG-Kartenseite sendet keine solche Sperre
    // (sogar `Access-Control-Allow-Origin: *`) und lässt sich embedden --
    // die hier hinterlegte URL zeigt ganz Österreich (Dietmars eigener
    // Fund); eine automatische Zentrierung auf einen einzelnen Standort
    // bräuchte eine verifizierte Umrechnung nach Österreichs Kartenprojektion
    // (vermutlich EPSG:31287) und ist bewusst noch nicht umgesetzt.
    private const DWD_MAP_URL = 'https://www.dwd.de/DE/wetter/warnungen/warnWetter_node.html';
    private const METEOSWISS_MAP_URL = 'https://www.meteoswiss.admin.ch/weather/hazards/hazard-map.html';
    private const ZAMG_MAP_URL = 'https://warnungen.zamg.at/wsapp/de/alle/gesamterzeitraum/-62732,135250,865393,656783';
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

    // Schweizerischer Erdbebendienst (SED, ETH Zürich) -- amtliche Erdbeben-
    // Überwachung, live geprüft 07.09.2026 gegen eida.ethz.ch/fdsnws/event/1
    // (echte Ereignisse abgerufen, u. a. zwei Beben bei Bourg-Saint-Pierre VS
    // vom 06.09.2026). Standard-FDSNWS-Event-Webservice (SeisComP-Backend),
    // Antwortformat "text" statt "geojson" (Letzteres von DIESER Instanz
    // nicht unterstützt -- live per Fehlermeldung geprüft), pipe-getrennt.
    // Anders als bei Wetterwarnungen liefert die Quelle selbst weder eine
    // Warnfläche noch einen "Gültig bis"-Zeitpunkt (ein Erdbeben ist ein
    // Momentereignis, keine andauernde Wetterlage) -- WarnHub bildet daraus
    // deshalb einen synthetischen Kreis um den Epizentrum (Radius nach
    // Magnitude gestuft, siehe sedMagnitudeSeverity() -- eigene, grobe
    // Näherung, KEINE amtliche Gefährdungsfläche) sowie ein 2-Stunden-
    // "Gültig bis"-Fenster (Nachbeben-Zeitfenster), danach zählt es nicht
    // mehr als aktiv. Ab Magnitude 2.5 gemeldet -- laut SED die Schwelle,
    // ab der ein Beben in der Schweiz überhaupt spürbar ist.
    private const SED_EVENT_URL = 'http://eida.ethz.ch/fdsnws/event/1/query';
    private const SED_MIN_MAGNITUDE = 2.5;
    private const SED_ACTIVE_SECONDS = 7200;

    // DWD-Waldbrandgefahrenindex (WBI) -- live geprüft 07.09.2026. Anders als
    // die übrigen Quellen gibt es KEINE einzelne kombinierte Datei für "heute,
    // alle Stationen", nur eine eigene gezippte CSV JE Station (Format
    // "Stationsindex;Datum;WBI", 5 Stufen) unter .../recomputed/recent/ --
    // mehrere hundert Dateien. Ein Abruf ALLER Dateien bei jeder Prüfung wäre
    // unhöflich gegenüber DWDs Server; stattdessen wird pro deutschem Standort
    // nur die NÄCHSTGELEGENE Station abgefragt (eigene Stationsliste im selben
    // Verzeichnis, "..._stations_list.txt", 484 Stationen mit Koordinaten --
    // live verifiziert, KEIN Rückgriff auf eine fremde Datensatz-Stationsliste
    // nötig/riskant). Amtliche 5-stufige Skala (1=sehr geringe Gefahr bis
    // 5=sehr hohe Gefahr).
    private const WBI_STATION_LIST_URL = 'https://opendata.dwd.de/climate_environment/CDC/derived_germany/fire_danger_index/woodland/recomputed/recent/derived_germany_fire_danger_index_woodland_recomputed_recent_v2-3--0_stations_list.txt';
    private const WBI_STATION_CSV_URL_TEMPLATE = 'https://opendata.dwd.de/climate_environment/CDC/derived_germany/fire_danger_index/woodland/recomputed/recent/derived_germany_fire_danger_index_woodland_recomputed_recent_%d_v2-3--0.csv.gz';
    private const WBI_LEVEL_LABEL = [
        1 => 'sehr geringe Gefahr', 2 => 'geringe Gefahr', 3 => 'mittlere Gefahr',
        4 => 'hohe Gefahr', 5 => 'sehr hohe Gefahr',
    ];
    private const WBI_LEVEL_SEVERITY = [1 => 'Minor', 2 => 'Minor', 3 => 'Moderate', 4 => 'Severe', 5 => 'Extreme'];

    // Umweltbundesamt (UBA) Luftqualitätsdaten -- amtliche bundesweite API,
    // live geprüft 07.09.2026 (offiziell dokumentiert unter
    // github.com/bundesAPI/luftqualitaet-api, Server-URL dort v2 unter
    // www.umweltbundesamt.de leitet per 301 auf luftdaten.umweltbundesamt.de
    // um -- dieselbe Antwortstruktur). Anders als beim Waldbrandgefahrenindex
    // liefert /airquality/json ALLE Stationen bundesweit in EINEM Abruf
    // (live geprüft: 416 Stationen, ~41 KB) -- deshalb wie bei BAFU ein
    // globaler Abruf statt Standort-für-Standort. Bewusst nur Ozon (Komponente
    // 3) statt des kombinierten Luftqualitätsindex (der den schlechtesten
    // von 5 Schadstoffen abbildet) -- Dietmars Wunsch war ausdrücklich eine
    // "Ozon-/Sommersmog-Warnung", kein allgemeines Luftgüte-Monitoring (siehe
    // Diskussion 07.09.2026, klare Abgrenzung zu privaten Innenraum-Sensoren).
    // Amtliche Stufen laut /thresholds/json (Komponente 3, 1-Stunden-Mittel,
    // µg/m³): 0-60=Stufe 0, 61-120=Stufe 1, 121-180=Stufe 2, 181-240=Stufe 3,
    // >240=Stufe 4. Deaktivierte Stationen (Feld "aktiv bis" gesetzt) werden
    // übersprungen.
    private const UBA_STATIONS_URL = 'https://luftdaten.umweltbundesamt.de/api/air-data/v2/stations/json';
    private const UBA_AIRQUALITY_URL = 'https://luftdaten.umweltbundesamt.de/api/air-data/v2/airquality/json';
    private const UBA_OZONE_COMPONENT_ID = 3;
    private const UBA_LQI_LABEL = [
        0 => 'sehr gut', 1 => 'gut', 2 => 'mäßig', 3 => 'schlecht', 4 => 'sehr schlecht',
    ];
    private const UBA_LQI_SEVERITY = [0 => 'Minor', 1 => 'Minor', 2 => 'Moderate', 3 => 'Severe', 4 => 'Extreme'];

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
        $this->RegisterPropertyBoolean('QuelleSedErdbebenCh', false);
        $this->RegisterPropertyBoolean('QuelleWaldbrandDe', false);
        $this->RegisterPropertyInteger('WaldbrandDeSchwelle', 3);
        $this->RegisterPropertyBoolean('QuelleOzonDe', false);
        $this->RegisterPropertyInteger('UbaOzonSchwelle', 3);
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
        $this->RegisterPropertyString('Fensterkontakte', '[]');
        $this->RegisterPropertyInteger('SchutzaktionVorlaufMinuten', 30);
        $this->RegisterPropertyString('WebFronts', '[]');
        $this->RegisterPropertyInteger('KartenkachelHoehePx', 0);
        $this->RegisterPropertyInteger('ZamgKachelHoehePx', 0);

        $this->RegisterTimer('PollTimer', 0, 'WHUB_Poll($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SirenOffTimer', 0, 'WHUB_CheckSirenOff($_IPS[\'TARGET\']);');

        $this->RegisterAttributeString('SeenWarnings', '{}');
        $this->RegisterAttributeString('FiredActions', '{}');
        $this->RegisterAttributeString('SeenFensterWarnungen', '{}');
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
        // Länder-Code (de/at/ch/...) des Symcon-Systemstandorts, in Poll()
        // im Hintergrund aktualisiert (siehe reverseGeocodeStandort(), 6h
        // Cache) -- GetConfigurationForm() liest NUR diesen Cache, macht
        // selbst KEINEN Netzwerkaufruf (würde das Öffnen der Konsole
        // verlangsamen). Steuert, welches Land-Panel bei "Datenquellen"
        // beim allerersten Öffnen aufgeklappt ist. Dietmars Idee 09.09.2026.
        $this->RegisterAttributeString('HeimLandCode', '');
        // Merkt sich je Panel-Name, ob der Nutzer es zuletzt manuell auf-
        // oder zugeklappt hat (JSON-Map Panel-Name -> bool) -- übersteuert
        // den sonstigen Default (Land-Erkennung bzw. fest einprogrammiert).
        // Dietmars Wunsch 09.09.2026: "Formular scrollt sich halb zu Tode"
        // nach jedem Speichern, weil alle Panels immer auf denselben festen
        // Zustand zurückspringen.
        $this->RegisterAttributeString('PanelExpandedState', '{}');

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
        $this->MaintainVariable('KachelKarte', 'Kachel (Karte)', VARIABLETYPE_STRING, '~HTMLBox', 7, true);
        $this->MaintainVariable('KachelZamg', 'Kachel (ZAMG-Warnkarte, Österreich)', VARIABLETYPE_STRING, '~HTMLBox', 8, true);
        $this->MaintainVariable('KachelAlle', 'Kachel (Alle Warnungen)', VARIABLETYPE_STRING, '~HTMLBox', 9, true);
        $this->refreshStatusVariables();

        if (!$this->hasAnyActiveSource()) {
            $this->SetStatus(104);
            $this->SetTimerInterval('PollTimer', 0);
            return;
        }

        $minutes = max(1, $this->ReadPropertyInteger('PollIntervalMinutes'));
        $this->SetTimerInterval('PollTimer', $minutes * 60 * 1000);
        $this->SetStatus(102);
    }

    /** @see SOURCE_TOGGLE_PROPERTIES -- plus die zwei Datenquellen, die keinen einfachen Bool-Schalter haben. */
    private function hasAnyActiveSource(): bool
    {
        foreach (self::SOURCE_TOGGLE_PROPERTIES as $prop) {
            if ($this->ReadPropertyBoolean($prop)) {
                return true;
            }
        }
        if (trim($this->ReadPropertyString('HagelschutzPollUrl')) !== '') {
            return true;
        }
        // Wie in Poll() -- eine Wetterstation gilt auch als aktiv, wenn NUR
        // die Wind-/Regen-Variable manuell gesetzt ist, ganz ohne
        // Froggit/Sainlogic/Meteobridge-Instanz (z. B. KNX/Netatmo/TFA ohne
        // automatische Erkennung). Ursprünglich hier vergessen, hätte
        // dieselbe Instanz-Lahmlegung wie bei den fehlenden Quellen (siehe
        // Klassenkonstante) für GENAU diese eine Konfiguration erneut
        // ermöglicht.
        if ($this->ReadPropertyInteger('WetterstationInstanceID') > 0
            || $this->ReadPropertyInteger('WetterstationWindVariableID') > 0
            || $this->ReadPropertyInteger('WetterstationRegenVariableID') > 0) {
            return true;
        }
        return false;
    }

    /**
     * Aktueller Auf-/Zugeklappt-Zustand eines Panels aus PanelExpandedState
     * -- $default gilt nur, solange der Nutzer dieses Panel noch nie selbst
     * per Klick verändert hat (siehe TogglePanelExpanded()).
     */
    private function getPanelExpandedState(string $panelName, bool $default): bool
    {
        $state = json_decode($this->ReadAttributeString('PanelExpandedState'), true) ?: [];
        return array_key_exists($panelName, $state) ? (bool) $state[$panelName] : $default;
    }

    /**
     * Von ExpansionPanels via 'onClick' aufgerufen -- feuert laut Symcon-
     * Community-Erfahrung bei JEDEM Auf-/Zuklappen (beide Richtungen), OHNE
     * dass die Richtung selbst als Parameter mitgeliefert wird (keine
     * onOpen/onClose-Unterscheidung im SDK, verifiziert gegen
     * community.symcon.de/t/expansionpanel-onclick/50897). $wasExpanded ist
     * deshalb der Zustand, den WIR beim Rendern selbst gesetzt hatten (als
     * PHP-Literal fest in den onClick-String eingebettet) -- der Klick kehrt
     * ihn genau einmal um. Rein clientseitige UI-Feedback-Aktion, braucht
     * kein UpdateFormField (das Auf-/Zuklappen selbst hat der Client schon
     * nativ erledigt), nur das Merken für den nächsten Formular-Aufbau.
     */
    public function TogglePanelExpanded(string $panelName, bool $wasExpanded): void
    {
        $state = json_decode($this->ReadAttributeString('PanelExpandedState'), true) ?: [];
        $state[$panelName] = !$wasExpanded;
        $this->WriteAttributeString('PanelExpandedState', json_encode($state));
    }

    /** Baut das 'onClick' für ein Panel, das sich seinen Auf-/Zugeklappt-Zustand merkt (siehe getPanelExpandedState()/TogglePanelExpanded()). */
    private function rememberPanelOnClick(string $panelName, bool $currentlyExpanded): string
    {
        return 'WHUB_TogglePanelExpanded($id, ' . json_encode($panelName) . ', ' . ($currentlyExpanded ? 'true' : 'false') . ');';
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
        @$this->SetValue('KachelKarte', $this->renderKachelKarte($active, $snoozed));
        @$this->SetValue('KachelZamg', $this->renderKachelZamg());
        @$this->SetValue('KachelAlle', $this->renderKachelAlleWarnungen($active, $lastTs, $snoozed));
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
        $expDokuHilfe = $this->getPanelExpandedState('DokuHilfe', false);
        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '📖  Dokumentation & Hilfe',
            'expanded' => $expDokuHilfe,
            'onClick' => $this->rememberPanelOnClick('DokuHilfe', $expDokuHilfe),
            'items' => [
                ['type' => 'Label', 'caption' => 'WarnHub Version ' . self::DOC_VERSION],
                ['type' => 'Label', 'caption' => 'Bündelt Warn- und Alarmmeldungen für Deutschland, Österreich und die Schweiz (D-A-CH) -- amtliche Quellen für Deutschland (Katastrophenschutz, Wetter, Hochwasser, Polizei, Pegel, Radioaktivität), europaweite Wetterwarnungen für 39 Länder (deckt Österreich/Schweiz mit ab) sowie optional die eigene Wetterstation -- und meldet nur, was innerhalb des selbst definierten Umkreises eines Standorts liegt (auch mobiler Standorte im Ausland).'],
                ['type' => 'Label', 'caption' => 'Datenquellen: NINA-Aggregation (offiziell von der BBK-App genutzt, warnung.bund.de, Deutschland), optional die direkten DWD-Wetterwarnungen (opendata.dwd.de, Deutschland), optional Pegelstände (PEGELONLINE/WSV, Deutschland), optional Radioaktivitäts-Messwerte (BfS Ortsdosisleistung, Deutschland), optional europaweite Wetterwarnungen (Meteoalarm, 39 Länder inkl. Österreich und Schweiz), optional koordinatengenaue Warnungen für Österreich (GeoSphere Austria/ZAMG), optional amtliche Hochwasser-Gefahrenstufen für die Schweiz (BAFU/LINDAS), optional die eigene Wetterstation als unabhängiges Sicherheitsnetz sowie -- BETA, ungetestet -- optional die eigene VKF-Hagelschutz-Signalbox (Schweiz).'],
                ['type' => 'Label', 'caption' => 'Bei PEGELONLINE, BfS ODL-Info und der eigenen Wetterstation gibt es keine amtliche Warnstufen-Klassifikation -- WarnHub meldet stattdessen einen erhöhten Pegel (über dem mittleren bzw. bisherigen Höchstwasser), eine Überschreitung des selbst eingestellten Strahlungs-Schwellwerts bzw. eine Überschreitung der selbst eingestellten Windböen-/Regenraten-Schwelle. Das ist keine amtliche Alarmstufe.'],
                ['type' => 'Label', 'caption' => 'Radius-Prüfung erfolgt geometrisch gegen die tatsächliche Warnfläche (Polygon/Kreis der Meldung), nicht gegen Postleitzahlen/Gemeindegrenzen.'],
                ['type' => 'Label', 'caption' => 'Liegt zu einer Meldung keine Geometrie vor, wird sie sicherheitshalber NICHT automatisch zugeordnet (keine geratene Präzision).'],
                ['type' => 'Label', 'caption' => 'Ein Standort kann statt fester Koordinaten auch an zwei Variablen (Lat/Lon) gebunden werden, z. B. aus Tessie, Geofency oder einem Stellantis-/Smartcar-/BMW ConnectedDrive-/Hyundai-Kia-Bluelink-/OVMS-native-Fahrzeug -- WarnHub liest dann bei jeder Prüfung die aktuelle Position. Die Objektbaum-Suche im Standorte-Panel findet passende Variablenpaare automatisch und verknüpft sie direkt. Über "Push nur an" lässt sich außerdem festlegen, dass ein Standort nur bestimmte WebFronts benachrichtigt (z. B. je eine Person/ein Fahrzeug bei mehreren gleichzeitig genutzten Standorten).'],
                ['type' => 'Label', 'caption' => 'Schutzaktionen feuern NICHT schon bei Eingang einer Meldung, sondern erst kurz vor deren tatsächlichem Gültigkeitsbeginn (einstellbarer Vorlauf im Schutzaktionen-Panel) -- eine morgens eintreffende, aber erst für den Nachmittag gültige Warnung fährt die Markise also nicht schon morgens ein. Die Push-Benachrichtigung selbst bleibt davon unberührt und kommt weiterhin sofort.'],
                ['type' => 'Label', 'caption' => 'Konfigurationsverhalten bei Standorten/Push-Zielen/Schutzaktionen: WarnHub durchsucht bei der Einrichtung automatisch den Objektbaum und schlägt Treffer VORAKTIVIERT vor -- mobile Standorte (Tessie-Fahrzeugposition, Geofency, Stellantis-/Smartcar-/BMW ConnectedDrive-/Hyundai-Kia-Bluelink-/OVMS-native-Fahrzeuge), die eigene Wetterstation (Froggit), WebFront- und Kachel-Visualisierung-Instanzen, sowie Instanzen/Variablen mit "Raffstore"/"Jalousie"/"Markise"/"Garage"/"Fenster schließen"/"Heckklappe"/"Sirene" im Namen -- die beiden Letzteren passen insbesondere zu Tessies eigenen Tesla-Aktionen. Ein Kofferraum/Heckklappe-Treffer bleibt dabei ausnahmsweise INAKTIV, wenn keine passende Zustands-Variable danebengefunden wurde (Sicherheitssperre). Nicht gewünschte Treffer lassen sich einfach über die Aktiv-Spalte abwählen -- eine erneute Suche überschreibt eigene Abwahl-Entscheidungen nicht.'],
            ],
        ];

        $expStandorte = $this->getPanelExpandedState('Standorte', true);
        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '📍  Standorte (Umkreis-Definition)',
            'expanded' => $expStandorte,
            'onClick' => $this->rememberPanelOnClick('Standorte', $expStandorte),
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
                ['type' => 'Label', 'caption' => 'Durchsucht den Objektbaum nach bekannten Positions-Variablenpaaren (Tessie "Fahrzeugposition – Breitengrad/Längengrad", Geofency "Current Latitude/Longitude", sowie Stellantis-, Smartcar-, BMW ConnectedDrive-, Hyundai/Kia-Bluelink- und OVMS-native-Fahrzeuge) und legt je Fund einen bereits mit den Live-Variablen verknüpften Standort an -- direkt aktiviert, "Live-Standort Lat/Lon" ist schon gesetzt. Nicht gewünschte Treffer einfach über die Aktiv-Spalte abwählen; Umkreis/Schweregrad danach noch prüfen. Eine erneute Suche ergänzt nur neue Funde.'],
                ['type' => 'Label', 'caption' => 'Mobiler Standort auch von Hand einrichtbar (z. B. aus Tessie, Geofency oder einem Stellantis-/Smartcar-/BMW ConnectedDrive-/Hyundai-Kia-Bluelink-/OVMS-native-Fahrzeug): "Live-Standort Lat/Lon" auf die jeweilige Positions-Variable verweisen -- WarnHub liest dann bei jeder Prüfung die AKTUELLE Position daraus, Lat/Lon in der Tabelle sind dann nur der Startwert/Fallback. 0 = feste Koordinaten aus der Tabelle (bisheriges Verhalten).'],
                ['type' => 'Label', 'caption' => 'Welche Module genau: "Tessie" (DG65-eigenes Modul für Tesla), "Geofency" (Bridge für die gleichnamige Geofencing-App), sowie vier über den Symcon Module Store bzw. GitHub installierbare Community-Module -- im Store nach dem jeweiligen Namen suchen: "Stellantis Vehicles" (Opel u. a. ehemalige PSA-Marken, github.com/slausch/Symcon-Stellantis-Vehicles), "Smartcar" (40+ Fahrzeugmarken über die Smartcar-Plattform, github.com/mb-stern/Smartcar), "BMW Connected Drive", "Hyundai/Kia Bluelink" (github.com/da8ter/Bluelink, aktuell Beta), "OVMS native" (für OVMS-Boxen mit der älteren API V2, github.com/lorbetzki/net.lorbetzki.native.ovms).'],
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

        // D-A-CH-Gruppierung -- Dietmars Vorschlag 09.09.2026, nachdem das
        // Datenquellen-Panel über die vielen Einzelquellen hinweg sehr lang
        // geworden war (Praxis-Fund hfichtinger: DWD/PEGELONLINE/BfS-ODL
        // interessieren ihn in Österreich gar nicht). "Allgemein" bleibt
        // immer offen (grenzüberschreitende Quellen + Wetterstation +
        // Abfragetakt), darunter je ein zuklappbares Land-Panel. Das
        // Heimatland-Panel klappt beim allerersten Öffnen automatisch auf
        // (HeimLandCode, siehe refreshHeimLandCode()) -- eine spätere
        // manuelle Wahl des Nutzers (PanelExpandedState) hat immer Vorrang.
        $heimLand = $this->ReadAttributeString('HeimLandCode');
        $expDatenquellen = $this->getPanelExpandedState('Datenquellen', true);
        $expDqAllgemein = $this->getPanelExpandedState('DatenquellenAllgemein', true);
        $expDqDe = $this->getPanelExpandedState('DatenquellenDe', $heimLand === 'de');
        $expDqAt = $this->getPanelExpandedState('DatenquellenAt', $heimLand === 'at');
        $expDqCh = $this->getPanelExpandedState('DatenquellenCh', $heimLand === 'ch');
        $expHagelschutz = $this->getPanelExpandedState('HagelschutzCh', false);

        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🌐  Datenquellen',
            'expanded' => $expDatenquellen,
            'onClick' => $this->rememberPanelOnClick('Datenquellen', $expDatenquellen),
            'items' => [
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenAllgemeinPanel',
                    'caption' => 'Allgemein (grenzüberschreitend)',
                    'expanded' => $expDqAllgemein,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenAllgemein', $expDqAllgemein),
                    'items' => [
                        ['type' => 'CheckBox', 'name' => 'QuelleMeteoalarm', 'caption' => 'Meteoalarm (europaweite Wetterwarnungen, 39 Länder) -- wichtig für mobile Standorte im Ausland'],
                        ['type' => 'Label', 'caption' => 'Meteoalarm liefert KEINE Warnfläche (Polygon/Kreis), nur benannte Verwaltungsgebiete -- der Abgleich erfolgt deshalb per Namensvergleich (Standort wird per Reverse-Geocoding einem Kreis/einer Region zugeordnet), nicht geometrisch wie bei den übrigen Quellen. Das ist ungenauer und wird in der Meldung ausdrücklich als "Namensabgleich" gekennzeichnet. Für Deutschland liefert die direkte DWD-Anbindung unten bereits die präziseren Polygone -- Meteoalarm lohnt sich vor allem für Standorte im europäischen Ausland.'],
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
                        ['type' => 'Label', 'caption' => 'Die EINZIGE Ausnahme von "keine automatische Rückstellung" im ganzen Modul -- bewusst nur hier erlaubt, weil die eigene Wetterstation (anders als eine amtliche Warnung) einen fortlaufenden, lokalen Live-Wert liefert. Stellt eine durch die eigene Wetterstation ausgelöste Raffstore-/Markisen-/Garagentor-Aktion automatisch auf den Wert zurück, den sie unmittelbar vor dem Auslösen hatte -- aber erst, nachdem Wind UND Regen seit 20 Minuten durchgehend wieder unter der Moderate-Schwelle liegen (Ruhephase gegen kurze Windböen-Pausen mitten im Sturm). Gilt NUR für durch die eigene Wetterstation ausgelöste Aktionen, nicht für amtliche Warnungen. Fenster-/Kofferraum-/Sirenen-/Skript-Aktionen bleiben wie gehabt ohne Rückstellung. Jede Rückstellung wird in der Warnungs-Historie (WHUB_GetHistory()) protokolliert, eine anstehende Rückstellung steht unten im Panel "Prüfung & Status".'],
                        ['type' => 'NumberSpinner', 'name' => 'PollIntervalMinutes', 'caption' => 'Abfragetakt (Minuten)', 'minValue' => 1, 'maxValue' => 60],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenDePanel',
                    'caption' => '🇩🇪  Deutschland',
                    'expanded' => $expDqDe,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenDe', $expDqDe),
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
                        ['type' => 'CheckBox', 'name' => 'QuelleWaldbrandDe', 'caption' => 'Zusätzlich deutscher Waldbrandgefahrenindex (DWD) -- amtliche 5-stufige Skala, je Standort die nächstgelegene Messstation'],
                        ['type' => 'NumberSpinner', 'name' => 'WaldbrandDeSchwelle', 'caption' => 'Ab Gefahrenstufe (1-5)', 'minValue' => 1, 'maxValue' => 5],
                        ['type' => 'Label', 'caption' => 'Nutzt DWDs amtliche 5-stufige Waldbrandgefahrenindex-Skala (1 = sehr geringe Gefahr bis 5 = sehr hohe Gefahr). Es gibt keine einzelne Datei für "heute, alle Stationen" -- WarnHub ermittelt deshalb je deutschem Standort die nächstgelegene der 484 DWD-Messstationen und fragt NUR deren aktuellen Wert ab, statt alle Stationen bei jeder Prüfung abzurufen. Braucht die PHP-Erweiterung "zlib" (für gzip, meist Standard); fehlt sie, bleibt diese Quelle inaktiv, alle anderen Quellen funktionieren unabhängig davon weiter.'],
                        ['type' => 'CheckBox', 'name' => 'QuelleOzonDe', 'caption' => 'Zusätzlich deutsche Ozonbelastung (Umweltbundesamt) -- amtlicher Luftqualitätsindex, nur Ozon/Sommersmog'],
                        ['type' => 'NumberSpinner', 'name' => 'UbaOzonSchwelle', 'caption' => 'Ab Luftqualitätsindex-Stufe (0-4)', 'minValue' => 0, 'maxValue' => 4],
                        ['type' => 'Label', 'caption' => 'Nutzt das Umweltbundesamt (UBA) als amtliche Quelle für Ozonmesswerte, ausdrücklich NUR Ozon/Sommersmog -- kein allgemeines Luftgüte-Monitoring und keine private Innenraum-Sensorik (dafür ist WarnHub nicht gedacht, siehe Forum-Diskussion). Amtliche 5-stufige Skala (0 = sehr gut bis 4 = sehr schlecht), berechnet aus der stündlichen Ozonkonzentration je Messstation (0-60 µg/m³ = Stufe 0, ... über 240 µg/m³ = Stufe 4).'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenAtPanel',
                    'caption' => '🇦🇹  Österreich',
                    'expanded' => $expDqAt,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenAt', $expDqAt),
                    'items' => [
                        ['type' => 'CheckBox', 'name' => 'QuelleGeosphereAt', 'caption' => 'Zusätzlich direkte GeoSphere-Austria-Warnungen (warnungen.zamg.at) -- koordinatengenau für österreichische Standorte, präziser als Meteoalarm'],
                        ['type' => 'Label', 'caption' => 'Ist diese direkte Anbindung aktiv, übernimmt sie für österreichische Standorte automatisch von Meteoalarm (koordinatengenau statt Namensabgleich) -- analog zur direkten DWD-Anbindung, die für deutsche Standorte den entsprechenden NINA-Kanal ersetzt. Amtliche Quelle (GeoSphere Austria/ZAMG), kein Zugangsschlüssel nötig.'],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenChPanel',
                    'caption' => '🇨🇭  Schweiz',
                    'expanded' => $expDqCh,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenCh', $expDqCh),
                    'items' => [
                        ['type' => 'CheckBox', 'name' => 'QuelleBafuHydroCh', 'caption' => 'Zusätzlich Schweizer Hochwassergefahr (BAFU/LINDAS) -- amtliche Gefahrenstufe für Fliessgewässer und Seen'],
                        ['type' => 'NumberSpinner', 'name' => 'BafuHydroSchwelle', 'caption' => 'Ab Gefahrenstufe (2-5)', 'minValue' => 2, 'maxValue' => 5],
                        ['type' => 'Label', 'caption' => 'Nutzt BAFUs amtliche 5-stufige Gefahrenstufen-Skala für Hochwasser (1 = keine/geringe Gefahr bis 5 = sehr große Gefahr) -- anders als PEGELONLINE, BfS und die eigene Wetterstation also KEINE Eigenkonstruktion, sondern eine echte behördliche Klassifikation. Nur die Schwelle, AB der WarnHub meldet, ist einstellbar.'],
                        ['type' => 'CheckBox', 'name' => 'QuelleSedErdbebenCh', 'caption' => 'Zusätzlich Schweizer Erdbeben (Schweizerischer Erdbebendienst SED, ETH Zürich) -- ab Magnitude 2.5 (laut SED die Spürbarkeitsschwelle)'],
                        ['type' => 'Label', 'caption' => 'Amtliche seismologische Überwachung, keine Wetterquelle. Anders als bei Wetterwarnungen liefert die Quelle selbst weder eine Warnfläche noch ein "Gültig bis" -- ein Erdbeben ist ein Momentereignis. WarnHub bildet deshalb einen eigenen Kreis um das Epizentrum (Radius nach Magnitude gestuft -- eine grobe eigene Näherung, KEINE amtliche Gefährdungsfläche) und ein 2-Stunden-Zeitfenster (Nachbeben-relevant), danach zählt es nicht mehr als aktiv.'],
                        [
                            'type' => 'ExpansionPanel',
                            'name' => 'HagelschutzChPanel',
                            'caption' => '🧪  BETA: Hagelschutz Schweiz (VKF-Signalbox)',
                            'expanded' => $expHagelschutz,
                            'onClick' => $this->rememberPanelOnClick('HagelschutzCh', $expHagelschutz),
                            'items' => [
                                ['type' => 'Label', 'caption' => 'AUSDRÜCKLICH BETA -- ungetestet: Diese Anbindung wurde ausschließlich aus der offiziellen VKF-Dokumentation und dem Quellcode eines aktiven Community-Adapters gebaut. Ohne eigene Signalbox konnte der Live-Abruf selbst nicht gegengeprüft werden -- die sonst in diesem Modul durchgehend befolgte "live verifizieren"-Regel wird hier bewusst ausgesetzt. Rückmeldungen (funktioniert/funktioniert nicht) sind ausdrücklich willkommen, siehe Feedback-Hinweis am Ende des Formulars.'],
                                ['type' => 'Label', 'caption' => 'Setzt eine physisch bei einem konkreten Schweizer Gebäude registrierte VKF-Hagelschutz-Signalbox voraus (hagelschutz-einfach-automatisch.ch) -- kein reiner Software-Zugang. Ohne eigene Signalbox einfach leer lassen, dann bleibt diese Quelle inaktiv.'],
                                ['type' => 'ValidationTextBox', 'name' => 'HagelschutzPollUrl', 'caption' => 'Poll-URL der eigenen Signalbox'],
                                ['type' => 'Label', 'caption' => 'Die vollständige Adresse aus der eigenen Signalbox-Konfiguration eintragen (Format https://meteo.netitservices.com/api/v1/devices/<deviceId>/poll?hwtypeId=<HID>) -- nicht selbst aus deviceId/hwtypeId zusammensetzen, da sich das Format zwischen Signalbox-Generationen unterscheiden kann. Meldet eine Hagelwarnung am Symcon-Systemstandort, sobald die Signalbox "currentState" ungleich 0 zurückgibt (inkl. Testalarm).'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $expBenachrichtigung = $this->getPanelExpandedState('Benachrichtigung', true);
        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🔔  Benachrichtigung',
            'expanded' => $expBenachrichtigung,
            'onClick' => $this->rememberPanelOnClick('Benachrichtigung', $expBenachrichtigung),
            'items' => [
                ['type' => 'CheckBox', 'name' => 'PushAktiv', 'caption' => 'Push-Benachrichtigung an aktivierte Push-Ziele (WebFront/Kachel-Visualisierung/Telegram/Pushover/E-Mail, auch Handy)'],
                ['type' => 'Select', 'name' => 'PushSound', 'caption' => 'Signalton (nur WebFront/Kachel-Visualisierung)', 'options' => $this->soundOptions()],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Push-Ziele suchen',
                    'onClick' => 'echo WHUB_DiscoverWebFronts($id);',
                ],
                ['type' => 'Label', 'caption' => $this->webfrontStatusLine()],
                ['type' => 'Label', 'caption' => 'Sucht WebFront-Instanzen, Kachel-Visualisierung-Instanzen (die neuere Symcon-Oberfläche, unter "Visualisierung Instanzen" im Objektbaum -- häufig die eigentlich genutzte Oberfläche), sowie -- falls installiert -- Telegram-Bot- (offizielles Symcon-Modul), Pushover- (Community-Modul) und SMTP-Instanzen (offizielles Symcon-Modul, für E-Mail). Gefundene Ziele sind standardmäßig aktiv (bekommen Push) -- nicht gewünschte einfach über die Aktiv-Spalte abwählen. Eine erneute Suche fügt nur neue Ziele hinzu und lässt bestehende Abwahl-Entscheidungen unangetastet.'],
                ['type' => 'Label', 'caption' => 'Telegram/Pushover/E-Mail: Anbindung anhand des echten Quellcodes bzw. der echten Funktionssignatur der jeweiligen Module gebaut, aber ohne eigenen Telegram-Bot-/Pushover-Account nicht selbst live gegenprüfbar -- Rückmeldungen willkommen, siehe Feedback-Hinweis am Ende des Formulars.'],
                ['type' => 'Label', 'caption' => 'E-Mail (SMTP): eine gefundene SMTP-Instanz kennt nur den Versandweg, nicht den Empfänger -- deshalb zunächst INAKTIV angelegt. Erst in der Spalte "Zieladresse" die gewünschte E-Mail-Adresse eintragen, dann in der Spalte "Aktiv" aktivieren. Mehrere Adressen: einfach mit Komma oder Semikolon getrennt in dasselbe Feld eintragen.'],
                [
                    'type' => 'List',
                    'name' => 'WebFronts',
                    'rowCount' => $this->listRowCount(count($this->decodeWebFronts()), 3),
                    'add' => false,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '190px', 'edit' => ['type' => 'ValidationTextBox', 'enabled' => false]],
                        ['caption' => 'Typ', 'name' => 'Typ', 'width' => '160px', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'WebFront', 'value' => 'webfront'],
                            ['caption' => 'Kachel-Visualisierung', 'value' => 'kachel'],
                            ['caption' => 'Telegram', 'value' => 'telegram'],
                            ['caption' => 'Pushover', 'value' => 'pushover'],
                            ['caption' => 'E-Mail (SMTP)', 'value' => 'email'],
                        ]]],
                        ['caption' => 'Instanz-ID', 'name' => 'InstanceID', 'width' => '90px', 'edit' => ['type' => 'NumberSpinner', 'enabled' => false]],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '70px', 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zieladresse (nur E-Mail)', 'name' => 'Zieladresse', 'width' => '210px', 'edit' => ['type' => 'ValidationTextBox']],
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

        $expSchutzaktionen = $this->getPanelExpandedState('Schutzaktionen', false);
        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🛡️  Schutzaktionen (Jalousien/Raffstore, Markisen, Garagentor, Fenster, Kofferraum, Sirenen, Skripte)',
            'expanded' => $expSchutzaktionen,
            'onClick' => $this->rememberPanelOnClick('Schutzaktionen', $expSchutzaktionen),
            'items' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => '🛑 SICHERHEITSHINWEIS -- Fenster/Kofferraum an Fahrzeugen: bitte unbedingt lesen',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Betrifft die beiden Aktionstypen "Fenster schließen" und "Kofferraum/Heckklappe schließen": Weder das Fahrzeug noch WarnHub können erkennen, ob sich gerade eine Person, eine Hand oder ein anderes Körperteil im Bewegungsbereich der schließenden Scheibe oder Klappe befindet.'],
                        ['type' => 'Label', 'caption' => 'Anders als bei vielen Garagentoren gibt es hier KEINE zuverlässige Einklemmschutz- oder Hinderniserkennung, auf die sich WarnHub verlassen könnte -- die Aktion schließt unbeaufsichtigt, ohne Vorwarnung und unabhängig davon, ob gerade jemand danebensteht. Das kann zu ernsthaften Verletzungen führen.'],
                        ['type' => 'Label', 'caption' => 'Aktiviere diese beiden Aktionstypen deshalb NUR, wenn du sicher bist, dass niemand unbeaufsichtigt in Reichweite des Fahrzeugs sein kann, wenn eine Warnung eintrifft (z. B. ein Fahrzeug, das ausschließlich auf einem nicht öffentlich zugänglichen Grundstück steht). IM ZWEIFEL VERZICHTE AUF DIESE FUNKTION und schließe Fenster/Kofferraum stattdessen selbst von Hand, sobald du die Warnung siehst.'],
                    ],
                ],
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
                            ['type' => 'Label', 'caption' => 'Fenster schließen: nur Ziel-Variable, kein Zielwert nötig (schaltet die Aktion immer auf "Ein" -- bei Tessies eigener Fenster-schließen-Aktion löst das Teslas gerichteten Schließen-Befehl aus, sicher auch bei bereits geschlossenen Fenstern). 🛑 Beachte unbedingt den Sicherheitshinweis ganz oben im Panel -- Einklemmgefahr, keine Hinderniserkennung.'],
                            ['type' => 'Label', 'caption' => 'Kofferraum/Heckklappe schließen: WICHTIG -- Teslas Kofferraum-Befehl ist ein reiner Umschalter ohne Richtung, ein Auslösen bei bereits geschlossener Klappe würde sie ÖFFNEN statt schließen. Deshalb zusätzlich zur Ziel-Variable zwingend eine Zustands-Variable angeben, die aktuell offene Klappen namentlich nennt (z. B. Tessies "Tür-/Klappenstatus") -- ausgelöst wird nur, wenn "Kofferraum" oder "Heckklappe" darin vorkommt, sonst passiert nichts. Ohne gültige Zustands-Variable feuert die Aktion GAR NICHT (Sicherheitssperre, kein Raten). 🛑 Beachte unbedingt den Sicherheitshinweis ganz oben im Panel -- Einklemmgefahr, keine Hinderniserkennung.'],
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

        $expFenster = $this->getPanelExpandedState('FensterUeberwachung', false);
        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🪟  Fenster-/Tür-Überwachung',
            'expanded' => $expFenster,
            'onClick' => $this->rememberPanelOnClick('FensterUeberwachung', $expFenster),
            'items' => [
                ['type' => 'Label', 'caption' => 'WarnHub kann ein Fenster oder eine Tür nicht selbst schließen -- anders als Raffstore/Markise/Garage gibt es dafür keinen generischen Aktor. Ist aber ein Öffnungskontakt vorhanden, kann WarnHub erkennen, dass er offen ist, und bei einer passenden aktiven Warnung gezielt darauf hinweisen.'],
                [
                    'type' => 'Button',
                    'caption' => '🔎 Objektbaum nach Fenster-/Tür-Kontakten durchsuchen',
                    'onClick' => 'echo WHUB_DiscoverFensterkontakte($id);',
                ],
                ['type' => 'Label', 'caption' => 'Findet Boolean-Variablen sowohl über ein klassisches Symcon-Profil (~Window/~Door, auch .Reversed) als auch über die seit Symcon 9.0 neue Variablendarstellung mit einer "Geöffnet"/"Offen"-Option -- unabhängig vom Hersteller/der Integration (z. B. auch Matter-Kontakte). Gefundene Treffer werden vorausgefüllt und mit Sturm/Hagel/Starkregen aktiviert (ein offenes Fenster lässt bei allen dreien Wind/Wasser herein). Eine erneute Suche fügt nur neue Treffer hinzu.'],
                [
                    'type' => 'List',
                    'name' => 'Fensterkontakte',
                    'rowCount' => $this->listRowCount(count($this->decodeFensterkontakte())),
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '190px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Aktiv', 'name' => 'Aktiv', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ...array_map(fn ($f) => ['caption' => $f[1], 'name' => $f[0], 'width' => '75px', 'add' => in_array($f[0], ['KatSturm', 'KatHagel', 'KatStarkregen'], true), 'edit' => ['type' => 'CheckBox']], array_values(self::CATEGORY_FIELDS)),
                        ['caption' => 'Ab Schweregrad', 'name' => 'MinSeverity', 'width' => '140px', 'add' => 3, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
                        ['caption' => 'Nur Standort (leer=alle festen)', 'name' => 'StandortFilter', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Kontakt-Variable', 'name' => 'VariableID', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ],
                ],
                ['type' => 'Label', 'caption' => 'Push kommt nur EINMAL je (Warnung, Kontakt) -- schließt man das Fenster und öffnet es später während derselben Warnung erneut, kommt eine neue Meldung. Läuft über dieselben Push-Ziele wie normale Warnungen, respektiert die Push-Ruhephase.'],
            ],
        ];

        $pruefungItems = [
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
        ];
        $restoreLine = $this->wetterstationRestoreStatusLine();
        if ($restoreLine !== null) {
            $pruefungItems[] = ['type' => 'Label', 'name' => 'WetterstationRestoreStatusLabel', 'caption' => $restoreLine];
        }
        $pruefungItems[] = ['type' => 'Label', 'caption' => 'Für ein eigenes Dashboard (z. B. IPSView): dieselben Werte stehen unten im Objektbaum als vier eigene Variablen (Aktive Warnungen, Höchster Schweregrad, Status, Letzte Prüfung) -- IPSView baut Views aus vorhandenen Symcon-Variablen zusammen, nicht über einen eigenen Push-Kanal, deshalb hier keine gesonderte Einrichtung nötig.'];
        $pruefungItems[] = ['type' => 'Label', 'caption' => '🧊 Fertige WebFront-Kacheln: fünf weitere Variablen im Objektbaum enthalten fertiges, eigenständiges HTML -- kein eigenes Bauen nötig, einfach im Objektbaum in den Bereich des WebFronts verlinken. "Kachel (kompakt)", "Kachel (Übersicht)" und "Kachel (Alle Warnungen)" passen sich automatisch an Hell/Dunkel an und laden keine externen Ressourcen. Alle enthalten unten außerdem drei kleine Links zu den amtlichen Warnkarten (DWD/ZAMG/MeteoSchweiz).'];
        $pruefungItems[] = ['type' => 'Label', 'caption' => '📜 "Kachel (Alle Warnungen)": wie "Kachel (Übersicht)", aber OHNE 8er-Deckel -- scrollbare Liste aller aktiven Warnungen, sortiert nach Schweregrad. Jede Karte hat einen "✕"-Button zum Ausblenden, dazu eine Filterleiste je Ereignistyp ("🚫 Waldbrandgefahr" etc.) -- beides rein im jeweiligen Browser gemerkt (wie die Zoomstufe bei "Kachel (Karte)"), betrifft nur die Anzeige, nicht Push/Historie/Schutzaktionen.'];
        $pruefungItems[] = ['type' => 'Label', 'caption' => '🗺️ "Kachel (Karte)": zeigt eine Straßenkarte (Esri) mit JEDEM aktiven Standort als eigenem, farbigen Pin (auch mobile Standorte -- folgt deren Live-Position) plus einer kleinen Legende zum Anklicken. Startansicht zeigt alle Standorte gemeinsam; ein Klick auf einen Pin/Legenden-Eintrag zoomt auf diesen -- die Wahl merkt sich der jeweilige Browser für sich (z. B. dein Handy unterwegs auf "Kohlekasten", das Tablet zuhause weiterhin auf alle bzw. den eigenen Standort). Lädt anders als die übrigen Kacheln externe Ressourcen (Leaflet.js von unpkg.com, Kartenkacheln von server.arcgisonline.com -- bewusst nicht OpenStreetMaps eigene Tile-Server, die für eingebettete Drittanbieter-Widgets wie diese Kachel einen Referer-Header verlangen und je nach Browser/WebFront blockieren können).'];
        $pruefungItems[] = ['type' => 'NumberSpinner', 'name' => 'KartenkachelHoehePx', 'caption' => 'Höhe "Kachel (Karte)" in Pixel (0 = automatisch, an die Kachel anpassen)', 'minValue' => 0, 'maxValue' => 2000];
        $pruefungItems[] = ['type' => 'Label', 'caption' => 'Bei 0 versucht die Kachel, die Höhe der sie umgebenden WebFront-/Kachel-Visualisierung-Kachel zu übernehmen -- funktioniert nicht in jeder Konfiguration zuverlässig (Praxis-Fund kronos/Bricoleur, Symcon-Forum, 07.09.2026: Karte skalierte nur in der Breite, nicht in der Höhe). Bei diesem Verhalten hier eine feste Pixelzahl eintragen.'];
        $pruefungItems[] = ['type' => 'Label', 'caption' => '🇦🇹 "Kachel (ZAMG-Warnkarte, Österreich)": bettet die offizielle ZAMG-Warnkarte direkt ein (iframe) -- speziell für österreichische Nutzer. Zeigt aktuell ganz Österreich, noch ohne automatische Zentrierung auf einen einzelnen Standort.'];
        $pruefungItems[] = ['type' => 'NumberSpinner', 'name' => 'ZamgKachelHoehePx', 'caption' => 'Höhe "Kachel (ZAMG-Warnkarte, Österreich)" in Pixel (0 = automatisch, an die Kachel anpassen)', 'minValue' => 0, 'maxValue' => 2000];
        $pruefungItems[] = ['type' => 'Label', 'caption' => 'Bei 0 versucht die Kachel, die Höhe der sie umgebenden WebFront-/Kachel-Visualisierung-Kachel zu übernehmen -- funktioniert nicht in jeder Konfiguration zuverlässig (derselbe Effekt wie bei "Kachel (Karte)", Praxis-Fund kronos/Bricoleur bzw. ruan/Andreas, Symcon-Forum). Bei diesem Verhalten hier eine feste Pixelzahl eintragen.'];
        $pruefungItems[] = ['type' => 'Label', 'caption' => 'Warnungs-Historie (auch vergangene, nicht nur aktuell aktive Warnungen/Entwarnungen -- bis zu 500 Einträge) für eigene Auswertungen/Skripte über die Funktion WHUB_GetHistory($id, $limit) abrufbar, kein eigenes Formularfeld dafür nötig.'];
        $pruefungItems[] = ['type' => 'Label', 'caption' => 'Zum Testen des Zustellwegs, unabhängig von einer echten Warnung:'];
        $pruefungItems[] = [
            'type' => 'Button',
            'caption' => '🧪 Testbenachrichtigung senden',
            'onClick' => 'echo WHUB_TestPush($id);',
        ];
        $expPruefung = $this->getPanelExpandedState('PruefungStatus', true);
        $form['elements'][] = [
            'type' => 'ExpansionPanel',
            'caption' => '🔎  Prüfung & Status',
            'expanded' => $expPruefung,
            'onClick' => $this->rememberPanelOnClick('PruefungStatus', $expPruefung),
            'items' => $pruefungItems,
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
        foreach ($this->findInstancesByModuleNameSubstring(self::SMTP_GUID, 'smtp') as $instanceID => $moduleName) {
            $out[] = ['InstanceID' => $instanceID, 'Name' => @IPS_GetName($instanceID) ?: ('#' . $instanceID), 'Typ' => 'email'];
        }
        return $out;
    }

    /** @return array<int,array{InstanceID:int,Name:string,Typ:string,Aktiv:bool,Zieladresse:string}> */
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
                'Zieladresse' => (string) ($w['Zieladresse'] ?? ''),
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
        $addedEmail = 0;
        foreach ($foundTargets as $target) {
            if (isset($known[$target['InstanceID']])) {
                continue;
            }
            // E-Mail (SMTP) ist anders als die übrigen Typen KEIN fertiges Ziel:
            // die SMTP-Instanz kennt nur den Versandweg, nicht den Empfänger --
            // ohne von Hand eingetragene Zieladresse würde ein aktivierter
            // Eintrag beim Versand nur scheitern. Deshalb bewusst inaktiv
            // vorbelegt, bis die Adresse eingetragen ist (siehe Hinweistext).
            $isEmail = $target['Typ'] === 'email';
            $rows[] = ['InstanceID' => $target['InstanceID'], 'Name' => $target['Name'], 'Typ' => $target['Typ'], 'Aktiv' => !$isEmail, 'Zieladresse' => ''];
            $added++;
            if ($isEmail) {
                $addedEmail++;
            }
        }
        $this->UpdateFormField('WebFronts', 'values', json_encode($rows));
        $this->UpdateFormField('WebFronts', 'rowCount', $this->listRowCount(count($rows), 3));
        if ($added === 0 && count($rows) > 0) {
            return sprintf('ℹ️ Keine neuen Push-Ziele gefunden (%d bereits bekannt). Bitte unten „Übernehmen" klicken, falls noch nicht gespeichert.', count($rows));
        }
        if ($added === 0) {
            return '⚠️ Weder WebFront-, Kachel-Visualisierung-, Telegram-Bot-, Pushover- noch SMTP-Instanzen im Objektbaum gefunden.';
        }
        if ($addedEmail > 0) {
            return sprintf('✅ %d neue(s) Push-Ziel(e) gefunden (insgesamt %d) -- bitte unten „Übernehmen" klicken. %d E-Mail-Ziel(e) sind noch INAKTIV: erst Zieladresse eintragen und aktivieren.', $added, count($rows), $addedEmail);
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

    /**
     * Sichtbarkeit für eine noch nicht abgeschlossene Auto-Rückstellung --
     * ohne diese Zeile merkt man erst am Push (falls aktiv), dass sich
     * überhaupt etwas tut. null = nichts anstehend (Label wird dann im
     * Formular weggelassen). Dietmars Bestätigung 04.09.2026.
     */
    private function wetterstationRestoreStatusLine(): ?string
    {
        $restoreState = json_decode($this->ReadAttributeString('WetterstationRestoreState'), true) ?: [];
        if (count($restoreState) === 0) {
            return null;
        }
        $minRemainingMinutes = null;
        foreach ($restoreState as $entry) {
            if ($entry['CalmSinceTs'] === null) {
                continue;
            }
            $remainingSeconds = max(0, self::WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN - (time() - (int) $entry['CalmSinceTs']));
            $minutes = (int) ceil($remainingSeconds / 60);
            if ($minRemainingMinutes === null || $minutes < $minRemainingMinutes) {
                $minRemainingMinutes = $minutes;
            }
        }
        $count = count($restoreState);
        if ($minRemainingMinutes !== null) {
            return sprintf('🔽 %d durch die Wetterstation geschützte Aktion(en) -- frühestens in %d Min. wieder zurückgestellt.', $count, $minRemainingMinutes);
        }
        return sprintf('🔽 %d durch die Wetterstation geschützte Aktion(en) -- wartet noch auf Windberuhigung.', $count);
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
                ['type' => 'Label', 'caption' => '🎉 WarnHub ist jetzt im Symcon Module Store als Stable-Version verfügbar (bisher nur Beta)!'],
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
                ['type' => 'Label', 'caption' => '• Auto-Rückstellung: wird jetzt zusätzlich in der Warnungs-Historie protokolliert (neuer Verlaufstyp "Rückstellung"), eine noch anstehende Rückstellung steht als eigene Statuszeile im Panel "Prüfung & Status"'],
                ['type' => 'Label', 'caption' => '• Eigene Wetterstation sendet jetzt auch eine echte "✅ Entwarnung"-Push, sobald Windböe/Regenrate wieder unter dem Schwellwert liegen -- vorher gab es nur die "Sturm kommt"-Meldung, nie ein "Sturm vorbei" (die Quelle liefert selbst kein Cancel, WarnHub erkennt es jetzt anhand des eigenen Verlaufs)'],
                ['type' => 'Label', 'caption' => '• GeoSphere Austria: die Warnungs-Beschreibung enthält jetzt zusätzlich die meteorologische Kurzerklärung der Quelle (z. B. "Mit einer stürmischen Nordwestströmung erreichen Sturmböen etwa 60 bis 80 km/h") vor Auswirkungen/Empfehlungen'],
                ['type' => 'Label', 'caption' => '• "Kachel (kompakt)" und "Kachel (Übersicht)" enthalten jetzt unten drei kleine Links zu den amtlichen Warnkarten (DWD, ZAMG, MeteoSchweiz)'],
                ['type' => 'Label', 'caption' => '• NEU: zwei weitere fertige WebFront-Kacheln. "Kachel (Karte)" zeigt eine OpenStreetMap-Karte, zentriert auf einen frei wählbaren Standort (auch mobile -- folgt der Live-Position), Markerfarbe nach höchstem aktivem Schweregrad. "Kachel (ZAMG-Warnkarte, Österreich)" bettet die offizielle ZAMG-Warnkarte direkt ein -- speziell für österreichische Nutzer. Beide laden anders als die bisherigen zwei Kacheln bewusst externe Ressourcen (Leaflet.js/OpenStreetMap bzw. die ZAMG-Seite selbst); eine Einbettung der DWD-/MeteoSchweiz-Warnkarten war technisch nicht möglich, beide verbieten das per X-Frame-Options'],
                ['type' => 'Label', 'caption' => '• NEU: E-Mail als fünfter Push-Kanal (über eine bereits eingerichtete SMTP-Instanz, offizielles Symcon-Modul) -- einfach "🔎 Push-Ziele suchen" erneut klicken, eine gefundene SMTP-Instanz wird zunächst inaktiv angelegt (kennt nur den Versandweg, nicht den Empfänger), erst Zieladresse eintragen und dann aktivieren'],
                ['type' => 'Label', 'caption' => '• NEU: Fenster-/Tür-Überwachung (eigenes Panel) -- WarnHub kann ein Fenster nicht selbst schließen, erkennt aber über einen Öffnungskontakt, dass eines offen ist, und warnt gezielt bei einer passenden aktiven Warnung. Findet Kontakte über ein klassisches Symcon-Profil (~Window/~Door) UND die seit Symcon 9.0 neue Variablendarstellung (herstellerunabhängig, auch Matter-Kontakte) -- einfach "🔎 Objektbaum nach Fenster-/Tür-Kontakten durchsuchen" klicken'],
                ['type' => 'Label', 'caption' => '• NEU: Schweizer Erdbeben als weitere Datenquelle (Schweizerischer Erdbebendienst SED, ETH Zürich) -- ab Magnitude 2.5 (laut SED die Spürbarkeitsschwelle). Keine Wetterquelle, eigener Kreis um das Epizentrum statt amtlicher Warnfläche (siehe Datenquellen-Panel für Details)'],
                ['type' => 'Label', 'caption' => '• NEU: Deutscher Waldbrandgefahrenindex (DWD) als weitere Datenquelle -- amtliche 5-stufige Skala, je Standort automatisch die nächstgelegene der 484 DWD-Messstationen'],
                ['type' => 'Label', 'caption' => '• Fix "Kachel (Karte)": lud bei manchen Nutzern (Firefox) keine Kartenkacheln (HTTP 403) -- OpenStreetMaps eigene Tile-Server verlangen für eingebettete Widgets einen Referer-Header, der je nach Browser fehlen kann. Zeigt jetzt Kartenkacheln von Esri (referer-frei). Außerdem füllt die Kachel jetzt auch in der Höhe den verfügbaren Platz, nicht nur in der Breite'],
                ['type' => 'Label', 'caption' => '• "Kachel (Karte)": automatische Höhenanpassung funktioniert nicht in jeder WebFront-/Kachel-Visualisierung-Konfiguration zuverlässig -- neues Feld im Panel "Prüfung & Status" erlaubt jetzt ersatzweise eine feste Höhe in Pixel'],
                ['type' => 'Label', 'caption' => '• NEU: Deutsche Ozonbelastung (Umweltbundesamt) als weitere Datenquelle -- amtlicher Luftqualitätsindex, ausdrücklich nur Ozon/Sommersmog, kein allgemeines Luftgüte-Monitoring'],
                ['type' => 'Label', 'caption' => '• "Kachel (Karte)": eine manuell gewählte Zoomstufe geht jetzt nicht mehr bei jeder Prüfung verloren -- wird je Browser/Gerät gemerkt, die Kartenmitte folgt weiterhin immer dem aktuellen Standort'],
                ['type' => 'Label', 'caption' => '• Fix "Kachel (Karte)": das Zoom-Merken von eben konnte die Karte bei manchen Nutzern komplett leer lassen -- wenn der Browser den Zugriff auf localStorage verweigert (z. B. eingebettete WebFront-Ansicht), brach das Lesen der gemerkten Zoomstufe unabgesichert die ganze Kartenerstellung ab. Jetzt robust gegen einen solchen Fehler'],
                ['type' => 'Label', 'caption' => '• Fix "Kachel (ZAMG-Warnkarte, Österreich)": derselbe Höhen-Fund wie bei "Kachel (Karte)" -- eine fest genagelte Pixelzahl skalierte nicht in jeder WebFront-/Kachel-Visualisierung-Konfiguration. Füllt jetzt ebenfalls automatisch die verfügbare Höhe, mit neuem eigenen Feld "ZamgKachelHoehePx" als feste Alternative'],
                ['type' => 'Label', 'caption' => '• "Kachel (Karte)" komplett neu: zeigt jetzt ALLE aktiven Standorte gleichzeitig als eigene, farbige Pins plus eine klickbare Legende, statt nur einen fest in der Konsole ausgewählten. Grund: dieselbe Kachel-Variable geht an jeden WebFront-Betrachter gleich -- stelltest du unterwegs auf deinen eigenen Standort um, sah der Rest der Familie zuhause zwangsläufig denselben, nicht mehr den eigenen (Dietmars Fund 07.09.2026). Welcher Standort fokussiert ist, merkt sich jetzt jeder Browser für sich, die Startansicht zeigt immer alle gemeinsam. Das Feld "Standort für Kachel (Karte)" entfällt damit'],
                ['type' => 'Label', 'caption' => '• Fix: mehrere eng beieinanderliegende Standorte (z. B. mehrere mobile Standorte am selben Ort) konnten bei Waldbrandgefahr und österreichischen GeoSphere-Warnungen dieselbe Meldung mehrfach zeigen/verschicken statt einmal je Standort -- Praxis-Fund ruan/Andreas, Symcon-Forum ("1 Warnung + 3 Standorte -> 9 statt 3 Einträge")'],
                ['type' => 'Label', 'caption' => '• Fix "Kachel (Übersicht)": bei mehr als 8 aktiven Warnungen werden die angezeigten 8 Karten jetzt nach Schweregrad sortiert, statt einfach die ersten 8 in Ankunftsreihenfolge zu nehmen -- vorher hätte ausgerechnet die wichtigste Warnung hinter "+N weitere" verschwinden können, nur weil sie zufällig weiter hinten stand'],
                ['type' => 'Label', 'caption' => '• NEU: "Kachel (Alle Warnungen)" -- wie "Kachel (Übersicht)", aber ohne 8er-Deckel: scrollbare Liste ALLER aktiven Warnungen, nach Schweregrad sortiert. Jede Karte hat einen eigenen "✕"-Button zum Ausblenden, dazu eine Filterleiste je Ereignistyp ("🚫 Waldbrandgefahr" etc.) -- beides rein im jeweiligen Browser gemerkt, betrifft nur die Anzeige, nicht Push/Historie/Schutzaktionen'],
                ['type' => 'Label', 'caption' => '• "🔎 Objektbaum nach Schutzaktionen durchsuchen" findet jetzt auch Raffstore-/Jalousie-Steuerungen, deren Name selbst keinen Hinweis darauf gibt -- über Symcons eingebaute Rollladen-Variablendarstellung, unabhängig von Sprache/Eigennamen'],
                ['type' => 'Label', 'caption' => '• Fix "🔎 Wetterstation suchen": meldete bisher pauschal "keine unterstützte Instanz gefunden", selbst wenn tatsächlich eine (z. B. Froggit-)Instanz im Baum stand, ihr aber die Windböe-/Regenrate-Felder fehlten (z. B. ein reiner Temperatur-Außensensor ohne Wind-/Regenmesser). Nennt jetzt ehrlich die gefundene, aber ungeeignete Instanz -- Praxis-Fund ralf, Symcon-Forum'],
                ['type' => 'Label', 'caption' => '• Fix eigene Wetterstation: neuere Ecowitt-Gateways mit Piezo-Regensensor (z. B. WS90) melden Regen nur noch über das Feld "rrain_piezo" statt des klassischen "rainratein" -- wird jetzt zusätzlich erkannt, sowohl bei der Objektbaum-Suche als auch beim eigentlichen Auslesen. Praxis-Fund ralf, Symcon-Forum'],
                ['type' => 'Label', 'caption' => '• Schutzaktionen: neuer, unübersehbarer Sicherheitshinweis ganz oben im Panel zu "Fenster schließen"/"Kofferraum/Heckklappe schließen" -- weder Fahrzeug noch WarnHub können erkennen, ob sich eine Person im Bewegungsbereich der Scheibe/Klappe befindet, vorher nur versteckt im Hilfe-Popup'],
                ['type' => 'Label', 'caption' => '• NEU: mobiler Standort erkennt jetzt auch Stellantis-, Smartcar-, BMW ConnectedDrive-, Hyundai/Kia-Bluelink- und OVMS-native-Fahrzeuge automatisch -- über stabile Idents statt Namensabgleich. Bewusst OHNE Schutzaktions-Anbindung (Fenster/Kofferraum): keines der fünf community Module bietet dafür eine Fernbefehls-Funktion -- Fenster-/Kofferraum-Fernsteuerung bleibt ein Tesla-/Tessie-Spezifikum'],
                ['type' => 'Label', 'caption' => '• Fix Push-/Schutzaktions-Schwall: der DWD vergibt bei "Vorabinformationen vor Unwetter" für dieselbe andauernde Gefahr alle 15-30 Minuten eine NEUE Meldungs-ID statt eines Updates der alten -- das führte zu Schwärmen mehrerer Push-Benachrichtigungen für ein und dasselbe Ereignis und hätte theoretisch auch Schutzaktionen (Jalousie/Markise/Garage) wiederholt auslösen können. Push und Schutzaktionen erkennen fortlaufende Meldungen jetzt am Ereignistyp statt an der wechselnden ID und lösen pro andauerndem Ereignis nur noch einmal aus'],
                ['type' => 'Label', 'caption' => '• Fix Sprachauswahl: eine über NINA aggregierte Meldung konnte komplett auf Englisch erscheinen, wenn deren deutschsprachiger Eintrag nicht exakt "de-DE"/"de" geschrieben war -- die Erkennung ist jetzt toleranter (Groß-/Kleinschreibung, Leerraum)'],
                ['type' => 'Label', 'caption' => '• "Kachel (Alle Warnungen)": jede Karte lässt sich jetzt per Klick aufklappen und zeigt dann die volle Handlungsempfehlung, den genauen Gültigkeitszeitraum sowie die komplette amtliche Beschreibung -- unabgekürzt, anders als die Push-Benachrichtigung, die von WFC_PushNotification/VISU_PostNotificationEx hart auf 256 Byte begrenzt wird. Push-Texte nennen außerdem jetzt zuerst die Handlungsempfehlung und die Gültigkeit, erst danach (falls noch Platz ist) die ausführliche Beschreibung -- vorher konnte eine lange Beschreibung das ganze Byte-Budget aufbrauchen. Praxis-Wunsch kronos, Symcon-Forum'],
                ['type' => 'Label', 'caption' => '• Fix: schaltet man NINA UND die direkten DWD-Wetterwarnungen bewusst ab (z. B. in Österreich/der Schweiz, andere Quellen decken das ab), legte das bisher die KOMPLETTE Instanz lahm, obwohl eine andere Datenquelle (z. B. GeoSphere Austria) aktiv war -- die "gibt es überhaupt eine aktive Quelle"-Prüfung kannte nur NINA/DWD und wurde bei keiner der seither dazugekommenen acht weiteren Quellen mitgezogen. Praxis-Fund hfichtinger, Symcon-Forum'],
                ['type' => 'Label', 'caption' => '• E-Mail-Push: eine Zieladresse kann jetzt mehrere Empfänger enthalten, mit Komma oder Semikolon getrennt -- jede Adresse bekommt einen eigenen Versand, unabhängig vom internen Verhalten des SMTP-Moduls bei mehreren Empfängern. Praxis-Wunsch hfichtinger, Symcon-Forum'],
                ['type' => 'Label', 'caption' => '• Fix: die 256-Byte-Kürzung galt bisher für ALLE Push-Kanäle gleichermaßen -- dabei ist das nachweislich nur eine Grenze von WebFront/Kachel-Visualisierung. Telegram, Pushover und vor allem E-Mail (keine bekannte Längenbeschränkung) bekommen jetzt den vollen, unabgekürzten Text. Praxis-Fund hfichtinger, Symcon-Forum: "Mail kann auch mehr"'],
                ['type' => 'Label', 'caption' => '• NEU: "Datenquellen" nach D-A-CH gruppiert -- "Allgemein" (Meteoalarm, eigene Wetterstation, Abfragetakt) bleibt immer offen, darunter je ein zuklappbares Länder-Panel (🇩🇪/🇦🇹/🇨🇭). Das Panel des über den Symcon-Systemstandort erkannten Heimatlands klappt beim allerersten Öffnen automatisch auf. Praxis-Wunsch Dietmar (angeregt durch hfichtingers Rückmeldung, dass ihn als Österreicher die deutschen Quellen gar nicht interessieren)'],
                ['type' => 'Label', 'caption' => '• NEU: alle Panels merken sich jetzt selbst, ob sie zuletzt auf- oder zugeklappt waren -- kein "Formular scrollt sich nach jedem Speichern wieder von vorne auf" mehr. Dietmars Wunsch 09.09.2026'],
                ['type' => 'Label', 'caption' => '• Fix "Kachel (Alle Warnungen)": der DWD reißt bei seinen "Vorabinformationen vor Unwetter" die CAP-Update-Kette faktisch ab und schickt für dieselbe andauernde Gefahr alle 15-30 Minuten eine neue Meldungs-ID -- das erschien bisher als mehrere fast identische, leicht unterschiedlich formulierte Karten für ein und dasselbe Ereignis (Push-Zustellung war seit 1.12.1 bereits korrekt, nur die Anzeige war betroffen). Es wird jetzt nur noch die jeweils aktuellste Fassung gezeigt. Praxis-Fund ruan, Symcon-Forum'],
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
                ['type' => 'Label', 'caption' => 'Rückmeldungen, Fehler, fehlende Datenquellen oder Ideen für weitere Schutzaktionen bitte gerne hier im Thread.'],
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
                ['type' => 'Label', 'caption' => 'Gewidmet Sepp Lausch, der zeitgleich an einer eigenen Symcon-Warnmeldungs-Anbindung arbeitet -- der freundschaftliche Wetteifer hat WarnHub sichtlich gutgetan. 😉'],
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

    /**
     * Fenster-/Tür-Überwachung: WarnHub kann ein Fenster (anders als
     * Raffstore/Markise/Garage) nicht selbst schließen -- aber sehr wohl
     * erkennen, dass eines offen ist, und warnen. Bewusst eine EIGENE Liste
     * statt ein weiterer Schutzaktions-Typ, weil hier nichts geschaltet
     * wird (kein ZielVariableID/ZielWert), sondern nur ein Zustand geprüft
     * wird. Gleiche Optik/Felder wie bei Schutzaktionen (Kategorien/
     * MinSeverity/StandortFilter) für Wiedererkennbarkeit. Dietmars Wunsch
     * 07.09.2026, nach Installation eigener Öffnungskontakte.
     * @return array<int,array{Name:string,Aktiv:bool,Kategorien:array<int,string>,MinSeverity:int,StandortFilter:string,VariableID:int}>
     */
    private function decodeFensterkontakte(): array
    {
        $raw = json_decode($this->ReadPropertyString('Fensterkontakte'), true);
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
            $out[] = [
                'Name' => (string) ($a['Name'] ?? ''),
                'Aktiv' => (bool) ($a['Aktiv'] ?? true),
                'Kategorien' => $kategorien, // leer = gilt für jede Kategorie
                'MinSeverity' => (int) ($a['MinSeverity'] ?? 3),
                'StandortFilter' => (string) ($a['StandortFilter'] ?? ''),
                'VariableID' => (int) ($a['VariableID'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Welcher Rohwert (true/false) bei dieser Boolean-Variable "offen"
     * bedeutet -- das ist KEINE feste Konvention, siehe Live-Fund
     * 07.09.2026: Dietmars klassisches Symcon-Systemprofil "~Window" sagt
     * true=offen, seine NEUEN (Matter-basierten) Fensterkontakte über die
     * seit Symcon 9.0 neue Variablendarstellung (VariablePresentation,
     * kein klassisches Profil mehr) sagen für dieselbe Bedeutung true=ZU,
     * false=offen -- genau umgekehrt. Deshalb wird IMMER die tatsächliche
     * Profil-/Vorlagen-Zuordnung gelesen statt geraten: zuerst die
     * klassischen Profile ~Window/~Door (inkl. .Reversed), sonst die
     * OPTIONS der neuen Vorlage nach der Caption "Geöffnet"/"Offen"
     * durchsucht. null = keins von beidem gefunden (kein erkennbarer
     * Fenster-/Tür-Kontakt).
     */
    /**
     * Ob eine Variable Symcons eingebaute Rollladen-Darstellung trägt
     * (VARIABLE_PRESENTATION_SHUTTER) -- ein strukturelles, namens- und
     * sprachunabhängiges Erkennungsmerkmal für Raffstore-/Jalousie-
     * Steuerungen, zusätzlich zum Namensabgleich in
     * DiscoverSchutzaktionen(). Symcon-Store-Review-Anregung, 07.09.2026
     * ("es gibt ja eine spezialisierte Darstellung für Rollläden ... kann
     * man die Information ja sinnvoll für deine Heuristik nutzen"), GUID
     * live gegen Dietmars System verifiziert:
     * {6075FC22-69AF-B110-3749-C24138883082}. defined()-Absicherung: die
     * Konstante gibt es erst seit den Variablendarstellungen (Symcon 8) --
     * auf älteren Installationen bleibt diese Prüfung einfach false, der
     * Namensabgleich greift dort unverändert weiter.
     */
    private function hasShutterPresentation(int $variableID): bool
    {
        if (!defined('VARIABLE_PRESENTATION_SHUTTER')) {
            return false;
        }
        $var = @IPS_GetVariable($variableID);
        if (!is_array($var)) {
            return false;
        }
        return ($var['VariablePresentation']['PRESENTATION'] ?? null) === VARIABLE_PRESENTATION_SHUTTER;
    }

    private function contactOpenRawValue(int $variableID): ?bool
    {
        $var = @IPS_GetVariable($variableID);
        if (!is_array($var)) {
            return null;
        }
        $profile = (string) ($var['VariableCustomProfile'] ?: ($var['VariableProfile'] ?? ''));
        if ($profile === '~Window' || $profile === '~Door') {
            return true;
        }
        if ($profile === '~Window.Reversed' || $profile === '~Door.Reversed') {
            return false;
        }
        $optionsRaw = $var['VariablePresentation']['OPTIONS'] ?? null;
        if (is_string($optionsRaw) && $optionsRaw !== '') {
            $options = json_decode($optionsRaw, true);
            if (is_array($options)) {
                foreach ($options as $opt) {
                    $caption = mb_strtolower(trim((string) ($opt['Caption'] ?? '')));
                    if ($caption === 'geöffnet' || $caption === 'offen') {
                        return (bool) ($opt['Value'] ?? false);
                    }
                }
            }
        }
        return null;
    }

    /** Aktueller Zustand eines Fenster-/Tür-Kontakts -- null = Variable fehlt oder Bedeutung nicht bestimmbar (siehe contactOpenRawValue()). */
    private function isContactOpen(int $variableID): ?bool
    {
        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            return null;
        }
        $openValue = $this->contactOpenRawValue($variableID);
        if ($openValue === null) {
            return null;
        }
        return (bool) @GetValue($variableID) === $openValue;
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
                // Zusätzliches, NAMENSUNABHÄNGIGES Signal für 'raffstore':
                // Symcons eigene Rollladen-Variablendarstellung erkennt eine
                // Steuerung auch dann, wenn weder ihr Name noch der ihrer
                // Instanz "Raffstore"/"Jalousie" enthält (andere Sprache,
                // Eigenname, Fremdmodul-Konvention). Siehe
                // hasShutterPresentation().
                if (!$matched && $actionType === 'raffstore' && $type === 2 && $this->hasShutterPresentation($id)) {
                    $matched = true;
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

    /**
     * Durchsucht ALLE Boolean-Variablen im System nach Fenster-/Tür-Kontakten
     * -- über contactOpenRawValue() (klassisches Profil ~Window/~Door ODER
     * die seit Symcon 9.0 neue Variablendarstellung mit einer "Geöffnet"/
     * "Offen"-Option, siehe dort). Anders als
     * discoverWetterstationVariablesByProfile() werden ALLE Treffer
     * übernommen, nicht nur ein eindeutiger einzelner -- es gibt
     * typischerweise mehrere Fenster/Türen im Haus, nicht genau eine.
     * Vorbelegung Sturm/Hagel/Starkregen, analog zum Schutzaktionstyp
     * "fenster" (ein offenes Fenster lässt bei allen dreien Wasser/Wind
     * herein). Dietmars Wunsch 07.09.2026, nach Installation eigener
     * Öffnungskontakte.
     */
    public function DiscoverFensterkontakte(): string
    {
        $rows = json_decode($this->ReadPropertyString('Fensterkontakte'), true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $known = array_column($rows, null, 'VariableID');
        $added = 0;
        foreach (@IPS_GetVariableList() ?: [] as $variableID) {
            if (isset($known[$variableID]) || $this->contactOpenRawValue($variableID) === null) {
                continue;
            }
            $rows[] = [
                'Name' => (string) (@IPS_GetName($variableID) ?: ('#' . $variableID)),
                'Aktiv' => true,
                'KatSturm' => true,
                'KatHagel' => true,
                'KatStarkregen' => true,
                'KatGewitter' => false,
                'KatSchnee' => false,
                'KatHitze' => false,
                'MinSeverity' => 3,
                'StandortFilter' => '',
                'VariableID' => $variableID,
            ];
            $added++;
        }
        $this->UpdateFormField('Fensterkontakte', 'values', json_encode($rows));
        $this->UpdateFormField('Fensterkontakte', 'rowCount', $this->listRowCount(count($rows)));
        if ($added === 0) {
            return count($rows) > 0
                ? sprintf('ℹ️ Keine neuen Fenster-/Tür-Kontakte gefunden (%d bereits bekannt). Bitte unten „Übernehmen" klicken, falls noch nicht gespeichert.', count($rows))
                : 'ℹ️ Keine Fenster-/Tür-Kontakte gefunden -- weder über ein klassisches Profil (~Window/~Door) noch über die neue Variablendarstellung mit einer "Geöffnet"/"Offen"-Option.';
        }
        return sprintf('✅ %d neue(n) Fenster-/Tür-Kontakt(e) gefunden und aktiviert (insgesamt %d) -- bitte unten „Übernehmen" klicken, um zu speichern.', $added, count($rows));
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
     * Aktualisiert HeimLandCode aus dem Symcon-Systemstandort -- bewusst
     * NUR hier in Poll() (Hintergrund-Timer), NICHT in
     * GetConfigurationForm(), damit das Öffnen der Konsole nie auf einen
     * Nominatim-Netzwerkaufruf wartet. reverseGeocodeStandort() cached
     * selbst 6h, ist also außerhalb des allerersten Aufrufs praktisch
     * kostenlos. Kein konfigurierter Systemstandort bzw. ein fehlgeschlagener
     * Abruf lässt den zuletzt bekannten Wert einfach unangetastet stehen,
     * statt ihn zu löschen -- besser eine leicht veraltete als gar keine
     * Voreinstellung fürs Formular.
     */
    private function refreshHeimLandCode(): void
    {
        $loc = $this->getSystemLocation();
        if ($loc === null) {
            return;
        }
        $geo = $this->reverseGeocodeStandort($loc['lat'], $loc['lon']);
        if ($geo['countryCode'] !== '') {
            $this->WriteAttributeString('HeimLandCode', $geo['countryCode']);
        }
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
     * UND zusätzlich nach Instanzen der fünf GUID-gescopten Fahrzeug-/
     * Fernzugriffs-Module (Stellantis, Smartcar, BMW ConnectedDrive,
     * Hyundai/Kia Bluelink, OVMS native -- siehe DISCOVERY_LATLON_IDENTS-
     * Kommentar oben, jeweils über deren stabile Idents statt Namensvergleich) --
     * und ergänzt je Treffer
     * einen VORAKTIVIERTEN, bereits an die Live-Variablen gebundenen
     * mobilen Standort. Dietmars Nachfrage 04.09.2026: "Warum kannst du die
     * Zuordnung nicht auch gleich ... übernehmen?" -- bisher musste
     * QuellVarLat/QuellVarLon je Standort von Hand gesetzt werden. Läuft
     * wie DiscoverWebFronts()/DiscoverSchutzaktionen() nur auf der offenen
     * Formularmaske, "Übernehmen" bleibt der bewusste letzte Schritt.
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

        // Fahrzeug-/Fernzugriffs-Module zusätzlich über ihre STABILEN
        // Idents statt über einen Namensvergleich (siehe
        // DISCOVERY_LATLON_IDENTS-Kommentar oben) -- robuster als
        // DISCOVERY_LATLON_PAIRS, aber bewusst ein eigener, GUID-gescopter
        // Pfad je Modul: bei manchen (Stellantis/Smartcar/Bluelink) liegen
        // "Breitengrad"/"Längengrad" OHNE unterscheidenden Namens-Prefix
        // direkt unter der Fahrzeuginstanz (anders als bei Tessie), ein
        // reiner Namensabgleich wäre hier unnötig fragil, wo es doch echte
        // Idents gibt. Praxis-Fund Froggit/Ecowitt 07.09.2026: Ident statt
        // Name, wo immer möglich.
        foreach (self::DISCOVERY_LATLON_IDENTS as $pattern) {
            foreach (@IPS_GetInstanceListByModuleID($pattern['guid']) ?: [] as $vehicleID) {
                $latID = $this->findChildVariableByIdent($vehicleID, $pattern['lat']);
                $lonID = $this->findChildVariableByIdent($vehicleID, $pattern['lon']);
                if ($latID === null || $lonID === null) {
                    continue;
                }
                $key = $vehicleID . '|' . $pattern['guid'];
                $latCandidates[$key] = $latID;
                $lonCandidates[$key] = $lonID;
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
            return 'ℹ️ Keine neuen Fahrzeug-/Standort-Variablenpaare gefunden (gesucht: Tessie "Fahrzeugposition", Geofency "Current Latitude/Longitude", Stellantis, Smartcar, BMW ConnectedDrive, Hyundai/Kia Bluelink, OVMS native).';
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
        // Sammelt Treffer, deren MODUL erkannt wurde, denen aber die
        // benötigten Windböe-/Regenrate-Felder fehlen -- für eine ehrliche
        // Rückmeldung statt des generischen "nichts gefunden" am Ende.
        // Praxis-Fund ralf, Symcon-Forum, 07.09.2026: hatte eine echte
        // Froggit-Instanz im Baum (nur Außentemperatur-Sensor, offenbar ohne
        // Anemometer/Regenmesser), bekam trotzdem "keine Instanz gefunden"
        // zu sehen und war entsprechend verwirrt ("obwohl es eine Instanz
        // gibt"). Dietmars eigene Froggit+Ecowitt-Kombination (mit
        // Wind-/Regensensor) findet sich weiterhin normal über den
        // Erfolgsfall oben.
        $foundButIncomplete = [];

        $candidates = $this->findInstancesByModuleNameSubstring(self::FROGGIT_GUID, 'froggit');
        foreach (array_keys($candidates) as $instanceID) {
            // Ident-basiert statt Namensvergleich (Praxis-Fund ralf, Symcon-
            // Forum, 05.09.2026: Instanz nicht gefunden) -- der Name "Windböe"/
            // "Regenrate" kommt erst aus locale.json und hängt damit von der
            // Symcon-Systemsprache ab bzw. verschwindet bei einem manuell vom
            // Nutzer umbenannten Objekt; die Idents "windgustmph"/"rainratein"
            // (verifiziert gegen den echten Quellcode github.com/IPSAttain/
            // Froggit) bleiben davon unberührt, analog zu Wolbolar/Meteobridge.
            // "rrain_piezo" zusätzlich zu "rainratein" (Praxis-Fund ralf,
            // Symcon-Forum, 07.09.2026): neuere Ecowitt-Gateways mit
            // Piezo-Regensensor (z. B. WS90) senden GAR KEIN "rainratein"
            // mehr, nur noch die "*_piezo"-Feldfamilie -- der Froggit-
            // Quellcode legt für JEDES Feld, dessen Name "rain" enthält, per
            // generischem Catch-all eine eigene Variable an (Ident = roher
            // Gateway-Feldname, unübersetzt), "rrain_piezo" ist darunter laut
            // Ecowitt-eigener Protokolldokumentation die aktuelle Regenrate
            // (die anderen -- erain/hrain/drain/wrain/mrain/yrain_piezo --
            // sind kumulierte Zeiträume, kein Rate-Wert, deshalb NICHT
            // mitgeprüft).
            $windboe = $this->findChildVariableByIdent($instanceID, 'windgustmph');
            $regenrate = $this->findChildVariableByIdent($instanceID, 'rainratein')
                ?? $this->findChildVariableByIdent($instanceID, 'rrain_piezo');
            if ($windboe === null || $regenrate === null) {
                $foundButIncomplete[] = (@IPS_GetName($instanceID) ?: ('#' . $instanceID)) . ' (Froggit)';
                continue; // Ident passt, aber die entscheidenden Felder fehlen -- kein Treffer
            }
            $this->UpdateFormField('WetterstationInstanceID', 'value', $instanceID);
            return sprintf('✅ Wetterstation "%s" gefunden (Froggit, Windböe/Regenrate vorhanden) -- bitte unten „Übernehmen" klicken, um zu speichern.', @IPS_GetName($instanceID) ?: ('#' . $instanceID));
        }

        $candidatesWu = $this->findInstancesByModuleNameSubstring(self::WEATHERSTATION_WU_GUID, 'weatherstation');
        foreach (array_keys($candidatesWu) as $instanceID) {
            $windgust = $this->findChildVariableByIdent($instanceID, 'Windgust');
            $rainin = $this->findChildVariableByIdent($instanceID, 'rainin');
            if ($windgust === null || $rainin === null) {
                $foundButIncomplete[] = (@IPS_GetName($instanceID) ?: ('#' . $instanceID)) . ' (Sainlogic/ELV)';
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
                $foundButIncomplete[] = (@IPS_GetName($instanceID) ?: ('#' . $instanceID)) . ' (Meteobridge/Meteohub)';
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

        if ($foundButIncomplete !== []) {
            return sprintf(
                'ℹ️ Wetterstations-Instanz gefunden, aber OHNE die benötigten Windböe-/Regenrate-Felder: %s -- vermutlich misst dieses Gerät nur Temperatur/Luftfeuchte, ohne Wind-/Regensensor. Sind Wind-/Regenwerte trotzdem vorhanden (z. B. an anderer Stelle im Objektbaum), unten die Wind-/Regen-Variable manuell auswählen.',
                implode(', ', $foundButIncomplete)
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
        // $this->LogMessage() statt IPS_LogMessage() -- liefert automatisch
        // den korrekten Instanz-Kontext (Symcon-Store-Review-Hinweis,
        // 07.09.2026), IPS_LogMessage() bräuchte den Bezug manuell als
        // Sender-String nachgebaut.
        $this->LogMessage($context . ': ' . $message, KL_ERROR);
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

    /**
     * Wählt aus einem CAP-`info[]`-Array den deutschsprachigen Eintrag.
     *
     * NINA aggregiert Meldungen aus mehreren Quellen (MoWaS/KATWARN/BIWAPP/DWD/
     * LHP/Polizei) mit uneinheitlicher `language`-Schreibweise -- ein exakter
     * Vergleich auf 'de-DE'/'de' hatte einzelne Meldungen (z. B. mit 'DE' oder
     * 'de-DE ' mit Leerraum) verfehlt und still auf den ersten, teils
     * englischsprachigen Eintrag zurückfallen lassen (live beobachtet: eine
     * Gewitterwarnung erschien komplett auf Englisch). Bewusst kein komplettes
     * Verwerfen ohne deutschen Treffer -- eine Sicherheitswarnung lieber in
     * Fremdsprache zeigen als sie stillschweigend zu unterschlagen.
     *
     * @param array<int,array<string,mixed>> $infoList
     * @return array<string,mixed>|null
     */
    private function selectGermanCapInfo(array $infoList): ?array
    {
        foreach ($infoList as $info) {
            $lang = strtolower(trim((string) ($info['language'] ?? '')));
            if ($lang !== '' && strpos($lang, 'de') === 0) {
                return $info;
            }
        }
        return $infoList[0] ?? null;
    }

    private function fetchNinaDetail(string $id): ?array
    {
        $meta = $this->httpGetJson('https://warnung.bund.de/api31/warnings/' . rawurlencode($id) . '.json');
        if ($meta === null || !isset($meta['info'][0])) {
            return null;
        }
        $infoDe = $this->selectGermanCapInfo($meta['info']);

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
     * Meteohub). Der $idents-Aufrufer übergibt jetzt AUCH Froggits eigene
     * Idents ("windgustmph"/"rainratein"/"rrain_piezo") -- vorher fehlten
     * die hier, dieser Pfad verließ sich für Froggit ausschließlich auf den
     * (sprachabhängigen, umbenennbaren) Anzeigenamen "Windböe"/"Regenrate"
     * -- derselbe Fragilitäts-Fund, der DiscoverWetterstation() schon am
     * 05.09.2026 zum Ident-Abgleich gebracht hat, hier aber übersehen.
     * Praxis-Fund ralf, Symcon-Forum, 07.09.2026 (neuere Ecowitt-Gateways
     * mit Piezo-Regensensor legen gar keine "Regenrate"-Variable mehr an).
     * $identSuffix bleibt bei der bisherigen, instanzbasierten
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
            ['windgustmph', 'Windgust', 'Wind_Gust_KmH']
        );
        [$regenrateID] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationRegenVariableID'),
            $instanceID,
            'Regenrate',
            ['rainratein', 'rrain_piezo', 'rainin', 'Rain_Rate']
        );
        $windCalm = $windboeID === null || $this->windSeverityForSpeed($this->readWindSpeedKmh($windboeID)) === null;
        $regenCalm = $regenrateID === null || $this->regenSeverityForRate((float) @GetValue($regenrateID)) === null;
        $actions = $this->decodeSchutzaktionen();

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
                $actionName = $actions[$idx]['Name'] ?? ('Ziel-Variable #' . $variableID);
                $this->logHistory(
                    'rueckstellung',
                    $actionName,
                    'Automatische Rückstellung',
                    $actionName . ' automatisch zurückgestellt -- Wind/Regen war seit ' . (int) round(self::WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN / 60) . ' Minuten wieder ruhig.',
                    'Unknown',
                    $entry['Source'] === 'windboe' ? 'sturm' : 'starkregen',
                    'wetterstation'
                );
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
            ['windgustmph', 'Windgust', 'Wind_Gust_KmH']
        );
        [$regenrateID, $regenIdentSuffix] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationRegenVariableID'),
            $instanceID,
            'Regenrate',
            ['rainratein', 'rrain_piezo', 'rainin', 'Rain_Rate']
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
            $windIdentifier = 'wetterstation-windboe-' . $windIdentSuffix;
            if ($severity !== null) {
                $out[] = [
                    'identifier' => $windIdentifier,
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
            } elseif ($this->wetterstationIdentifierWasSeen($windIdentifier)) {
                // War zuvor aktiv, jetzt wieder ruhig -- eigenes "Cancel"
                // erzeugen, damit dieselbe Entwarnungs-Logik greift wie bei
                // amtlichen Warnungen (die eigene Wetterstation liefert sonst
                // nie ein Cancel, die Meldung verschwindet sonst nur still).
                $out[] = [
                    'identifier' => $windIdentifier,
                    'source' => 'wetterstation',
                    'msgType' => 'Cancel',
                    'event' => 'Sturm (eigene Messung)',
                    'headline' => 'Eigene Wetterstation: Windböe wieder unter dem Schwellwert',
                    'description' => '',
                    'instruction' => '',
                    'severity' => 'Extreme', // nur für den Standort-Schwellwert-Gate relevant, nicht angezeigt
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
            $regenIdentifier = 'wetterstation-regenrate-' . $regenIdentSuffix;
            if ($regenSeverity !== null) {
                $out[] = [
                    'identifier' => $regenIdentifier,
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
            } elseif ($this->wetterstationIdentifierWasSeen($regenIdentifier)) {
                $out[] = [
                    'identifier' => $regenIdentifier,
                    'source' => 'wetterstation',
                    'msgType' => 'Cancel',
                    'event' => 'Starkregen (eigene Messung)',
                    'headline' => 'Eigene Wetterstation: Regenrate wieder unter dem Schwellwert',
                    'description' => '',
                    'instruction' => '',
                    'severity' => 'Extreme',
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

    /**
     * Prüft, ob ein Wetterstations-Identifier für IRGENDEINEN Standort noch
     * als aktiv verfolgt wird (siehe fetchWetterstation()-Entwarnungslogik).
     * $seen ist seit der Umstellung auf episodenbasierte Schlüssel
     * (warningEpisodeKey(), Dietmars Meldung 08.09.2026 zu Push-Schwärmen)
     * nicht mehr NACH der identifier benannt -- die identifier steckt
     * stattdessen im Wert jedes Eintrags, dort wird gesucht.
     */
    private function wetterstationIdentifierWasSeen(string $identifier): bool
    {
        $seen = json_decode($this->ReadAttributeString('SeenWarnings'), true) ?: [];
        foreach ($seen as $entry) {
            if (($entry['identifier'] ?? null) === $identifier) {
                return true;
            }
        }
        return false;
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
            // forStandort sperrt jeden Datensatz HART auf genau diesen
            // Standort (siehe processWarnings()) -- sonst würde derselbe
            // reale ZAMG-Alarm bei mehreren eng beieinanderliegenden
            // Standorten (z. B. zwei mobile Standorte am selben Ort)
            // zusätzlich über den normalen Umkreis-Abgleich AUCH bei den
            // jeweils anderen matchen, obwohl schon ein eigener Datensatz je
            // Standort existiert -- dasselbe Vervielfachungs-Muster wie bei
            // fetchWaldbrandDe(), Praxis-Fund ruan/Andreas 07.09.2026.
            $eigene = array_map(
                fn ($w) => $w + ['forStandort' => $s['Name']],
                $this->fetchGeosphereAtCoords($coords['lat'], $coords['lon'])
            );
            $out = array_merge($out, $eigene);
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
            // meteotext (meteorologische Kurzerklärung, z. B. "Mit einer
            // stürmischen Nordwestströmung erreichen Sturmböen etwa 60 bis
            // 80 km/h") liefert die API zusätzlich zu auswirkungen/
            // empfehlungen mit, wurde bisher aber nicht ausgelesen -- Fund
            // von hfichtinger (unabhängiges Skript) im Symcon-Forum,
            // 06.09.2026, per eigener Testfixture (Fund von damals, echte
            // API-Antwort) bestätigt.
            $descriptionParts = array_filter([
                trim((string) ($props['meteotext'] ?? '')),
                trim((string) ($props['auswirkungen'] ?? '')),
                trim((string) ($props['empfehlungen'] ?? '')),
            ], fn ($part) => $part !== '');
            $description = implode(' ', $descriptionParts);
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
    //  Schweizerischer Erdbebendienst (SED) -- siehe Konstanten-Kommentar
    //  oben. EIN globaler Abruf für ganz Schweiz (analog BAFU/PEGELONLINE),
    //  die geometrische Umkreis-Prüfung in processWarnings() erledigt den
    //  Rest -- kein Standort-Filter beim Abruf selbst nötig.
    // ----------------------------------------------------------------

    private function fetchSedErdbebenCh(): array
    {
        $url = self::SED_EVENT_URL . '?' . http_build_query([
            'format' => 'text',
            'minmagnitude' => self::SED_MIN_MAGNITUDE,
            // Grobe Bounding-Box Schweiz + Grenzsaum -- deckt auch
            // grenznahe Epizentren ab, deren Umkreis noch Schweizer
            // Standorte erreichen kann.
            'minlatitude' => 45.6, 'maxlatitude' => 47.9,
            'minlongitude' => 5.7, 'maxlongitude' => 10.6,
            'starttime' => date('Y-m-d\TH:i:s', time() - 86400),
        ]);
        $body = $this->httpGet($url, 15, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            $this->LogError('fetchSedErdbebenCh', 'SED-Abfrage (eida.ethz.ch) nicht erreichbar.');
            return [];
        }
        return $this->parseSedResponse($body);
    }

    /** Vom HTTP-Abruf getrennt, damit sich die Auswertung ohne Netzzugriff testen lässt. */
    private function parseSedResponse(string $body): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue; // Kopfzeile/Leerzeile
            }
            $cols = explode('|', $line);
            if (count($cols) < 14) {
                continue; // unerwartetes Format -- lieber überspringen als raten
            }
            [$eventId, $time, $lat, $lon, $depth, , , , , , $magnitude, , $placeName, $eventType] = $cols;
            if (trim($eventType) !== 'earthquake') {
                continue;
            }
            $ts = strtotime(trim($time));
            if ($ts === false) {
                continue;
            }
            $magnitude = (float) $magnitude;
            $tier = $this->sedMagnitudeSeverity($magnitude);
            $place = trim($placeName) !== '' ? trim($placeName) : 'Schweiz';
            $out[] = [
                'identifier' => 'sed-' . trim($eventId),
                'source' => 'sed_ch',
                'msgType' => 'Alert',
                'event' => 'Erdbeben',
                'headline' => sprintf('Erdbeben Magnitude %.1f bei %s', $magnitude, $place),
                'description' => sprintf('Tiefe %.1f km, Epizentrum %s, gemessen %s Uhr (Schweizerischer Erdbebendienst).', (float) $depth, $place, date('d.m. H:i', $ts)),
                'instruction' => '',
                'severity' => $tier['severity'],
                'effective' => date('c', $ts),
                'onset' => date('c', $ts),
                'expires' => date('c', $ts + self::SED_ACTIVE_SECONDS),
                'areaDesc' => $place,
                'rings' => [],
                'circles' => [['lat' => (float) $lat, 'lon' => (float) $lon, 'radiusKm' => $tier['radiusKm']]],
            ];
        }
        return $out;
    }

    /**
     * Eigene, grobe Stufung nach Magnitude -- SED selbst liefert keine
     * Gefährdungs-/Warnstufe zum Ereignis, nur den reinen Messwert. Referenz-
     * Schwellen laut SED-Öffentlichkeitsarbeit: spürbar ab ca. 2.5, leichte
     * bis moderate Gebäudeschäden ab ca. 5. KEINE amtliche Klassifikation
     * (anders als z. B. BAFU_HYDRO_LEVEL_SEVERITY) -- ausdrücklich als
     * Näherung zu verstehen, auch beim Radius (grobe Faustregel für die
     * "spürbar"-Reichweite, keine seismologische Schadensflächen-Berechnung).
     * @return array{severity:string,radiusKm:float}
     */
    private function sedMagnitudeSeverity(float $magnitude): array
    {
        if ($magnitude >= 6.0) {
            return ['severity' => 'Extreme', 'radiusKm' => 120.0];
        }
        if ($magnitude >= 4.5) {
            return ['severity' => 'Severe', 'radiusKm' => 60.0];
        }
        if ($magnitude >= 3.5) {
            return ['severity' => 'Moderate', 'radiusKm' => 30.0];
        }
        return ['severity' => 'Minor', 'radiusKm' => 15.0];
    }

    // ----------------------------------------------------------------
    //  DWD-Waldbrandgefahrenindex (WBI) -- siehe Konstanten-Kommentar oben.
    //  Anders als bei den übrigen Quellen kein einzelner globaler Abruf --
    //  je deutschem Standort wird die nächstgelegene Station ermittelt und
    //  NUR deren aktueller Wert abgefragt (analog zum Muster von
    //  fetchGeosphereAt(): pro Standort iterieren statt ein Land komplett
    //  auf einmal abzurufen).
    // ----------------------------------------------------------------

    private function fetchWaldbrandDe(): array
    {
        $standorte = array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '');
        if (count($standorte) === 0) {
            return [];
        }
        if (!function_exists('gzdecode')) {
            $this->LogError('fetchWaldbrandDe', 'zlib-Erweiterung (gzdecode) fehlt -- kann die gezippten WBI-Stationsdateien nicht lesen.');
            return [];
        }
        $stations = $this->fetchWbiStationList();
        if ($stations === null) {
            $this->LogError('fetchWaldbrandDe', 'WBI-Stationsliste (opendata.dwd.de) nicht erreichbar.');
            return [];
        }
        $schwelle = $this->ReadPropertyInteger('WaldbrandDeSchwelle');
        $stationReadings = []; // Stationsindex => Ergebnis, dedupliziert je Aufruf (mehrere Standorte können dieselbe nächste Station teilen)
        $out = [];
        foreach ($standorte as $standort) {
            $coords = $this->resolveStandortCoords($standort);
            $geo = $this->reverseGeocodeStandort($coords['lat'], $coords['lon']);
            if ($geo['countryCode'] !== 'de') {
                continue;
            }
            $nearest = $this->findNearestWbiStation($stations, $coords['lat'], $coords['lon']);
            if ($nearest === null) {
                continue;
            }
            if (!array_key_exists($nearest['index'], $stationReadings)) {
                $stationReadings[$nearest['index']] = $this->fetchWbiStationCsv($nearest['index']);
            }
            $reading = $stationReadings[$nearest['index']];
            if ($reading === null || $reading['wbi'] < $schwelle) {
                continue;
            }
            $level = $reading['wbi'];
            $dateObj = DateTime::createFromFormat('Ymd', $reading['date']);
            $dateLabel = $dateObj !== false ? $dateObj->format('d.m.Y') : $reading['date'];
            $out[] = [
                'identifier' => 'waldbrand-de-' . $nearest['index'] . '-' . $reading['date'],
                'source' => 'waldbrand_de',
                'msgType' => 'Alert',
                'event' => 'Waldbrandgefahr',
                'headline' => sprintf('Waldbrandgefahrenindex Stufe %d von 5 (%s)', $level, self::WBI_LEVEL_LABEL[$level] ?? ''),
                'description' => sprintf(
                    'Amtlicher Waldbrandgefahrenindex, Stand %s. Nächstgelegene DWD-Messstation: %s (%.0f km entfernt).',
                    $dateLabel,
                    $nearest['name'],
                    $nearest['distanceKm']
                ),
                'instruction' => '',
                'severity' => self::WBI_LEVEL_SEVERITY[$level] ?? 'Moderate',
                'effective' => null,
                'onset' => null,
                'expires' => null,
                'areaDesc' => $standort['Name'],
                'rings' => [],
                // Kleiner Kreis exakt am Standort (wie bei GeoSphere Austria) --
                // die Stationsauswahl hat die Ortsfrage bereits beantwortet,
                // kein zusätzliches geometrisches Matching nötig. forStandort
                // sperrt diesen Datensatz zusätzlich HART auf genau diesen
                // Standort (siehe processWarnings()) -- ohne das würde er bei
                // eng beieinanderliegenden Standorten (z. B. mehrere mobile
                // Standorte am selben Ort) zusätzlich über den normalen
                // Umkreis-Abgleich AUCH bei den anderen matchen, obwohl er
                // schon "nur für diesen Standort" gedacht war -- ein
                // Datensatz je Standort UND ein Match je Standort macht dann
                // ein Vielfaches an Meldungen. Praxis-Fund ruan/Andreas,
                // Symcon-Forum, 07.09.2026 (1 Warnung + 3 eng beieinander
                // liegende Standorte -> 9 statt 3 Einträge).
                'forStandort' => $standort['Name'],
                'circles' => [['lat' => $coords['lat'], 'lon' => $coords['lon'], 'radiusKm' => 5.0]],
            ];
        }
        return $out;
    }

    private function fetchWbiStationList(): ?array
    {
        $body = $this->httpGet(self::WBI_STATION_LIST_URL, 15, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        return $body !== null ? $this->parseWbiStationList($body) : null;
    }

    /** Vom HTTP-Abruf getrennt, damit sich die Auswertung ohne Netzzugriff testen lässt. @return array<int,array{index:int,name:string,lat:float,lon:float}> */
    private function parseWbiStationList(string $body): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($body)) as $i => $line) {
            if ($i === 0 || trim($line) === '') {
                continue; // Kopfzeile
            }
            $cols = array_map('trim', explode(';', $line));
            if (count($cols) < 5) {
                continue;
            }
            [$index, , $lat, $lon, $name] = $cols;
            if (!is_numeric($index) || !is_numeric($lat) || !is_numeric($lon)) {
                continue;
            }
            $out[] = ['index' => (int) $index, 'name' => $name, 'lat' => (float) $lat, 'lon' => (float) $lon];
        }
        return $out;
    }

    /** @param array<int,array{index:int,name:string,lat:float,lon:float}> $stations @return ?array{index:int,name:string,lat:float,lon:float,distanceKm:float} */
    private function findNearestWbiStation(array $stations, float $lat, float $lon): ?array
    {
        $best = null;
        $bestDistance = INF;
        foreach ($stations as $s) {
            $d = WHUB_Geo::haversineKm($lat, $lon, $s['lat'], $s['lon']);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $s;
            }
        }
        if ($best === null) {
            return null;
        }
        return $best + ['distanceKm' => $bestDistance];
    }

    private function fetchWbiStationCsv(int $stationIndex): ?array
    {
        $url = sprintf(self::WBI_STATION_CSV_URL_TEMPLATE, $stationIndex);
        $body = $this->httpGet($url, 15, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            return null;
        }
        $csv = @gzdecode($body);
        if ($csv === false || $csv === '') {
            return null;
        }
        return $this->parseWbiStationCsv($csv);
    }

    /** Vom HTTP-Abruf/Entpacken getrennt, damit sich die Auswertung ohne Netzzugriff testen lässt. @return ?array{date:string,wbi:int} letzter verfügbarer Wert (Datum <= heute), null wenn leer. */
    private function parseWbiStationCsv(string $csv): ?array
    {
        $today = date('Ymd');
        $best = null;
        foreach (preg_split('/\r?\n/', trim($csv)) as $i => $line) {
            if ($i === 0 || trim($line) === '') {
                continue; // Kopfzeile "Stationsindex;Datum;WBI"
            }
            $cols = explode(';', $line);
            if (count($cols) < 3) {
                continue;
            }
            $datum = trim($cols[1]);
            if ($datum > $today) {
                continue; // vorsorglich -- die Quelle liefert regulär keine Zukunftswerte
            }
            $best = ['date' => $datum, 'wbi' => (int) trim($cols[2])];
        }
        return $best;
    }

    // ----------------------------------------------------------------
    //  Umweltbundesamt (UBA) Ozon-/Luftqualitätsdaten -- siehe Konstanten-
    //  Kommentar oben. EIN globaler Abruf für ganz Deutschland (analog
    //  BAFU/PEGELONLINE), die geometrische Umkreis-Prüfung in
    //  processWarnings() erledigt den Rest.
    // ----------------------------------------------------------------

    private function fetchOzonDe(): array
    {
        $stations = $this->fetchUbaStations();
        if ($stations === null) {
            $this->LogError('fetchOzonDe', 'UBA-Stationsliste (luftdaten.umweltbundesamt.de) nicht erreichbar.');
            return [];
        }
        $readings = $this->fetchUbaOzoneReadings();
        if ($readings === null) {
            $this->LogError('fetchOzonDe', 'UBA-Luftqualitätsdaten (luftdaten.umweltbundesamt.de) nicht erreichbar.');
            return [];
        }
        $schwelle = $this->ReadPropertyInteger('UbaOzonSchwelle');
        $out = [];
        foreach ($readings as $stationId => $reading) {
            if ($reading['index'] < $schwelle) {
                continue;
            }
            $station = $stations[$stationId] ?? null;
            if ($station === null) {
                continue; // nicht mehr aktive/unbekannte Station -- keine Koordinate vorhanden
            }
            $level = $reading['index'];
            $out[] = [
                'identifier' => 'ozon-de-' . $stationId . '-' . $reading['time'],
                'source' => 'ozon_de',
                'msgType' => 'Alert',
                'event' => 'Ozonbelastung',
                'headline' => sprintf('Ozon-Luftqualitätsindex Stufe %d von 4 (%s)', $level, self::UBA_LQI_LABEL[$level] ?? ''),
                'description' => sprintf(
                    'Ozonkonzentration %.0f µg/m³ (1-Stunden-Mittel) an der Messstation %s, Stand %s Uhr. Amtlicher Luftqualitätsindex des Umweltbundesamts.',
                    $reading['value'],
                    $station['name'],
                    substr($reading['time'], 11, 5)
                ),
                'instruction' => '',
                'severity' => self::UBA_LQI_SEVERITY[$level] ?? 'Moderate',
                'effective' => null,
                'onset' => null,
                'expires' => null,
                'areaDesc' => $station['name'],
                'rings' => [],
                'circles' => [['lat' => $station['lat'], 'lon' => $station['lon'], 'radiusKm' => 10.0]],
            ];
        }
        return $out;
    }

    private function fetchUbaStations(): ?array
    {
        $body = $this->httpGet(self::UBA_STATIONS_URL . '?lang=de', 20, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        return $body !== null ? $this->parseUbaStations($body) : null;
    }

    /**
     * Vom HTTP-Abruf getrennt, damit sich die Auswertung ohne Netzzugriff
     * testen lässt. Stationsarray-Reihenfolge laut offizieller Doku (siehe
     * Konstanten-Kommentar): 0=Id, 1=Code, 2=Name, 3=Ort, 4=Kürzel, 5=aktiv
     * ab, 6=aktiv bis (null=weiterhin aktiv), 7=Länge, 8=Breite. Stationen
     * mit gesetztem "aktiv bis" (nicht mehr in Betrieb) werden übersprungen
     * -- live gefunden: ein erheblicher Teil der gelisteten Stationen ist
     * historisch und liefert keine aktuellen Werte mehr.
     * @return array<string,array{name:string,lat:float,lon:float}>
     */
    private function parseUbaStations(string $body): array
    {
        $json = json_decode($body, true);
        $data = is_array($json) ? ($json['data'] ?? []) : [];
        $out = [];
        foreach ($data as $id => $row) {
            if (!is_array($row) || count($row) < 9) {
                continue;
            }
            if ($row[6] !== null) {
                continue; // Station nicht mehr aktiv
            }
            $out[(string) $id] = [
                'name' => (string) $row[2],
                'lat' => (float) $row[8],
                'lon' => (float) $row[7],
            ];
        }
        return $out;
    }

    private function fetchUbaOzoneReadings(): ?array
    {
        // NUR der heutige Tag (nicht zusätzlich gestern) -- ein Zwei-Tage-
        // Zeitraum über alle ~600 deutschen Stationen führte im Praxistest
        // 07.09.2026 wiederholt zu HTTP 504 (Server-Timeout, zu große
        // Antwort); ein Tag liefert dieselben ~294 KB in < 1s zuverlässig.
        // Kurz nach Mitternacht (vor der ersten Stunden-Veröffentlichung)
        // kann das Ergebnis leer sein -- korrigiert sich beim nächsten Poll
        // von selbst, sobald die erste Stunde des Tages vorliegt.
        $url = self::UBA_AIRQUALITY_URL . '?' . http_build_query([
            'date_from' => date('Y-m-d'),
            'time_from' => 1,
            'date_to' => date('Y-m-d'),
            'time_to' => 24,
            'lang' => 'de',
        ]);
        $body = $this->httpGet($url, 20, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        return $body !== null ? $this->parseUbaAirquality($body) : null;
    }

    /**
     * Vom HTTP-Abruf getrennt, damit sich die Auswertung ohne Netzzugriff
     * testen lässt. Je Station liefert die Quelle einen Zeitstempel je
     * abgefragter Stunde -- Symcon-JSON/PHP-Arrays erhalten dabei die
     * Einfügereihenfolge, die live geprüft chronologisch aufsteigend ist,
     * daher liefert der LETZTE Schlüssel den neuesten Wert. Nicht jede
     * Station misst Ozon -- fehlt die Komponente, wird die Station
     * übersprungen (kein Eintrag im Ergebnis).
     * @return array<string,array{value:float,index:int,time:string}>
     */
    private function parseUbaAirquality(string $body): array
    {
        $json = json_decode($body, true);
        $data = is_array($json) ? ($json['data'] ?? []) : [];
        $out = [];
        foreach ($data as $stationId => $timestamps) {
            if (!is_array($timestamps) || count($timestamps) === 0) {
                continue;
            }
            $latestKey = array_key_last($timestamps);
            $entry = $timestamps[$latestKey];
            if (!is_array($entry) || count($entry) < 3) {
                continue;
            }
            $ozone = null;
            foreach (array_slice($entry, 3) as $component) {
                if (is_array($component) && (int) ($component[0] ?? -1) === self::UBA_OZONE_COMPONENT_ID) {
                    $ozone = ['value' => (float) ($component[1] ?? 0), 'index' => (int) ($component[2] ?? 0)];
                    break;
                }
            }
            if ($ozone === null) {
                continue;
            }
            $out[(string) $stationId] = $ozone + ['time' => (string) $latestKey];
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
        if ($this->ReadPropertyBoolean('QuelleSedErdbebenCh')) {
            $warnings = array_merge($warnings, $this->fetchSedErdbebenCh());
        }
        if ($this->ReadPropertyBoolean('QuelleWaldbrandDe')) {
            $warnings = array_merge($warnings, $this->fetchWaldbrandDe());
        }
        if ($this->ReadPropertyBoolean('QuelleOzonDe')) {
            $warnings = array_merge($warnings, $this->fetchOzonDe());
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
        $this->refreshHeimLandCode();

        $this->WriteAttributeInteger('LastPollTs', time());
        $this->WriteAttributeString('LastActiveWarningsJson', json_encode($result['active']));
        $this->refreshStatusVariables();
        @$this->UpdateFormField('PollStatusLabel', 'caption', $this->getPollStatusLine());
        $restoreLine = $this->wetterstationRestoreStatusLine();
        if ($restoreLine !== null) {
            @$this->UpdateFormField('WetterstationRestoreStatusLabel', 'caption', $restoreLine);
        }

        $icon = $result['activeCount'] > 0 ? '⚠️' : '✅';
        $text = sprintf(
            '%s Prüfung abgeschlossen: %d aktive Warnung(en), %d neu gemeldet, %d hochgestuft, %d Entwarnung(en), %d Schutzaktion(en) ausgelöst.',
            $icon,
            $result['activeCount'],
            $result['newlyPushed'],
            $result['escalated'],
            $result['cancelled'],
            $result['actionsTriggered']
        );
        if ($result['fensterWarnungen'] > 0) {
            $text .= sprintf(' %d Fenster-/Tür-Warnung(en).', $result['fensterWarnungen']);
        }
        return $text;
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

    /**
     * Stabiler Schlüssel für eine "Warnungs-Episode" an einem Standort --
     * anders als die rohe CAP-`identifier`, die z. B. der DWD bei seinen
     * "Vorabinformationen vor Unwetter" (PVW) alle 15-30 Minuten NEU
     * vergibt, obwohl es inhaltlich dieselbe andauernde Gefahr ist (live
     * beobachtet: 6 verschiedene Identifier für "Starkes Gewitter" binnen
     * einer Stunde). Ein Dedup über `identifier` hätte bei jedem Reissue
     * erneut gepusht UND erneut Schutzaktionen ausgelöst -- Dietmars
     * Meldung 08.09.2026: "10 Meldungen auf einmal" bei einem einzigen
     * Push-Schwall. `event` (statt `headline`, die z. B. die Gültigkeit
     * enthalten kann) bleibt über Reissues hinweg stabil.
     */
    private function warningEpisodeKey(array $w, string $standortName): string
    {
        return $w['source'] . '|' . $w['event'] . '|' . $standortName;
    }

    /**
     * Wählt zwischen zwei $active-Einträgen DERSELBEN Episode (siehe
     * warningEpisodeKey()) denjenigen, der in "Kachel (Alle Warnungen)"
     * gezeigt werden soll -- der mit dem SPÄTEREN 'effective'/onset gilt als
     * der aktuellere, weil ein Reissue des DWD normalerweise die Gültigkeit
     * verlängert/aktualisiert. Bei gleichem oder nicht vergleichbarem
     * Zeitpunkt entscheidet der höhere Schweregrad, sonst bleibt der erste
     * (bereits vorhandene) Eintrag stehen -- stabil, kein zufälliges
     * Umspringen zwischen Polls.
     */
    private function pickMoreCurrentActiveEntry(array $a, array $b): array
    {
        $aTs = !empty($a['effective']) ? strtotime((string) $a['effective']) : false;
        $bTs = !empty($b['effective']) ? strtotime((string) $b['effective']) : false;
        if ($aTs !== false && $bTs !== false && $aTs !== $bTs) {
            return $bTs > $aTs ? $b : $a;
        }
        $aRank = self::SEVERITY_RANK[$a['severity'] ?? 'Unknown'] ?? 0;
        $bRank = self::SEVERITY_RANK[$b['severity'] ?? 'Unknown'] ?? 0;
        return $bRank > $aRank ? $b : $a;
    }

    private function processWarnings(array $warnings): array
    {
        $standorte = array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '');
        $seen = json_decode($this->ReadAttributeString('SeenWarnings'), true) ?: [];
        $fired = json_decode($this->ReadAttributeString('FiredActions'), true) ?: [];
        $actions = array_filter($this->decodeSchutzaktionen(), fn ($a) => $a['Aktiv']);
        $fensterkontakte = array_filter($this->decodeFensterkontakte(), fn ($k) => $k['Aktiv'] && $k['VariableID'] > 0);
        $seenFenster = json_decode($this->ReadAttributeString('SeenFensterWarnungen'), true) ?: [];
        $pushSound = $this->ReadPropertyString('PushSound');
        // Snooze pausiert NUR die tatsächliche Zustellung -- Erkennung,
        // Warnungs-Historie und Schutzaktionen laufen unverändert weiter
        // (Dietmars Wunsch 04.09.2026: z. B. im Urlaub weiterhin
        // automatisch schützen, nur nicht ständig benachrichtigt werden).
        $pushAktiv = $this->ReadPropertyBoolean('PushAktiv') && !$this->isPushSnoozed();

        $stillPresentEpisodes = [];
        // Je Episode NUR der aktuellste Eintrag -- der DWD reißt bei seinen
        // "Vorabinformationen vor Unwetter" die CAP-Update-Kette faktisch ab
        // und schickt für dieselbe andauernde Gefahr alle 15-30 Minuten eine
        // NEUE identifier statt eines Updates der alten (siehe
        // warningEpisodeKey()); ohne Deduplizierung hier erschienen mehrere
        // fast identische Karten für ein und dasselbe Ereignis in "Kachel
        // (Alle Warnungen)" -- sowohl der offizielle DWD-Auftritt als auch
        // ein zum Vergleich herangezogenes drittes Symcon-Modul zeigen dafür
        // nur EINEN Eintrag. Praxis-Fund ruan, Symcon-Forum, 09.09.2026.
        $activeByEpisode = [];
        $newlyPushed = 0;
        $escalated = 0;
        $cancelled = 0;
        $actionsTriggered = 0;
        $fensterWarnungen = 0;
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
                // Manche Quellen (WBI/GeoSphere Austria) liefern schon einen
                // EIGENEN Datensatz JE STANDORT (Stationsauswahl/API-Abfrage
                // ist bereits standortspezifisch) und markieren ihn mit
                // forStandort. So ein Datensatz darf NIE zusätzlich über den
                // normalen Umkreis-/Namensabgleich unten bei einem ANDEREN
                // Standort matchen, sonst vervielfacht sich die Meldung bei
                // eng beieinanderliegenden Standorten (ein Datensatz je
                // Standort UND ein Umkreis-Match je Standort). Praxis-Fund
                // ruan/Andreas, Symcon-Forum, 07.09.2026.
                if (isset($w['forStandort']) && $w['forStandort'] !== $standort['Name']) {
                    continue;
                }
                $episodeKey = $this->warningEpisodeKey($w, $standort['Name']);
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

                $stillPresentEpisodes[$episodeKey] = true;

                if ($w['msgType'] === 'Cancel') {
                    if (isset($seen[$episodeKey])) {
                        unset($seen[$episodeKey]);
                        $this->logHistory('entwarnung', $standort['Name'], $w['event'], $w['headline'], $w['severity'], $category, $w['source']);
                        if ($pushAktiv) {
                            $this->pushToAllWebfronts(
                                '✅ Entwarnung',
                                $standort['Name'] . ': ' . $w['headline'] . ' aufgehoben.',
                                $pushSound,
                                $pushZiele
                            );
                        }
                        $cancelled++;
                    }
                    continue;
                }

                $activeEntry = [
                    'identifier' => $w['identifier'],
                    'standort' => $standort['Name'],
                    'event' => $w['event'],
                    'headline' => $w['headline'],
                    'description' => $w['description'],
                    'instruction' => $w['instruction'] ?? '',
                    'severity' => $w['severity'],
                    'category' => $category,
                    'source' => $w['source'],
                    'distanceKm' => $distanceKm !== null ? round($distanceKm, 1) : null,
                    'nameMatched' => $nameMatched,
                    'effective' => $w['effective'],
                    'expires' => $w['expires'],
                ];
                $activeByEpisode[$episodeKey] = isset($activeByEpisode[$episodeKey])
                    ? $this->pickMoreCurrentActiveEntry($activeByEpisode[$episodeKey], $activeEntry)
                    : $activeEntry;

                // Erneut pushen, wenn die Meldung NEU ist (auch: eine neue
                // Episode nach einer ECHTEN Lücke -- die vorige ist
                // abgelaufen, bevor diese beginnt) ODER seit dem letzten
                // Push tatsächlich HOCHGESTUFT wurde (z. B. DWD verschärft
                // eine laufende Sturmwarnung von Moderate auf Severe).
                // Reissues derselben Episode mit fortlaufender/überlappender
                // Gültigkeit (siehe warningEpisodeKey()) aktualisieren den
                // gemerkten Zustand nur still, OHNE erneut zu pushen --
                // sonst pusht z. B. jede der alle 15-30 Minuten neu
                // vergebenen DWD-PVW-Identifier erneut, obwohl es dieselbe
                // andauernde Gefahr ist (Dietmars Meldung 08.09.2026: "10
                // Meldungen auf einmal"). Eine Abstufung pusht bewusst
                // NICHT erneut (keine dringende Nachricht), hält die
                // gespeicherte Severity aber aktuell, damit ein SPÄTERES
                // erneutes Ansteigen auf denselben Wert wieder zählt.
                $seenEntry = $seen[$episodeKey] ?? null;
                $prevExpiresTs = $seenEntry !== null && !empty($seenEntry['expires']) ? strtotime((string) $seenEntry['expires']) : false;
                $currentStartTs = !empty($w['onset']) ? strtotime((string) $w['onset']) : (!empty($w['effective']) ? strtotime((string) $w['effective']) : false);
                $isGenuineGap = $seenEntry !== null && $prevExpiresTs !== false && $currentStartTs !== false && $prevExpiresTs < $currentStartTs;
                $isNewWarning = $seenEntry === null || $isGenuineGap;
                $isEscalation = !$isNewWarning && $this->severityRank($w['severity']) > $this->severityRank($seenEntry['severity'] ?? 'Unknown');
                if ($isNewWarning || $isEscalation) {
                    $seen[$episodeKey] = ['identifier' => $w['identifier'], 'msgType' => $w['msgType'], 'pushedAt' => time(), 'severity' => $w['severity'], 'expires' => $w['expires']];
                    $this->logHistory($isNewWarning ? 'warnung' : 'eskalation', $standort['Name'], $w['event'], $w['headline'], $w['severity'], $category, $w['source']);
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
                    $seen[$episodeKey]['severity'] = $w['severity'];
                    $seen[$episodeKey]['identifier'] = $w['identifier'];
                    $seen[$episodeKey]['expires'] = $w['expires'] ?? $seenEntry['expires'] ?? null;
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
                    // Über die Episode (nicht die rohe, bei Reissues
                    // wechselnde identifier) gekeyt -- sonst würde z. B. ein
                    // alle 15-30 Minuten neu vergebener DWD-PVW-Identifier
                    // dieselbe Schutzaktion (Jalousie/Markise/Garage) erneut
                    // auslösen, obwohl die andauernde Gefahr unverändert ist
                    // (Dietmars Meldung 08.09.2026, dieselbe Ursache wie beim
                    // Push-Schwall).
                    $fireKey = $episodeKey . '|' . $idx;
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

                // Fenster-/Tür-Überwachung: kein Schalten, nur eine Warnung,
                // wenn der Kontakt offen ist -- dieselben Standort-/Kategorie-/
                // Schweregrad-Regeln wie bei Schutzaktionen (inkl. Sperre für
                // unfilterte mobile Standorte, siehe oben), aber KEIN
                // $fired-Dedupe (das bliebe für immer gesetzt) -- stattdessen
                // eigener $seenFenster-Speicher, der weiter unten geleert
                // wird, sobald der jeweilige Kontakt wieder geschlossen ist,
                // damit ein erneutes Öffnen während derselben Warnung wieder
                // eine Meldung auslöst.
                foreach ($fensterkontakte as $kontakt) {
                    if ($kontakt['StandortFilter'] !== '' && $kontakt['StandortFilter'] !== $standort['Name']) {
                        continue;
                    }
                    if ($kontakt['StandortFilter'] === '' && $this->isStandortMobil($standort)) {
                        continue;
                    }
                    if (count($kontakt['Kategorien']) > 0 && !in_array($category, $kontakt['Kategorien'], true)) {
                        continue;
                    }
                    if ($this->severityRank($w['severity']) < $kontakt['MinSeverity']) {
                        continue;
                    }
                    if ($this->isContactOpen($kontakt['VariableID']) !== true) {
                        continue;
                    }
                    // Ebenfalls episodenbasiert -- sonst würde ein bereits
                    // offener Fensterkontakt bei jedem DWD-Reissue erneut
                    // gemeldet, obwohl er die ganze Zeit unverändert offen war.
                    $fensterKey = $episodeKey . '|' . $kontakt['Name'];
                    if (isset($seenFenster[$fensterKey])) {
                        continue;
                    }
                    $seenFenster[$fensterKey] = true;
                    $this->logHistory('fensterwarnung', $kontakt['Name'], $w['event'], $w['headline'], $w['severity'], $category, $w['source']);
                    if ($pushAktiv) {
                        $this->pushToAllWebfronts(
                            '⚠️ Fenster/Tür offen',
                            $kontakt['Name'] . ' ist offen -- ' . $w['headline'] . '. Bitte schließen.',
                            $pushSound
                        );
                    }
                    $fensterWarnungen++;
                }
            }
        }

        // Fenster-/Tür-Kontakte, die inzwischen wieder geschlossen sind: den
        // Verlauf für GENAU diesen Kontakt zurücksetzen (unabhängig davon,
        // zu welcher Warnung er gehörte), damit ein SPÄTERES erneutes Öffnen
        // während derselben noch laufenden Warnung wieder eine Meldung
        // auslöst -- anders als bei $seen/$fired oben gibt es hier keinen
        // "Warnung nicht mehr vorhanden"-Anlass zum Aufräumen, sondern den
        // Kontakt-Zustand selbst.
        foreach ($fensterkontakte as $kontakt) {
            if ($this->isContactOpen($kontakt['VariableID']) === true) {
                continue;
            }
            foreach (array_keys($seenFenster) as $key) {
                if (str_ends_with($key, '|' . $kontakt['Name'])) {
                    unset($seenFenster[$key]);
                }
            }
        }
        $this->WriteAttributeString('SeenFensterWarnungen', json_encode($seenFenster));

        // Episoden, die im aktuellen Abruf nicht mehr auftauchen (abgelaufen/
        // aus der Quelle entfernt), still aus dem "gesehen"-/"ausgelöst"-
        // Speicher nehmen -- kein Cancel-Ereignis vorhanden, daher keine
        // Entwarnungs-Push, nur Bereinigung, damit der Zustand nicht
        // dauerhaft "aktiv" bleibt.
        foreach (array_keys($seen) as $episodeKey) {
            if (!isset($stillPresentEpisodes[$episodeKey])) {
                unset($seen[$episodeKey]);
            }
        }
        foreach (array_keys($fired) as $fireKey) {
            // $fireKey = episodeKey . '|' . $idx -- der Aktions-Index hängt
            // hinten dran, daher am LETZTEN '|' trennen (die episodeKey
            // selbst enthält bereits '|' als Trenner zwischen source/event/
            // Standort).
            $lastPipe = strrpos($fireKey, '|');
            $episodeKey = $lastPipe !== false ? substr($fireKey, 0, $lastPipe) : $fireKey;
            if (!isset($stillPresentEpisodes[$episodeKey])) {
                unset($fired[$fireKey]);
            }
        }

        $this->WriteAttributeString('SeenWarnings', json_encode($seen));
        $this->WriteAttributeString('FiredActions', json_encode($fired));

        $active = array_values($activeByEpisode);

        return [
            'active' => $active,
            'activeCount' => count($active),
            'newlyPushed' => $newlyPushed,
            'escalated' => $escalated,
            'cancelled' => $cancelled,
            'actionsTriggered' => $actionsTriggered,
            'fensterWarnungen' => $fensterWarnungen,
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

    /** Voller, unabgekürzter Titel -- die 32-Byte-Kürzung gilt nur für WFC_PushNotification/VISU_PostNotificationEx, siehe buildPushText(). */
    private function buildPushTitle(string $severity, string $event): string
    {
        $icon = self::SEVERITY_ICON[$severity] ?? '⚠️';
        $label = mb_convert_case(mb_strtolower(trim($event) !== '' ? $event : 'Warnung'), MB_CASE_TITLE);
        return $icon . ' ' . $label;
    }

    /**
     * Liefert den VOLLSTÄNDIGEN, unabgekürzten Push-Text -- die Kürzung auf
     * 256 Byte ist NUR eine Grenze von WFC_PushNotification/
     * VISU_PostNotificationEx (WebFront/Kachel-Visualisierung) und wird
     * deshalb erst in pushToAllWebfronts() unmittelbar vor genau diesen
     * beiden Aufrufen angewendet, nicht mehr hier zentral. Telegram
     * (~4096 Zeichen erlaubt), Pushover (~1024 Zeichen) und vor allem
     * E-Mail (keine bekannte Längenbeschränkung) bekamen bis 1.12.3
     * denselben künstlich gekappten Text, obwohl nur WebFront/Kachel-
     * Visualisierung das überhaupt bräuchten -- Praxis-Fund hfichtinger,
     * Symcon-Forum, 09.09.2026: "Ist das auch den 256 Zeichen geschuldet?
     * [...] Mail kann auch mehr".
     *
     * Reihenfolge bewusst NICHT die Lesereihenfolge einer CAP-Meldung:
     * Handlungsempfehlung + Gültigkeit stehen VOR der (oft langen)
     * Beschreibung, damit sie bei den weiterhin gekappten Kanälen die
     * Kürzung eher überleben (Praxis-Fund kronos, Symcon-Forum,
     * 09.09.2026).
     */
    private function buildPushText(string $standortName, array $w, bool $nameMatched = false): string
    {
        $text = $standortName . ': ' . $w['headline'];
        // Handlungsempfehlung der Quelle (CAP <instruction>, z. B. "Meiden Sie
        // den Aufenthalt im Wald") -- echter, unmittelbar nutzbarer Inhalt,
        // kein Eigenwert.
        if (!empty($w['instruction'])) {
            $text .= '. ' . $w['instruction'];
        }
        if ($w['expires'] !== null) {
            $text .= ' Gültig bis ' . $this->formatDateDe($w['expires']) . ' Uhr.';
        }
        // Meteoalarm-Namensabgleich statt Geometrie -- Transparenz statt
        // vorgetäuschter Präzision (siehe fetchMeteoalarmCountry()).
        if ($nameMatched) {
            $text .= ' (Namensabgleich, keine Warnfläche verfügbar.)';
        }
        if ($w['description'] !== '') {
            $text .= ' ' . $w['description'];
        }
        return $text;
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
.whub-maplinks{display:flex;gap:12px;margin-top:10px;padding-top:8px;border-top:1px solid rgba(0,0,0,0.08);}
.whub-maplinks a{font-size:11px;font-weight:600;text-decoration:none;color:inherit;opacity:0.65;}
@media (prefers-color-scheme:dark){.whub-maplinks{border-top-color:rgba(255,255,255,0.12);}}
.whub-map-legend{flex:0 0 auto;display:flex;flex-wrap:wrap;gap:6px;padding:9px 10px;}
.whub-map-pill{display:flex;align-items:center;gap:6px;padding:6px 11px;border-radius:999px;font-size:12px;font-weight:600;color:#1D1D1F;background:rgba(0,0,0,0.06);cursor:pointer;white-space:nowrap;}
.whub-map-pill.whub-active{background:rgba(10,132,255,0.92);color:#fff;}
.whub-map-pill-dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto;}
@media (prefers-color-scheme:dark){.whub-map-pill{color:#F5F5F7;background:rgba(255,255,255,0.12);}}
.whub-allwarn-list{max-height:420px;overflow-y:auto;}
.whub-card-dismiss{position:absolute;top:8px;right:10px;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;background:rgba(0,0,0,0.08);opacity:0.55;cursor:pointer;line-height:1;}
.whub-card-dismiss:hover{opacity:1;}
@media (prefers-color-scheme:dark){.whub-card-dismiss{background:rgba(255,255,255,0.14);}}
.whub-allwarn-reset{font-size:11.5px;font-weight:600;text-align:center;padding:10px 0 2px 0;opacity:0.65;cursor:pointer;text-decoration:underline;}
.whub-card-expandable{cursor:pointer;}
.whub-card-chevron{float:right;margin-right:16px;opacity:0.4;font-size:12px;transition:transform 0.15s ease;}
.whub-card.whub-expanded .whub-card-chevron{transform:rotate(90deg);}
.whub-card-detail{margin-top:8px;padding-top:8px;border-top:1px solid rgba(0,0,0,0.08);font-size:12px;line-height:1.45;opacity:0.85;}
@media (prefers-color-scheme:dark){.whub-card-detail{border-top-color:rgba(255,255,255,0.12);}}
.whub-card-detail-instruction{font-weight:600;margin-bottom:5px;}
.whub-card-detail-validity{opacity:0.7;margin-bottom:5px;}
/* .whub-card/.whub-empty setzen selbst display:flex -- ohne diese Regel
   gewinnt das gegen das versteckende [hidden]-Attribut (gleiche Spezifität,
   Autoren-CSS schlägt die Browser-Vorgabe), das JS-gesteuerte Ausblenden in
   "Kachel (Alle Warnungen)" (c.hidden = ...) hätte dann optisch GAR NICHTS
   bewirkt -- live im Browser gefunden, bevor es ausgeliefert wurde. */
.whub-card[hidden],.whub-empty[hidden]{display:none;}
</style>
CSS;
    }

    /**
     * Kompakte Link-Zeile zu den drei amtlichen Warnkarten (DWD/ZAMG/
     * MeteoSchweiz) -- echte externe Links (kein iframe), deshalb unabhängig
     * von der X-Frame-Options-Sperre, die DWD und MeteoSchweiz für eine
     * Einbettung setzen (siehe Konstanten-Kommentar oben). Allgemeine,
     * nicht personalisierte Landkarten -- immer alle drei gezeigt statt
     * nach Standort zu filtern, kein Mehrwert durch Auswahl. Dietmars
     * Wunsch 06.09.2026.
     */
    private function officialMapLinksHtml(): string
    {
        return '<div class="whub-maplinks">'
            . '<a href="' . htmlspecialchars(self::DWD_MAP_URL) . '" target="_blank" rel="noopener">🇩🇪 DWD</a>'
            . '<a href="' . htmlspecialchars(self::ZAMG_MAP_URL) . '" target="_blank" rel="noopener">🇦🇹 ZAMG</a>'
            . '<a href="' . htmlspecialchars(self::METEOSWISS_MAP_URL) . '" target="_blank" rel="noopener">🇨🇭 MeteoSchweiz</a>'
            . '</div>';
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
        // Absolute Uhrzeit statt relativer Minutenangabe (Fix Praxis-Fund
        // ralf, Symcon-Forum 05.09.2026): relativeMinutesText() wurde IMMER
        // direkt im selben Poll()-Durchlauf berechnet, der LastPollTs auch
        // erst auf time() gesetzt hat -- die "verstrichene Zeit" war dadurch
        // rechnerisch stets ~0 und die Kachel blieb bis zum nächsten Poll
        // für den Betrachter fälschlich bei "gerade eben geprüft" stehen,
        // ganz gleich wie lange der letzte Check tatsächlich zurücklag.
        // Eine echte "vor X Min." Angabe bräuchte eine client-seitig
        // tickende Uhr (JS in der Kachel); die Übersichts-Kachel zeigt aus
        // demselben Grund schon länger die absolute Uhrzeit statt Relativzeit.
        $sub = $lastTs > 0 ? htmlspecialchars(date('H:i', $lastTs)) . ' Uhr geprüft' : 'noch nie geprüft';
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
  {$this->officialMapLinksHtml()}
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
            // Nach Schweregrad sortiert VOR dem Deckeln auf 8 -- sonst könnte
            // bei mehr als 8 aktiven Warnungen ausgerechnet die wichtigste
            // hinter "+N weitere" verschwinden, nur weil sie zufällig weiter
            // hinten in $active stand. Dietmars Einwand 07.09.2026: "die 10.
            // Meldung ist die wichtigste ... kann dann ausgerechnet die nicht
            // erreichen". Stabile Sortierung (usort ist seit PHP 8 stabil) --
            // bei gleichem Schweregrad bleibt die ursprüngliche Reihenfolge
            // erhalten.
            $sortedActive = $active;
            usort($sortedActive, fn ($a, $b) => (self::SEVERITY_RANK[$b['severity'] ?? 'Unknown'] ?? 0) <=> (self::SEVERITY_RANK[$a['severity'] ?? 'Unknown'] ?? 0));
            $shown = array_slice($sortedActive, 0, 8);
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
  {$this->officialMapLinksHtml()}
</div>
HTML;
    }

    /**
     * Vollständige, scrollbare Warnungsliste OHNE 8er-Deckel -- Ergänzung zu
     * "Kachel (Übersicht)" (die bleibt bewusst als kompakte Momentaufnahme
     * fürs Dashboard), Dietmars Einwand 07.09.2026: "baue das so, dass man
     * auch die 100. Meldung noch ansehen könnte". Jede Karte hat einen
     * eigenen "✕"-Button zum Ausblenden, dazu eine Filterleiste mit einem
     * Chip je aktuell vorkommendem Ereignistyp ("🚫 Waldbrandgefahr" etc.) --
     * beides rein clientseitig in localStorage gemerkt, GENAU WIE Dietmars
     * Entscheidung 07.09.2026 ("pro Browser/Gerät", analog zum Zoom-/Fokus-
     * Merken bei "Kachel (Karte)"): kein Rückkanal zu Symcon, jeder
     * Betrachter blendet nur für sich selbst aus. Ausgeblendete Warnungs-
     * Schlüssel, die beim nächsten Rendern gar nicht mehr vorkommen (Warnung
     * inzwischen abgelaufen), werden aus localStorage entfernt statt sich
     * unbegrenzt anzusammeln. Push, Historie und Schutzaktionen sind von
     * dieser rein optischen Ausblendung komplett unberührt -- die laufen
     * serverseitig auf der VOLLEN Liste weiter.
     */
    private function renderKachelAlleWarnungen(array $active, int $lastTs, bool $snoozed = false): string
    {
        $time = $lastTs > 0 ? htmlspecialchars(date('H:i', $lastTs)) . ' Uhr' : '--:--';
        if ($snoozed) {
            $time = '🔕 ' . $time;
        }
        $listId = 'whuballwarn' . substr(md5((string) $this->InstanceID), 0, 10);

        if (count($active) === 0) {
            $body = '<div class="whub-empty"><div class="whub-empty-icon">✅</div><div class="whub-empty-text">Keine aktive Warnung</div></div>';
            return $this->tileStyleBlock() . <<<HTML
<div class="whub-overview">
  <div class="whub-header">
    <span class="whub-header-icon">🛡️</span>
    <span class="whub-header-title">WarnHub -- Alle Warnungen</span>
    <span class="whub-header-time">{$time}</span>
  </div>
  {$body}
  {$this->officialMapLinksHtml()}
</div>
HTML;
        }

        // Wie bei "Kachel (Übersicht)" nach Schweregrad sortiert -- hier
        // zwar ohne Deckel, aber die wichtigsten Warnungen sollen trotzdem
        // ganz oben stehen, nicht in Ankunftsreihenfolge verstreut.
        $sortedActive = $active;
        usort($sortedActive, fn ($a, $b) => (self::SEVERITY_RANK[$b['severity'] ?? 'Unknown'] ?? 0) <=> (self::SEVERITY_RANK[$a['severity'] ?? 'Unknown'] ?? 0));

        $eventTypes = [];
        $cards = '';
        foreach ($sortedActive as $w) {
            $severity = $w['severity'] ?? 'Unknown';
            $color = self::TILE_SEVERITY_COLOR[$severity] ?? self::TILE_SEVERITY_COLOR['Unknown'];
            $icon = self::SEVERITY_ICON[$severity] ?? 'ℹ️';
            $eventRaw = trim($w['event'] ?? '') !== '' ? $w['event'] : 'Warnung';
            $eventLabel = htmlspecialchars(mb_convert_case(mb_strtolower($eventRaw), MB_CASE_TITLE));
            $eventTypes[$eventRaw] = true;
            $sub = htmlspecialchars($w['standort'] ?? '');
            if (!empty($w['expires'])) {
                $expTs = strtotime((string) $w['expires']);
                if ($expTs !== false) {
                    $sub .= ' · bis ' . htmlspecialchars(date('H:i', $expTs)) . ' Uhr';
                }
            }
            // Schlüssel wie SeenWarnings/$pairKey (identifier|standort) --
            // dieselbe Warnung an DIESEM Standort bleibt über Prüfungen
            // hinweg stabil identifizierbar, auch wenn sie an einem anderen
            // Standort ebenfalls aktiv ist (eigener Schlüssel je Standort).
            $key = htmlspecialchars(($w['identifier'] ?? '') . '|' . ($w['standort'] ?? ''));
            $eventAttr = htmlspecialchars($eventRaw);

            // Push-Benachrichtigungen kappen laut Symcon-Doku hart bei
            // 256 Byte (siehe truncateBytes()) -- der volle, unabgekürzte
            // Text (Handlungsempfehlung + Gültigkeitszeitraum + komplette
            // amtliche Beschreibung) ist deshalb hier zusätzlich per Klick
            // auf die Karte abrufbar. Praxis-Wunsch kronos, Symcon-Forum,
            // 09.09.2026.
            $detailParts = [];
            if (!empty($w['instruction'])) {
                $detailParts[] = '<div class="whub-card-detail-instruction">' . nl2br(htmlspecialchars($w['instruction'])) . '</div>';
            }
            $validity = '';
            if (!empty($w['effective'])) {
                $effTs = strtotime((string) $w['effective']);
                if ($effTs !== false) {
                    $validity .= 'Beginn: ' . htmlspecialchars(date('d.m. H:i', $effTs)) . ' Uhr. ';
                }
            }
            if (!empty($w['expires'])) {
                $expTs = strtotime((string) $w['expires']);
                if ($expTs !== false) {
                    $validity .= 'Gültig bis: ' . htmlspecialchars(date('d.m. H:i', $expTs)) . ' Uhr.';
                }
            }
            if ($validity !== '') {
                $detailParts[] = '<div class="whub-card-detail-validity">' . trim($validity) . '</div>';
            }
            if (!empty($w['description'])) {
                $detailParts[] = '<div class="whub-card-detail-desc">' . nl2br(htmlspecialchars($w['description'])) . '</div>';
            }
            $hasDetail = count($detailParts) > 0;
            $detailBlock = $hasDetail ? '<div class="whub-card-detail" hidden>' . implode('', $detailParts) . '</div>' : '';
            $expandableClass = $hasDetail ? ' whub-card-expandable' : '';
            $chevron = $hasDetail ? '<span class="whub-card-chevron">›</span>' : '';

            $cards .= <<<HTML
<div class="whub-card{$expandableClass}" data-key="{$key}" data-event="{$eventAttr}" style="border-left-color:{$color};position:relative;padding-right:36px;">
  <div class="whub-card-icon">{$icon}</div>
  <div class="whub-card-body">
    <div class="whub-card-title">{$eventLabel}{$chevron}</div>
    <div class="whub-card-sub">{$sub}</div>
    {$detailBlock}
  </div>
  <div class="whub-card-dismiss" title="Ausblenden">✕</div>
</div>
HTML;
        }

        $filterChips = '';
        foreach (array_keys($eventTypes) as $eventRaw) {
            $label = htmlspecialchars(mb_convert_case(mb_strtolower($eventRaw), MB_CASE_TITLE));
            $eventAttr = htmlspecialchars($eventRaw);
            $filterChips .= <<<HTML
<div class="whub-map-pill" data-event="{$eventAttr}">🚫 {$label}</div>
HTML;
        }

        return $this->tileStyleBlock() . <<<HTML
<div class="whub-overview">
  <div class="whub-header">
    <span class="whub-header-icon">🛡️</span>
    <span class="whub-header-title">WarnHub -- Alle Warnungen</span>
    <span class="whub-header-time">{$time}</span>
  </div>
  <div id="{$listId}filterbar" class="whub-map-legend" style="padding:0 0 10px 0;">{$filterChips}</div>
  <div id="{$listId}" class="whub-allwarn-list">{$cards}</div>
  <div id="{$listId}empty" class="whub-empty" hidden style="margin-top:8px;">
    <div class="whub-empty-icon">🙈</div>
    <div class="whub-empty-text">Alle aktiven Warnungen ausgeblendet</div>
  </div>
  <div id="{$listId}reset" class="whub-allwarn-reset">Alle wieder einblenden</div>
  {$this->officialMapLinksHtml()}
  <script>
  (function(){
    var list = document.getElementById('{$listId}');
    var filterbar = document.getElementById('{$listId}filterbar');
    var emptyFiltered = document.getElementById('{$listId}empty');
    var resetBtn = document.getElementById('{$listId}reset');
    if (!list) { return; }
    var cards = Array.prototype.slice.call(list.querySelectorAll('.whub-card'));
    if (!cards.length) { return; }

    var dismissKey = 'whub-allwarn-dismissed-{$listId}';
    var muteKey = 'whub-allwarn-muted-{$listId}';
    var dismissed = [];
    var muted = [];
    try {
      dismissed = JSON.parse(localStorage.getItem(dismissKey) || '[]');
      muted = JSON.parse(localStorage.getItem(muteKey) || '[]');
    } catch (e) {}
    if (!Array.isArray(dismissed)) { dismissed = []; }
    if (!Array.isArray(muted)) { muted = []; }

    // Gespeicherte Ausblend-Schlüssel, die es jetzt gar nicht mehr gibt
    // (Warnung inzwischen abgelaufen), aufräumen statt für immer behalten.
    var currentKeys = cards.map(function (c) { return c.getAttribute('data-key'); });
    dismissed = dismissed.filter(function (k) { return currentKeys.indexOf(k) !== -1; });
    try { localStorage.setItem(dismissKey, JSON.stringify(dismissed)); } catch (e) {}

    function applyVisibility() {
      var anyVisible = false;
      cards.forEach(function (c) {
        var hide = dismissed.indexOf(c.getAttribute('data-key')) !== -1 || muted.indexOf(c.getAttribute('data-event')) !== -1;
        c.hidden = hide;
        if (!hide) { anyVisible = true; }
      });
      if (emptyFiltered) { emptyFiltered.hidden = anyVisible; }
      if (filterbar) {
        Array.prototype.slice.call(filterbar.querySelectorAll('.whub-map-pill[data-event]')).forEach(function (p) {
          p.classList.toggle('whub-active', muted.indexOf(p.getAttribute('data-event')) !== -1);
        });
      }
    }

    cards.forEach(function (c) {
      var btn = c.querySelector('.whub-card-dismiss');
      if (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation(); // nicht gleichzeitig die Detailansicht auf-/zuklappen
          var key = c.getAttribute('data-key');
          if (dismissed.indexOf(key) === -1) { dismissed.push(key); }
          try { localStorage.setItem(dismissKey, JSON.stringify(dismissed)); } catch (e2) {}
          applyVisibility();
        });
      }
      // Volltext (Handlungsempfehlung/Gültigkeit/Beschreibung) per Klick
      // auf-/zuklappen -- Push-Benachrichtigungen kappen hart bei 256 Byte,
      // hier steht der komplette Text (Praxis-Wunsch kronos, Symcon-Forum,
      // 09.09.2026).
      if (c.classList.contains('whub-card-expandable')) {
        var detail = c.querySelector('.whub-card-detail');
        if (detail) {
          c.addEventListener('click', function () {
            detail.hidden = !detail.hidden;
            c.classList.toggle('whub-expanded', !detail.hidden);
          });
        }
      }
    });

    if (filterbar) {
      Array.prototype.slice.call(filterbar.querySelectorAll('.whub-map-pill[data-event]')).forEach(function (p) {
        p.addEventListener('click', function () {
          var ev = p.getAttribute('data-event');
          var idx = muted.indexOf(ev);
          if (idx === -1) { muted.push(ev); } else { muted.splice(idx, 1); }
          try { localStorage.setItem(muteKey, JSON.stringify(muted)); } catch (e) {}
          applyVisibility();
        });
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        dismissed = [];
        muted = [];
        try { localStorage.removeItem(dismissKey); localStorage.removeItem(muteKey); } catch (e) {}
        applyVisibility();
      });
    }

    applyVisibility();
  })();
  </script>
</div>
HTML;
    }

    /**
     * Übersichts-Karten-Kachel (Kachelbilder via Leaflet.js, CDN) -- einziger
     * Unterschied zu den übrigen Kacheln: lädt bewusst externe Ressourcen
     * (Leaflet-JS/CSS + Kartenkachelbilder), keine rein eigenständige
     * HTML/CSS-Lösung wie die kompakte/Übersichts-Kachel. Zeigt JEDEN aktiven
     * Standort gleichzeitig als eigenen Pin (auch mobile, Live-Standort-
     * gebundene -- resolveStandortCoords() liest bei jeder Prüfung neu),
     * Pin-Farbe je Standort nach dessen EIGENEM höchsten aktiven Schweregrad
     * (nicht dem instanzweiten Höchststand -- vorher zeigte selbst ein
     * unbeteiligter Standort die Farbe der Warnung eines ganz anderen
     * Standorts). Zeigt bewusst noch KEINE einzelnen Warnflächen
     * (Polygon/Kreis) -- die werden aktuell nicht dauerhaft gespeichert (nur
     * Anzeige-Felder in LastActiveWarningsJson), das wäre eine eigene
     * Erweiterung. Dietmars Wunsch 06.09.2026.
     *
     * Ursprünglich zeigte die Kachel nur EINEN, in der Konsole fest
     * ausgewählten Standort (KartenkachelStandort-Property) -- dieselbe
     * HTML-Variable geht aber an ALLE WebFront-Betrachter gleichzeitig
     * (SetValue() kennt keinen "pro Betrachter"-Unterschied). Stellte
     * Dietmar unterwegs auf seinen eigenen mobilen Standort um, sah seine
     * Frau zuhause zwangsläufig denselben, nicht mehr ihren eigenen (Dietmars
     * Fund 07.09.2026). Jetzt zeigt die Karte IMMER alle aktiven Standorte
     * gleichzeitig plus eine kleine klickbare Legende -- welcher Pin gerade
     * fokussiert ist, merkt sich JEDER BROWSER FÜR SICH in localStorage
     * (Schlüssel whub-map-focus-<Instanz>, analog zur bereits bestehenden
     * Zoomstufen-Merkung), nicht die Instanz zentral. Startansicht (noch
     * keine eigene Wahl gemerkt, oder der gemerkte Standort existiert nicht
     * mehr) ist bewusst IMMER "alle Standorte" (fitBounds) -- Dietmars
     * ausdrücklicher Wunsch 07.09.2026, statt eines weiterhin konfigurierten
     * Standard-Standorts.
     *
     * Kartenkacheln von Esri (server.arcgisonline.com/.../World_Street_Map),
     * NICHT OpenStreetMaps eigenen Tile-Servern -- Praxis-Fund ruan/Andreas,
     * Symcon-Forum, 07.09.2026: OSMs Server verlangen laut eigener
     * Nutzungsrichtlinie zwingend einen Referer-Header (osm.wiki/Blocked,
     * explizit gegen genau diesen Fall gerichtet -- ein Widget, das in
     * vielen unabhängigen Installationen eingebettet wird), Firefox schickte
     * in seinem WebFront keinen mit (HTTP 403), Chrome/Mobile zufällig schon.
     * Esris Dienst verlangt live geprüft KEINEN Referer und keinen API-Key
     * für diese Nutzung -- dafür React-Pflicht auf eine kleine Attribution
     * (Leaflets Standard-attributionControl, deshalb hier NICHT abgeschaltet
     * wie ursprünglich). Achtung Kachel-URL-Reihenfolge: Esris REST-Tiles
     * sind {z}/{y}/{x}, nicht das bei Leaflet/OSM übliche {z}/{x}/{y}.
     *
     * Höhe: füllt jetzt height:100% statt einer festen Pixelzahl -- Praxis-
     * Fund ruan/Andreas: die Breite passte sich der Kachel schon an, die
     * Höhe war fest auf 220px genagelt. Für Konfigurationen, in denen das
     * nicht zuverlässig funktioniert, zusätzlich KartenkachelHoehePx als
     * feste Alternative (Praxis-Fund kronos/Bricoleur).
     *
     * Zoomstufe wird je Kachel/Browser in localStorage gemerkt (NICHT die
     * Kartenmitte -- die soll immer dem fokussierten Standort folgen) -- ohne
     * das ginge ein manuelles Heran-/Herauszoomen bei jeder Prüfung wieder
     * verloren, da SetValue() das komplette HTML ersetzt. Praxis-Fund
     * ruan/Andreas, Symcon-Forum, 07.09.2026. JEDER localStorage-Zugriff
     * (lesend wie schreibend) steht in try/catch -- ein werfender Zugriff
     * (z. B. sandboxed WebFront-Einbettung ohne Storage-Erlaubnis) darf die
     * Kartenerstellung nie mehr abbrechen (Regression daraus, sofort
     * behoben, Praxis-Fund ruan/Andreas 07.09.2026).
     *
     * Die Legende liegt bewusst als EIGENE ZEILE UNTER der Karte
     * (flex-direction:column), NICHT als schwebendes Overlay AUF der Karte:
     * ein Overlay hätte zwei echte Probleme gehabt, live geprüft --
     * erstens verdeckt es bei "Alle" (fitBounds über alle Standorte) leicht
     * einen Marker im unteren Kartenbereich, weil fitBounds() die vom
     * Overlay optisch belegte Fläche nicht kennt; zweitens malt Leaflet
     * seine eigenen internen Panes (Kachel-/Marker-Ebenen, jeweils mit
     * eigenem z-index 200-700) über ein schwebendes Geschwister-Div, OBWOHL
     * das im HTML-Quelltext nach dem Karten-Div steht und nach der normalen
     * DOM-Reihenfolge eigentlich oben liegen müsste (nur per z-index
     * erzwingbar, aber löst das erste Problem nicht). Die eigene Zeile
     * umgeht beide Probleme gleichzeitig, kostet nur etwas Kartenhöhe.
     */
    private function renderKachelKarte(array $active, bool $snoozed = false): string
    {
        $standorte = array_values(array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== ''));
        if ($standorte === []) {
            return $this->tileStyleBlock() . <<<HTML
<div class="whub-status">
  <div class="whub-empty">
    <div class="whub-empty-icon">🗺️</div>
    <div class="whub-empty-text">Kein aktiver Standort konfiguriert (siehe Panel "Standorte")</div>
  </div>
</div>
HTML;
        }

        $markers = [];
        foreach ($standorte as $standort) {
            $coords = $this->resolveStandortCoords($standort);
            $ownActive = array_values(array_filter($active, fn ($w) => ($w['standort'] ?? '') === $standort['Name']));
            $top = count($ownActive) > 0 ? $this->highestActiveSeverity($ownActive) : null;
            $color = $top !== null ? (self::TILE_SEVERITY_COLOR[$top['severity']] ?? self::TILE_SEVERITY_COLOR['Unknown']) : self::TILE_COLOR_OK;
            $markers[] = ['name' => $standort['Name'], 'lat' => $coords['lat'], 'lon' => $coords['lon'], 'color' => $color];
        }
        $markersJson = json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $mapId = 'whubmap' . substr(md5((string) $this->InstanceID), 0, 10);
        // 0 = automatisch (height:100%, übernimmt die Höhe der umgebenden
        // WebFront-/Kachel-Visualisierung-Kachel) -- funktioniert nicht in
        // jeder Konfiguration zuverlässig (Praxis-Fund kronos/Bricoleur,
        // Symcon-Forum, 07.09.2026: skalierte nur in der Breite). Deshalb
        // zusätzlich eine feste Pixelzahl als Alternative.
        $hoehePx = $this->ReadPropertyInteger('KartenkachelHoehePx');
        $heightCss = $hoehePx > 0 ? $hoehePx . 'px' : '100%';

        return $this->tileStyleBlock() . <<<HTML
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<div class="whub-status" style="padding:0;overflow:hidden;display:flex;flex-direction:column;height:{$heightCss};min-height:220px;">
  <div id="{$mapId}" style="width:100%;flex:1 1 auto;min-height:160px;"></div>
  <div id="{$mapId}legend" class="whub-map-legend"></div>
  <script>
  (function(){
    var el = document.getElementById('{$mapId}');
    var legendEl = document.getElementById('{$mapId}legend');
    if (!el || !legendEl || typeof L === 'undefined') { return; }
    var markers = {$markersJson};
    if (!markers.length) { return; }

    var zoomKey = 'whub-map-zoom-{$mapId}';
    var focusKey = 'whub-map-focus-{$mapId}';
    var startZoom = 11;
    var savedFocus = '';
    try {
      var savedZoom = parseInt(localStorage.getItem(zoomKey), 10);
      if (savedZoom >= 1 && savedZoom <= 19) { startZoom = savedZoom; }
      savedFocus = localStorage.getItem(focusKey) || '';
    } catch (e) {}

    var map = L.map(el, {zoomControl:false}).setView([markers[0].lat, markers[0].lon], startZoom);
    map.on('zoomend', function(){
      try { localStorage.setItem(zoomKey, map.getZoom()); } catch (e) {}
    });
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {maxZoom:19, attribution:'Tiles &copy; Esri'}).addTo(map);

    var pins = {};
    var pillEls = {};
    markers.forEach(function(m){
      var marker = L.circleMarker([m.lat, m.lon], {radius:10, color:m.color, fillColor:m.color, fillOpacity:0.85, weight:2}).addTo(map).bindTooltip(m.name);
      marker.on('click', function(){ focusOn(m.name); });
      pins[m.name] = marker;
    });

    function highlightPill(name){
      var key = (name && pins[name]) ? name : '__all__';
      Object.keys(pillEls).forEach(function(k){
        pillEls[k].classList.toggle('whub-active', k === key);
      });
    }
    function applyView(name, animate){
      if (name && pins[name]) {
        var target = pins[name].getLatLng();
        if (animate) { map.flyTo(target, startZoom); } else { map.setView(target, startZoom); }
      } else if (markers.length === 1) {
        map.setView([markers[0].lat, markers[0].lon], startZoom);
      } else {
        var bounds = L.latLngBounds(markers.map(function(m){ return [m.lat, m.lon]; }));
        if (animate) { map.flyToBounds(bounds, {padding:[30,30]}); } else { map.fitBounds(bounds, {padding:[30,30]}); }
      }
      highlightPill(name);
    }
    function focusOn(name){
      try { localStorage.setItem(focusKey, name); } catch (e) {}
      applyView(name, true);
    }
    function focusAll(){
      try { localStorage.removeItem(focusKey); } catch (e) {}
      applyView('', true);
    }

    var allPill = document.createElement('div');
    allPill.className = 'whub-map-pill';
    allPill.textContent = '🌍 Alle';
    allPill.addEventListener('click', focusAll);
    legendEl.appendChild(allPill);
    pillEls['__all__'] = allPill;

    markers.forEach(function(m){
      var pill = document.createElement('div');
      pill.className = 'whub-map-pill';
      var dot = document.createElement('span');
      dot.className = 'whub-map-pill-dot';
      dot.style.background = m.color;
      pill.appendChild(dot);
      pill.appendChild(document.createTextNode(m.name));
      pill.addEventListener('click', function(){ focusOn(m.name); });
      legendEl.appendChild(pill);
      pillEls[m.name] = pill;
    });

    applyView(savedFocus, false);
  })();
  </script>
</div>
HTML;
    }

    /**
     * ZAMG-Warnkarte fest eingebettet (iframe) -- speziell für Österreich,
     * Dietmars Wunsch 06.09.2026. Zeigt derzeit ganz Österreich
     * (self::ZAMG_MAP_URL), noch OHNE automatische Zentrierung auf einen
     * einzelnen Standort (siehe Konstanten-Kommentar oben) -- bewusste V1-
     * Einschränkung statt einer ungeprüften Projektionsrechnung.
     *
     * Höhe: derselbe Fund wie bei "Kachel (Karte)" -- eine fest genagelte
     * Pixelzahl skaliert nicht in jeder WebFront-/Kachel-Visualisierung-
     * Konfiguration mit (Praxis-Fund ruan/Andreas, Symcon-Forum, 07.09.2026).
     * Deshalb height:100% als Standard, ZamgKachelHoehePx als feste
     * Alternative -- eigene Property statt KartenkachelHoehePx mitzuver-
     * wenden, weil beide Kacheln unabhängig voneinander im WebFront platziert
     * werden und unterschiedlich hoch sein können.
     */
    private function renderKachelZamg(): string
    {
        $url = htmlspecialchars(self::ZAMG_MAP_URL);
        $hoehePx = $this->ReadPropertyInteger('ZamgKachelHoehePx');
        $heightCss = $hoehePx > 0 ? $hoehePx . 'px' : '100%';
        return $this->tileStyleBlock() . <<<HTML
<div class="whub-status" style="padding:0;overflow:hidden;height:{$heightCss};min-height:200px;">
  <iframe src="{$url}" style="width:100%;height:100%;min-height:200px;border:0;border-radius:22px;" loading="lazy" title="ZAMG-Warnkarte Österreich"></iframe>
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
     *
     * $title/$text kommen hier UNGEKÜRZT an -- die 32/256-Byte-Kürzung wird
     * erst unmittelbar vor WFC_PushNotification/VISU_PostNotificationEx
     * angewendet (siehe truncateBytes()), NICHT mehr zentral in
     * buildPushText()/buildPushTitle(). Telegram/Pushover/E-Mail bekommen
     * den vollen Text (Praxis-Fund hfichtinger, Symcon-Forum, 09.09.2026).
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
                $ok = @VISU_PostNotificationEx($w['InstanceID'], $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), 'Alert', $sound, 0);
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
            } elseif ($w['Typ'] === 'email') {
                // SMTP_SendMailEx($InstanzID, $Empfänger, $Betreff, $Inhalt) --
                // live gegen eine echte IP-Symcon-Instanz verifiziert, siehe
                // SMTP_GUID-Kommentar oben. Erkennt HTML automatisch am
                // <html>-Wrapper (seit Symcon 7.0) -- Text wird deshalb erst
                // escaped (falls zufällig ein "<"/">" im Warntext vorkommt)
                // und dann mit nl2br() umgebrochen, statt roh durchzureichen.
                if (!function_exists('SMTP_SendMailEx')) {
                    $this->LogError('pushToAllWebfronts', 'SMTP_SendMailEx ist nicht verfügbar (kein SMTP-Modul installiert).');
                    continue;
                }
                $empfaenger = trim($w['Zieladresse'] ?? '');
                if ($empfaenger === '') {
                    $this->LogError('pushToAllWebfronts', 'E-Mail-Ziel "' . $w['Name'] . '" hat keine Zieladresse -- übersprungen.');
                    continue;
                }
                $body = '<html><body>' . nl2br(htmlspecialchars($text)) . '</body></html>';
                // Mehrere Adressen mit Komma/Semikolon getrennt möglich --
                // SMTP_SendMailEx ist Symcon-intern/closed-source (kein
                // öffentliches Repo), ihr Verhalten bei einem kombinierten
                // Mehrfach-Empfänger-String lässt sich am Quellcode nicht
                // verifizieren. Deshalb selbst aufsplitten und je Adresse
                // EINEN eigenen Aufruf machen -- unabhängig davon, was das
                // SMTP-Modul intern mit einem Rohstring anfängt. Praxis-
                // Wunsch hfichtinger, Symcon-Forum, 09.09.2026.
                $adressen = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $empfaenger))));
                $ok = false;
                foreach ($adressen as $adresse) {
                    if (@SMTP_SendMailEx($w['InstanceID'], $adresse, $title, $body)) {
                        $ok = true;
                    } else {
                        $this->LogError('pushToAllWebfronts', 'E-Mail an "' . $adresse . '" (Ziel "' . $w['Name'] . '") fehlgeschlagen.');
                    }
                }
            } else {
                if (!function_exists('WFC_PushNotification')) {
                    $this->LogError('pushToAllWebfronts', 'WFC_PushNotification ist nicht verfügbar (kein WebFront-Modul installiert).');
                    continue;
                }
                // Letzter Parameter ist laut offizieller Doku eine TargetID zum
                // Deep-Linking beim Antippen der Nachricht (0 = kein Sprungziel),
                // KEINE Sender-/Absender-Instanz-ID. $this->InstanceID war hier
                // fälschlich übergeben -- das ist innerhalb einer fremden
                // WebFront-Instanz kein gültiges/sichtbares Objekt, wodurch der
                // Push dort scheitern konnte (Praxis-Fund chrschli, Symcon-
                // Forum, 05.09.2026: ein Ziel schlug fehl, ein anderes ging).
                $ok = @WFC_PushNotification($w['InstanceID'], $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), $sound, 0);
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
    private function logHistory(string $kind, string $standortName, string $event, string $headline, string $severity, string $category, string $source): void
    {
        $log = json_decode($this->ReadAttributeString('WarnHistory'), true) ?: [];
        $log[] = [
            'ts' => time(),
            'kind' => $kind, // 'warnung', 'entwarnung', 'eskalation', 'rueckstellung' oder 'fensterwarnung'
            'standort' => $standortName,
            'event' => $event,
            'headline' => $headline,
            'severity' => $severity,
            'category' => $category,
            'source' => $source,
        ];
        if (count($log) > 500) {
            $log = array_slice($log, count($log) - 500);
        }
        $this->WriteAttributeString('WarnHistory', json_encode($log));
    }
}
