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
    private const DOC_VERSION = '1.20.1';
    private const LICENSE_URL = 'https://github.com/DG65/WarnHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-warnhub-warn-und-alarmmeldungen-fuer-deutschland-oesterreich-und-die-schweiz-mit-umkreis-filter-push-und-schutzaktionen/144349';

    // Stichwörter für die automatische Schutzaktionen-Suche im Objektbaum
    // (Instanz-/Variablenname enthält eins der Wörter -> Aktionstyp-Vorschlag).
    // Fremde Variablennamen kommen in der Sprache des Systems, auf dem sie
    // angelegt wurden -- deshalb seit 1.20.1 auch englische Namen.
    private const DISCOVERY_KEYWORDS = [
        'raffstore' => ['raffstore', 'jalousie', 'venetian blind', 'external blind'],
        'markise' => ['markise', 'sonnenschutz', 'awning'],
        'garage' => ['garage', 'garagentor'],
        // "fenster schließen", nicht nur "fenster" -- sonst würden auch
        // reine Fenster-OFFEN-Sensoren (keine Aktion, ohnehin durch
        // isActionableVariable() ausgefiltert, aber ambig benannt) sowie
        // fremde Geräte mit "Fenster" im Namen mit anfassen. Trifft exakt
        // Tessies eigene Standard-Variable "Fenster schließen"
        // (act_close_windows, sendet Teslas gerichteten close_windows-Befehl
        // -- sicher blind auslösbar, kein Umschalt-Risiko).
        'fenster' => ['fenster schließen', 'close_windows', 'close windows'],
        // Trifft Tessies eigene Aktion "Heckklappe öffnen/schließen"
        // (act_rear_trunk). Anders als bei "fenster" wird zusätzlich nach
        // einer passenden Zustands-Variable UNTER DERSELBEN INSTANZ gesucht
        // (Name enthält "klappenstatus", z. B. Tessies "Tür-/Klappenstatus")
        // -- Sonderbehandlung direkt im Suchlauf unten, siehe dortigen Kommentar.
        'kofferraum' => ['heckklappe', 'tailgate', 'rear trunk'],
        'sirene' => ['sirene', 'hupe', 'buzzer', 'signalhorn', 'siren'],
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
        // Tessie auf einem englischen Symcon: "Location – Latitude/Longitude" (1.20.1).
        // Mit Gedankenstrich als Präfix, damit nicht jede "Location"-Variable passt.
        ['label' => 'Tessie/Fahrzeug', 'prefix' => 'location –', 'lat' => 'latitude', 'lon' => 'longitude'],
        // "current" grenzt Geofencys aktuelle Position von dessen zusätzlicher,
        // gleichnamiger "Latitude"/"Longitude" ab (vermutlich Geofence-Zentrum,
        // nicht die Live-Position) -- live an Dietmars System geprüft.
        ['label' => 'Geofency', 'prefix' => 'current', 'lat' => 'latitude', 'lon' => 'longitude'],
    ];

    /**
     * Idents, unter denen die unterstützten Wetterstations-Module Windböe
     * bzw. Regenrate ablegen (Froggit, Sainlogic/ELV, Meteobridge) -- EINE
     * Stelle für Abruf, Auto-Rückstellung UND die Statuszeile im Formular,
     * damit die Anzeige nie etwas anderes erkennt als der Abruf.
     */
    private const WETTERSTATION_WIND_IDENTS = ['windgustmph', 'Windgust', 'Wind_Gust_KmH'];
    private const WETTERSTATION_REGEN_IDENTS = ['rainratein', 'rrain_piezo', 'rainin', 'Rain_Rate'];

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
        'QuelleGeosphereAt', 'QuelleBafuHydroCh', 'QuelleSedErdbebenCh', 'QuelleAlertSwissCh', 'QuelleWaldbrandDe', 'QuelleOzonDe',
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

    // Notification Control (Symcon-Kernmodul, Prefix "NC") -- live gegen
    // Dietmars eigene IP-Symcon-Instanz verifiziert (01.10.2026, Instanz
    // #34698 "Notifications"), da kein öffentliches GitHub-Repo existiert
    // (Kernmodul, kein Community-/Store-Modul). Anders als WebFront/Kachel-
    // Visualisierung (WFC_PushNotification/VISU_PostNotificationEx, je EIN
    // Push an EINEN Konfigurator) verwaltet Notification Control die
    // einzelnen GERÄTE (Handy/Tablet) geräteübergreifend -- ein Gerät kann
    // bei mehreren Konfiguratoren (WebFront UND/ODER Kachel-Visualisierung
    // UND/ODER IPSView) gleichzeitig registriert sein. Genau DESHALB deckt
    // sie den Fall ab, den WebFront/Kachel-Visualisierung strukturell nie
    // erreichen: ein Gerät, das nur über die IPSView-App eingeloggt ist und
    // bei KEINER WebFront-/Kachel-Instanz als Konfigurator eingetragen ist
    // (Praxis-Fund tomfes, Symcon-Forum, 01.10.2026). `NC_GetDevices($InstanzID)`
    // liefert je Gerät `ID`/`Name`/`Visualizations` (Map Konfigurator-
    // Instanz-ID -> aktiv/inaktiv, live an Dietmars System geprüft: 12
    // Geräte, auch mehrere Konfiguratoren je Gerät). WICHTIG, per echtem
    // Testpush an Dietmars eigenes Gerät verifiziert (01.10.2026): der
    // ZWEITE Parameter von `NC_PushNotification($InstanzID,
    // $VisualisierungsID, $Titel, $Text, $Sound)` ist die
    // KONFIGURATOR-Instanz-ID aus `Visualizations` (ein Schlüssel dieser
    // Map), NICHT die geräteeigene `ID` aus `NC_GetDevices()` -- Letztere
    // lieferte live `false`, Erstere eine echte Benachrichtigungs-ID.
    // Rückgabe ist KEIN Bool (Benachrichtigungs-ID bei Erfolg, `false` bei
    // Fehlschlag) -- Erfolgsprüfung deshalb immer `!== false`, nie
    // `=== true`. Beide Funktionen sind offiziell UNDOKUMENTIERT (kein
    // Eintrag in der Symcon-Modulreferenz), aber live bestätigt real und
    // funktionsfähig (`get_defined_functions()` listet
    // `nc_pushnotification`/`nc_getdevices`) sowie durch mehrere
    // unabhängige Community-Referenzimplementierungen mit vergleichbarer
    // Signatur gedeckt (u. a. Tommi2Day/ipsymcon-phpmodule-by-Tommi,
    // JohannesMaennel/IPS-Module -- deren Rückgabetyp wich allerdings vom
    // live beobachteten Verhalten ab, siehe oben). Da undokumentiert, kein
    // Vertrauen in die Rückgabestruktur -- jede Ebene wird defensiv mit
    // `is_array()` geprüft.
    private const NOTIFICATION_CONTROL_GUID = '{D4B231D6-8141-4B9E-9B32-82DA3AEEAB78}';

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

    /**
     * Amtliche Warn-Webseiten weiterer europäischer Länder, über den
     * ISO-3166-1-alpha-2-Ländercode aus reverseGeocodeStandort() adressiert
     * -- ergänzt D/A/CH um die Länder, für die eine offizielle amtliche
     * Warnseite recherchiert wurde (Stand 09.09.2026). Bewusst NICHT einzeln
     * live gegen jede Webseite verifiziert, anders als sonst in diesem Modul
     * durchgehend praktiziert -- reine Informationslinks ohne eingebetteten/
     * geparsten Inhalt, ein leicht ungenauer Link (z. B. Startseite statt
     * Warnunterseite) bleibt folgenlos und ist leicht nachbesserbar.
     * Dietmars Recherchewunsch 09.09.2026: "wenn man in Europa auf Reisen
     * ist ... auch im Ausland" -- greift zusammen mit refreshStandortLaenderCodes()
     * automatisch das Land jedes aktiven (auch mobilen) Standorts auf.
     */
    private const COUNTRY_MAP_LINKS = [
        'de' => ['label' => '🇩🇪 DWD', 'url' => self::DWD_MAP_URL],
        'at' => ['label' => '🇦🇹 ZAMG', 'url' => self::ZAMG_MAP_URL],
        'ch' => ['label' => '🇨🇭 MeteoSchweiz', 'url' => self::METEOSWISS_MAP_URL],
        'fr' => ['label' => '🇫🇷 Météo-France', 'url' => 'https://vigilance.meteofrance.fr/'],
        'it' => ['label' => '🇮🇹 Protezione Civile', 'url' => 'https://allertameteo.protezionecivile.it/'],
        'es' => ['label' => '🇪🇸 AEMET', 'url' => 'https://www.aemet.es/en/avisos'],
        'nl' => ['label' => '🇳🇱 KNMI', 'url' => 'https://www.knmi.nl/waarschuwingen_en_verwachtingen/weerwaarschuwingen'],
        'be' => ['label' => '🇧🇪 RMI/IRM', 'url' => 'https://www.meteo.be/en/weather/warnings/overview-belgium'],
        'pl' => ['label' => '🇵🇱 IMGW', 'url' => 'https://meteo.imgw.pl/'],
        'cz' => ['label' => '🇨🇿 ČHMÚ', 'url' => 'https://www.chmi.cz/'],
        'dk' => ['label' => '🇩🇰 DMI', 'url' => 'https://www.dmi.dk/vejr/varsler'],
        'no' => ['label' => '🇳🇴 Varsom', 'url' => 'https://www.varsom.no/en/'],
        'se' => ['label' => '🇸🇪 SMHI', 'url' => 'https://www.smhi.se/vader/varningar-och-brandrisk'],
        'fi' => ['label' => '🇫🇮 Ilmatieteen laitos', 'url' => 'https://en.ilmatieteenlaitos.fi/warnings'],
        'gb' => ['label' => '🇬🇧 Met Office', 'url' => 'https://www.metoffice.gov.uk/weather/warnings-and-advice/uk-warnings'],
        'ie' => ['label' => '🇮🇪 Met Éireann', 'url' => 'https://www.met.ie/warnings'],
        'pt' => ['label' => '🇵🇹 IPMA', 'url' => 'https://www.ipma.pt/en/otempo/previsao.avisos/'],
    ];
    // Ereignisname (englisch, übersetzt an der Verwendungsstelle) und fest
    // zugeordnete Kategorie je GeoSphere-Warntyp -- dieselbe Zuordnung, die die
    // Stichwortsuche bis 1.20.0 aus den deutschen Namen ergab (Kälte = sonstige).
    private const GEOSPHERE_AT_WARNTYPE_EVENT = [
        1 => 'Storm', 2 => 'Heavy rain', 3 => 'Snow', 4 => 'Black ice',
        5 => 'Thunderstorm', 6 => 'Heat', 7 => 'Cold',
    ];
    private const GEOSPHERE_AT_WARNTYPE_CATEGORY = [
        1 => 'sturm', 2 => 'starkregen', 3 => 'schnee', 4 => 'schnee',
        5 => 'gewitter', 6 => 'hitze', 7 => 'sonstige',
    ];
    private const GEOSPHERE_AT_WARNLEVEL_SEVERITY = [1 => 'Moderate', 2 => 'Severe', 3 => 'Extreme'];

    // Alertswiss (alert.swiss, Bundesamt für Bevölkerungsschutz BABS) --
    // Schweizer Pendant zu NINA: kantonale Feuerverbote, Waldbrand,
    // Trockenheit, Fels-/Bergsturz, Sturm u. a. Zivilschutz-Meldungen,
    // NICHT nur Wetter. Live geprüft 22.09.2026 (15 aktive Meldungen,
    // echte Polygon-/Kreis-Geometrie je Meldung -- anders als Meteoalarm
    // also koordinatengenau statt Namensabgleich), kein Zugangsschlüssel
    // nötig. Endpunkt aus dem quelloffenen Community-Adapter
    // github.com/forseti1982/HA-AlertSwiss (custom_components/alertswiss/
    // const.py) übernommen und live gegen die echte Antwort verifiziert,
    // nicht nur aus dessen Doku geglaubt -- der dortige Adapter selbst
    // wertet nur Kantonsnamen aus, WarnHub nutzt die tatsächlich
    // enthaltene Geometrie. Anfrage baslerleckerli, Symcon-Forum,
    // 22.09.2026. Feld "technicalTestAlert" (im Live-Feed real beobachtet:
    // ein dauerhafter "Cap Test Event") entspricht CAP status=Test/Exercise
    // -- wird von Anfang an gefiltert (siehe isCapStatusActual()-Fund
    // 1.14.2, hier gleich mitgebaut statt erst nach einem Praxis-Fund).
    // Die Quelle liefert KEIN Gültig-bis-Datum (anders als CAP) -- expires
    // bleibt null, das bestehende "still present"-Verfahren räumt eine aus
    // dem Feed verschwundene Meldung wie bei BAFU/SED automatisch auf.
    // Alertswiss liefert zwei Meldungsarten ohne eigenes Kennzeichen: flächige
    // (kantonal/regional, "Gesamter Kanton Solothurn", Waldbrandgefahr,
    // Feuerverbot, Trockenheit) und örtliche (Fels-/Bergsturz, Strassensperrung,
    // Grossveranstaltung). Live-Feed 04.10.2026 (14 Meldungen): flächige
    // Meldungen haben 14-114 km Diagonale, örtliche 0,5-6 km -- sauber getrennt.
    // Flächige Meldungen zählen nur, wenn der Standort IN der Fläche liegt,
    // sonst bekäme ein Standort in Basel auch die Solothurner Kantonsmeldung,
    // nur weil der Kanton in Umkreisnähe beginnt (Praxis-Wunsch
    // baslerleckerli, Symcon-Forum, 04.10.2026). Örtliche Meldungen behalten
    // den Umkreis.
    private const ALERTSWISS_FLAECHIG_AB_KM = 10.0;
    private const ALERTSWISS_URL = 'https://www.alert.swiss/content/alertswiss-internet/de/home/_jcr_content/polyalert.alertswiss_alerts.actual.json';

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
        // Englisch wie in den offiziellen englischen BAFU-Gefahrenstufen, Deutsch in locale.json
        1 => 'no or minor danger', 2 => 'moderate danger', 3 => 'considerable danger',
        4 => 'high danger', 5 => 'very high danger',
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
        // Englisch kurz (eigene Schlüssel, nicht die BAFU-Bezeichnungen -- "high danger"
        // heißt dort "grosse Gefahr", beim DWD "hohe Gefahr"), Deutsch in locale.json
        1 => 'very low', 2 => 'low', 3 => 'medium',
        4 => 'high', 5 => 'very high',
    ];
    private const WBI_LEVEL_SEVERITY = [1 => 'Minor', 2 => 'Minor', 3 => 'Moderate', 4 => 'Severe', 5 => 'Extreme'];
    // Höchstalter des letzten Stationswerts. DWD rechnet den Index nur in
    // der Waldbrandsaison (etwa März bis Ende September/Oktober); danach
    // bleibt die "recent"-Datei beim letzten Saisonwert stehen, wird aber
    // weiterhin täglich neu erzeugt. Praxis-Fund baslerleckerli, Symcon-
    // Forum, 24.10.2026: Station Rheinfelden (4175) endete am 24.09.2026 mit
    // Stufe 4 -- WarnHub zeigte den Wert einen Monat später noch als aktive
    // "Waldbrandgefahr". Ein Wert älter als 3 Tage ist deshalb KEIN
    // aktueller Index mehr (der Index ist ein Tageswert).
    private const WBI_MAX_AGE_DAYS = 3;
    // Zwischenspeicher für Stationsliste und Tageswerte (siehe wbiStations()).
    private const WBI_STATION_CACHE_SEC = 7 * 86400;
    private const WBI_READING_CACHE_SEC = 6 * 3600;
    private const WBI_FAIL_LOG_SEC = 6 * 3600;

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
    // Zwischenspeicher (siehe fetchOzonDe()/cachedSourceData()).
    private const UBA_STATION_CACHE_SEC = 7 * 86400;
    private const UBA_READING_CACHE_SEC = 20 * 60;
    private const UBA_READING_MAX_AGE_SEC = 3 * 3600;
    private const UBA_OZONE_COMPONENT_ID = 3;
    private const UBA_LQI_LABEL = [
        // Englisch wie im englischen UBA-Angebot, Deutsch in locale.json
        0 => 'very good', 1 => 'good', 2 => 'moderate', 3 => 'poor', 4 => 'very poor',
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
        // Rauch/Gefahrstoffe ZUERST: das schnee-Stichwort 'eis' steckt in
        // Wörtern wie "Hinweis" und würde eine solche Meldung sonst
        // wegnehmen. Nur spezifische Stichwörter -- "Brand"/"Großbrand" allein
        // bewusst nicht (ein fernes Feuer ohne Rauchgefahr soll z. B. keine
        // Lüftung abschalten). Praxis-Wunsch mike256, Symcon-Forum, 17.10.2026
        // ("Rauchgase, Fenster schließen").
        'rauch'      => ['rauch', 'brandgas', 'gefahrstoff', 'schadstoff', 'gasaustritt', 'gaswolke', 'chemieunfall', 'chemikalien', 'giftig'],
        'sturm'      => ['sturm', 'orkan', 'böen', 'boeen', 'windböen', 'windboeen'],
        'hagel'      => ['hagel'],
        'starkregen' => ['starkregen', 'dauerregen', 'hochwasser', 'flut', 'überflutung', 'ueberflutung'],
        'gewitter'   => ['gewitter', 'blitz'],
        'schnee'     => ['schnee', 'glätte', 'glaette', 'glatteis', '/(*UCP)\\beis/u'],
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
        'sturm'      => ['KatSturm', '🌪️ Storm'],
        'hagel'      => ['KatHagel', '🧊 Hail'],
        'starkregen' => ['KatStarkregen', '🌧️ Heavy rain'],
        'gewitter'   => ['KatGewitter', '⚡ Thunderstorm'],
        'schnee'     => ['KatSchnee', '❄️ Snow'],
        'hitze'      => ['KatHitze', '🥵 Heat'],
        'rauch'      => ['KatRauch', '💨 Smoke/hazardous substances'],
    ];

    // ----------------------------------------------------------------
    //  Modul-Lebenszyklus
    // ----------------------------------------------------------------

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Standorte', '[]');
        // "🪟 Formular: Aufbau" (SUITE.md 3a): je Fachpanel Aufklapp-Zustand und Position, siehe formLayout()
        $this->RegisterPropertyString('FormLayout', '[]');
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
        $this->RegisterPropertyBoolean('QuelleAlertSwissCh', false);
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
        $this->RegisterPropertyBoolean('AlleWarnungenAufgeklappt', false);

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
        $this->RegisterAttributeString('WbiCache', '{}');
        $this->RegisterAttributeString('UbaCache', '{}');
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
        // Ländercodes ALLER aktiven Standorte (fest UND mobil), ebenfalls in
        // Poll() im Hintergrund aktualisiert -- steuert, welche Links am
        // Ende jeder Kachel erscheinen (siehe COUNTRY_MAP_LINKS/
        // officialMapLinksHtml()): reist ein mobiler Standort ins Ausland,
        // taucht dessen Land automatisch mit auf, sobald der nächste Poll
        // gelaufen ist. Dietmars Recherchewunsch 09.09.2026.
        $this->RegisterAttributeString('StandortLaenderCodes', '[]');
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
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 0, $this->Translate('No active warning'), 'ℹ️', 0x00C853);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 1, 'Minor', 'ℹ️', 0x00C853);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 2, 'Moderate', '⚠️', 0xFFD600);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 3, 'Severe', '🚨', 0xFF6D00);
            IPS_SetVariableProfileAssociation('WHUB.Schweregrad', 4, 'Extreme', '🆘', 0xD50000);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainVariable('AktiveWarnungen', $this->Translate('Active warnings'), VARIABLETYPE_INTEGER, '', 1, true);
        $this->MaintainVariable('HoechsterSchweregrad', $this->Translate('Highest severity'), VARIABLETYPE_INTEGER, 'WHUB.Schweregrad', 2, true);
        $this->MaintainVariable('StatusText', 'Status', VARIABLETYPE_STRING, '', 3, true);
        $this->MaintainVariable('LetztePruefung', $this->Translate('Last check'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 4, true);
        $this->MaintainVariable('KachelStatus', $this->Translate('Tile (compact)'), VARIABLETYPE_STRING, '~HTMLBox', 5, true);
        $this->MaintainVariable('KachelUebersicht', $this->Translate('Tile (overview)'), VARIABLETYPE_STRING, '~HTMLBox', 6, true);
        $this->MaintainVariable('KachelKarte', $this->Translate('Tile (map)'), VARIABLETYPE_STRING, '~HTMLBox', 7, true);
        $this->MaintainVariable('KachelZamg', $this->Translate('Tile (ZAMG warning map, Austria)'), VARIABLETYPE_STRING, '~HTMLBox', 8, true);
        $this->MaintainVariable('KachelAlle', $this->Translate('Tile (all warnings)'), VARIABLETYPE_STRING, '~HTMLBox', 9, true);
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
        return $this->hasWetterstationConfigured();
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
            ? $this->Translate('No check performed yet.')
            : (count($active) > 0
                ? sprintf($this->Translate('%d active warning(s)'), count($active))
                : $this->Translate('No active warning.'));
        $snoozed = $this->isPushSnoozed();
        if ($snoozed) {
            $statusText .= ' ' . sprintf($this->Translate('🔕 Push paused until %s.'), date('d.m. H:i', $this->ReadAttributeInteger('PushSnoozeUntilTs')));
        }
        @$this->SetValue('AktiveWarnungen', count($active));
        @$this->SetValue('HoechsterSchweregrad', $highest);
        @$this->SetValue('StatusText', $statusText);
        @$this->SetValue('LetztePruefung', $lastTs);
        @$this->SetValue('KachelStatus', $this->renderKachelStatus($active, $lastTs, $snoozed));
        @$this->SetValue('KachelUebersicht', $this->renderKachelUebersicht($active, $lastTs, $snoozed));
        @$this->SetValue('KachelKarte', $this->renderKachelKarte($active, $snoozed));
        @$this->SetValue('KachelZamg', $this->renderKachelZamg());
        @$this->SetValue('KachelAlle', $this->renderKachelAlleWarnungen($active, $lastTs, $snoozed, $this->ReadPropertyBoolean('AlleWarnungenAufgeklappt')));
    }

    // ----------------------------------------------------------------
    //  Formular
    // ----------------------------------------------------------------

    public function GetConfigurationForm(): string
    {
        $form = ['elements' => [], 'actions' => [], 'status' => []];
        // Fachpanels landen erst in $free (Schlüssel aus FORM_PANELS); Reihenfolge,
        // Aufklapp-Zustand und Sichtbarkeit legt der Nutzer im Panel
        // "🪟 Formular: Aufbau" fest (SUITE.md 3a), eingebaut unten vor dem Forum-Hinweis.
        $free = [];

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
            'caption' => $this->Translate('📖  Documentation & help'),
            'expanded' => $expDokuHilfe,
            'onClick' => $this->rememberPanelOnClick('DokuHilfe', $expDokuHilfe),
            'items' => [
                ['type' => 'Label', 'caption' => sprintf($this->Translate('WarnHub version %s'), self::DOC_VERSION)],
                ['type' => 'Label', 'caption' => $this->Translate('Bundles warning and alert messages for Germany, Austria and Switzerland (D-A-CH) -- official sources for Germany (civil protection, weather, floods, police, water levels, radioactivity), Europe-wide weather warnings for 39 countries (also covering Austria/Switzerland) and optionally your own weather station -- and only reports what lies within the radius you define around a location (including mobile locations abroad).')],
                ['type' => 'Label', 'caption' => $this->Translate('Data sources: NINA aggregation (officially used by the BBK app, warnung.bund.de, Germany), optionally the direct DWD weather warnings (opendata.dwd.de, Germany), optionally water levels (PEGELONLINE/WSV, Germany), optionally radioactivity readings (BfS ambient dose rate, Germany), optionally Europe-wide weather warnings (Meteoalarm, 39 countries incl. Austria and Switzerland), optionally coordinate-exact warnings for Austria (GeoSphere Austria/ZAMG), optionally coordinate-exact cantonal civil protection messages for Switzerland (Alertswiss/BABS), optionally official flood danger levels for Switzerland (BAFU/LINDAS), optionally your own weather station as an independent safety net and -- BETA, untested -- optionally your own VKF hail protection signal box (Switzerland).')],
                ['type' => 'Label', 'caption' => $this->Translate('PEGELONLINE, BfS ODL-Info and your own weather station have no official warning level classification -- instead WarnHub reports an elevated water level (above the mean or previous highest water level), an exceedance of your own radiation threshold or an exceedance of your own wind gust/rain rate threshold. This is not an official alert level.')],
                ['type' => 'Label', 'caption' => $this->Translate('The radius check is geometric against the actual warning area (polygon/circle of the message), not against postcodes or municipal boundaries.')],
                ['type' => 'Label', 'caption' => $this->Translate('If a message has no geometry, it is deliberately NOT assigned automatically (no guessed precision).')],
                ['type' => 'Label', 'caption' => $this->Translate('Instead of fixed coordinates, a location can also be bound to two variables (lat/lon), e.g. from Tessie, Geofency or a Stellantis/Smartcar/BMW ConnectedDrive/Hyundai-Kia Bluelink/OVMS native vehicle -- WarnHub then reads the current position at every check. The object tree search in the locations panel finds matching variable pairs automatically and links them directly. "Push only to" also lets a location notify only certain WebFronts (e.g. one person/vehicle each when several locations are used at the same time).')],
                ['type' => 'Label', 'caption' => $this->Translate('Protective actions do NOT fire as soon as a message arrives, but only shortly before it actually becomes valid (adjustable lead time in the protective actions panel) -- a warning that arrives in the morning but is only valid for the afternoon does not retract the awning in the morning. The push notification itself is not affected and still arrives immediately.')],
                ['type' => 'Label', 'caption' => $this->Translate('Configuration behaviour for locations/push targets/protective actions: during setup WarnHub automatically searches the object tree and suggests matches PRE-ACTIVATED -- mobile locations (Tessie vehicle position, Geofency, Stellantis/Smartcar/BMW ConnectedDrive/Hyundai-Kia Bluelink/OVMS native vehicles), your own weather station (Froggit), WebFront and tile visualization instances, as well as instances/variables with "Raffstore"/"Jalousie"/"Markise"/"Garage"/"Fenster schließen"/"Heckklappe"/"Sirene" in their name -- the last two fit Tessie\'s own Tesla actions in particular. As an exception, a boot/tailgate match stays INACTIVE if no matching state variable was found next to it (safety lock). Unwanted matches can simply be deselected in the active column -- a new search does not overwrite your own deselections.')],
            ],
        ];

        $expStandorte = $this->getPanelExpandedState('Standorte', true);
        $free['standorte'] = [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('📍  Locations (radius definition)'),
            'expanded' => $expStandorte,
            'onClick' => $this->rememberPanelOnClick('Standorte', $expStandorte),
            'items' => [
                ['type' => 'Label', 'caption' => $this->Translate('Each location only receives warnings if a message lies within the given radius and reaches at least the selected severity. Several locations are possible (e.g. your own home + second home/relatives).')],
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('📍 Take location from Symcon system settings'),
                    'onClick' => 'echo WHUB_AddStandortFromSystemLocation($id);',
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Takes latitude/longitude from the Symcon core instance "Location" (core instances) as a new row -- it is only added to the open table, "Apply" is still required.')],
                $this->statusLabelItem('SystemLocationStatusLabel', $this->systemLocationStatusLine()),
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('🔎 Search vehicle/location variables (mobile location)'),
                    'onClick' => 'echo WHUB_DiscoverMobileStandorte($id);',
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Searches the object tree for known position variable pairs (Tessie "Fahrzeugposition – Breitengrad/Längengrad", Geofency "Current Latitude/Longitude", as well as Stellantis, Smartcar, BMW ConnectedDrive, Hyundai/Kia Bluelink and OVMS native vehicles) and creates a location already linked to the live variables for each match -- activated directly, "Live location lat/lon" is already set. Simply deselect unwanted matches in the active column; check radius/severity afterwards. A new search only adds new matches.')],
                ['type' => 'Label', 'caption' => $this->Translate('A mobile location can also be set up by hand (e.g. from Tessie, Geofency or a Stellantis/Smartcar/BMW ConnectedDrive/Hyundai-Kia Bluelink/OVMS native vehicle): point "Live location lat/lon" to the respective position variable -- WarnHub then reads the CURRENT position from it at every check, lat/lon in the table are then only the start value/fallback. 0 = fixed coordinates from the table (previous behaviour).')],
                ['type' => 'Label', 'caption' => $this->Translate('Which modules exactly: "Tessie" (DG65 module for Tesla), "Geofency" (bridge for the geofencing app of the same name), as well as four community modules installable from the Symcon Module Store or GitHub -- search the store for the respective name: "Stellantis Vehicles" (Opel and other former PSA brands, github.com/slausch/Symcon-Stellantis-Vehicles), "Smartcar" (40+ vehicle brands via the Smartcar platform, github.com/mb-stern/Smartcar), "BMW Connected Drive", "Hyundai/Kia Bluelink" (github.com/da8ter/Bluelink, currently beta), "OVMS native" (for OVMS boxes with the older API V2, github.com/lorbetzki/net.lorbetzki.native.ovms).')],
                ['type' => 'Label', 'caption' => $this->Translate('"Push only to" limits the notifications of this location to individual targets from the WebFronts list further down, named explicitly (comma-separated, e.g. "iPhone Dietmar") -- handy with several people/vehicles so that not everyone gets the warnings of the other person. Empty = all activated targets as before.')],
                $this->statusLabelItem('MobileStandorteStatusLabel', $this->mobileStandorteStatusLine()),
                [
                    'type' => 'List',
                    'name' => 'Standorte',
                    'rowCount' => $this->listRowCount(count($this->decodeStandorte())),
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => $this->Translate('Name'), 'name' => 'Name', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Postcode/place (info)'), 'name' => 'Ort', 'width' => '140px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Latitude (lat)'), 'name' => 'Lat', 'width' => '120px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 5]],
                        ['caption' => $this->Translate('Longitude (lon)'), 'name' => 'Lon', 'width' => '120px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 5]],
                        ['caption' => $this->Translate('Live location lat (0=fixed)'), 'name' => 'QuellVarLat', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->Translate('Live location lon (0=fixed)'), 'name' => 'QuellVarLon', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->Translate('Radius (km)'), 'name' => 'RadiusKm', 'width' => '100px', 'add' => 10.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1, 'minValue' => 0]],
                        ['caption' => $this->Translate('From severity'), 'name' => 'MinSeverity', 'width' => '140px', 'add' => 2, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
                        ['caption' => $this->Translate('Push only to (name, comma; empty=all)'), 'name' => 'PushZielFilter', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Active'), 'name' => 'Aktiv', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
                ['type' => 'ValidationTextBox', 'name' => 'GeoLookupOrt', 'caption' => $this->Translate('Postcode or place')],
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('🔍 Look up coordinates'),
                    'onClick' => 'echo WHUB_LookupCoordinates($id, $GeoLookupOrt);',
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Determines latitude/longitude via OpenStreetMap Nominatim (free, no access key) and shows them for transfer into the table above -- writes nothing into a row automatically.')],
                [
                    'type' => 'SelectLocation',
                    'name' => 'KartenStandort',
                    'caption' => $this->Translate('Or pick on the map'),
                    'value' => $this->mapDefaultLocation(),
                ],
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('📍 Take map point as location'),
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

        $free['datenquellen'] = [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('🌐  Data sources'),
            'expanded' => $expDatenquellen,
            'onClick' => $this->rememberPanelOnClick('Datenquellen', $expDatenquellen),
            'items' => [
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenAllgemeinPanel',
                    'caption' => $this->Translate('General (cross-border)'),
                    'expanded' => $expDqAllgemein,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenAllgemein', $expDqAllgemein),
                    'items' => [
                        ['type' => 'CheckBox', 'name' => 'QuelleMeteoalarm', 'caption' => $this->Translate('Meteoalarm (Europe-wide weather warnings, 39 countries) -- important for mobile locations abroad')],
                        ['type' => 'Label', 'caption' => $this->Translate('Meteoalarm provides NO warning area (polygon/circle), only named administrative areas -- matching is therefore done by name (the location is assigned to a district/region via reverse geocoding), not geometrically as with the other sources. This is less precise and is explicitly marked as "name match" in the message. For Germany, NINA or the direct DWD connection take over automatically (precise polygons, in German): as soon as one of them is active, the Meteoalarm Germany feed is no longer queried -- it would only deliver the same DWD warnings a second time in English. Meteoalarm is therefore mainly worthwhile for locations elsewhere in Europe.')],
                        ['type' => 'Label', 'caption' => $this->Translate('Own weather station: triggers INDEPENDENTLY of the other sources as soon as the locally measured wind gust/rain rate exceeds your own threshold -- a safety net in case official warnings do not report an event that actually occurs locally, or not in time. 0 = disabled.')],
                        $this->statusLabelItem('WetterstationStatusLabel', $this->wetterstationStatusLine(
                            $this->ReadPropertyInteger('WetterstationInstanceID'),
                            $this->ReadPropertyInteger('WetterstationWindVariableID'),
                            $this->ReadPropertyInteger('WetterstationRegenVariableID')
                        )),
                        [
                            'type' => 'SelectInstance',
                            'name' => 'WetterstationInstanceID',
                            'caption' => $this->Translate('Weather station instance'),
                            'onChange' => 'WHUB_OnChangeWetterstation($id, $WetterstationInstanceID, $WetterstationWindVariableID, $WetterstationRegenVariableID);',
                        ],
                        [
                            'type' => 'Button',
                            'caption' => $this->Translate('🔎 Search weather station (Froggit/Sainlogic/ELV/Meteobridge & co.)'),
                            'onClick' => 'echo WHUB_DiscoverWetterstation($id);',
                        ],
                        ['type' => 'Label', 'caption' => $this->Translate('Finds Froggit (including Ecowitt hardware sold as Sainlogic/HP1000SE/WH3000SE), Sainlogic/ELV (Wunderground protocol) and Meteobridge/Meteohub (which as a data logger aggregator also covers other brands such as DAVIS). If none of them is found, the whole system is finally searched for variables with the matching Symcon standard profile (e.g. a KNX weather station that already has a profile) -- only taken over if there is exactly one match.')],
                        ['type' => 'Label', 'caption' => $this->Translate('Other makes/brands (e.g. KNX weather station without an assigned profile, Netatmo, TFA, Bresser, Homematic): no automatic detection possible -- KNX names variables freely according to its own ETS configuration, other modules use their own names. Simply select the matching wind/rain variables of your system manually below (one is enough, both are not required).')],
                        ['type' => 'SelectVariable', 'name' => 'WetterstationWindVariableID', 'caption' => $this->Translate('Wind variable (manual, if no Froggit instance)'), 'onChange' => 'WHUB_OnChangeWetterstation($id, $WetterstationInstanceID, $WetterstationWindVariableID, $WetterstationRegenVariableID);'],
                        ['type' => 'SelectVariable', 'name' => 'WetterstationRegenVariableID', 'caption' => $this->Translate('Rain variable (manual, if no Froggit instance)'), 'onChange' => 'WHUB_OnChangeWetterstation($id, $WetterstationInstanceID, $WetterstationWindVariableID, $WetterstationRegenVariableID);'],
                        ['type' => 'Label', 'caption' => $this->Translate('If a variable is set manually above, it takes precedence over the Froggit instance -- mixing is possible (e.g. wind from a KNX weather station, rain from the Froggit gateway).')],
                        ['type' => 'Label', 'caption' => $this->Translate('Wind gust -- three thresholds instead of one: an awning is much more sensitive to wind than a robust external blind. Each protective action row chooses via its own "From severity" field from which level it reacts.')],
                        [
                            'type' => 'PopupButton',
                            'caption' => $this->Translate('Which threshold should I choose?'),
                            'popup' => [
                                'caption' => $this->Translate('Wind gust thresholds -- classification'),
                                'items' => [
                                    ['type' => 'Label', 'caption' => $this->Translate('The default values follow the official DWD wind warning levels (wind gusts from 50 km/h, gale-force gusts 65-89 km/h, severe gale-force gusts 90-104 km/h) -- the Moderate level is deliberately lower because property protection (awning, external blind) needs more lead time than a pure warning for people.')],
                                    ['type' => 'Label', 'caption' => $this->Translate('Moderate (default 40 km/h): just above EN 13561 wind resistance class 2 (up to 38 km/h) -- the limit above which an average residential awning is at risk. Recommendation for awnings/sun sails: set "From severity" to Moderate.')],
                                    ['type' => 'Label', 'caption' => $this->Translate('Severe (default 65 km/h): corresponds to the DWD "Sturmböen" (gale-force gusts). Recommendation for standard external blinds/roller shutters/garage doors: set "From severity" to Severe.')],
                                    ['type' => 'Label', 'caption' => $this->Translate('Extreme (default 90 km/h): corresponds to the DWD "schwere Sturmböen" (severe gale-force gusts) -- only useful for particularly robust systems or as a general catch-all value.')],
                                    ['type' => 'Label', 'caption' => $this->Translate('The actual wind resistance class depends on the product, the guide rails and the installation -- the manufacturer specification (if available) always takes precedence over these guide values.')],
                                ],
                            ],
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'WetterstationWindSchwelleModerate', 'caption' => $this->Translate('Wind gust threshold -- Moderate (km/h)'), 'digits' => 1, 'minValue' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'WetterstationWindSchwelleSevere', 'caption' => $this->Translate('Wind gust threshold -- Severe (km/h)'), 'digits' => 1, 'minValue' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'WetterstationWindSchwelleExtreme', 'caption' => $this->Translate('Wind gust threshold -- Extreme (km/h)'), 'digits' => 1, 'minValue' => 1],
                        ['type' => 'Label', 'caption' => $this->Translate('Rain rate -- also three thresholds instead of one, analogous to the wind gust.')],
                        [
                            'type' => 'PopupButton',
                            'caption' => $this->Translate('Which rain threshold should I choose?'),
                            'popup' => [
                                'caption' => $this->Translate('Rain rate thresholds -- classification'),
                                'items' => [
                                    ['type' => 'Label', 'caption' => $this->Translate('The default values correspond to the official DWD heavy rain warning levels (1 hour): Moderate 15 mm/h ("Markante Wetterwarnung"), Severe 25 mm/h ("Unwetterwarnung"), Extreme 40 mm/h ("Warnung vor extremem Unwetter").')],
                                    ['type' => 'Label', 'caption' => $this->Translate('As with the wind gust, each protective action row chooses via its own "From severity" field from which level it reacts (e.g. close windows already at Moderate, a robust garage door only at Severe).')],
                                ],
                            ],
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'WetterstationRegenSchwelleModerate', 'caption' => $this->Translate('Rain rate threshold -- Moderate (mm/h)'), 'digits' => 1, 'minValue' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'WetterstationRegenSchwelleSevere', 'caption' => $this->Translate('Rain rate threshold -- Severe (mm/h)'), 'digits' => 1, 'minValue' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'WetterstationRegenSchwelleExtreme', 'caption' => $this->Translate('Rain rate threshold -- Extreme (mm/h)'), 'digits' => 1, 'minValue' => 1],
                        ['type' => 'CheckBox', 'name' => 'WetterstationAutoRueckstellung', 'caption' => $this->Translate('Automatic reset after the wind calms down (external blind/awning/garage)')],
                        ['type' => 'Label', 'caption' => $this->Translate('The ONLY exception to "no automatic reset" in the whole module -- deliberately allowed only here, because your own weather station (unlike an official warning) provides a continuous local live value. Automatically sets an external blind/awning/garage door action triggered by your own weather station back to the value it had immediately before triggering -- but only after wind AND rain have been continuously below the Moderate threshold for 20 minutes (calm phase against short wind gust pauses in the middle of a storm). Applies ONLY to actions triggered by your own weather station, not to official warnings. Window/boot/siren/script actions remain without reset as before. Every reset is logged in the warning history (WHUB_GetHistory()), a pending reset is shown at the bottom of the "Check & status" panel.')],
                        ['type' => 'NumberSpinner', 'name' => 'PollIntervalMinutes', 'caption' => $this->Translate('Polling interval (minutes)'), 'minValue' => 1, 'maxValue' => 60],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenDePanel',
                    'caption' => $this->Translate('🇩🇪  Germany'),
                    'expanded' => $expDqDe,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenDe', $expDqDe),
                    'items' => [
                        ['type' => 'CheckBox', 'name' => 'QuelleNina', 'caption' => $this->Translate('NINA aggregation (MoWaS/Katwarn/Biwapp/DWD/floods/police, warnung.bund.de)')],
                        ['type' => 'CheckBox', 'name' => 'QuelleDwd', 'caption' => $this->Translate('Additionally direct DWD weather warnings (more detail than the NINA summary)')],
                        ['type' => 'CheckBox', 'name' => 'QuellePegelonline', 'caption' => $this->Translate('Water levels (PEGELONLINE/WSV) -- warns about levels above the mean or previous highest water level near a location')],
                        ['type' => 'CheckBox', 'name' => 'QuelleBfsOdl', 'caption' => $this->Translate('Radioactivity (BfS ambient dose rate) -- own threshold, no official alert level')],
                        ['type' => 'NumberSpinner', 'name' => 'BfsOdlSchwellwert', 'caption' => $this->Translate('Radioactivity threshold (µSv/h)'), 'digits' => 3, 'minValue' => 0.05],
                        [
                            'type' => 'PopupButton',
                            'caption' => $this->Translate('What does this value mean? (classification of dose rate/exposure time)'),
                            'popup' => [
                                'caption' => $this->Translate('Ambient dose rate -- classification'),
                                'items' => $this->bfsOdlReferencePopupItems(),
                            ],
                        ],
                        ['type' => 'CheckBox', 'name' => 'QuelleWaldbrandDe', 'caption' => $this->Translate('Additionally German forest fire danger index (DWD) -- official 5-level scale, the nearest measuring station for each location')],
                        ['type' => 'NumberSpinner', 'name' => 'WaldbrandDeSchwelle', 'caption' => $this->Translate('From danger level (1-5)'), 'minValue' => 1, 'maxValue' => 5],
                        ['type' => 'Label', 'caption' => $this->Translate('Uses the official DWD 5-level forest fire danger index scale (1 = very low danger to 5 = very high danger). There is no single file for "today, all stations" -- WarnHub therefore determines the nearest of the 484 DWD measuring stations for each German location and queries ONLY its current value instead of fetching all stations at every check. Requires the PHP extension "zlib" (for gzip, usually standard); without it this source stays inactive, all other sources keep working independently. The DWD only calculates the index during the forest fire season (roughly March to September/October): if the last station value is more than 3 days old, it counts as outdated and does not trigger.')],
                        ['type' => 'CheckBox', 'name' => 'QuelleOzonDe', 'caption' => $this->Translate('Additionally German ozone pollution (Federal Environment Agency) -- official air quality index, ozone/summer smog only')],
                        ['type' => 'NumberSpinner', 'name' => 'UbaOzonSchwelle', 'caption' => $this->Translate('From air quality index level (0-4)'), 'minValue' => 0, 'maxValue' => 4],
                        ['type' => 'Label', 'caption' => $this->Translate('Uses the Federal Environment Agency (UBA) as the official source for ozone readings, explicitly ONLY ozone/summer smog -- no general air quality monitoring and no private indoor sensors (WarnHub is not meant for that, see forum discussion). Official 5-level scale (0 = very good to 4 = very poor), calculated from the hourly ozone concentration per measuring station (0-60 µg/m³ = level 0, ... above 240 µg/m³ = level 4).')],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenAtPanel',
                    'caption' => $this->Translate('🇦🇹  Austria'),
                    'expanded' => $expDqAt,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenAt', $expDqAt),
                    'items' => [
                        ['type' => 'CheckBox', 'name' => 'QuelleGeosphereAt', 'caption' => $this->Translate('Additionally direct GeoSphere Austria warnings (warnungen.zamg.at) -- coordinate-exact for Austrian locations, more precise than Meteoalarm')],
                        ['type' => 'Label', 'caption' => $this->Translate('If this direct connection is active, it automatically takes over Austrian locations from Meteoalarm (coordinate-exact instead of name match) -- analogous to the direct DWD connection, which replaces the corresponding NINA channel for German locations. Official source (GeoSphere Austria/ZAMG), no access key required.')],
                    ],
                ],
                [
                    'type' => 'ExpansionPanel',
                    'name' => 'DatenquellenChPanel',
                    'caption' => $this->Translate('🇨🇭  Switzerland'),
                    'expanded' => $expDqCh,
                    'onClick' => $this->rememberPanelOnClick('DatenquellenCh', $expDqCh),
                    'items' => [
                        ['type' => 'CheckBox', 'name' => 'QuelleAlertSwissCh', 'caption' => $this->Translate('Additionally Alertswiss (alert.swiss/BABS) -- Swiss counterpart to NINA: cantonal fire bans, forest fires, drought, rockfall/landslides and other civil protection messages, coordinate-exact')],
                        ['type' => 'Label', 'caption' => $this->Translate('Complements Meteoalarm for Swiss locations, does NOT replace it: Alertswiss does not carry MeteoSwiss weather warnings (storm, heavy rain, hail), they still only come via Meteoalarm above. Official source of the Federal Office for Civil Protection (BABS), no access key required. Covers more than weather, so it also complements the specialised sources below (BAFU floods, earthquakes).')],
                        ['type' => 'CheckBox', 'name' => 'QuelleBafuHydroCh', 'caption' => $this->Translate('Additionally Swiss flood danger (BAFU/LINDAS) -- official danger level for rivers and lakes')],
                        ['type' => 'NumberSpinner', 'name' => 'BafuHydroSchwelle', 'caption' => $this->Translate('From danger level (2-5)'), 'minValue' => 2, 'maxValue' => 5],
                        ['type' => 'Label', 'caption' => $this->Translate('Uses the official BAFU 5-level flood danger scale (1 = no/low danger to 5 = very high danger) -- unlike PEGELONLINE, BfS and your own weather station, NOT a construct of its own but a real official classification. Only the threshold FROM which WarnHub reports is adjustable.')],
                        ['type' => 'CheckBox', 'name' => 'QuelleSedErdbebenCh', 'caption' => $this->Translate('Additionally Swiss earthquakes (Swiss Seismological Service SED, ETH Zurich) -- from magnitude 2.5 (the perceptibility threshold according to SED)')],
                        ['type' => 'Label', 'caption' => $this->Translate('Official seismological monitoring, not a weather source. Unlike weather warnings, the source itself provides neither a warning area nor a "valid until" -- an earthquake is an instantaneous event. WarnHub therefore draws its own circle around the epicentre (radius graded by magnitude -- a rough approximation of its own, NOT an official hazard area) and a 2-hour time window (relevant for aftershocks), after which it no longer counts as active.')],
                        [
                            'type' => 'ExpansionPanel',
                            'name' => 'HagelschutzChPanel',
                            'caption' => $this->Translate('🧪  BETA: hail protection Switzerland (VKF signal box)'),
                            'expanded' => $expHagelschutz,
                            'onClick' => $this->rememberPanelOnClick('HagelschutzCh', $expHagelschutz),
                            'items' => [
                                ['type' => 'Label', 'caption' => $this->Translate('EXPLICITLY BETA -- untested: this connection was built solely from the official VKF documentation and the source code of an active community adapter. Without a signal box of my own, the live query itself could not be cross-checked -- the "verify live" rule followed throughout this module is deliberately suspended here. Feedback (works/does not work) is explicitly welcome, see the feedback hint at the end of the form.')],
                                ['type' => 'Label', 'caption' => $this->Translate('Requires a VKF hail protection signal box physically registered at a specific Swiss building (hagelschutz-einfach-automatisch.ch) -- not a pure software access. Without your own signal box simply leave empty, then this source stays inactive.')],
                                ['type' => 'ValidationTextBox', 'name' => 'HagelschutzPollUrl', 'caption' => $this->Translate('Poll URL of your own signal box')],
                                ['type' => 'Label', 'caption' => $this->Translate('Enter the complete address from your own signal box configuration (format https://meteo.netitservices.com/api/v1/devices/<deviceId>/poll?hwtypeId=<HID>) -- do not assemble it yourself from deviceId/hwtypeId, as the format may differ between signal box generations. Reports a hail warning at the Symcon system location as soon as the signal box returns "currentState" other than 0 (incl. test alarm).')],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $expBenachrichtigung = $this->getPanelExpandedState('Benachrichtigung', true);
        $free['benachrichtigung'] = [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('🔔  Notification'),
            'expanded' => $expBenachrichtigung,
            'onClick' => $this->rememberPanelOnClick('Benachrichtigung', $expBenachrichtigung),
            'items' => [
                ['type' => 'CheckBox', 'name' => 'PushAktiv', 'caption' => $this->Translate('Push notification to activated push targets (WebFront/tile visualization/Telegram/Pushover/e-mail, also mobile phone)')],
                ['type' => 'Select', 'name' => 'PushSound', 'caption' => $this->Translate('Sound (WebFront/tile visualization only)'), 'options' => $this->soundOptions()],
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('🔎 Search push targets'),
                    'onClick' => 'echo WHUB_DiscoverWebFronts($id);',
                ],
                $this->statusLabelItem('WebFrontStatusLabel', $this->webfrontStatusLine()),
                ['type' => 'Label', 'caption' => $this->Translate('Searches WebFront instances, tile visualization instances (the newer Symcon interface, under "Visualization instances" in the object tree -- often the interface actually used), as well as -- if installed -- Telegram bot (official Symcon module), Pushover (community module) and SMTP instances (official Symcon module, for e-mail). Found targets are active by default (receive push) -- simply deselect unwanted ones in the active column. A new search only adds new targets and leaves existing deselections untouched.')],
                ['type' => 'Label', 'caption' => $this->Translate('Telegram/Pushover/e-mail: connection built from the real source code or the real function signature of the respective modules, but without my own Telegram bot/Pushover account not verifiable live -- feedback welcome, see the feedback hint at the end of the form.')],
                ['type' => 'Label', 'caption' => $this->Translate('E-mail (SMTP): a found SMTP instance only knows the sending route, not the recipient -- it is therefore created INACTIVE at first. Enter the desired e-mail address in the "Target address" column first, then activate it in the "Active" column. Several addresses: simply enter them separated by comma or semicolon in the same field.')],
                [
                    'type' => 'List',
                    'name' => 'WebFronts',
                    'rowCount' => $this->listRowCount(count($this->decodeWebFronts()), 3),
                    'add' => false,
                    'delete' => true,
                    'columns' => [
                        ['caption' => $this->Translate('Name'), 'name' => 'Name', 'width' => '190px', 'edit' => ['type' => 'ValidationTextBox', 'enabled' => false]],
                        ['caption' => $this->Translate('Type'), 'name' => 'Typ', 'width' => '160px', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'WebFront', 'value' => 'webfront'],
                            ['caption' => $this->Translate('Tile visualization'), 'value' => 'kachel'],
                            ['caption' => 'Telegram', 'value' => 'telegram'],
                            ['caption' => 'Pushover', 'value' => 'pushover'],
                            ['caption' => $this->Translate('E-mail (SMTP)'), 'value' => 'email'],
                            ['caption' => 'Notification Control (IPSView & Co.)', 'value' => 'notification'],
                        ]]],
                        ['caption' => $this->Translate('Instance ID'), 'name' => 'InstanceID', 'width' => '90px', 'edit' => ['type' => 'NumberSpinner', 'enabled' => false]],
                        ['caption' => $this->Translate('Active'), 'name' => 'Aktiv', 'width' => '70px', 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Target address (e-mail only)'), 'name' => 'Zieladresse', 'width' => '210px', 'edit' => ['type' => 'ValidationTextBox']],
                    ],
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Quiet period: pauses ONLY the notification itself -- detection and protective actions keep running unchanged (e.g. on holiday the awning still retracts in a storm, only the phone stays silent). A click on "🧪 Send test notification" above also arrives during a pause.')],
                [
                    'type' => 'Label',
                    'name' => 'SnoozeStatusLabel',
                    'caption' => $this->snoozeStatusLine(),
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        ['type' => 'Button', 'caption' => $this->Translate('Pause for 1 h'), 'onClick' => 'echo WHUB_SnoozePush($id, 60);'],
                        ['type' => 'Button', 'caption' => $this->Translate('Pause for 4 h'), 'onClick' => 'echo WHUB_SnoozePush($id, 240);'],
                        ['type' => 'Button', 'caption' => $this->Translate('Pause for 24 h'), 'onClick' => 'echo WHUB_SnoozePush($id, 1440);'],
                        ['type' => 'Button', 'caption' => $this->Translate('🔔 Resume notifications'), 'onClick' => 'echo WHUB_CancelSnooze($id);'],
                    ],
                ],
            ],
        ];

        $expSchutzaktionen = $this->getPanelExpandedState('Schutzaktionen', false);
        $free['schutzaktionen'] = [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('🛡️  Protective actions (blinds/external blinds, awnings, garage door, windows, boot, sirens, scripts)'),
            'expanded' => $expSchutzaktionen,
            'onClick' => $this->rememberPanelOnClick('Schutzaktionen', $expSchutzaktionen),
            'items' => [
                [
                    'type' => 'ExpansionPanel',
                    'caption' => $this->Translate('🛑 SAFETY NOTICE -- windows/boot on vehicles: please be sure to read'),
                    'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => $this->Translate('Concerns the two action types "Close windows" and "Close boot/tailgate": neither the vehicle nor WarnHub can detect whether a person, a hand or another body part is in the range of motion of the closing window or tailgate.')],
                        ['type' => 'Label', 'caption' => $this->Translate('Unlike many garage doors, there is NO reliable anti-trap or obstacle detection that WarnHub could rely on -- the action closes unattended, without warning and regardless of whether someone is standing next to it. This can lead to serious injuries.')],
                        ['type' => 'Label', 'caption' => $this->Translate('Therefore only activate these two action types if you are sure that nobody can be within reach of the vehicle unattended when a warning arrives (e.g. a vehicle that is only ever parked on private property not accessible to the public). WHEN IN DOUBT, DO WITHOUT THIS FUNCTION and close windows/boot yourself by hand as soon as you see the warning.')],
                    ],
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Automatically triggers an action for a matching warning -- e.g. raise external blinds, close the garage door, close car windows, switch an acoustic signal or run your own script. Each action fires only ONCE per warning, there is no automatic reset -- that deliberately remains up to the user.')],
                ['type' => 'Label', 'caption' => $this->Translate('Warnings often arrive hours before they actually become valid -- an action therefore does NOT fire immediately when the message arrives, but only shortly before the actual start (lead time below, so that e.g. the awning is safely retracted). Warnings without their own time information (rare) still trigger immediately; warnings that are already running/acute as well.')],
                ['type' => 'NumberSpinner', 'name' => 'SchutzaktionVorlaufMinuten', 'caption' => $this->Translate('Lead time before start of validity (minutes)'), 'minValue' => 0, 'maxValue' => 720],
                [
                    'type' => 'PopupButton',
                    'caption' => $this->Translate('Which fields do I need for which action type?'),
                    'popup' => [
                        'caption' => $this->Translate('Fields per action type'),
                        'items' => [
                            ['type' => 'Label', 'caption' => $this->Translate('Raise external blind/roller shutter, retract awning, close garage door, acoustic alarm: target variable (the switchable value, e.g. shutter/awning position or door control) + target value (the value set when triggering -- differs by manufacturer, e.g. 0 = open/raised/retracted, please check on your own actuator).')],
                            ['type' => 'Label', 'caption' => $this->Translate('Set variable to value: target variable + target value, nothing else -- for anything that simply needs to be set to a value, e.g. the level of the home ventilation to 0 when smoke gases are reported (tick the "💨 Smoke/hazardous substances" category). WarnHub does NOT reset the value after an all-clear -- that remains up to the user (or your own script).')],
                            ['type' => 'Label', 'caption' => $this->Translate('Close windows: target variable only, no target value needed (always switches the action to "On" -- with Tessie\'s own close-windows action this triggers Tesla\'s directed close command, safe even with windows that are already closed). 🛑 Be sure to observe the safety notice at the very top of the panel -- risk of trapping, no obstacle detection.')],
                            ['type' => 'Label', 'caption' => $this->Translate('Close boot/tailgate: IMPORTANT -- Tesla\'s boot command is a pure toggle without direction, triggering it with the tailgate already closed would OPEN it instead of closing it. Therefore, in addition to the target variable, a state variable that names the currently open tailgates must be given (e.g. Tessie\'s "Tür-/Klappenstatus") -- it only triggers if "Kofferraum" or "Heckklappe" appears in it, otherwise nothing happens. Without a valid state variable the action does NOT fire AT ALL (safety lock, no guessing). 🛑 Be sure to observe the safety notice at the very top of the panel -- risk of trapping, no obstacle detection.')],
                            ['type' => 'Label', 'caption' => $this->Translate('Acoustic alarm additionally: auto-off (seconds) -- 0 means no automatic switch-off.')],
                            ['type' => 'Label', 'caption' => $this->Translate('Run script: target script instead of target variable/target value.')],
                            ['type' => 'Label', 'caption' => $this->Translate('Several triggers at once (e.g. the awning should retract in storm AND hail): simply tick several boxes in the same row -- the action fires as soon as ANY ticked category applies. No box ticked = the action applies to every category. The automatic object tree search ticks storm + hail for external blinds/awnings, and additionally heavy rain for closing windows.')],
                            ['type' => 'Label', 'caption' => $this->Translate('An empty "Location only" means "all FIXED locations", NOT mobile ones as well (bound to a live location, see locations panel) -- otherwise e.g. a storm over Hamburg that only the mobile location reports would retract the blind installed at home. This applies to every action automatically, no setup required.')],
                        ],
                    ],
                ],
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('🔎 Search object tree for external blinds/blinds/awnings/garage/close windows/tailgate/siren'),
                    'onClick' => 'echo WHUB_DiscoverSchutzaktionen($id);',
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Matches found are pre-filled and usually added ACTIVATED as a new row (severity "High" as a cautious default) -- simply deselect unwanted ones in the active column. Exception boot/tailgate: stays INACTIVE without an automatically found state variable (safety lock, see help button above). A new search leaves existing rows/deselections untouched and only adds new matches.')],
                [
                    'type' => 'List',
                    'name' => 'Schutzaktionen',
                    'rowCount' => $this->listRowCount(count($this->decodeSchutzaktionen())),
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => $this->Translate('Name'), 'name' => 'Name', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Active'), 'name' => 'Aktiv', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Type'), 'name' => $this->Translate('Type'), 'width' => '190px', 'add' => 'raffstore', 'edit' => ['type' => 'Select', 'options' => $this->actionTypeOptions()]],
                        ...array_map(fn ($f) => ['caption' => $this->Translate($f[1]), 'name' => $f[0], 'width' => '75px', 'add' => false, 'edit' => ['type' => 'CheckBox']], array_values(self::CATEGORY_FIELDS)),
                        ['caption' => $this->Translate('From severity'), 'name' => 'MinSeverity', 'width' => '140px', 'add' => 3, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
                        ['caption' => $this->Translate('Location only (empty=all fixed)'), 'name' => 'StandortFilter', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Target variable'), 'name' => 'ZielVariableID', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->Translate('Target value'), 'name' => 'ZielWert', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ['caption' => $this->Translate('State variable (boot only)'), 'name' => 'ZustandsVariableID', 'width' => '190px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->Translate('Target script'), 'name' => 'ZielSkriptID', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectScript']],
                        ['caption' => $this->Translate('Auto-off (s)'), 'name' => 'AutoOffSekunden', 'width' => '100px', 'add' => 60, 'edit' => ['type' => 'NumberSpinner', 'minValue' => 0]],
                    ],
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Test per alert type: IMMEDIATELY triggers all active protective actions that apply to the selected alert type (ticked category OR none ticked at all) -- independent of a real warning, of the location filter and of the minimum severity. Only checks whether the action really does the right thing (e.g. does the awning really retract?), not the warning detection itself. Without a real warning no automatic reset is scheduled -- reset by hand afterwards.')],
                [
                    'type' => 'RowLayout',
                    'items' => (function () {
                        $buttons = [];
                        foreach (self::CATEGORY_FIELDS as $key => $f) {
                            $buttons[] = [
                                'type' => 'Button',
                                'caption' => sprintf($this->Translate('Test %s'), $this->Translate($f[1])),
                                'onClick' => "echo WHUB_TestSchutzaktionen(\$id, '" . $key . "');",
                            ];
                        }
                        return $buttons;
                    })(),
                ],
            ],
        ];

        $expFenster = $this->getPanelExpandedState('FensterUeberwachung', false);
        $free['fenster'] = [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('🪟  Window/door monitoring'),
            'expanded' => $expFenster,
            'onClick' => $this->rememberPanelOnClick('FensterUeberwachung', $expFenster),
            'items' => [
                ['type' => 'Label', 'caption' => $this->Translate('WarnHub cannot close a window or a door by itself -- unlike external blinds/awnings/garages there is no generic actuator for that. But if an opening contact exists, WarnHub can detect that it is open and point this out specifically when a matching warning is active.')],
                [
                    'type' => 'Button',
                    'caption' => $this->Translate('🔎 Search object tree for window/door contacts'),
                    'onClick' => 'echo WHUB_DiscoverFensterkontakte($id);',
                ],
                ['type' => 'Label', 'caption' => $this->Translate('Finds Boolean variables both via a classic Symcon profile (~Window/~Door, also .Reversed) and via the variable presentation introduced in Symcon 9.0 with an "Opened"/"Open" option -- regardless of manufacturer/integration (e.g. Matter contacts as well). Matches found are pre-filled and activated with storm/hail/heavy rain (an open window lets wind/water in with all three). A new search only adds new matches.')],
                [
                    'type' => 'List',
                    'name' => 'Fensterkontakte',
                    'rowCount' => $this->listRowCount(count($this->decodeFensterkontakte())),
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        ['caption' => $this->Translate('Name'), 'name' => 'Name', 'width' => '190px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Active'), 'name' => 'Aktiv', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ...array_map(fn ($f) => ['caption' => $this->Translate($f[1]), 'name' => $f[0], 'width' => '75px', 'add' => in_array($f[0], ['KatSturm', 'KatHagel', 'KatStarkregen'], true), 'edit' => ['type' => 'CheckBox']], array_values(self::CATEGORY_FIELDS)),
                        ['caption' => $this->Translate('From severity'), 'name' => 'MinSeverity', 'width' => '140px', 'add' => 3, 'edit' => ['type' => 'Select', 'options' => $this->severityOptions()]],
                        ['caption' => $this->Translate('Location only (empty=all fixed)'), 'name' => 'StandortFilter', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => $this->Translate('Contact variable'), 'name' => 'VariableID', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ],
                ],
                ['type' => 'Label', 'caption' => $this->Translate('A push is sent only ONCE per (warning, contact) -- if you close the window and open it again later during the same warning, a new message is sent. Uses the same push targets as normal warnings and respects the push quiet period.')],
            ],
        ];

        $pruefungItems = [
            ['type' => 'Label', 'caption' => $this->Translate('WarnHub automatically queries the data sources configured above at the set polling interval and matches them against the locations/protective actions further up. The button below additionally triggers the whole thing immediately (e.g. to test the setup directly), without waiting for the next automatic run.')],
            [
                'type' => 'Label',
                'name' => 'PollStatusLabel',
                'caption' => $this->getPollStatusLine(),
            ],
            [
                'type' => 'Button',
                'caption' => $this->Translate('🔎 Check now'),
                'onClick' => 'echo WHUB_Poll($id);',
            ],
        ];
        $restoreLine = $this->wetterstationRestoreStatusLine();
        if ($restoreLine !== null) {
            $pruefungItems[] = ['type' => 'Label', 'name' => 'WetterstationRestoreStatusLabel', 'caption' => $restoreLine];
        }
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('For your own dashboard (e.g. IPSView): the same values are available below in the object tree as four separate variables (active warnings, highest severity, status, last check) -- IPSView builds views from existing Symcon variables, not via a push channel of its own, so no separate setup is needed here.')];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('🧊 Ready-made WebFront tiles: five more variables in the object tree contain finished, self-contained HTML -- nothing to build yourself, simply link them into the WebFront area in the object tree. "Tile (compact)", "Tile (overview)" and "Tile (all warnings)" adapt automatically to light/dark and load no external resources. All of them also contain three small links to the official warning maps (DWD/ZAMG/MeteoSwiss) at the bottom.')];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('📜 "Tile (all warnings)": like "Tile (overview)", but WITHOUT the cap of 8 -- scrollable list of all active warnings, sorted by severity. Each card has a "✕" button to hide it, plus a filter bar per event type ("🚫 Waldbrandgefahr" etc.) -- both remembered only in the respective browser (like the zoom level of "Tile (map)"), affecting only the display, not push/history/protective actions. Each card can also be expanded with a click (recommended action, validity period, full official description).')];
        $pruefungItems[] = ['type' => 'CheckBox', 'name' => 'AlleWarnungenAufgeklappt', 'caption' => $this->Translate('"Tile (all warnings)": show cards expanded by default (instead of collapsed) -- clicking to expand/collapse is still possible per card')];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('Request by ruan, Symcon forum, 09.09.2026: rather see everything at once without having to click each card individually.')];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('🗺️ "Tile (map)": shows a street map (Esri) with EVERY active location as its own coloured pin (mobile locations too -- follows their live position) plus a small clickable legend. The start view shows all locations together; a click on a pin/legend entry zooms to it -- each browser remembers the choice for itself (e.g. your phone on the road on "Kohlekasten", the tablet at home still on all or your own location). Unlike the other tiles it loads external resources (Leaflet.js from unpkg.com, map tiles from server.arcgisonline.com -- deliberately not the OpenStreetMap tile servers, which require a referer header for embedded third-party widgets like this tile and may block them depending on browser/WebFront).')];
        $pruefungItems[] = ['type' => 'NumberSpinner', 'name' => 'KartenkachelHoehePx', 'caption' => $this->Translate('Height of "Tile (map)" in pixels (0 = automatic, fit to the tile)'), 'minValue' => 0, 'maxValue' => 2000];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('At 0 the tile tries to take over the height of the surrounding WebFront/tile visualization tile -- this does not work reliably in every configuration (field report kronos/Bricoleur, Symcon forum, 07.09.2026: map only scaled in width, not in height). If you see this behaviour, enter a fixed number of pixels here.')];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('🇦🇹 "Tile (ZAMG warning map, Austria)": embeds the official ZAMG warning map directly (iframe) -- especially for Austrian users. Currently shows all of Austria, not yet automatically centred on a single location.')];
        $pruefungItems[] = ['type' => 'NumberSpinner', 'name' => 'ZamgKachelHoehePx', 'caption' => $this->Translate('Height of "Tile (ZAMG warning map, Austria)" in pixels (0 = automatic, fit to the tile)'), 'minValue' => 0, 'maxValue' => 2000];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('At 0 the tile tries to take over the height of the surrounding WebFront/tile visualization tile -- this does not work reliably in every configuration (same effect as with "Tile (map)", field report kronos/Bricoleur and ruan/Andreas, Symcon forum). If you see this behaviour, enter a fixed number of pixels here.')];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('Warning history (also past warnings, not only currently active warnings/all-clears -- up to 500 entries) can be retrieved for your own evaluations/scripts with the function WHUB_GetHistory($id, $limit), no form field of its own needed.')];
        $pruefungItems[] = ['type' => 'Label', 'caption' => $this->Translate('To test the delivery route, independent of a real warning:')];
        $pruefungItems[] = [
            'type' => 'Button',
            'caption' => $this->Translate('🧪 Send test notification'),
            'onClick' => 'echo WHUB_TestPush($id);',
        ];
        $expPruefung = $this->getPanelExpandedState('PruefungStatus', true);
        $free['pruefung'] = [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('🔎  Check & status'),
            'expanded' => $expPruefung,
            'onClick' => $this->rememberPanelOnClick('PruefungStatus', $expPruefung),
            'items' => $pruefungItems,
        ];

        // Fachpanels in der vom Nutzer gewählten Reihenfolge / mit gewähltem
        // Zustand einhängen (SUITE.md 3a). "Standard" lässt den oben per
        // getPanelExpandedState() ermittelten Zustand unverändert. Ausgeblendet
        // heißt visible:false, NIE weglassen -- sonst gingen die gespeicherten
        // Werte des Bereichs beim "Übernehmen" verloren.
        $layout = $this->formLayout(array_keys($free));
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                $panel['visible'] = false;
            }
            $form['elements'][] = $panel;
        }
        $form['elements'][] = $this->LayoutPanel($layout);

        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }
        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    // ----------------------------------------------------------------
    //  "🪟 Formular: Aufbau" -- Verbund-Konvention SUITE.md 3a (Dietmar,
    //  29.10.2026, Referenz Batteriewächter formLayout()/LayoutPanel()/
    //  ResetFormLayout()). Symcon merkt sich das Auf-/Zuklappen nicht
    //  selbst; der Nutzer legt je Fachpanel Zustand und Position fest.
    //  Nur die sechs Fachpanels sind wählbar, die festen Panels oben (Wozu,
    //  Neu, Doku) und unten (Forum, Über dieses Modul) nicht.
    // ----------------------------------------------------------------

    /** Stabile Schlüssel je Fachpanel (NICHT die Caption, die darf sich ändern) => Anzeigename in der Aufbau-Liste. Reihenfolge = Standardreihenfolge. */
    private const FORM_PANELS = [
        'standorte'        => '📍 Locations',
        'datenquellen'     => '🌐 Data sources',
        'benachrichtigung' => '🔔 Notification',
        'schutzaktionen'   => '🛡️ Protective actions',
        'fenster'          => '🪟 Window/door monitoring',
        'pruefung'         => '🔎 Check & status',
    ];

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Property
     * "FormLayout". Unbekannte Schlüssel in der Property werden ignoriert,
     * fehlende (neues Panel nach einem Update) fallen auf Standard und
     * Standardposition zurück -- neue Panels erscheinen also automatisch.
     * Widerspruch bei mehreren Haken: ausgeblendet vor zugeklappt vor
     * aufgeklappt. Gleiche Position: Standardreihenfolge (stabil über idx).
     *
     * @param string[] $keys Schlüssel in Standardreihenfolge
     * @return array<string,array{open:string,pos:int,idx:int}> sortiert
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string) $this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string) $row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int) $r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, fn ($a, $b) => [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]);
        return $out;
    }

    /** @param array<string,array{open:string,pos:int,idx:int}> $layout Ergebnis von formLayout() */
    private function LayoutPanel(array $layout): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = $this->Translate(self::FORM_PANELS[$key] ?? $key);
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed', 'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => sprintf($this->Translate('🙈 Hidden: %s -- to show again, remove the tick at "Hidden". Hidden sections keep working, only the display is missing.'), implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'name' => 'FormLayoutPanel', 'expanded' => false,
            'caption' => $this->Translate('🪟  Form: layout') . ($hidden ? ' (' . count($hidden) . ' ausgeblendet)' : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => $this->Translate('Here you define which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing by itself, so the setting here applies every time the form opens. It takes effect after "Apply" the next time the form is opened.')],
                ['type' => 'Label', 'caption' => $this->Translate('No tick means "default": as the module intends (e.g. "Locations" and "Notification" expanded, sections last collapsed by hand stay closed). The position is a number; with the same number the default order is kept. "Hidden" removes a section from the display without losing anything: its settings are kept and keep working (meant for sections you rarely need, e.g. window/door monitoring without window contacts). To show it again, remove the tick at "Hidden" or click "Reset to default". If several ticks are set, "Hidden" beats "Collapsed" beats "Expanded". The sections at the top (purpose, new, documentation & help) and at the bottom (feedback in the Symcon forum, about this module) stay where they are and cannot be hidden.')],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => $this->Translate('Sections'), 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => $this->Translate('Section'), 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => $this->Translate('Key'), 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => $this->Translate('Expanded'), 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Collapsed'), 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('🙈 Hidden'), 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => $this->Translate('Position'), 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => $this->Translate('↩️ Reset to default'), 'onClick' => 'echo WHUB_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Knopf "Auf Standard zurücksetzen": nur die Liste im Formular zurücksetzen, keine Selbstpersistenz -- gespeichert wird erst mit "Übernehmen". */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach (self::FORM_PANELS as $key => $name) {
            $rows[] = ['Key' => $key, 'Panel' => $this->Translate($name), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return $this->Translate('ℹ️ Set to default. Click "Apply" below to save.');
    }

    /** Sichtbare Zeilenzahl einer Liste an ihren tatsächlichen Inhalt anpassen, statt fest zu scrollen -- Dietmars Fund 04.09.2026 (Schutzaktionen-Liste nach der Objektbaum-Suche). Plus 1 Luft für "Hinzufügen", nach oben gedeckelt, damit ein einzelnes Panel nicht die ganze Seite dominiert. */
    private function listRowCount(int $currentRows, int $min = 5, int $max = 20): int
    {
        return max($min, min($max, $currentRows + 1));
    }

    private function severityOptions(): array
    {
        return [
            ['caption' => $this->Translate('Low (Minor)'), 'value' => 1],
            ['caption' => $this->Translate('Medium (Moderate)'), 'value' => 2],
            ['caption' => $this->Translate('High (Severe)'), 'value' => 3],
            ['caption' => $this->Translate('Extreme (Extreme)'), 'value' => 4],
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
        ['rate' => 0.1, 'label' => 'typical natural background level in Germany'],
        ['rate' => 0.2, 'label' => 'upper natural range in Germany'],
        ['rate' => 0.3, 'label' => 'WarnHub default threshold'],
        ['rate' => 1.0, 'label' => ''],
        ['rate' => 10.0, 'label' => ''],
        ['rate' => 100.0, 'label' => ''],
        ['rate' => 1000.0, 'label' => 'corresponds to 1 mSv/h'],
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
            $this->Translate('Purely by calculation (with continuous presence at this spot) the annual precautionary value for the public (1 mSv) would be reached after %s -- this is a precautionary value, not a danger value.'),
            $this->formatDurationToPublicLimit($doseRateUSvH)
        );
    }

    /** Gestaffelte Einordnungstabelle Dosisleistung/Verweildauer als Popup-Inhalt (siehe Datenquellen-Panel). */
    private function bfsOdlReferencePopupItems(): array
    {
        $items = [
            ['type' => 'Label', 'caption' => $this->Translate('Natural ambient dose rate in Germany: approx. 0.05-0.2 µSv/h (sum of terrestrial and cosmic radiation, depending on location). Source: odlinfo.bfs.de.')],
            ['type' => 'Label', 'caption' => $this->Translate('Annual limit for the public: 1 millisievert (mSv) in addition to natural/medical radiation -- according to BfS explicitly a precautionary value, not a danger value: exceeding it means a statistically increased long-term risk, not immediate damage to health.')],
            ['type' => 'Label', 'caption' => $this->Translate('According to BfS, acute radiation damage (radiation sickness) only occurs from about 500 mSv as a SINGLE DOSE -- far above what ambient measuring stations can realistically show.')],
        ];
        foreach (self::BFS_ODL_REFERENZWERTE as $ref) {
            $caption = sprintf(
                $this->Translate('%s µSv/h%s -- annual precautionary value (1 mSv) reached by calculation after %s with CONTINUOUS presence.'),
                number_format($ref['rate'], $ref['rate'] < 1 ? 1 : 0, ',', '.'),
                $ref['label'] !== '' ? ' (' . $this->Translate($ref['label']) . ')' : '',
                $this->formatDurationToPublicLimit($ref['rate'])
            );
            $items[] = ['type' => 'Label', 'caption' => $caption];
        }
        $items[] = ['type' => 'Label', 'caption' => $this->Translate('Purely an orientation calculation (my own calculation, no official exposure time table) -- in a real situation only the assessment of the authorities counts (BfS, NINA warning app, civil protection), not this table.')];
        return $items;
    }

    private function actionTypeOptions(): array
    {
        return [
            ['caption' => $this->Translate('Raise external blind/roller shutter'), 'value' => 'raffstore'],
            ['caption' => $this->Translate('Retract awning'), 'value' => 'markise'],
            ['caption' => $this->Translate('Close garage door'), 'value' => 'garage'],
            ['caption' => $this->Translate('Close windows (e.g. Tesla)'), 'value' => 'fenster'],
            ['caption' => $this->Translate('Close boot/tailgate (only with state variable)'), 'value' => 'kofferraum'],
            ['caption' => $this->Translate('Acoustic alarm'), 'value' => 'sirene'],
            ['caption' => $this->Translate('Set variable to value (e.g. ventilation off)'), 'value' => 'wert'],
            ['caption' => $this->Translate('Run script'), 'value' => 'skript'],
        ];
    }

    private function soundOptions(): array
    {
        $sounds = ['alarm', 'bell', 'boom', 'buzzer', 'connected', 'dark', 'digital', 'drums', 'duck', 'full',
            'happy', 'horn', 'inception', 'kazoo', 'roll', 'siren', 'space', 'trickling', 'turn'];
        return array_map(fn ($s) => ['caption' => $s, 'value' => $s], $sounds);
    }

    /**
     * Notification-Control-Instanzen (Kerninstanz) -- DREI Wege hintereinander,
     * weil die Erkennung bei Praxis-Fund tomfes (Symcon-Forum, 02.10.2026)
     * trotz vorhandener Instanz (#25900 unter "Kern-Instanzen") nichts fand,
     * obwohl dieselbe Suche an Dietmars Instanz (#34698) funktionierte; welcher
     * der beiden ersten Wege dort scheiterte, ließ sich nicht klären. 1) exakte
     * GUID, 2) Modulliste nach dem VOLLEN Namen "Notification Control" (nicht
     * nur "notification" -- das träfe auch das Store-Modul "Benachrichtigung/
     * Notification"), 3) alle Instanzen durchgehen und Modulname bzw. Präfix
     * "NC" prüfen -- unabhängig von GUID UND Modulliste (an Dietmars System
     * live bestätigt: ModuleName "Notification Control", Prefix "NC").
     *
     * @return int[]
     */
    private function findNotificationControlInstances(): array
    {
        $found = array_keys($this->findInstancesByModuleNameSubstring(self::NOTIFICATION_CONTROL_GUID, 'notification control'));
        if (count($found) > 0) {
            return $found;
        }
        foreach (@IPS_GetInstanceList() ?: [] as $instanceID) {
            $instance = @IPS_GetInstance($instanceID);
            $moduleID = is_array($instance) ? (string) ($instance['ModuleInfo']['ModuleID'] ?? '') : '';
            if ($moduleID === '') {
                continue;
            }
            $module = @IPS_GetModule($moduleID);
            if (!is_array($module)) {
                continue;
            }
            if ((string) ($module['Prefix'] ?? '') === 'NC' || strcasecmp((string) ($module['ModuleName'] ?? ''), 'Notification Control') === 0) {
                $found[] = (int) $instanceID;
            }
        }
        return $found;
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
        foreach ($this->findNotificationControlInstances() as $instanceID) {
            $out[] = ['InstanceID' => $instanceID, 'Name' => @IPS_GetName($instanceID) ?: ('#' . $instanceID), 'Typ' => 'notification'];
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
        $this->setStatusLabel('WebFrontStatusLabel', $this->webfrontStatusLine($rows));
        if ($added === 0 && count($rows) > 0) {
            return sprintf($this->Translate('ℹ️ No new push targets found (%d already known). Please click „Apply" below if not saved yet.'), count($rows));
        }
        if ($added === 0) {
            return $this->Translate('⚠️ No WebFront, tile visualization, Telegram bot, Pushover, SMTP or Notification Control instances found in the object tree.');
        }
        if ($addedEmail > 0) {
            return sprintf($this->Translate('✅ %d new push target(s) found (%d in total) -- please click „Apply" below. %d e-mail target(s) are still INACTIVE: enter the target address first and activate them.'), $added, count($rows), $addedEmail);
        }
        return sprintf($this->Translate('✅ %d new push target(s) found and activated (%d in total) -- please click „Apply" below to save.'), $added, count($rows));
    }

    /**
     * Farbe einer Verbindungs-Statuszeile nach der Verbund-Regel (SUITE.md
     * 21.09.2026): eine automatisch übernommene, funktionierende Verbindung
     * (✅ mit 🔗) ist GRÜN (0x2E8B3D), ⛔ ROT (0xFF0000), alles andere
     * (✏️ von Hand, ℹ️, ⚠️) Standardfarbe (-1). Ein ⚠️ mit einem 🔗-Teil
     * bleibt bewusst ungefärbt -- grün hieße "in Ordnung".
     */
    private function statusLineColor(string $caption): int
    {
        if (str_starts_with($caption, '⛔')) {
            return 0xFF0000;
        }
        if (str_starts_with($caption, '✅') && str_contains($caption, '🔗')) {
            return 0x2E8B3D;
        }
        return -1;
    }

    /** Label-Element einer Statuszeile: Caption UND Farbe aus einer Quelle, damit Formular und UpdateFormField nie auseinanderlaufen. */
    private function statusLabelItem(string $name, string $caption): array
    {
        return ['type' => 'Label', 'name' => $name, 'caption' => $caption, 'color' => $this->statusLineColor($caption)];
    }

    /** Aktualisiert eine Statuszeile im offenen Formular (Caption + Farbe). */
    private function setStatusLabel(string $name, string $caption): void
    {
        @$this->UpdateFormField($name, 'caption', $caption);
        @$this->UpdateFormField($name, 'color', $this->statusLineColor($caption));
    }

    /**
     * Live berechnete Statuszeile zu den Push-Zielen (Verbund-Regel
     * "Verbindungen im Formular sichtbar machen", SUITE.md 21.09.2026):
     * nennt, WELCHE Ziele tatsächlich funktionieren, und schlägt nicht nur
     * anhand der Aktiv-Häkchen Alarm -- ein aktiviertes E-Mail-Ziel ohne
     * Zieladresse oder eine inzwischen gelöschte Instanz zeigte bisher
     * trotzdem "✅", obwohl dort nichts ankommt. $rows nur beim Auffrischen
     * direkt nach der Suche (dann der noch ungespeicherte Stand der
     * offenen Tabelle), sonst der gespeicherte.
     *
     * @param array<int,array<string,mixed>>|null $rows
     */
    private function webfrontStatusLine(?array $rows = null): string
    {
        $ungespeichert = $rows !== null ? ' ' . $this->Translate('(state of the open table, not saved yet -- click „Apply“)') : '';
        $rows ??= $this->decodeWebFronts();
        if (count($rows) === 0) {
            return $this->Translate('ℹ️ No push targets searched yet -- click "🔎 Search push targets" above. Without a push target no notification arrives (detection, tiles and protective actions still run).');
        }
        $aktive = array_values(array_filter($rows, fn ($w) => !empty($w['Aktiv'])));
        if (count($aktive) === 0) {
            return sprintf($this->Translate('⚠️ %d push target(s) found, but none activated -- push notifications currently arrive nowhere.%s'), count($rows), $ungespeichert);
        }

        $typNamen = ['webfront' => 'WebFront', 'kachel' => $this->Translate('Tile visualization'), 'telegram' => 'Telegram', 'pushover' => 'Pushover', 'email' => $this->Translate('E-mail'), 'notification' => 'Notification Control'];
        $laufen = [];
        $ohneAdresse = [];
        $verwaist = [];
        foreach ($aktive as $w) {
            $name = trim((string) ($w['Name'] ?? '')) !== '' ? (string) $w['Name'] : ('#' . (int) $w['InstanceID']);
            $typ = (string) ($w['Typ'] ?? '');
            if (!@IPS_InstanceExists((int) $w['InstanceID'])) {
                $verwaist[] = sprintf($this->Translate('„%s“ (instance #%d)'), $name, (int) $w['InstanceID']);
                continue;
            }
            if ($typ === 'email') {
                $adressen = array_filter(array_map('trim', preg_split('/[,;]+/', (string) ($w['Zieladresse'] ?? ''))));
                if (count($adressen) === 0) {
                    $ohneAdresse[] = '„' . $name . '“';
                    continue;
                }
                $laufen[] = count($adressen) === 1 ? sprintf($this->Translate('%s (e-mail to 1 address)'), $name) : sprintf($this->Translate('%s (e-mail to %d addresses)'), $name, count($adressen));
                continue;
            }
            $laufen[] = sprintf('%s (%s)', $name, $typNamen[$typ] ?? $typ);
        }

        $funktioniert = count($laufen) > 0
            ? ' ' . sprintf($this->Translate('Working: %s.'), implode(', ', $laufen))
            : ' ' . $this->Translate('So currently no notification arrives anywhere.');
        if ($ohneAdresse !== []) {
            return sprintf($this->Translate('⛔ E-mail target %s is active, but has no target address -- nothing goes there, enter it in the „Target address“ column below.%s%s'), implode(', ', $ohneAdresse), $funktioniert, $ungespeichert);
        }
        if ($verwaist !== []) {
            return sprintf($this->Translate('⚠️ Push target %s no longer exists in the object tree -- nothing goes there, delete the row or click „🔎 Search push targets“ again.%s%s'), implode(', ', $verwaist), $funktioniert, $ungespeichert);
        }
        return sprintf($this->Translate('✅ %d of %d push targets active and reachable: %s -- push notifications go there.%s'), count($aktive), count($rows), implode(', ', $laufen), $ungespeichert);
    }

    /** Anzeigename zu einem ISO-3166-Ländercode (nur D-A-CH als Klartext, sonst der Code) -- für die Statuszeilen. */
    private function landName(string $code): string
    {
        return ['de' => $this->Translate('Germany'), 'at' => $this->Translate('Austria'), 'ch' => $this->Translate('Switzerland')][$code] ?? strtoupper($code);
    }

    /** Ist irgendeine eigene Wetterstation eingetragen (Instanz ODER manuelle Wind-/Regen-Variable)? Wie in Poll(). */
    private function hasWetterstationConfigured(): bool
    {
        return $this->ReadPropertyInteger('WetterstationInstanceID') > 0
            || $this->ReadPropertyInteger('WetterstationWindVariableID') > 0
            || $this->ReadPropertyInteger('WetterstationRegenVariableID') > 0;
    }

    /**
     * Statuszeile zur Verbindung mit dem Symcon-Systemstandort (Kerninstanz
     * "Standort") -- SUITE.md 21.09.2026: eine automatisch übernommene
     * Quelle muss live zeigen, OB die Verbindung steht und welcher Wert
     * tatsächlich gilt, statt nur statisch zu erklären, was der Knopf tut.
     * ⛔ nur, wenn eine aktivierte Quelle den Systemstandort WIRKLICH
     * braucht (eigene Wetterstation, Hagelschutz Schweiz -- beide melden
     * ohne ihn nichts).
     */
    private function systemLocationStatusLine(): string
    {
        $loc = $this->getSystemLocation();
        $braucht = [];
        if ($this->hasWetterstationConfigured()) {
            $braucht[] = $this->Translate('your own weather station');
        }
        if (trim($this->ReadPropertyString('HagelschutzPollUrl')) !== '') {
            $braucht[] = $this->Translate('hail protection Switzerland');
        }
        if ($loc === null) {
            if ($braucht !== []) {
                return sprintf($this->Translate('⛔ No system location entered in the Symcon core instance „Location“ -- %s needs it and reports nothing without it. Enter latitude/longitude there.'), implode($this->Translate(' and '), $braucht));
            }
            return $this->Translate('ℹ️ No system location entered in the Symcon core instance „Location“ -- the button above has no effect, the map starts in the middle of Germany, the country panel under „Data sources“ has no preselection. Locations can still be created via address, map or coordinates.');
        }
        $heim = $this->ReadAttributeString('HeimLandCode');
        return sprintf(
            $this->Translate('✅ System location 🔗 taken over automatically from the Symcon core instance „Location“ #%d: latitude %s, longitude %s, country: %s. Used for the button „Take location from Symcon system settings“, the map start and the country preselection under „Data sources“%s.'),
            (int) ($loc['instanceID'] ?? 0),
            number_format($loc['lat'], 5, ',', '.'),
            number_format($loc['lon'], 5, ',', '.'),
            $heim !== '' ? $this->landName($heim) : $this->Translate('determined at the next check'),
            $braucht !== [] ? ' ' . sprintf($this->Translate('and as placement for %s'), implode($this->Translate(' and '), $braucht)) : ''
        );
    }

    /**
     * Statuszeile zur eigenen Wetterstation -- nimmt die (ggf. noch
     * ungespeicherten) Formularwerte als Parameter, damit sie per
     * 'onChange' der drei Auswahlfelder der AUSWAHL folgt und nicht dem
     * Speicherstand (SUITE.md 21.09.2026, MeterHub-Erfahrung). Nutzt
     * dieselbe resolveWetterstationSource()-Logik wie der Abruf, zeigt also
     * genau das, was auch wirklich gelesen wird.
     */
    private function wetterstationStatusLine(int $instanceID, int $windVar, int $regenVar): string
    {
        if ($instanceID <= 0 && $windVar <= 0 && $regenVar <= 0) {
            return $this->Translate('ℹ️ Own weather station not set up -- this source is off, official warnings run independently of it. Click „🔎 Search weather station“ or choose a wind/rain variable by hand.');
        }
        [$windID] = $this->resolveWetterstationSource($windVar, $instanceID, 'Windböe', self::WETTERSTATION_WIND_IDENTS);
        [$regenID] = $this->resolveWetterstationSource($regenVar, $instanceID, 'Regenrate', self::WETTERSTATION_REGEN_IDENTS);
        $instanzVorhanden = $instanceID > 0 && @IPS_InstanceExists($instanceID);
        $instanzName = $instanzVorhanden ? (string) (@IPS_GetName($instanceID) ?: '') : '';

        $beschreibe = function (?int $id, int $manuell, string $label, bool $istWind) use ($instanceID, $instanzName): string {
            if ($id === null) {
                return sprintf($this->Translate('%s: nothing found'), $label);
            }
            $wert = $istWind ? $this->readWindSpeedKmh($id) : (float) @GetValue($id);
            $quelle = ($manuell > 0 && $id === $manuell)
                ? $this->Translate('✏️ chosen by hand')
                : sprintf($this->Translate('🔗 automatically from instance „%s“ #%d'), $instanzName, $instanceID);
            return sprintf($this->Translate('%s %s %s (variable „%s“ #%d, %s)'), $label, number_format($wert, 1, ',', '.'), $istWind ? 'km/h' : 'mm/h', (string) (@IPS_GetName($id) ?: ''), $id, $quelle);
        };
        $wind = $beschreibe($windID, $windVar, $this->Translate('Wind gust'), true);
        $regen = $beschreibe($regenID, $regenVar, $this->Translate('Rain rate'), false);

        if ($windID !== null && $regenID !== null) {
            return sprintf($this->Translate('✅ Own weather station connected: %s; %s.'), $wind, $regen);
        }
        $grund = ($instanceID > 0 && !$instanzVorhanden) ? ' ' . sprintf($this->Translate('The entered instance #%d no longer exists.'), $instanceID) : '';
        if ($windID === null && $regenID === null) {
            return sprintf($this->Translate('⚠️ Own weather station entered, but neither wind gust nor rain rate can be found.%s Click „🔎 Search weather station“ again or choose the variables below by hand -- until then this source counts as off.'), $grund);
        }
        return sprintf(
            $this->Translate('⚠️ Own weather station only partly connected: %s; %s: no variable found -- this quantity triggers nothing.%s Choose the missing variable below by hand.'),
            $windID !== null ? $wind : $regen,
            $windID === null ? $this->Translate('Wind gust') : $this->Translate('Rain rate'),
            $grund
        );
    }

    /**
     * Wird von den drei Wetterstations-Auswahlfeldern per 'onChange'
     * aufgerufen und führt die Statuszeile der aktuellen AUSWAHL nach
     * (nicht dem Speicherstand).
     */
    public function OnChangeWetterstation(int $instanceID, int $windVar, int $regenVar): void
    {
        $this->setStatusLabel('WetterstationStatusLabel', $this->wetterstationStatusLine($instanceID, $windVar, $regenVar));
    }

    /** Frischt die Wetterstations-Statuszeile auf -- fehlende Werte kommen aus dem gespeicherten Stand (siehe Discover-Knopf). */
    private function refreshWetterstationStatus(?int $instanceID = null, ?int $windVar = null, ?int $regenVar = null): void
    {
        $this->setStatusLabel('WetterstationStatusLabel', $this->wetterstationStatusLine(
            $instanceID ?? $this->ReadPropertyInteger('WetterstationInstanceID'),
            $windVar ?? $this->ReadPropertyInteger('WetterstationWindVariableID'),
            $regenVar ?? $this->ReadPropertyInteger('WetterstationRegenVariableID')
        ));
    }

    /**
     * Statuszeile zu den mobilen (Live-)Standorten -- die Live-Position
     * liefert ein FREMDMODUL (Tessie, Geofency, Fahrzeug-Module), ob die
     * Verbindung tatsächlich steht, sah man bisher nirgends: eine
     * gelöschte oder umbenannte Variable ließ WarnHub STILL auf die festen
     * Koordinaten der Tabelle zurückfallen (siehe resolveStandortCoords()).
     * $standorte nur beim Auffrischen direkt nach der Suche (offene,
     * ungespeicherte Tabelle), sonst der gespeicherte Stand.
     *
     * @param array<int,array<string,mixed>>|null $standorte
     */
    private function mobileStandorteStatusLine(?array $standorte = null): string
    {
        $ungespeichert = $standorte !== null ? ' ' . $this->Translate('(state of the open table, not saved yet -- click „Apply“)') : '';
        $rows = $standorte ?? $this->decodeStandorte();
        $mobil = array_values(array_filter($rows, fn ($s) => (int) ($s['QuellVarLat'] ?? 0) > 0 || (int) ($s['QuellVarLon'] ?? 0) > 0));
        if (count($mobil) === 0) {
            return $this->Translate('ℹ️ No mobile location set up -- all locations use fixed coordinates. „🔎 Search vehicle/location variables“ creates one as soon as Tessie, Geofency or a vehicle module exists in the system.');
        }
        $teile = [];
        $alleOk = true;
        foreach ($mobil as $s) {
            $latVar = (int) ($s['QuellVarLat'] ?? 0);
            $lonVar = (int) ($s['QuellVarLon'] ?? 0);
            $name = trim((string) ($s['Name'] ?? '')) !== '' ? (string) $s['Name'] : $this->Translate('(no name)');
            $inaktiv = !empty($s['Aktiv']) ? '' : ' ' . $this->Translate('[inactive]');
            $latOk = $latVar > 0 && @IPS_VariableExists($latVar);
            $lonOk = $lonVar > 0 && @IPS_VariableExists($lonVar);
            if ($latOk && $lonOk) {
                $quelle = (string) (@IPS_GetName((int) @IPS_GetParent($latVar)) ?: '');
                $teile[] = sprintf(
                    $this->Translate('%s%s: %s / %s (🔗 automatically from „%s“, variables #%d/#%d)'),
                    $name,
                    $inaktiv,
                    number_format((float) @GetValue($latVar), 4, ',', '.'),
                    number_format((float) @GetValue($lonVar), 4, ',', '.'),
                    $quelle,
                    $latVar,
                    $lonVar
                );
                continue;
            }
            $alleOk = false;
            $teile[] = sprintf(
                $this->Translate('%s%s: live variable %s -- WarnHub uses the fixed coordinates %s / %s from the table instead'),
                $name,
                $inaktiv,
                ($latVar > 0 xor $lonVar > 0) ? $this->Translate('set for one axis only') : $this->Translate('missing or no longer exists'),
                number_format((float) ($s['Lat'] ?? 0), 4, ',', '.'),
                number_format((float) ($s['Lon'] ?? 0), 4, ',', '.')
            );
        }
        return sprintf($alleOk ? $this->Translate('✅ Mobile locations: %s.%s') : $this->Translate('⚠️ Mobile locations: %s.%s'), implode(' · ', $teile), $ungespeichert);
    }

    private function isPushSnoozed(): bool
    {
        return $this->ReadAttributeInteger('PushSnoozeUntilTs') > time();
    }

    private function snoozeStatusLine(): string
    {
        if (!$this->isPushSnoozed()) {
            return $this->Translate('ℹ️ Push notification runs normally (not paused).');
        }
        return sprintf($this->Translate('🔕 Push notification paused until %s -- detection and protective actions keep running unchanged, only the notification itself is silent.'), date('d.m. H:i', $this->ReadAttributeInteger('PushSnoozeUntilTs')));
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
            return sprintf($this->Translate('🔽 %d action(s) protected by the weather station -- reset at the earliest in %d min.'), $count, $minRemainingMinutes);
        }
        return sprintf($this->Translate('🔽 %d action(s) protected by the weather station -- still waiting for the wind to calm down.'), $count);
    }

    private function getPollStatusLine(): string
    {
        $lastTs = $this->ReadAttributeInteger('LastPollTs');
        if ($lastTs === 0) {
            return $this->Translate('ℹ️ No check performed yet.');
        }
        $active = json_decode($this->ReadAttributeString('LastActiveWarningsJson'), true) ?: [];
        $standorte = $this->decodeStandorte();
        $activeStandorte = count(array_filter($standorte, fn ($s) => $s['Aktiv']));
        $icon = count($active) > 0 ? '⚠️' : '✅';
        return sprintf(
            $this->Translate('%s %d active warning(s) at %d configured locations (last checked %s).'),
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
            'caption' => $this->Translate('👋  What is this module for?'),
            'items' => [
                ['type' => 'Label', 'caption' => $this->Translate('WarnHub bundles official warning and alert messages for Germany, Austria and Switzerland -- Germany (severe weather, civil protection, floods, police, water levels, radioactivity) plus Europe-wide weather warnings (also covering Austria/Switzerland) and optionally your own weather station as a safety net -- and only reports what actually falls within the radius you set around your locations, including mobile locations abroad.')],
                ['type' => 'Label', 'caption' => $this->Translate('Active warnings appear as push notifications on all WebFront, tile visualization, Telegram and Pushover targets (also mobile phones) and can optionally trigger protective actions -- e.g. raise external blinds or close the garage door before the storm arrives.')],
                ['type' => 'Button', 'caption' => $this->Translate('Got it – don\'t show again'), 'onClick' => 'WHUB_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    /**
     * "Neu"-Panel nach der Verbund-Konvention NEWS_VERSIONS (SUITE.md
     * "Einheitliche Formular-Optik" Punkt 1, Referenz NRGDashboard): zeigt
     * alle Versionen, die neuer sind als die zuletzt bestätigte (Attribut
     * SeenNews), gruppiert nach Version. Seit 1.20.0 -- vorher eine einzige,
     * seit der Erstversion gewachsene Liste mit 80 Zeilen; die ältere
     * Geschichte steht im CHANGELOG. Texte englisch, Deutsch in locale.json.
     */
    private const NEWS_VERSIONS = [
        '1.18.0' => [
            'New alert type "💨 Smoke/hazardous substances" for protective actions and window/door monitoring, plus the action type "Set variable to value" -- e.g. set the ventilation level to 0 as soon as smoke gases are reported. WarnHub does not reset the value after an all-clear.',
        ],
        '1.18.1' => [
            'Fix Meteoalarm: with NINA/DWD active, a DWD warning for German locations appeared twice -- once in German and once in English from the Meteoalarm Germany feed. NINA or the DWD connection now take over Germany from Meteoalarm, as GeoSphere Austria and Alertswiss already did.',
        ],
        '1.18.2' => [
            'Fix Switzerland: Alertswiss no longer replaces Meteoalarm for Swiss locations -- Alertswiss is civil protection and does not carry MeteoSwiss weather warnings.',
            'Fix forest fire danger index (DWD): outside the fire season, a month-old station value is no longer shown as an active danger.',
            'Tiles: matches within the radius now show the distance ("Home · 8 km away · until 18:00"), so a warning for a neighbouring municipality no longer looks like one for the location itself.',
        ],
        '1.19.0' => [
            '🪟 New: choose the form layout yourself -- in the new "Form: layout" section (at the bottom, before the forum hint) you set for each section whether it opens expanded, collapsed or hidden, and its position. Hidden sections keep working.',
        ],
        '1.19.1' => [
            'Fix forest fire danger index (DWD): a short outage of opendata.dwd.de no longer shows up as an error at every check. The station list is cached (reloaded at most once a week), the daily value per station at most every 6 hours.',
        ],
        '1.20.0' => [
            '🌐 WarnHub now speaks English: forms, notifications, tiles and messages follow the language of your Symcon system. The warnings themselves stay in the language of their source (mostly German).',
            'This "New" panel now shows exactly the versions you have not confirmed yet instead of one ever-growing list.',
            'Fix ozone (Federal Environment Agency): short outages of luftdaten.umweltbundesamt.de no longer show up as an error at every check -- the station list and readings are cached like the forest fire index.',
        ],
        '1.20.1' => [
            'Fix protective actions: the radioactivity warning (BfS) and NINA messages such as "large fire in the district of ..." were counted as "Snow" -- the snow keyword "eis" was hidden in German words like "Landkreis" and "Ortsdosisleistung". A snow action could therefore trigger on radiation.',
            'Fix Meteoalarm: storm, rain, thunderstorm, snow and heat warnings now get their alert type from the standardised Meteoalarm warning type. Until now they always counted as "other", so a storm action with a ticked category never triggered on Meteoalarm (affects Swiss locations in particular).',
            'English now also for the messages WarnHub generates itself (water levels, radioactivity, forest fire, ozone, earthquakes, BAFU floods, own weather station, hail signal box). Their alert type is fixed per source and no longer depends on the text.',
            'On an English Symcon, the object tree searches now also find window contacts ("Opened"/"Open"), Tessie positions ("Location – Latitude") and English actuator names (awning, siren, close windows, tailgate).',
        ],
    ];

    private function NewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::NEWS_VERSIONS as $ver => $lines) {
            if ($seen === '' || version_compare($ver, $seen, '>')) {
                $pending[$ver] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        $multi = count($pending) > 1;
        foreach ($pending as $ver => $lines) {
            if ($multi) {
                $items[] = ['type' => 'Label', 'caption' => sprintf($this->Translate('Version %s:'), $ver)];
            }
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => '• ' . $this->Translate($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => $this->Translate('Got it – don\'t show again'), 'onClick' => 'WHUB_AckNews($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => sprintf($this->Translate('🆕  New up to version %s'), (string) array_key_last($pending)),
            'items' => $items,
        ];
    }

    /** Merkt die tatsächlich installierte Bibliotheksversion (ohne Beta-/Build-Zusatz), nicht nur den letzten NEWS_VERSIONS-Schlüssel -- so erscheint das Panel nach jedem späteren Update mit Neuigkeiten sicher wieder. */
    public function AckNews()
    {
        $lib = @IPS_GetLibrary('{584C68EE-2104-4E89-B0A1-79250761B30D}');
        $ver = is_array($lib) ? (string) preg_replace('/-.*$/', '', (string) ($lib['Version'] ?? '')) : '';
        if ($ver === '') {
            $ver = (string) array_key_last(self::NEWS_VERSIONS);
        }
        $this->WriteAttributeString('SeenNews', $ver);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => false,
            'caption' => $this->Translate('💬  Feedback in the Symcon forum'),
            'items' => [
                ['type' => 'Label', 'caption' => $this->Translate('Feedback, bugs, missing data sources or ideas for further protective actions are very welcome here in the thread.')],
                ['type' => 'Button', 'caption' => $this->Translate('To the forum thread'), 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => $this->Translate('Got it – don\'t show again'), 'onClick' => 'WHUB_AckForumHint($id);'],
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
            'caption' => $this->Translate('🧡  About this module'),
            'items' => [
                ['type' => 'Label', 'caption' => $this->Translate('Born out of genuine enthusiasm for home automation -- and a few evenings of typing. Still: software hobby or not, this is intellectual property and real work went into it.')],
                ['type' => 'Label', 'caption' => $this->Translate('Licence: PolyForm Noncommercial 1.0.0 -- free to use privately and non-commercially, commercial use requires a separate licence from the rights holder.')],
                ['type' => 'Button', 'caption' => $this->Translate('View licence text'), 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => $this->Translate('Commercial use or questions about the licence? Just get in touch: dietmar@gureth.eu')],
                ['type' => 'Label', 'caption' => $this->Translate('You like the module and would still like to leave something? I am happy about a small donation -- completely voluntary, nothing required in return.')],
                ['type' => 'Button', 'caption' => $this->Translate('☕  Donate via PayPal'), 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => $this->Translate('Dedicated to Sepp Lausch, who is working on his own Symcon warning connection at the same time -- the friendly rivalry has clearly done WarnHub good. 😉')],
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
                // Die Optionstexte schreibt das jeweilige Fremdmodul in der
                // Sprache des Systems, auf dem die Variable angelegt wurde --
                // deshalb deutsch UND englisch (bis 1.20.0 nur deutsch: auf
                // einem englischen Symcon fand die Suche keinen einzigen
                // Kontakt). Ersatzweise die Gegenrichtung "geschlossen"/"closed".
                $geschlossenWert = null;
                foreach ($options as $opt) {
                    $caption = mb_strtolower(trim((string) ($opt['Caption'] ?? '')));
                    if (in_array($caption, ['geöffnet', 'offen', 'open', 'opened'], true)) {
                        return (bool) ($opt['Value'] ?? false);
                    }
                    if (in_array($caption, ['geschlossen', 'zu', 'closed'], true)) {
                        $geschlossenWert = (bool) ($opt['Value'] ?? false);
                    }
                }
                if ($geschlossenWert !== null) {
                    return !$geschlossenWert;
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
                    // Tessie auf einem englischen Symcon: "DoorState" (1.20.1)
                    $zustandsVariableID = $this->findSiblingVariableByNameSubstring($variableID, 'klappenstatus')
                        ?? $this->findSiblingVariableByNameSubstring($variableID, 'doorstate')
                        ?? $this->findSiblingVariableByNameSubstring($variableID, 'door state') ?? 0;
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
            return $this->Translate('ℹ️ No new matches for external blind/blind/awning/garage/close windows/tailgate/siren found in the object tree.');
        }
        return sprintf(
            $this->Translate('✅ %d new protective action(s) found (severity "High"/"Extreme" as a cautious default) -- IMPORTANT: check the target value of each row (direction differs by manufacturer, see help button above), then click „Apply" below. Boot/tailgate matches without an automatically found state variable stay INACTIVE for safety reasons -- add the state variable by hand and then activate them.'),
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
                'KatRauch' => false,
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
                ? sprintf($this->Translate('ℹ️ No new window/door contacts found (%d already known). Please click „Apply" below if not saved yet.'), count($rows))
                : $this->Translate('ℹ️ No window/door contacts found -- neither via a classic profile (~Window/~Door) nor via the new variable presentation with an "Opened"/"Open" option.');
        }
        return sprintf($this->Translate('✅ %d new window/door contact(s) found and activated (%d in total) -- please click „Apply" below to save.'), $added, count($rows));
    }

    /** Einmalige Abfrage, kein Formular-Feld wird automatisch befüllt -- siehe SUITE.md-Muster "nur in die offene Maske, Übernehmen bleibt bewusster letzter Schritt" (hier: gar nicht erst schreiben, nur anzeigen). */
    public function LookupCoordinates(string $Ort): string
    {
        $ort = trim($Ort);
        if ($ort === '') {
            return $this->Translate('ℹ️ Please enter a postcode or a place first.');
        }
        $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=de&q=' . rawurlencode($ort);
        $body = $this->httpGet($url, 15, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            return $this->Translate('⚠️ Nominatim was not reachable -- please try again later or enter coordinates manually.');
        }
        $json = json_decode($body, true);
        if (!is_array($json) || count($json) === 0) {
            return sprintf($this->Translate('⚠️ No match for "%s".'), $ort);
        }
        $lat = round((float) $json[0]['lat'], 5);
        $lon = round((float) $json[0]['lon'], 5);
        $name = (string) ($json[0]['display_name'] ?? $ort);
        return sprintf($this->Translate('✅ %s → lat %s / lon %s -- please transfer into the locations table above.'), $name, $lat, $lon);
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
        return ['lat' => $lat, 'lon' => $lon, 'instanceID' => (int) $id];
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
     * Aktualisiert StandortLaenderCodes aus ALLEN aktiven Standorten (fest
     * UND mobil, über resolveStandortCoords() -- ein mobiler Standort
     * "wandert" also mit) -- ebenfalls bewusst NUR hier in Poll(), aus
     * demselben Grund wie refreshHeimLandCode(). Bleibt der vorige, bereits
     * bekannte Stand stehen, wenn gerade GAR KEIN Ländercode ermittelt
     * werden konnte (z. B. Nominatim kurzzeitig nicht erreichbar) --
     * besser ein leicht veralteter als ein plötzlich leerer Link-Bereich.
     */
    private function refreshStandortLaenderCodes(): void
    {
        $codes = [];
        foreach ($this->decodeStandorte() as $standort) {
            if (!$standort['Aktiv']) {
                continue;
            }
            $coords = $this->resolveStandortCoords($standort);
            if ($coords['lat'] === 0.0 && $coords['lon'] === 0.0) {
                continue;
            }
            $geo = $this->reverseGeocodeStandort($coords['lat'], $coords['lon']);
            if ($geo['countryCode'] !== '' && !in_array($geo['countryCode'], $codes, true)) {
                $codes[] = $geo['countryCode'];
            }
        }
        if (count($codes) > 0) {
            $this->WriteAttributeString('StandortLaenderCodes', json_encode($codes));
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
            return $this->Translate('⚠️ No configured Symcon location found (create/fill in core instances > Location, or enter coordinates here manually).');
        }
        $rows = $this->decodeStandorte();
        $rows[] = [
            'Name' => $this->Translate('My location (from Symcon)'),
            'Ort' => '',
            'Lat' => round($loc['lat'], 5),
            'Lon' => round($loc['lon'], 5),
            'RadiusKm' => 20.0,
            'MinSeverity' => 2,
            'Aktiv' => true,
        ];
        $this->UpdateFormField('Standorte', 'values', json_encode($rows));
        $this->UpdateFormField('Standorte', 'rowCount', $this->listRowCount(count($rows)));
        return sprintf($this->Translate('✅ Location taken over (lat %s / lon %s) -- please click „Apply" below to save.'), round($loc['lat'], 5), round($loc['lon'], 5));
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
            $name = (string) (@IPS_GetName((int) $parentID) ?: $this->Translate('Mobile location'));
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
        $this->setStatusLabel('MobileStandorteStatusLabel', $this->mobileStandorteStatusLine($rows));
        if ($added === 0) {
            return $this->Translate('ℹ️ No new vehicle/location variable pairs found (searched: Tessie "Fahrzeugposition", Geofency "Current Latitude/Longitude", Stellantis, Smartcar, BMW ConnectedDrive, Hyundai/Kia Bluelink, OVMS native).');
        }
        return sprintf($this->Translate('✅ %d mobile location(s) found and linked to the live variables -- please check radius/severity, then click „Apply" below.'), $added);
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
            $this->refreshWetterstationStatus($instanceID);
            return sprintf($this->Translate('✅ Weather station "%s" found (Froggit, wind gust/rain rate present) -- please click „Apply" below to save.'), @IPS_GetName($instanceID) ?: ('#' . $instanceID));
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
            $this->refreshWetterstationStatus($instanceID);
            return sprintf($this->Translate('✅ Weather station "%s" found (Sainlogic/ELV, Windgust/rainin present) -- please click „Apply" below to save.'), @IPS_GetName($instanceID) ?: ('#' . $instanceID));
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
            $this->refreshWetterstationStatus($instanceID);
            return sprintf($this->Translate('✅ Weather station "%s" found (Meteobridge/Meteohub, Wind_Gust_KmH/Rain_Rate present) -- please click „Apply" below to save.'), @IPS_GetName($instanceID) ?: ('#' . $instanceID));
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
            $this->refreshWetterstationStatus(null, $foundWind ? $byProfile['wind'] : null, $foundRegen ? $byProfile['regen'] : null);
            return sprintf(
                $this->Translate('✅ No known weather station module, but %s found in the system via the standard profile and taken over into the manual selection -- please check and click „Apply" below.'),
                $foundWind && $foundRegen ? $this->Translate('wind AND rain variable') : ($foundWind ? $this->Translate('a wind variable') : $this->Translate('a rain variable'))
            );
        }

        if ($foundButIncomplete !== []) {
            return sprintf(
                $this->Translate('ℹ️ Weather station instance found, but WITHOUT the required wind gust/rain rate fields: %s -- this device probably only measures temperature/humidity, without a wind/rain sensor. If wind/rain values exist anyway (e.g. elsewhere in the object tree), select the wind/rain variable manually below.'),
                implode(', ', $foundButIncomplete)
            );
        }

        return $this->Translate('ℹ️ No supported weather station instance found (Froggit/Sainlogic/ELV/Meteobridge) -- for other makes (e.g. KNX, Netatmo) select the wind/rain variable manually below.');
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
            return $this->Translate('⚠️ No map point selected.');
        }
        $lat = (float) $loc['latitude'];
        $lon = (float) $loc['longitude'];
        if ($lat === 0.0 && $lon === 0.0) {
            return $this->Translate('⚠️ Please select a point on the map first.');
        }
        $rows = $this->decodeStandorte();
        $rows[] = [
            'Name' => $this->Translate('New location (map)'),
            'Ort' => '',
            'Lat' => round($lat, 5),
            'Lon' => round($lon, 5),
            'RadiusKm' => 10.0,
            'MinSeverity' => 2,
            'Aktiv' => true,
        ];
        $this->UpdateFormField('Standorte', 'values', json_encode($rows));
        $this->UpdateFormField('Standorte', 'rowCount', $this->listRowCount(count($rows)));
        return sprintf($this->Translate('✅ Location taken over (lat %s / lon %s) -- please click „Apply" below to save.'), round($lat, 5), round($lon, 5));
    }

    // ----------------------------------------------------------------
    //  Kategorisierung / Formatierung
    // ----------------------------------------------------------------

    private function classifyEventCategory(string $event, string $headline): string
    {
        $haystack = mb_strtolower($event . ' ' . $headline);
        foreach (self::CATEGORY_KEYWORDS as $category => $keywords) {
            foreach ($keywords as $kw) {
                // Stichwörter, die mit "/" beginnen, sind reguläre Ausdrücke: "eis"
                // nur am Wortanfang (Eisglätte, Eisregen). Als reiner Teiltext traf es
                // bis 1.20.0 "Landkreis", "Hinweis", "Ortsdosisleistung" -- eine
                // NINA-Meldung "Großbrand im Landkreis X" oder die BfS-Strahlungswarnung
                // landeten so unter "Schnee".
                if ($kw[0] === '/' ? preg_match($kw, $haystack) === 1 : mb_strpos($haystack, $kw) !== false) {
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
            $this->LogError('httpGet', sprintf($this->Translate('cURL extension missing -- cannot fetch %s.'), $url));
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
        // Die Instanzmethode statt der globalen Log-Funktion -- liefert
        // automatisch den korrekten Instanz-Kontext (Symcon-Store-Review-
        // Hinweis, 07.09.2026 und 12.10.2026, SUITE.md Store-Review-Checkliste
        // Punkt 15); die globale Funktion bräuchte den Bezug manuell als
        // Sender-String nachgebaut. Der Funktionsname steht hier bewusst nicht
        // im Klartext: .tools/test-form.php prüft module.php auf sein Fehlen.
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
    /**
     * true, wenn das CAP-Standardfeld `status` FEHLT oder explizit "Actual"
     * ist -- eine fehlende Angabe wird wie bisher als echte Meldung
     * behandelt (nicht jede angebundene Quelle liefert dieses Feld
     * überhaupt), alles andere (Exercise/System/Test/Draft, laut CAP-1.2-
     * Spezifikation die vorgesehenen Werte für Übungen/Tests) wird
     * verworfen. Praxis-Fund ralf, Symcon-Forum, 16.09.2026: eine
     * Test-/Übungsmeldung ("ACHTUNG! TEST TEST ... Heute scheint der
     * Mond.") kam unverändert als echte Warnung durch, weil WarnHub dieses
     * CAP-Feld bisher an keiner Stelle prüfte -- live gegen NINA
     * ("status":"Actual" im JSON) und Meteoalarm (<cap:status>Actual</cap:status>
     * im Atom-Feed) verifiziert, dass das Feld tatsächlich vorhanden ist.
     */
    private function isCapStatusActual(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return true;
        }
        return strcasecmp(trim($status), 'Actual') === 0;
    }

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
        if (!$this->isCapStatusActual($meta['status'] ?? null)) {
            return null; // Übung/Test (siehe isCapStatusActual()), keine echte Warnung
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
            'headline' => (string) ($infoDe['headline'] ?? ($infoDe['event'] ?? $this->Translate('Warning'))),
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
            $this->LogError('fetchDwdCap', $this->Translate('cURL extension missing -- DWD direct source skipped.'));
            return [];
        }
        if (!class_exists('ZipArchive')) {
            $this->LogError('fetchDwdCap', $this->Translate('ZipArchive extension missing -- DWD direct source skipped (NINA still delivers DWD warnings, only without this extra detail).'));
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
            $this->LogError('fetchDwdCap', sprintf($this->Translate('Could not open ZIP: %s'), $zipUrl));
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
        if (!$this->isCapStatusActual((string) $sxml->status)) {
            return null; // Übung/Test (siehe isCapStatusActual()), keine echte Warnung
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
            'headline' => (string) ($infoDe->headline ?: $infoDe->event ?: $this->Translate('Warning')),
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
                $name = (string) ($station['longname'] ?? $station['shortname'] ?? $this->Translate('Gauge'));
                $water = (string) ($station['water']['longname'] ?? '');
                $valueCm = (float) ($cm['value'] ?? 0);
                $severity = $nswHsw === 'high' ? 'Extreme' : 'Moderate';
                $out[] = [
                    'identifier' => 'pegelonline-' . (string) ($station['uuid'] ?? $name),
                    'source' => 'pegelonline',
                    'category' => 'starkregen',
                    'msgType' => 'Alert',
                    'event' => $this->Translate('Flood'),
                    'headline' => $water !== '' ? sprintf($this->Translate('Elevated water level %s (%s)'), $name, $water) : sprintf($this->Translate('Elevated water level %s'), $name),
                    'description' => sprintf(
                        $nswHsw === 'high'
                            ? $this->Translate('Current water level %s cm -- at/above the highest level recorded since measurements began (HSW).')
                            : $this->Translate('Current water level %s cm -- above the mean high water (MHW), possibly normal for the season.'),
                        rtrim(rtrim(number_format($valueCm, 0, ',', '.'), '0'), ',')
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
            $name = (string) ($props['name'] ?? $props['id'] ?? $this->Translate('Measuring point'));
            $out[] = [
                'identifier' => 'bfsodl-' . (string) ($props['id'] ?? $name),
                'source' => 'bfs_odl',
                'category' => 'sonstige',
                'msgType' => 'Alert',
                'event' => $this->Translate('Elevated radioactivity'),
                'headline' => sprintf($this->Translate('Elevated ambient dose rate: %s'), $name),
                'description' => trim(sprintf(
                    $this->Translate('%s µSv/h at measuring point %s (own threshold %s µSv/h -- no official alert level, the raw data has no official reporting threshold). %s'),
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
            self::WETTERSTATION_WIND_IDENTS
        );
        [$regenrateID] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationRegenVariableID'),
            $instanceID,
            'Regenrate',
            self::WETTERSTATION_REGEN_IDENTS
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
                $actionName = $actions[$idx]['Name'] ?? sprintf($this->Translate('Target variable #%d'), $variableID);
                $this->logHistory(
                    'rueckstellung',
                    $actionName,
                    $this->Translate('Automatic reset'),
                    sprintf($this->Translate('%s reset automatically -- wind/rain had been calm again for %d minutes.'), $actionName, (int) round(self::WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN / 60)),
                    'Unknown',
                    $entry['Source'] === 'windboe' ? 'sturm' : 'starkregen',
                    'wetterstation'
                );
                if ($this->ReadPropertyBoolean('PushAktiv') && !$this->isPushSnoozed()) {
                    $this->pushToAllWebfronts(
                        $this->Translate('🔽 Reset automatically'),
                        sprintf($this->Translate('Wind/rain had been calm again for %d minutes -- protective action reset.'), (int) round(self::WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN / 60)),
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
            self::WETTERSTATION_WIND_IDENTS
        );
        [$regenrateID, $regenIdentSuffix] = $this->resolveWetterstationSource(
            $this->ReadPropertyInteger('WetterstationRegenVariableID'),
            $instanceID,
            'Regenrate',
            self::WETTERSTATION_REGEN_IDENTS
        );
        if ($windboeID === null && $regenrateID === null) {
            return [];
        }
        $loc = $this->getSystemLocation();
        if ($loc === null) {
            $this->LogError('fetchWetterstation', $this->Translate('No configured Symcon system location -- weather station values cannot be placed (no guessed position).'));
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
                    'category' => 'sturm',
                    'msgType' => 'Alert',
                    'event' => $this->Translate('Storm (own measurement)'),
                    'headline' => sprintf($this->Translate('Own weather station: wind gust %s km/h'), number_format($windboe, 1, ',', '.')),
                    'description' => sprintf(
                        $this->Translate('Locally measured wind gust %s km/h, level "%s" -- independent of official warnings, own thresholds, no official classification.'),
                        number_format($windboe, 1, ',', '.'),
                        $severity
                    ),
                    'instruction' => '',
                    'severity' => $severity,
                    'effective' => null,
                    'onset' => null,
                    'expires' => null,
                    'areaDesc' => $this->Translate('Own weather station'),
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
                    'category' => 'sturm',
                    'msgType' => 'Cancel',
                    'event' => $this->Translate('Storm (own measurement)'),
                    'headline' => $this->Translate('Own weather station: wind gust below the threshold again'),
                    'description' => '',
                    'instruction' => '',
                    'severity' => 'Extreme', // nur für den Standort-Schwellwert-Gate relevant, nicht angezeigt
                    'effective' => null,
                    'onset' => null,
                    'expires' => null,
                    'areaDesc' => $this->Translate('Own weather station'),
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
                    'category' => 'starkregen',
                    'msgType' => 'Alert',
                    'event' => $this->Translate('Heavy rain (own measurement)'),
                    'headline' => sprintf($this->Translate('Own weather station: rain rate %s mm/h'), number_format($regenrate, 1, ',', '.')),
                    'description' => sprintf(
                        $this->Translate('Locally measured rain rate %s mm/h, level "%s" -- independent of official warnings, own thresholds, no official classification.'),
                        number_format($regenrate, 1, ',', '.'),
                        $regenSeverity
                    ),
                    'instruction' => '',
                    'severity' => $regenSeverity,
                    'effective' => null,
                    'onset' => null,
                    'expires' => null,
                    'areaDesc' => $this->Translate('Own weather station'),
                    'rings' => [],
                    'circles' => [$circle],
                ];
            } elseif ($this->wetterstationIdentifierWasSeen($regenIdentifier)) {
                $out[] = [
                    'identifier' => $regenIdentifier,
                    'source' => 'wetterstation',
                    'category' => 'starkregen',
                    'msgType' => 'Cancel',
                    'event' => $this->Translate('Heavy rain (own measurement)'),
                    'headline' => $this->Translate('Own weather station: rain rate below the threshold again'),
                    'description' => '',
                    'instruction' => '',
                    'severity' => 'Extreme',
                    'effective' => null,
                    'onset' => null,
                    'expires' => null,
                    'areaDesc' => $this->Translate('Own weather station'),
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
        $out = [];
        foreach ($this->meteoalarmSlugsForStandorte() as $slug) {
            $out = array_merge($out, $this->fetchMeteoalarmCountry($slug));
        }
        return $out;
    }

    /**
     * Länder-Slugs der Meteoalarm-Feeds, die für die aktiven Standorte
     * tatsächlich abzufragen sind -- vom HTTP-Abruf getrennt, damit sich die
     * Länderauswahl ohne Netzzugriff testen lässt (.tools/test-meteoalarm.php).
     *
     * Ist für ein Land eine direkte, amtliche WETTER-Quelle aktiv, übernimmt
     * SIE das Land, und Meteoalarm wird dort NICHT abgefragt: GeoSphere
     * Austria für Österreich (koordinatengenau, Meteoalarm liefert dort nur
     * den ungenaueren Namensabgleich) und NINA bzw. die direkte DWD-Anbindung
     * für Deutschland. Gleiches Prinzip wie "DWD direkt ersetzt den
     * NINA-'dwd'-Kanal" in Poll().
     *
     * Schweiz-Fall (1.16.0 bis 1.18.1 FALSCH): Alertswiss (BABS) ist
     * Zivilschutz -- Feuerverbot, Trockenheit, Sperrungen -- und führt die
     * MeteoSchweiz-Wetterwarnungen NICHT. Live 24.10.2026: Meteoalarm-CH
     * "Gale force wind" für Basel/Oberes Baselbiet, Alertswiss zugleich ohne
     * eine einzige Wetterwarnung. Alertswiss darf Meteoalarm-CH deshalb nur
     * ERGÄNZEN, nicht ersetzen -- sonst verliert ein Schweizer Standort die
     * Sturmwarnung seines eigenen Wetterdienstes, während er die deutschen
     * DWD-Warnungen der Grenzgemeinden über den Umkreis weiterhin bekommt
     * (Praxis-Fund baslerleckerli, Symcon-Forum, 24.10.2026).
     *
     * Deutschland-Fall: der Meteoalarm-Feed "germany" reicht genau die
     * DWD-Warnungen weiter, die NINA/DWD ohnehin liefern -- nur ENGLISCH
     * ("gale-force gusts" statt "STURMBÖEN"), also mit anderem Ereignisnamen
     * und damit für warningEpisodeKey() eine ZWEITE Meldung. Praxis-Fund
     * hbraun, Symcon-Forum, 23.10.2026: "Sturmböen" und "Gale-Force Gusts"
     * zugleich in der Kachel, beide "bis 04:00 Uhr". Live gegen den Feed
     * geprüft: Kreis Osterholz, gale-force gusts, expires 02:00 UTC =
     * 04:00 Uhr MESZ, exakt die Kachel des Anwenders.
     *
     * @return string[]
     */
    private function meteoalarmSlugsForStandorte(): array
    {
        $geosphereAtActive = $this->ReadPropertyBoolean('QuelleGeosphereAt');
        $germanyCovered = $this->ReadPropertyBoolean('QuelleNina') || $this->ReadPropertyBoolean('QuelleDwd');

        $standorte = array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '');
        $slugs = [];
        foreach ($standorte as $s) {
            $coords = $this->resolveStandortCoords($s);
            $geo = $this->reverseGeocodeStandort($coords['lat'], $coords['lon']);
            $slug = self::METEOALARM_COUNTRY_SLUGS[$geo['countryCode']] ?? null;
            if ($slug === null
                || ($slug === 'austria' && $geosphereAtActive)
                || ($slug === 'germany' && $germanyCovered)
            ) {
                continue;
            }
            $slugs[$slug] = true;
        }
        return array_keys($slugs);
    }

    /**
     * Kategorie einer Meteoalarm-Meldung aus dem genormten Meteoalarm-Warntyp im
     * Titel ("Orange Wind Warning issued for ...", Typen laut Meteoalarm: Wind,
     * Snow-ice, Thunderstorm, Fog, High-temperature, Low-temperature,
     * Coastalevent, Forest-fire, Avalanches, Rain, Flooding, Rain-flood). Die
     * Ereignisnamen selbst sind frei und englisch ("moderate wind warning",
     * "gale-force gusts", "frost") -- bis 1.20.0 lief die Einordnung über die
     * deutschen Stichwörter und landete deshalb immer bei "sonstige": eine
     * Sturm-Schutzaktion mit Kategorie-Haken löste bei Meteoalarm nie aus
     * (betrifft vor allem Schweizer Standorte, für die Meteoalarm die einzige
     * Sturmquelle ist). Live geprüft 09.11.2026 an zehn Länder-Feeds.
     */
    private function meteoalarmCategory(string $title): string
    {
        if (!preg_match('/^\s*(?:green|yellow|orange|red)\s+(.+?)\s+warning\b/i', $title, $m)) {
            return 'sonstige';
        }
        $typ = strtolower(trim($m[1]));
        return match ($typ) {
            'wind' => 'sturm',
            'thunderstorm', 'thunderstorms' => 'gewitter',
            'rain', 'rain-flood', 'flooding', 'flood' => 'starkregen',
            'snow-ice', 'snow/ice', 'snow', 'ice' => 'schnee',
            'high-temperature', 'high temperature' => 'hitze',
            default => 'sonstige',
        };
    }

    private function fetchMeteoalarmCountry(string $slug): array
    {
        $url = 'https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-' . $slug;
        $body = $this->httpGet($url, 20, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            $this->LogError('fetchMeteoalarmCountry', sprintf($this->Translate('Meteoalarm feed for "%s" not reachable.'), $slug));
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
            $this->LogError('parseMeteoalarmAtom', sprintf($this->Translate('Meteoalarm feed for "%s" could not be parsed as XML.'), $slug));
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
            if (!$this->isCapStatusActual((string) $capNs->status)) {
                continue; // Übung/Test (siehe isCapStatusActual()), keine echte Warnung
            }
            $msgType = (string) $capNs->message_type;
            $out[] = [
                'identifier' => $identifier . '|' . $areaDesc,
                'source' => 'meteoalarm',
                'category' => $this->meteoalarmCategory((string) ($entry->title ?? '')),
                'msgType' => $msgType !== '' ? $msgType : 'Alert',
                'event' => (string) $capNs->event,
                'headline' => (string) ($entry->title ?: $capNs->event ?: $this->Translate('Warning')),
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
        $areaName = (string) ($json['properties']['location']['properties']['name'] ?? $this->Translate('Austria'));

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
            $event = $this->Translate(self::GEOSPHERE_AT_WARNTYPE_EVENT[$wtype] ?? 'Warning');
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
                'category' => self::GEOSPHERE_AT_WARNTYPE_CATEGORY[$wtype] ?? 'sonstige',
                'msgType' => 'Alert',
                'event' => $event,
                'headline' => (string) ($props['text'] ?? sprintf($this->Translate('%s warning for %s'), $event, $areaName)),
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
            $this->LogError('fetchBafuHydroCh', $this->Translate('LINDAS query not reachable.'));
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
            $name = (string) ($row['name'] ?? $this->Translate('Measuring station'));
            $label = isset(self::BAFU_HYDRO_LEVEL_LABEL[$level]) ? $this->Translate(self::BAFU_HYDRO_LEVEL_LABEL[$level]) : sprintf($this->Translate('Level %d'), $level);
            $out[] = [
                'identifier' => 'bafu-hydro-' . ($row['id'] ?? $name),
                'source' => 'bafu_hydro_ch',
                'category' => 'starkregen',
                'msgType' => 'Alert',
                'event' => $this->Translate('Flood'),
                'headline' => sprintf($this->Translate('Flood danger: %s (danger level %d)'), $name, $level),
                'description' => sprintf(
                    $this->Translate('Official danger level %d of 5 (%s) at measuring station %s -- official BAFU scale, not a construct of its own.'),
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
            $this->LogError('fetchSedErdbebenCh', $this->Translate('SED query (eida.ethz.ch) not reachable.'));
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
            $place = trim($placeName) !== '' ? trim($placeName) : $this->Translate('Switzerland');
            $out[] = [
                'identifier' => 'sed-' . trim($eventId),
                'source' => 'sed_ch',
                'category' => 'sonstige',
                'msgType' => 'Alert',
                'event' => $this->Translate('Earthquake'),
                'headline' => sprintf($this->Translate('Earthquake magnitude %.1f near %s'), $magnitude, $place),
                'description' => sprintf($this->Translate('Depth %.1f km, epicentre %s, measured at %s (Swiss Seismological Service).'), (float) $depth, $place, date('d.m. H:i', $ts)),
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
    //  Alertswiss (alert.swiss/BABS) -- siehe Konstanten-Kommentar oben.
    //  EIN globaler Abruf für alle kantonalen Meldungen (wie Meteoalarm/
    //  BAFU/SED), das geometrische Matching in processWarnings() übernimmt
    //  danach die Zuordnung zu den einzelnen Standorten.
    // ----------------------------------------------------------------

    private function fetchAlertSwissCh(): array
    {
        $body = $this->httpGet(self::ALERTSWISS_URL, 15, 'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            $this->LogError('fetchAlertSwissCh', $this->Translate('Alertswiss query (alert.swiss) not reachable.'));
            return [];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $this->LogError('fetchAlertSwissCh', $this->Translate('Alertswiss response could not be read as JSON.'));
            return [];
        }
        return $this->parseAlertSwissJson($json);
    }

    /** Vom HTTP-Abruf getrennt (wie parseCapXml()/parseMeteoalarmAtom()), damit sich die Auswertung ohne Netzzugriff testen lässt. */
    private function parseAlertSwissJson(array $json): array
    {
        $alerts = $json['alerts'] ?? null;
        if (!is_array($alerts)) {
            return [];
        }

        $out = [];
        foreach ($alerts as $a) {
            $identifier = trim((string) ($a['identifier'] ?? ''));
            if ($identifier === '') {
                continue;
            }
            // "technicalTestAlert"/"testAlert" entsprechen CAP status=Test/
            // Exercise (siehe isCapStatusActual()) -- im Live-Feed real
            // beobachtet als dauerhafter Eintrag "Cap Test Event".
            if (!empty($a['technicalTestAlert']) || !empty($a['testAlert'])) {
                continue;
            }

            $areas = is_array($a['areas'] ?? null) ? $a['areas'] : [];
            $rings = [];
            $circles = [];
            $areaNames = [];
            foreach ($areas as $area) {
                $areaName = trim((string) ($area['description']['description'] ?? ''));
                if ($areaName !== '') {
                    $areaNames[] = $areaName;
                }
                foreach ((is_array($area['polygons'] ?? null) ? $area['polygons'] : []) as $polygon) {
                    $ring = [];
                    foreach ((is_array($polygon['coordinates'] ?? null) ? $polygon['coordinates'] : []) as $pair) {
                        if (is_array($pair) && count($pair) === 2) {
                            $ring[] = [(float) $pair[0], (float) $pair[1]];
                        }
                    }
                    if (count($ring) >= 3) {
                        $rings[] = $ring;
                    }
                }
                foreach ((is_array($area['circles'] ?? null) ? $area['circles'] : []) as $circle) {
                    $center = is_array($circle['centerPosition'] ?? null) ? $circle['centerPosition'] : null;
                    if ($center !== null && count($center) === 2 && isset($circle['radius'])) {
                        $circles[] = ['lat' => (float) $center[0], 'lon' => (float) $center[1], 'radiusKm' => (float) $circle['radius']];
                    }
                }
            }
            // Ausdehnung der ECHTEN Geometrie (vor dem Landesweit-Näherungskreis
            // unten, der bewusst nicht zählt): Diagonale der Hüllbox in km.
            $insideOnly = $this->geometryDiagonalKm($rings, $circles) >= self::ALERTSWISS_FLAECHIG_AB_KM;

            // Landesweite Meldung OHNE eigene Geometrie (im Live-Feed noch
            // nie beobachtet, "nationWide" war durchgehend false) -- grobe
            // Näherung durch einen Kreis über ganz Schweiz, statt sie
            // mangels Fläche stillschweigend zu verwerfen. Ausdrücklich
            // UNGETESTET gegen einen echten Live-Fall, wie die BETA-
            // Hagelschutz-Anbindung -- Rückmeldung willkommen.
            if (count($rings) === 0 && count($circles) === 0 && !empty($a['nationWide'])) {
                $circles[] = ['lat' => 46.8, 'lon' => 8.2, 'radiusKm' => 150.0];
            }

            $title = trim((string) ($a['title']['title'] ?? ''));
            $event = trim((string) ($a['event'] ?? ''));
            $description = trim((string) ($a['description']['description'] ?? ''));
            $publisher = trim((string) ($a['publisherName'] ?? ''));
            if ($publisher !== '') {
                $description = $description !== '' ? sprintf($this->Translate('%s (source: %s)'), $description, $publisher) : sprintf($this->Translate('Source: %s'), $publisher);
            }
            $instructions = [];
            foreach ((is_array($a['instructions'] ?? null) ? $a['instructions'] : []) as $instr) {
                $text = trim((string) ($instr['text'] ?? ''));
                if ($text !== '') {
                    $instructions[] = $text;
                }
            }

            $ts = $this->parseAlertSwissReferenceTimestamp((string) ($a['reference'] ?? ''));
            $severity = ucfirst(strtolower(trim((string) ($a['severity'] ?? ''))));
            if (!isset(self::SEVERITY_RANK[$severity])) {
                $severity = 'Unknown';
            }

            $out[] = [
                'identifier' => 'alertswiss-' . $identifier,
                'source' => 'alertswiss_ch',
                'msgType' => !empty($a['allClear']) ? 'Cancel' : 'Alert',
                'event' => $event !== '' ? $event : $this->Translate('Warning'),
                'headline' => $title !== '' ? $title : ($event !== '' ? $event : $this->Translate('Warning')),
                'description' => $description,
                'instruction' => implode("\n", $instructions),
                'severity' => $severity,
                'effective' => $ts,
                'onset' => $ts,
                'expires' => null,
                'areaDesc' => count($areaNames) > 0 ? implode('; ', $areaNames) : 'Schweiz',
                'rings' => $rings,
                'circles' => $circles,
                'insideOnly' => $insideOnly,
            ];
        }
        return $out;
    }

    /**
     * Diagonale der Hüllbox aller Ringe/Kreise in km (0, wenn keine Geometrie).
     * Kreise tragen mit ihrem Radius zur Hüllbox bei.
     *
     * @param array<array<array{0:float,1:float}>> $rings
     * @param array<array{lat:float,lon:float,radiusKm:float}> $circles
     */
    private function geometryDiagonalKm(array $rings, array $circles): float
    {
        $lats = [];
        $lons = [];
        foreach ($rings as $ring) {
            foreach ($ring as $pt) {
                $lats[] = $pt[0];
                $lons[] = $pt[1];
            }
        }
        foreach ($circles as $c) {
            $dLat = $c['radiusKm'] / 111.32;
            $dLon = $c['radiusKm'] / (111.32 * max(0.01, cos(deg2rad($c['lat']))));
            array_push($lats, $c['lat'] - $dLat, $c['lat'] + $dLat);
            array_push($lons, $c['lon'] - $dLon, $c['lon'] + $dLon);
        }
        if (count($lats) === 0) {
            return 0.0;
        }
        return WHUB_Geo::haversineKm(min($lats), min($lons), max($lats), max($lons));
    }

    /**
     * Alertswiss liefert KEIN eigenes Zeitfeld im ISO-8601-Format direkt --
     * "sent"/"publishDate" stehen als deutsch formatierter Text ("Mo.,
     * 14.09.2026, 14:53"), unzuverlässig zu parsen. Das Feld "reference"
     * (Format "<sender>,<identifier>,<ISO-8601-Zeitstempel>") enthält
     * denselben Zeitpunkt bereits maschinenlesbar -- live im Feed bestätigt
     * 22.09.2026, auch im Testeintrag ("...,2026-09-22T09:23:01+02:00").
     */
    private function parseAlertSwissReferenceTimestamp(string $reference): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})$/', $reference, $m) !== 1) {
            return null;
        }
        $ts = strtotime($m[1]);
        return $ts !== false ? date('c', $ts) : null;
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
        // Nur deutsche Standorte haben eine DWD-Station -- erst filtern, dann
        // abrufen (ein reiner Schweizer/österreichischer Aufbau mit
        // versehentlich aktivierter Quelle lädt so gar nichts).
        $standorteDe = [];
        foreach (array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== '') as $standort) {
            $coords = $this->resolveStandortCoords($standort);
            if ($this->reverseGeocodeStandort($coords['lat'], $coords['lon'])['countryCode'] === 'de') {
                $standorteDe[] = [$standort, $coords];
            }
        }
        if (count($standorteDe) === 0) {
            return [];
        }
        if (!function_exists('gzdecode')) {
            $this->LogError('fetchWaldbrandDe', $this->Translate('zlib extension (gzdecode) missing -- cannot read the zipped WBI station files.'));
            return [];
        }
        $now = time();
        $stations = $this->wbiStations($now);
        if ($stations === null) {
            if ($this->wbiShouldLogFailure($now)) {
                $this->LogError('fetchWaldbrandDe', $this->Translate('WBI station list (opendata.dwd.de) not reachable and no cached list yet -- forest fire danger index stays without a value for now, next attempt at the next check. This message appears at most every 6 hours.'));
            }
            return [];
        }
        $schwelle = $this->ReadPropertyInteger('WaldbrandDeSchwelle');
        $stationReadings = []; // Stationsindex => Ergebnis, dedupliziert je Aufruf (mehrere Standorte können dieselbe nächste Station teilen)
        $out = [];
        foreach ($standorteDe as [$standort, $coords]) {
            $nearest = $this->findNearestWbiStation($stations, $coords['lat'], $coords['lon']);
            if ($nearest === null) {
                continue;
            }
            if (!array_key_exists($nearest['index'], $stationReadings)) {
                $stationReadings[$nearest['index']] = $this->wbiReading($nearest['index'], $now);
            }
            $reading = $stationReadings[$nearest['index']];
            if ($reading === null || $reading['wbi'] < $schwelle) {
                continue;
            }
            if (!$this->wbiReadingIsCurrent($reading['date'], date('Ymd'))) {
                $this->SendDebug('fetchWaldbrandDe', sprintf('Station %d: letzter Wert vom %s ist älter als %d Tage (Saisonende?) -- nicht als aktuelle Waldbrandgefahr gewertet.', $nearest['index'], $reading['date'], self::WBI_MAX_AGE_DAYS), 0);
                continue;
            }
            $level = $reading['wbi'];
            $dateObj = DateTime::createFromFormat('Ymd', $reading['date']);
            $dateLabel = $dateObj !== false ? $dateObj->format('d.m.Y') : $reading['date'];
            $out[] = [
                'identifier' => 'waldbrand-de-' . $nearest['index'] . '-' . $reading['date'],
                'source' => 'waldbrand_de',
                'category' => 'sonstige',
                'msgType' => 'Alert',
                'event' => $this->Translate('Forest fire danger'),
                'headline' => sprintf($this->Translate('Forest fire danger index level %d of 5 (%s)'), $level, isset(self::WBI_LEVEL_LABEL[$level]) ? $this->Translate(self::WBI_LEVEL_LABEL[$level]) : ''),
                'description' => sprintf(
                    $this->Translate('Official forest fire danger index, as of %s. Nearest DWD measuring station: %s (%.0f km away).'),
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

    /**
     * Stationsliste mit Zwischenspeicher (Attribut WbiCache). Die Liste
     * (484 Stationen, ~97 KB) ändert sich praktisch nie, wurde bis 1.19.0
     * aber bei JEDER Prüfung neu geladen (Standardtakt 10 Minuten = ~14 MB
     * am Tag) -- und jeder einzelne Aussetzer von opendata.dwd.de landete
     * als Fehler im Meldungsprotokoll. Praxis-Fund baslerleckerli,
     * Symcon-Forum, 02.11.2026: "produziert regelmässig die Fehlermeldung
     * ... WBI-Stationsliste (opendata.dwd.de) nicht erreichbar" (Datei war
     * zur selben Zeit von hier aus abrufbar, also ein zeitweiser Aussetzer).
     * Jetzt: frisch geladen höchstens alle WBI_STATION_CACHE_SEC; schlägt
     * der Abruf fehl, gilt die letzte gespeicherte Liste weiter, egal wie
     * alt. null nur, wenn es noch nie eine gab.
     */
    private function wbiStations(int $now): ?array
    {
        $cache = $this->wbiCache();
        $list = $cache['stations'] ?? [];
        if (is_array($list) && count($list) > 0 && ($now - (int) ($cache['stationsTs'] ?? 0)) < self::WBI_STATION_CACHE_SEC) {
            return $list;
        }
        return $this->wbiStationsAfterFetch($this->fetchWbiStationList(), $now);
    }

    /** Vom HTTP-Abruf getrennt, damit sich Speichern/Rückfall ohne Netzzugriff testen lassen. $fetched = Ergebnis von fetchWbiStationList() (null/leer = Abruf fehlgeschlagen). */
    private function wbiStationsAfterFetch(?array $fetched, int $now): ?array
    {
        $cache = $this->wbiCache();
        if ($fetched !== null && count($fetched) > 0) {
            $cache['stations'] = $fetched;
            $cache['stationsTs'] = $now;
            $this->WriteAttributeString('WbiCache', json_encode($cache));
            return $fetched;
        }
        $list = $cache['stations'] ?? [];
        if (is_array($list) && count($list) > 0) {
            $this->SendDebug('fetchWaldbrandDe', 'WBI-Stationsliste nicht erreichbar -- nutze die zwischengespeicherte Liste vom ' . date('d.m.Y H:i', (int) ($cache['stationsTs'] ?? 0)) . '.', 0);
            return $list;
        }
        return null;
    }

    /**
     * Tageswert einer Station mit Zwischenspeicher: der Index ist ein
     * Tageswert, ein erneuter Abruf vor Ablauf von WBI_READING_CACHE_SEC
     * bringt nichts Neues. Schlägt der Abruf fehl, gilt der gespeicherte
     * Wert weiter (die Altersgrenze wbiReadingIsCurrent() greift trotzdem,
     * weil sie am Datum des Werts hängt, nicht am Abrufzeitpunkt).
     */
    private function wbiReading(int $stationIndex, int $now): ?array
    {
        $cache = $this->wbiCache();
        $c = $cache['readings'][(string) $stationIndex] ?? null;
        if (is_array($c) && isset($c['date'], $c['wbi']) && ($now - (int) ($c['ts'] ?? 0)) < self::WBI_READING_CACHE_SEC) {
            return ['date' => (string) $c['date'], 'wbi' => (int) $c['wbi']];
        }
        return $this->wbiReadingAfterFetch($stationIndex, $this->fetchWbiStationCsv($stationIndex), $now);
    }

    /** Vom HTTP-Abruf getrennt (siehe wbiStationsAfterFetch()). */
    private function wbiReadingAfterFetch(int $stationIndex, ?array $fetched, int $now): ?array
    {
        $cache = $this->wbiCache();
        if ($fetched !== null) {
            $cache['readings'][(string) $stationIndex] = ['ts' => $now, 'date' => $fetched['date'], 'wbi' => $fetched['wbi']];
            $this->WriteAttributeString('WbiCache', json_encode($cache));
            return $fetched;
        }
        $c = $cache['readings'][(string) $stationIndex] ?? null;
        return is_array($c) && isset($c['date'], $c['wbi']) ? ['date' => (string) $c['date'], 'wbi' => (int) $c['wbi']] : null;
    }

    /** true höchstens einmal je WBI_FAIL_LOG_SEC -- eine dauerhaft fehlende Stationsliste soll sichtbar sein, aber nicht alle 10 Minuten. */
    private function wbiShouldLogFailure(int $now): bool
    {
        $cache = $this->wbiCache();
        if (($now - (int) ($cache['failLogTs'] ?? 0)) < self::WBI_FAIL_LOG_SEC) {
            return false;
        }
        $cache['failLogTs'] = $now;
        $this->WriteAttributeString('WbiCache', json_encode($cache));
        return true;
    }

    private function wbiCache(): array
    {
        $c = json_decode($this->ReadAttributeString('WbiCache'), true);
        return is_array($c) ? $c : [];
    }

    private function fetchWbiStationList(): ?array
    {
        $body = $this->httpGet(self::WBI_STATION_LIST_URL, 30,'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
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
        $body = $this->httpGet($url, 30,'WarnHub/' . self::DOC_VERSION . ' (Symcon-Modul; https://github.com/DG65/WarnHub)');
        if ($body === null) {
            return null;
        }
        $csv = @gzdecode($body);
        if ($csv === false || $csv === '') {
            return null;
        }
        return $this->parseWbiStationCsv($csv);
    }

    /**
     * true, wenn der Stationswert (Datum "Ymd") höchstens WBI_MAX_AGE_DAYS
     * Tage vor $today liegt. $today wird übergeben statt intern gelesen,
     * damit sich die Altersgrenze ohne Datums-Mock testen lässt
     * (.tools/test-waldbrand-de.php). Unlesbares Datum = nicht aktuell.
     */
    private function wbiReadingIsCurrent(string $datum, string $today): bool
    {
        $d = DateTime::createFromFormat('!Ymd', $datum);
        $t = DateTime::createFromFormat('!Ymd', $today);
        if ($d === false || $t === false) {
            return false;
        }
        $ageDays = (int) $d->diff($t)->format('%r%a');
        return $ageDays <= self::WBI_MAX_AGE_DAYS;
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
        // Wie beim Waldbrandindex (1.19.1): Stationsliste und Messwerte mit
        // Zwischenspeicher, Aussetzer nicht mehr bei jeder Prüfung als Fehler.
        // Praxis-Fund baslerleckerli, Symcon-Forum, 04.11.2026: beide
        // UBA-Meldungen "nicht erreichbar" zusammen 9-mal in 24 Stunden.
        // Messwerte sind Stundenwerte: frisch geladen höchstens alle 20 Minuten,
        // bei einem Aussetzer gilt der letzte Stand höchstens 3 Stunden weiter
        // (älter wäre keine aktuelle Ozonbelastung mehr).
        $now = time();
        $stations = $this->cachedSourceData('UbaCache', 'stations', $now, self::UBA_STATION_CACHE_SEC, PHP_INT_MAX, fn () => $this->fetchUbaStations());
        if ($stations === null) {
            if ($this->shouldLogSourceFailure('UbaCache', $now)) {
                $this->LogError('fetchOzonDe', $this->Translate('UBA station list (luftdaten.umweltbundesamt.de) not reachable and no cached list yet -- ozone stays without a value for now, next attempt at the next check. This message appears at most every 6 hours.'));
            }
            return [];
        }
        $readings = $this->cachedSourceData('UbaCache', 'readings', $now, self::UBA_READING_CACHE_SEC, self::UBA_READING_MAX_AGE_SEC, fn () => $this->fetchUbaOzoneReadings());
        if ($readings === null) {
            if ($this->shouldLogSourceFailure('UbaCache', $now)) {
                $this->LogError('fetchOzonDe', $this->Translate('UBA air quality data (luftdaten.umweltbundesamt.de) not reachable for more than 3 hours -- ozone stays without a value until the next successful check. This message appears at most every 6 hours.'));
            }
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
                'category' => 'sonstige',
                'msgType' => 'Alert',
                'event' => $this->Translate('Ozone pollution'),
                'headline' => sprintf($this->Translate('Ozone air quality index level %d of 4 (%s)'), $level, isset(self::UBA_LQI_LABEL[$level]) ? $this->Translate(self::UBA_LQI_LABEL[$level]) : ''),
                'description' => sprintf(
                    $this->Translate('Ozone concentration %.0f µg/m³ (1-hour mean) at measuring station %s, as of %s. Official air quality index of the German Federal Environment Agency.'),
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

    /**
     * Allgemeiner Zwischenspeicher für Quelldaten im Attribut $attr, Fach
     * $slot. Frisch (jünger als $freshSec) -> ohne Abruf aus dem Speicher.
     * Sonst $fetch(); liefert der etwas Nichtleeres, wird es gespeichert.
     * Schlägt der Abruf fehl, gilt der gespeicherte Stand weiter, solange er
     * jünger als $maxStaleSec ist. null = weder frisch noch gespeichert.
     * Der Abruf ist als Funktion übergeben, damit sich das ohne Netz prüfen
     * lässt (.tools/test-ozon-de.php).
     */
    private function cachedSourceData(string $attr, string $slot, int $now, int $freshSec, int $maxStaleSec, callable $fetch): ?array
    {
        $cache = json_decode($this->ReadAttributeString($attr), true);
        $cache = is_array($cache) ? $cache : [];
        $entry = $cache[$slot] ?? null;
        $hasEntry = is_array($entry) && is_array($entry['data'] ?? null) && count($entry['data']) > 0;
        $age = $hasEntry ? $now - (int) ($entry['ts'] ?? 0) : PHP_INT_MAX;
        if ($hasEntry && $age < $freshSec) {
            return $entry['data'];
        }
        $fetched = $fetch();
        if (is_array($fetched) && count($fetched) > 0) {
            $cache[$slot] = ['ts' => $now, 'data' => $fetched];
            $this->WriteAttributeString($attr, json_encode($cache));
            return $fetched;
        }
        if ($hasEntry && $age < $maxStaleSec) {
            $this->SendDebug($attr, sprintf('%s nicht erreichbar -- nutze den gespeicherten Stand vom %s.', $slot, date('d.m.Y H:i', (int) $entry['ts'])), 0);
            return $entry['data'];
        }
        return null;
    }

    /** true höchstens einmal je 6 Stunden und Speicher -- ein dauerhafter Ausfall soll sichtbar sein, aber nicht bei jeder Prüfung. */
    private function shouldLogSourceFailure(string $attr, int $now): bool
    {
        $cache = json_decode($this->ReadAttributeString($attr), true);
        $cache = is_array($cache) ? $cache : [];
        if (($now - (int) ($cache['failLogTs'] ?? 0)) < self::WBI_FAIL_LOG_SEC) {
            return false;
        }
        $cache['failLogTs'] = $now;
        $this->WriteAttributeString($attr, json_encode($cache));
        return true;
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
            $this->LogError('fetchHagelschutzCh', $this->Translate('No configured Symcon system location -- hail warning cannot be placed (no guessed position).'));
            return [];
        }
        return [[
            'identifier' => 'hagelschutz-ch-' . $this->InstanceID,
            'source' => 'hagelschutz_ch',
            'category' => 'hagel',
            'msgType' => 'Alert',
            'event' => $this->Translate('Hail'),
            'headline' => $state === 2 ? $this->Translate('Hail protection signal box: hail warning active (test alarm)') : $this->Translate('Hail protection signal box: hail warning active'),
            'description' => $this->Translate('Signal of your own VKF hail protection signal box (meteo.netitservices.com) -- official in the sense that the forecast comes from SRF Meteo/VKF, but a device signal without a warning area geometry of its own.'),
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
        if ($this->ReadPropertyBoolean('QuelleAlertSwissCh')) {
            $warnings = array_merge($warnings, $this->fetchAlertSwissCh());
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
        if ($this->hasWetterstationConfigured()) {
            $warnings = array_merge($warnings, $this->fetchWetterstation());
        }

        $result = $this->processWarnings($warnings);
        if ($this->ReadPropertyBoolean('WetterstationAutoRueckstellung')) {
            $this->checkWetterstationAutoRestore();
        }
        $this->refreshHeimLandCode();
        $this->refreshStandortLaenderCodes();

        $this->WriteAttributeInteger('LastPollTs', time());
        $this->WriteAttributeString('LastActiveWarningsJson', json_encode($result['active']));
        $this->refreshStatusVariables();
        @$this->UpdateFormField('PollStatusLabel', 'caption', $this->getPollStatusLine());
        // Verbindungs-Statuszeilen (SUITE.md 21.09.2026) nach jeder Prüfung
        // mit auffrischen: Land, Live-Positionen und Wetterstations-Werte
        // ändern sich von selbst, ohne dass im Formular etwas angeklickt wird.
        $this->setStatusLabel('SystemLocationStatusLabel', $this->systemLocationStatusLine());
        $this->setStatusLabel('MobileStandorteStatusLabel', $this->mobileStandorteStatusLine());
        $this->refreshWetterstationStatus();
        $restoreLine = $this->wetterstationRestoreStatusLine();
        if ($restoreLine !== null) {
            @$this->UpdateFormField('WetterstationRestoreStatusLabel', 'caption', $restoreLine);
        }

        $icon = $result['activeCount'] > 0 ? '⚠️' : '✅';
        $text = sprintf(
            $this->Translate('%s Check completed: %d active warning(s), %d newly reported, %d escalated, %d all-clear(s), %d protective action(s) triggered.'),
            $icon,
            $result['activeCount'],
            $result['newlyPushed'],
            $result['escalated'],
            $result['cancelled'],
            $result['actionsTriggered']
        );
        if ($result['fensterWarnungen'] > 0) {
            $text .= ' ' . sprintf($this->Translate('%d window/door warning(s).'), $result['fensterWarnungen']);
        }
        return $text;
    }

    /** Schickt eine harmlose Testbenachrichtigung an alle aktivierten Push-Ziele, unabhängig von echten Warnungen -- zum Prüfen, ob der Zustellweg (WebFront/Kachel-Visualisierung, Signalton) tatsächlich ankommt. */
    public function TestPush(): string
    {
        $active = array_filter($this->decodeWebFronts(), fn ($w) => $w['Aktiv']);
        if (count($active) === 0) {
            return $this->Translate('⚠️ No activated push target -- activate at least one instance above in "🔔 Notification".');
        }
        $sent = $this->pushToAllWebfronts(
            $this->truncateBytes('🧪 WarnHub Test', 32),
            $this->truncateBytes($this->Translate('If this message arrives, the delivery route works. Not a real warning.'), 256),
            $this->ReadPropertyString('PushSound')
        );
        if ($sent === 0) {
            return sprintf($this->Translate('⚠️ Test failed -- could not deliver to any of the %d activated targets (see system log).'), count($active));
        }
        return sprintf($this->Translate('✅ Test notification sent to %d of %d activated target(s).'), $sent, count($active));
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
            return $this->Translate('⚠️ Duration must be greater than 0.');
        }
        $bis = time() + $minuten * 60;
        $this->WriteAttributeInteger('PushSnoozeUntilTs', $bis);
        @$this->UpdateFormField('SnoozeStatusLabel', 'caption', $this->snoozeStatusLine());
        return sprintf($this->Translate('🔕 Push notification paused until %s.'), date('d.m. H:i', $bis));
    }

    public function CancelSnooze(): string
    {
        if (!$this->isPushSnoozed()) {
            return $this->Translate('ℹ️ Push notification was not paused.');
        }
        $this->WriteAttributeInteger('PushSnoozeUntilTs', 0);
        @$this->UpdateFormField('SnoozeStatusLabel', 'caption', $this->snoozeStatusLine());
        return $this->Translate('🔔 Pause lifted -- push notification runs normally again.');
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
            return sprintf($this->Translate('⚠️ Unknown alert type "%s".'), $kategorie);
        }
        $label = $this->Translate(self::CATEGORY_FIELDS[$kategorie][1]);
        $matching = array_values(array_filter(
            $this->decodeSchutzaktionen(),
            fn ($a) => $a['Aktiv'] && (count($a['Kategorien']) === 0 || in_array($kategorie, $a['Kategorien'], true))
        ));
        if (count($matching) === 0) {
            return sprintf($this->Translate('⚠️ No active protective action found for "%s".'), $label);
        }
        $names = [];
        foreach ($matching as $action) {
            $this->fireProtectiveAction($action);
            $names[] = $action['Name'] !== '' ? $action['Name'] : sprintf($this->Translate('Target variable #%d'), $action['ZielVariableID']);
        }
        return sprintf($this->Translate('✅ %d protective action(s) triggered for "%s": %s'), count($matching), $label, implode(', ', $names));
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

    /**
     * Entfernt HTML-Reste aus Text externer Quellen (headline/description/
     * instruction) -- Praxis-Fund kronos/ralf, Symcon-Forum, 10.09.2026:
     * die amtliche NINA-Quelle lieferte beim bundesweiten Warntag 2026 ein
     * rohes "<br/>" mitten im Beschreibungstext, unabhängig bestätigt über
     * zwei verschiedene Push-Kanäle (Symcon-App, Pushover) -- eindeutig ein
     * Quelldaten-Fehler, kein WarnHub-Bug. Wandelt trotzdem <br>-Varianten
     * in echte Zeilenumbrüche um und entfernt alle übrigen Tags als
     * Härtung gegen künftige, ähnlich fehlerhafte Quellmeldungen. Behebt
     * NICHT fehlenden Leerraum zwischen aneinandergereihten
     * Quelltextabschnitten (z. B. "...umfrage.deBeginn: ...") -- das ließe
     * sich nicht sicher von echtem, gewolltem Text ohne Leerzeichen
     * unterscheiden.
     */
    private function sanitizeCapText(string $text): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        return trim($text);
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
            // Härtung gegen HTML-Reste in Texten externer Quellen -- kein
            // WarnHub-Bug, aber die amtliche NINA-Quelle lieferte beim
            // bundesweiten Warntag 2026 ein rohes "<br/>" mitten im
            // Beschreibungstext, das dann unverändert (z. B. in
            // WFC_PushNotification, die kein HTML rendert) als sichtbarer
            // Text ankam -- unabhängig bestätigt über zwei verschiedene
            // Push-Kanäle (Symcon-App, Pushover). Praxis-Fund kronos/ralf,
            // Symcon-Forum, 10.09.2026. Einmal hier am Eingang der
            // Standort-Schleife bereinigt, statt an jeder einzelnen
            // Ausgabestelle einzeln (Push-Text, Kachel-Detailansicht,
            // Historie, Cancel-/Fenster-Meldungstext) -- kein Ausgabepfad
            // kann diese Härtung vergessen.
            $w['headline'] = $this->sanitizeCapText($w['headline'] ?? '');
            $w['description'] = $this->sanitizeCapText($w['description'] ?? '');
            $w['instruction'] = $this->sanitizeCapText($w['instruction'] ?? '');

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

            // Eigene Quellen (Pegel, Strahlung, Wetterstation, Waldbrand, Ozon,
            // Erdbeben, BAFU, Hagel-Signalbox, GeoSphere) und Meteoalarm (über
            // den genormten Warntyp) setzen ihre Kategorie selbst -- seit 1.20.1,
            // damit sie nicht am (übersetzten) Anzeigetext hängt (SUITE.md 9).
            // Nur Fremdtexte (NINA, DWD, Alertswiss) laufen über die Stichwörter.
            $category = (string) ($w['category'] ?? '') !== '' ? (string) $w['category'] : $this->classifyEventCategory($w['event'], $w['headline']);
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
                // 'insideOnly' (flächige Alertswiss-Meldung, siehe
                // ALERTSWISS_FLAECHIG_AB_KM): nur Abstand 0 = Standort liegt IN der
                // Fläche, der Umkreis des Standorts zählt hier nicht.
                $maxDistanceKm = !empty($w['insideOnly']) ? 0.0 : $standort['RadiusKm'];
                $matches = $hasGeo && $distanceKm !== null && $distanceKm <= $maxDistanceKm;

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
                                $this->Translate('✅ All-clear'),
                                sprintf($this->Translate('%s: %s lifted.'), $standort['Name'], $w['headline']),
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
                            $this->Translate('⚠️ Window/door open'),
                            sprintf($this->Translate('%s is open -- %s. Please close it.'), $kontakt['Name'], $w['headline']),
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
        $label = mb_convert_case(mb_strtolower(trim($event) !== '' ? $event : $this->Translate('Warning')), MB_CASE_TITLE);
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
            $text .= ' ' . sprintf($this->Translate('Valid until %s.'), $this->formatDateDe($w['expires']));
        }
        // Meteoalarm-Namensabgleich statt Geometrie -- Transparenz statt
        // vorgetäuschter Präzision (siehe fetchMeteoalarmCountry()).
        if ($nameMatched) {
            $text .= ' ' . $this->Translate('(Name match, no warning area available.)');
        }
        if ($w['description'] !== '') {
            $text .= ' ' . $w['description'];
        }
        return $text;
    }

    // ----------------------------------------------------------------
    //  Kacheln (WebFront-/Kachel-Visualisierung, ~HTMLBox-Variablen)
    // ----------------------------------------------------------------

    /**
     * " · 6 km entfernt" für Umkreis-Treffer, leer bei Standort IN der
     * Warnfläche (0 km) oder Namensabgleich (distanceKm null). Ohne diese
     * Angabe sah eine DWD-Warnung für die deutsche Grenzgemeinde in der
     * Kachel aus wie eine Warnung FÜR den Schweizer Standort selbst --
     * Praxis-Fund baslerleckerli, Symcon-Forum, 24.10.2026 ("In Alertswiss
     * ist nichts": die Sturmböen für Home BL waren die Rheinfeldener
     * DWD-Warnung 8 km weiter, über den eingestellten Umkreis getroffen).
     */
    private function tileDistanceSuffix(array $w): string
    {
        $d = $w['distanceKm'] ?? null;
        if (!is_numeric($d) || (float) $d < 0.05) {
            return '';
        }
        $d = (float) $d;
        $label = $d < 1.0 ? number_format($d, 1, ',', '') : (string) (int) round($d);
        return ' · ' . sprintf($this->Translate('%s km away'), $label);
    }

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
     * Zeigt die Links der Länder, in denen tatsächlich mindestens ein aktiver
     * Standort liegt (StandortLaenderCodes, siehe refreshStandortLaenderCodes()
     * -- ein mobiler Standort im Ausland bringt sein Land automatisch mit
     * rein, sobald der nächste Poll gelaufen ist), ergänzt um die Länder,
     * deren direkte Datenquelle aktiviert ist (z. B. GeoSphere Austria auch
     * ohne dort konfigurierten Standort). Echte externe Links (kein iframe),
     * deshalb unabhängig von der X-Frame-Options-Sperre, die DWD und
     * MeteoSchweiz für eine Einbettung setzen. Ist gar kein Land ermittelbar
     * (z. B. direkt nach der Installation, vor dem ersten Poll), werden
     * sicherheitshalber D/A/CH gezeigt statt eines leer wirkenden Bereichs.
     * Praxis-Wünsche hfichtinger und Dietmar, Symcon-Forum, 09.09.2026.
     */
    private function officialMapLinksHtml(): string
    {
        $codes = json_decode($this->ReadAttributeString('StandortLaenderCodes'), true) ?: [];
        if ($this->ReadPropertyBoolean('QuelleNina') || $this->ReadPropertyBoolean('QuelleDwd')) {
            $codes[] = 'de';
        }
        if ($this->ReadPropertyBoolean('QuelleGeosphereAt')) {
            $codes[] = 'at';
        }
        if ($this->ReadPropertyBoolean('QuelleBafuHydroCh')
            || $this->ReadPropertyBoolean('QuelleSedErdbebenCh')
            || trim($this->ReadPropertyString('HagelschutzPollUrl')) !== '') {
            $codes[] = 'ch';
        }
        $codes = array_unique($codes);

        $links = '';
        foreach ($codes as $code) {
            $link = self::COUNTRY_MAP_LINKS[$code] ?? null;
            if ($link === null) {
                continue; // Land ermittelt, aber (noch) keine hinterlegte amtliche Warnseite dafür
            }
            $links .= '<a href="' . htmlspecialchars($link['url']) . '" target="_blank" rel="noopener">' . htmlspecialchars($link['label']) . '</a>';
        }
        if ($links === '') {
            // Weder ein bekanntes Land ermittelbar noch eine direkte
            // Länder-Quelle aktiv (bzw. nur Länder ohne hinterlegte
            // Warnseite) -- D/A/CH als sicherer Auffangwert statt eines
            // leer wirkenden Bereichs.
            foreach (['de', 'at', 'ch'] as $code) {
                $link = self::COUNTRY_MAP_LINKS[$code];
                $links .= '<a href="' . htmlspecialchars($link['url']) . '" target="_blank" rel="noopener">' . htmlspecialchars($link['label']) . '</a>';
            }
        }
        return '<div class="whub-maplinks">' . $links . '</div>';
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
            $title = $this->Translate('No active warning');
        } else {
            $top = $this->highestActiveSeverity($active);
            $icon = self::SEVERITY_ICON[$top['severity']] ?? '⚠️';
            $color = self::TILE_SEVERITY_COLOR[$top['severity']] ?? self::TILE_SEVERITY_COLOR['Unknown'];
            $title = $count === 1 ? $this->Translate('1 active warning') : sprintf($this->Translate('%d active warnings'), $count);
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
        $sub = $lastTs > 0 ? htmlspecialchars(sprintf($this->Translate('checked at %s'), date('H:i', $lastTs))) : htmlspecialchars($this->Translate('never checked'));
        if ($snoozed) {
            $sub .= ' · ' . htmlspecialchars($this->Translate('🔕 Push paused'));
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
        $time = $lastTs > 0 ? htmlspecialchars(sprintf($this->Translate('%s (time)'), date('H:i', $lastTs))) : '--:--';
        if ($snoozed) {
            $time = '🔕 ' . $time;
        }
        $body = '';

        if (count($active) === 0) {
            $body = '<div class="whub-empty"><div class="whub-empty-icon">✅</div><div class="whub-empty-text">' . htmlspecialchars($this->Translate('No active warning')) . '</div></div>';
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
                $eventLabel = htmlspecialchars(mb_convert_case(mb_strtolower(trim($w['event'] ?? '') !== '' ? $w['event'] : $this->Translate('Warning')), MB_CASE_TITLE));
                $sub = htmlspecialchars($w['standort'] ?? '') . $this->tileDistanceSuffix($w);
                if (!empty($w['expires'])) {
                    $expTs = strtotime((string) $w['expires']);
                    if ($expTs !== false) {
                        $sub .= ' · ' . htmlspecialchars(sprintf($this->Translate('until %s'), date('H:i', $expTs)));
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
                $body .= '<div class="whub-more">' . htmlspecialchars(sprintf($this->Translate('+%d more'), count($active) - 8)) . '</div>';
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
    private function renderKachelAlleWarnungen(array $active, int $lastTs, bool $snoozed = false, bool $defaultExpanded = false): string
    {
        $tAll = htmlspecialchars($this->Translate('WarnHub -- all warnings'));
        $tHide = htmlspecialchars($this->Translate('Hide'));
        $tAllHidden = htmlspecialchars($this->Translate('All active warnings hidden'));
        $tShowAll = htmlspecialchars($this->Translate('Show all again'));
        $time = $lastTs > 0 ? htmlspecialchars(sprintf($this->Translate('%s (time)'), date('H:i', $lastTs))) : '--:--';
        if ($snoozed) {
            $time = '🔕 ' . $time;
        }
        $listId = 'whuballwarn' . substr(md5((string) $this->InstanceID), 0, 10);

        if (count($active) === 0) {
            $body = '<div class="whub-empty"><div class="whub-empty-icon">✅</div><div class="whub-empty-text">' . htmlspecialchars($this->Translate('No active warning')) . '</div></div>';
            return $this->tileStyleBlock() . <<<HTML
<div class="whub-overview">
  <div class="whub-header">
    <span class="whub-header-icon">🛡️</span>
    <span class="whub-header-title">{$tAll}</span>
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
            $eventRaw = trim($w['event'] ?? '') !== '' ? $w['event'] : $this->Translate('Warning');
            $eventLabel = htmlspecialchars(mb_convert_case(mb_strtolower($eventRaw), MB_CASE_TITLE));
            $eventTypes[$eventRaw] = true;
            $sub = htmlspecialchars($w['standort'] ?? '') . $this->tileDistanceSuffix($w);
            if (!empty($w['expires'])) {
                $expTs = strtotime((string) $w['expires']);
                if ($expTs !== false) {
                    $sub .= ' · ' . htmlspecialchars(sprintf($this->Translate('until %s'), date('H:i', $expTs)));
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
                    $validity .= htmlspecialchars(sprintf($this->Translate('Start: %s.'), date('d.m. H:i', $effTs))) . ' ';
                }
            }
            if (!empty($w['expires'])) {
                $expTs = strtotime((string) $w['expires']);
                if ($expTs !== false) {
                    $validity .= htmlspecialchars(sprintf($this->Translate('Valid until: %s.'), date('d.m. H:i', $expTs)));
                }
            }
            if ($validity !== '') {
                $detailParts[] = '<div class="whub-card-detail-validity">' . trim($validity) . '</div>';
            }
            if (!empty($w['description'])) {
                $detailParts[] = '<div class="whub-card-detail-desc">' . nl2br(htmlspecialchars($w['description'])) . '</div>';
            }
            $hasDetail = count($detailParts) > 0;
            // Praxis-Wunsch ruan, Symcon-Forum, 09.09.2026: wahlweise alle
            // Karten von vornherein aufgeklappt (AlleWarnungenAufgeklappt) --
            // das Ein-/Ausklappen per Klick bleibt trotzdem für jede Karte
            // einzeln verfügbar, nur der Startzustand ändert sich.
            $startExpanded = $hasDetail && $defaultExpanded;
            $detailBlock = $hasDetail ? '<div class="whub-card-detail"' . ($startExpanded ? '' : ' hidden') . '>' . implode('', $detailParts) . '</div>' : '';
            $expandableClass = $hasDetail ? ' whub-card-expandable' : '';
            $expandedClass = $startExpanded ? ' whub-expanded' : '';
            $chevron = $hasDetail ? '<span class="whub-card-chevron">›</span>' : '';

            $cards .= <<<HTML
<div class="whub-card{$expandableClass}{$expandedClass}" data-key="{$key}" data-event="{$eventAttr}" style="border-left-color:{$color};position:relative;padding-right:36px;">
  <div class="whub-card-icon">{$icon}</div>
  <div class="whub-card-body">
    <div class="whub-card-title">{$eventLabel}{$chevron}</div>
    <div class="whub-card-sub">{$sub}</div>
    {$detailBlock}
  </div>
  <div class="whub-card-dismiss" title="{$tHide}">✕</div>
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
    <span class="whub-header-title">{$tAll}</span>
    <span class="whub-header-time">{$time}</span>
  </div>
  <div id="{$listId}filterbar" class="whub-map-legend" style="padding:0 0 10px 0;">{$filterChips}</div>
  <div id="{$listId}" class="whub-allwarn-list">{$cards}</div>
  <div id="{$listId}empty" class="whub-empty" hidden style="margin-top:8px;">
    <div class="whub-empty-icon">🙈</div>
    <div class="whub-empty-text">{$tAllHidden}</div>
  </div>
  <div id="{$listId}reset" class="whub-allwarn-reset">{$tShowAll}</div>
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
        $tNoLoc = htmlspecialchars($this->Translate('No active location configured (see panel "Locations")'));
        $tAllJs = json_encode($this->Translate('🌍 All'), JSON_UNESCAPED_UNICODE);
        $standorte = array_values(array_filter($this->decodeStandorte(), fn ($s) => $s['Aktiv'] && $s['Name'] !== ''));
        if ($standorte === []) {
            return $this->tileStyleBlock() . <<<HTML
<div class="whub-status">
  <div class="whub-empty">
    <div class="whub-empty-icon">🗺️</div>
    <div class="whub-empty-text">{$tNoLoc}</div>
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
    allPill.textContent = {$tAllJs};
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
        $tZamg = htmlspecialchars($this->Translate('ZAMG warning map Austria'));
        $hoehePx = $this->ReadPropertyInteger('ZamgKachelHoehePx');
        $heightCss = $hoehePx > 0 ? $hoehePx . 'px' : '100%';
        return $this->tileStyleBlock() . <<<HTML
<div class="whub-status" style="padding:0;overflow:hidden;height:{$heightCss};min-height:200px;">
  <iframe src="{$url}" style="width:100%;height:100%;min-height:200px;border:0;border-radius:22px;" loading="lazy" title="{$tZamg}"></iframe>
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
                    $this->LogError('pushToAllWebfronts', $this->Translate('VISU_PostNotificationEx is not available (no tile visualization installed).'));
                    continue;
                }
                $ok = @VISU_PostNotificationEx($w['InstanceID'], $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), 'Alert', $sound, 0);
            } elseif ($w['Typ'] === 'telegram') {
                // TB_SendMessage() (offizielles Symcon-Modul symcon/TelegramBot)
                // kennt keinen separaten Titel -- Titel+Text deshalb zu einer
                // Nachricht zusammengefasst.
                if (!function_exists('TB_SendMessage')) {
                    $this->LogError('pushToAllWebfronts', $this->Translate('TB_SendMessage is not available (no Telegram bot module installed).'));
                    continue;
                }
                $ok = @TB_SendMessage($w['InstanceID'], $title . "\n" . $text);
            } elseif ($w['Typ'] === 'pushover') {
                // TUPO_SendMessage() (Community-Modul timo-u/Symcon_Pushover)
                // kennt Titel getrennt vom Text sowie eine Priorität -- Severe/
                // Extreme werden als "hohe Priorität" (1) markiert, alles
                // andere als normal (0).
                if (!function_exists('TUPO_SendMessage')) {
                    $this->LogError('pushToAllWebfronts', $this->Translate('TUPO_SendMessage is not available (no Pushover module installed).'));
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
                    $this->LogError('pushToAllWebfronts', $this->Translate('SMTP_SendMailEx is not available (no SMTP module installed).'));
                    continue;
                }
                $empfaenger = trim($w['Zieladresse'] ?? '');
                if ($empfaenger === '') {
                    $this->LogError('pushToAllWebfronts', sprintf($this->Translate('E-mail target "%s" has no target address -- skipped.'), $w['Name']));
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
                        $this->LogError('pushToAllWebfronts', sprintf($this->Translate('E-mail to "%s" (target "%s") failed.'), $adresse, $w['Name']));
                    }
                }
            } elseif ($w['Typ'] === 'notification') {
                // Notification Control kennt keinen eigenen Push-Adressaten --
                // NC_PushNotification() pusht an eine VISUALISIERUNG
                // (Konfigurator-Instanz-ID, der zweite Parameter), NICHT an
                // die Geräte-ID aus NC_GetDevices() selbst (Live-Test
                // 01.10.2026 an Dietmars System: die Geräte-ID lieferte
                // `false`, die Visualisierungs-ID aus `Visualizations`
                // lieferte eine echte Benachrichtigungs-ID). Erfolg = NICHT
                // false (eine Benachrichtigungs-ID, kein Bool -- offiziell
                // undokumentierte Funktion, kein `=== true` möglich). Deckt
                // damit u. a. IPSView-only-Geräte ab, die bei keiner
                // WebFront-/Kachel-Instanz als Konfigurator eingetragen sind
                // (Praxis-Fund tomfes, Symcon-Forum, 01.10.2026).
                // Jedes Gerät soll die Benachrichtigung genau EINMAL bekommen. Ein
                // Push an einen Konfigurator geht wie bei WFC_PushNotification an
                // ALLE seine Geräte -- ist ein Gerät bei mehreren Konfiguratoren
                // aktiv, bekäme es bei einem Aufruf je Konfigurator (so in
                // 1.17.0-1.17.2) jedes Mal eine Kopie (Rückmeldung baslerleckerli,
                // Symcon-Forum, 05.10.2026: je Meldung 3-4 Kopien, genau die Zahl
                // der aktiven Konfiguratoren seiner Geräte). Deshalb so wenige
                // Konfiguratoren wie nötig anstoßen, bis jedes Gerät erreicht ist
                // (Greedy: der Konfigurator mit den meisten noch nicht erreichten
                // Geräten zuerst, bei Gleichstand die kleinste ID). Schlägt ein
                // Aufruf fehl, bleiben seine Geräte offen und ein anderer
                // Konfigurator springt ein. $ok zählt diese EINE Zeile als
                // "funktioniert", sobald mindestens EIN Konfigurator erreicht
                // wurde (1 Zeile = 1 Ziel in "X von Y gesendet").
                if (!function_exists('NC_GetDevices') || !function_exists('NC_PushNotification')) {
                    $this->LogError('pushToAllWebfronts', $this->Translate('NC_GetDevices/NC_PushNotification are not available (no Notification Control instance installed).'));
                    continue;
                }
                $devices = @NC_GetDevices($w['InstanceID']);
                if (!is_array($devices)) {
                    $this->LogError('pushToAllWebfronts', sprintf($this->Translate('NC_GetDevices returned no device list for instance %s.'), $w['InstanceID']));
                    continue;
                }
                $aktiveJeGeraet = [];
                foreach ($devices as $deviceIndex => $device) {
                    if (!is_array($device) || !is_array($device['Visualizations'] ?? null)) {
                        continue;
                    }
                    foreach ($device['Visualizations'] as $visualizationID => $aktiv) {
                        if ($aktiv === true) {
                            $aktiveJeGeraet[$deviceIndex][] = (int) $visualizationID;
                        }
                    }
                }
                $offeneGeraete = array_keys($aktiveJeGeraet);
                $versucht = [];
                $ok = false;
                while (count($offeneGeraete) > 0) {
                    $abdeckung = [];
                    foreach ($offeneGeraete as $deviceIndex) {
                        foreach ($aktiveJeGeraet[$deviceIndex] as $visualizationID) {
                            if (!isset($versucht[$visualizationID])) {
                                $abdeckung[$visualizationID] = ($abdeckung[$visualizationID] ?? 0) + 1;
                            }
                        }
                    }
                    if (count($abdeckung) === 0) {
                        break; // übrige Geräte nur noch über bereits fehlgeschlagene Konfiguratoren erreichbar
                    }
                    ksort($abdeckung); // bei Gleichstand gewinnt die kleinste ID
                    $beste = null;
                    $besteAnzahl = 0;
                    foreach ($abdeckung as $visualizationID => $anzahl) {
                        if ($anzahl > $besteAnzahl) {
                            $beste = $visualizationID;
                            $besteAnzahl = $anzahl;
                        }
                    }
                    $versucht[$beste] = true;
                    $result = @NC_PushNotification($w['InstanceID'], $beste, $this->truncateBytes($title, 32), $this->truncateBytes($text, 256), $sound);
                    if ($result !== false) {
                        $ok = true;
                        $offeneGeraete = array_values(array_filter($offeneGeraete, fn ($deviceIndex) => !in_array($beste, $aktiveJeGeraet[$deviceIndex], true)));
                    } else {
                        $this->LogError('pushToAllWebfronts', sprintf($this->Translate('NC_PushNotification to configurator %s (Notification Control instance %s) failed.'), $beste, $w['InstanceID']));
                    }
                }
            } else {
                if (!function_exists('WFC_PushNotification')) {
                    $this->LogError('pushToAllWebfronts', $this->Translate('WFC_PushNotification is not available (no WebFront module installed).'));
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
                $this->LogError('pushToAllWebfronts', sprintf($this->Translate('Push to instance %s (%s) failed.'), $w['InstanceID'], $w['Typ']));
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
                    $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s": target script missing/does not exist.'), $action['Name']));
                }
                return;
            }

            if ($action['ZielVariableID'] <= 0 || !IPS_VariableExists($action['ZielVariableID'])) {
                $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s": target variable missing/does not exist.'), $action['Name']));
                return;
            }

            if ($action['Typ'] === 'sirene') {
                $ok = @RequestAction($action['ZielVariableID'], true);
                if (!$ok) {
                    $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s" (siren): RequestAction failed.'), $action['Name']));
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
                    $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s" (close windows): RequestAction failed.'), $action['Name']));
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
                    $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s" (boot/tailgate): no valid state variable configured -- no automatic triggering, as the toggle command might otherwise open instead of close.'), $action['Name']));
                    return;
                }
                $zustand = (string) GetValue($action['ZustandsVariableID']);
                // Tessie listet offene Klappen in der Systemsprache: "Kofferraum"
                // bzw. auf Englisch "TrunkRear" (1.20.1). Frunk zählt nicht.
                $offen = false;
                foreach (['kofferraum', 'heckklappe', 'trunkrear', 'rear trunk', 'tailgate'] as $klappe) {
                    $offen = $offen || mb_stripos($zustand, $klappe) !== false;
                }
                if (!$offen) {
                    return; // bereits geschlossen -- nichts zu tun, kein Fehler
                }
                $ok = @RequestAction($action['ZielVariableID'], true);
                if (!$ok) {
                    $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s" (boot/tailgate): RequestAction failed.'), $action['Name']));
                }
                return;
            }

            // raffstore / markise / garage / wert: Zielwert exakt wie vom Nutzer angegeben setzen
            $ok = @RequestAction($action['ZielVariableID'], $action['ZielWert']);
            if (!$ok) {
                $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s": RequestAction failed.'), $action['Name']));
            }
        } catch (\Throwable $e) {
            $this->LogError('fireProtectiveAction', sprintf($this->Translate('Protective action "%s" threw an error: %s'), $action['Name'], $e->getMessage()));
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
                    $this->LogError('CheckSirenOff', sprintf($this->Translate('Auto-off for variable %s failed.'), $p['variableID']));
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
