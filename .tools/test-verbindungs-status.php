<?php

/**
 * Prüfstand für die Verbindungs-Statuszeilen (SUITE.md 21.09.2026, Regel
 * "automatische Verbindung = Live-Statuszeile"): Push-Ziele,
 * Systemstandort, eigene Wetterstation, mobile Live-Standorte. Prüft je
 * Zeile ALLE Zustände (✅ / ⚠️ / ℹ️ / ⛔), dass die Zeilen im ausgelieferten
 * Formular unter ihrem Namen stehen (für UpdateFormField) und dass die
 * drei Wetterstations-Auswahlfelder per onChange die Auswahl (nicht den
 * Speicherstand) nachführen. Kein Netzzugriff nötig.
 *
 *   php .tools/test-verbindungs-status.php    # 0 = alle Prüfungen bestanden
 */

const FROGGIT_GUID = '{499F8100-B051-E713-CEC0-499D795B2639}';
const OTHER_FROGGIT_MODULE_GUID = '{22222222-2222-2222-2222-222222222222}';
const WEATHERSTATION_WU_GUID = '{FBDB2770-0232-43D2-F40B-1240CEAF6CD4}';
const METEOBRIDGE_GUID = '{24A6FC41-748D-4843-BEF9-0606DBB95CD3}';
const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
const LOCATION_INSTANCE_ID = 900;

// Fake-Objektbaum für DiscoverWetterstation():
//   10 Instanz "Wetterstation" (exakte Froggit-GUID) -> 101 Ident
//      "windgustmph"/Anzeigename "Windböe" (Profil ~WindSpeed.kmh), 102
//      Ident "rainratein"/Anzeigename "Regenrate" (~Rainfall), 103 Ident
//      "maxdailygust"/Anzeigename "Windböe (Max.) Tag" (Dekoy, der
//      Ident-Abgleich darf sie NICHT mit 101 verwechseln -- echte Idents
//      laut Quellcode github.com/IPSAttain/Froggit, Praxis-Fund ralf,
//      Symcon-Forum 05.09.2026: Namensabgleich fand die echte Instanz
//      nicht)
//   11 Instanz "Andere Wetterstation" (nur über Namenssuche "froggit"
//      auffindbar, KEINE Windböe/Regenrate-Variable -- muss trotz
//      Namenstreffer abgelehnt werden)
//   20 Instanz "Sainlogic" (Wolbolar/IPSymconWeatherStation, exakte GUID)
//      -> 201 Ident "Windgust" (Anzeigename "Wind gust", NICHT "Windböe" --
//         der Treffer muss über den Ident laufen, nicht den Namen; echtes
//         Profil laut Quellcode ~WindSpeed.ms, NICHT km/h -- prüft die
//         Einheiten-Umrechnung), 202 Ident "rainin" (Anzeigename "Rain",
//         ~Rainfall)
//   30 Instanz "Meteobridge" (elueckel/Symcon_Meteobridge_Meteohub, exakte
//      GUID) -> 301 Ident "Wind_Gust_KmH" (~WindSpeed.kmh, schon km/h),
//      302 Ident "Rain_Rate" (~Rainfall)
//   40 Instanz "Wetterstation Piezo" (exakte Froggit-GUID) -- neuere
//      Ecowitt-Gateway-Generation mit Piezo-Regensensor (z. B. WS90):
//      401 Ident "windgustmph"/Anzeigename "Windböe" wie gehabt, aber KEIN
//      "rainratein" mehr -- nur die "*_piezo"-Feldfamilie, vom Froggit-
//      Quellcode per generischem Catch-all ("enthält 'rain'") angelegt,
//      Ident UND Anzeigename beide der rohe, unübersetzte Gateway-
//      Feldname. 402 Ident/Name "rrain_piezo" (aktuelle Regenrate laut
//      Ecowitt-Protokolldokumentation -- der Treffer), 403 Ident/Name
//      "drain_piezo" (Tagessumme, Dekoy -- darf NICHT als Regenrate
//      durchgehen). Praxis-Fund ralf, Symcon-Forum, 07.09.2026.
$GLOBALS['whub_test_tree'] = [
    10 => [101, 102, 103],
    11 => [111],
    20 => [201, 202],
    30 => [301, 302],
    40 => [401, 402, 403],
];
$GLOBALS['whub_test_objects'] = [
    10 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation', 'ObjectIdent' => ''],
    11 => ['ObjectType' => 1, 'ObjectName' => 'Andere Wetterstation', 'ObjectIdent' => ''],
    20 => ['ObjectType' => 1, 'ObjectName' => 'Sainlogic', 'ObjectIdent' => ''],
    30 => ['ObjectType' => 1, 'ObjectName' => 'Meteobridge', 'ObjectIdent' => ''],
    40 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation Piezo', 'ObjectIdent' => ''],
    101 => ['ObjectType' => 2, 'ObjectName' => 'Windböe', 'ObjectIdent' => 'windgustmph'],
    102 => ['ObjectType' => 2, 'ObjectName' => 'Regenrate', 'ObjectIdent' => 'rainratein'],
    103 => ['ObjectType' => 2, 'ObjectName' => 'Windböe (Max.) Tag', 'ObjectIdent' => 'maxdailygust'],
    111 => ['ObjectType' => 2, 'ObjectName' => 'Innentemperatur', 'ObjectIdent' => ''],
    201 => ['ObjectType' => 2, 'ObjectName' => 'Wind gust', 'ObjectIdent' => 'Windgust'],
    202 => ['ObjectType' => 2, 'ObjectName' => 'Rain', 'ObjectIdent' => 'rainin'],
    301 => ['ObjectType' => 2, 'ObjectName' => 'Wind Gust km/h', 'ObjectIdent' => 'Wind_Gust_KmH'],
    302 => ['ObjectType' => 2, 'ObjectName' => 'Rain Rate', 'ObjectIdent' => 'Rain_Rate'],
    401 => ['ObjectType' => 2, 'ObjectName' => 'Windböe', 'ObjectIdent' => 'windgustmph'],
    402 => ['ObjectType' => 2, 'ObjectName' => 'rrain_piezo', 'ObjectIdent' => 'rrain_piezo'],
    403 => ['ObjectType' => 2, 'ObjectName' => 'drain_piezo', 'ObjectIdent' => 'drain_piezo'],
];
$GLOBALS['whub_test_variableProfiles'] = [
    101 => '~WindSpeed.kmh',
    102 => '~Rainfall',
    201 => '~WindSpeed.ms', // echtes Wolbolar-Verhalten -- KEIN km/h
    202 => '~Rainfall',
    301 => '~WindSpeed.kmh',
    302 => '~Rainfall',
    401 => '~WindSpeed.kmh',
    402 => '~Rainfall',
    403 => '~Rainfall',
];
$GLOBALS['whub_test_instancesByModule'] = [
    FROGGIT_GUID => [10, 40],
    WEATHERSTATION_WU_GUID => [20],
    METEOBRIDGE_GUID => [30],
];
$GLOBALS['whub_test_moduleNames'] = [
    OTHER_FROGGIT_MODULE_GUID => 'Froggit Legacy',
];
$GLOBALS['whub_test_instancesByOtherModule'] = [
    OTHER_FROGGIT_MODULE_GUID => [11],
];
$GLOBALS['whub_test_values'] = [];
$GLOBALS['whub_test_properties'] = [];
$GLOBALS['whub_test_kernelVersion'] = 9.0;
$GLOBALS['whub_test_formFieldSets'] = [];

function IPS_GetChildrenIDs(int $id): array
{
    return $GLOBALS['whub_test_tree'][$id] ?? [];
}
function IPS_GetObject(int $id)
{
    return $GLOBALS['whub_test_objects'][$id] ?? false;
}
function IPS_GetVariable(int $id)
{
    if (!isset($GLOBALS['whub_test_objects'][$id]) || $GLOBALS['whub_test_objects'][$id]['ObjectType'] !== 2) {
        return false;
    }
    return [
        'VariableProfile' => $GLOBALS['whub_test_variableProfiles'][$id] ?? '',
        'VariableCustomProfile' => '',
    ];
}
function IPS_GetVariableList(): array
{
    return array_keys(array_filter($GLOBALS['whub_test_objects'], fn ($o) => $o['ObjectType'] === 2));
}
function IPS_GetParent(int $id): int
{
    return (int) ($GLOBALS['whub_test_objects'][$id]['ParentID'] ?? 0);
}
function IPS_GetName(int $id): string
{
    return $GLOBALS['whub_test_objects'][$id]['ObjectName'] ?? '';
}
function IPS_GetInstanceListByModuleID(string $guid): array
{
    if (isset($GLOBALS['whub_test_instancesByModule'][$guid])) {
        return $GLOBALS['whub_test_instancesByModule'][$guid];
    }
    if ($guid === LOCATION_CONTROL_GUID) {
        return $GLOBALS['whub_test_locationInstances'] ?? [];
    }
    return $GLOBALS['whub_test_instancesByOtherModule'][$guid] ?? [];
}
function IPS_VariableExists(int $id): bool
{
    return isset($GLOBALS['whub_test_objects'][$id]) && $GLOBALS['whub_test_objects'][$id]['ObjectType'] === 2;
}
function IPS_GetModuleList(): array
{
    return array_keys($GLOBALS['whub_test_moduleNames']);
}
function IPS_GetModule(string $guid): array
{
    return ['ModuleName' => $GLOBALS['whub_test_moduleNames'][$guid] ?? 'Irgendwas'];
}
function IPS_InstanceExists(int $id): bool
{
    return isset($GLOBALS['whub_test_objects'][$id]) && $GLOBALS['whub_test_objects'][$id]['ObjectType'] === 1;
}
function IPS_GetKernelVersion(): float
{
    return $GLOBALS['whub_test_kernelVersion'];
}
function IPS_GetProperty(int $id, string $name)
{
    return $GLOBALS['whub_test_properties'][$id][$name] ?? '';
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_values'][$id] ?? 0;
}
function IPS_LogMessage(string $sender, string $message): void
{
}
$GLOBALS['whub_test_pushCalls'] = [];
function WFC_PushNotification(int $id, string $title, string $text, string $sound, int $senderId): bool
{
    $GLOBALS['whub_test_pushCalls'][] = ['webfront', $id, $title, $text, $sound];
    return true;
}
function VISU_PostNotificationEx(int $id, string $title, string $text, string $icon, string $sound, int $targetId): bool
{
    $GLOBALS['whub_test_pushCalls'][] = ['kachel', $id, $title, $text, $sound];
    return true;
}

class IPSModule
{
    public int $InstanceID = 999999;
    protected array $props = [];
    protected array $attrs = [];
    public function RegisterPropertyString($n, $v)
    {
        $this->props[$n] ??= $v;
    }
    public function RegisterPropertyBoolean($n, $v)
    {
        $this->props[$n] ??= $v;
    }
    public function RegisterPropertyInteger($n, $v)
    {
        $this->props[$n] ??= $v;
    }
    public function RegisterPropertyFloat($n, $v)
    {
        $this->props[$n] ??= $v;
    }
    public function ReadPropertyFloat($n)
    {
        return (float) ($this->props[$n] ?? 0);
    }
    public function ReadPropertyString($n)
    {
        return (string) ($this->props[$n] ?? '');
    }
    public function ReadPropertyBoolean($n)
    {
        return (bool) ($this->props[$n] ?? false);
    }
    public function ReadPropertyInteger($n)
    {
        return (int) ($this->props[$n] ?? 0);
    }
    public function SetProp($n, $v)
    {
        $this->props[$n] = $v;
    }
    public function RegisterAttributeString($n, $v)
    {
        $this->attrs[$n] ??= $v;
    }
    public function RegisterAttributeBoolean($n, $v)
    {
        $this->attrs[$n] ??= $v;
    }
    public function RegisterAttributeInteger($n, $v)
    {
        $this->attrs[$n] ??= $v;
    }
    public function ReadAttributeString($n)
    {
        return (string) ($this->attrs[$n] ?? '');
    }
    public function ReadAttributeInteger($n)
    {
        return (int) ($this->attrs[$n] ?? 0);
    }
    public function ReadAttributeBoolean($n)
    {
        return (bool) ($this->attrs[$n] ?? false);
    }
    public function WriteAttributeString($n, $v)
    {
        $this->attrs[$n] = $v;
    }
    public function WriteAttributeInteger($n, $v)
    {
        $this->attrs[$n] = $v;
    }
    public function WriteAttributeBoolean($n, $v)
    {
        $this->attrs[$n] = $v;
    }
    public function RegisterTimer($n, $ms, $c)
    {
    }
    public function SetTimerInterval($n, $ms)
    {
    }
    public function SetStatus($c)
    {
    }
    public function SendDebug($s, $m, $f)
    {
    }
    public function LogMessage($Message, $Type)
    {
    }
    public function UpdateFormField($n, $k, $v)
    {
        $GLOBALS['whub_test_formFieldSets'][] = [$n, $k, $v];
    }
    public function Create()
    {
    }
    public function MaintainVariable($ident, $name, $type, $profile, $position, $keep = true)
    {
    }
    public function SetValue($ident, $value)
    {
    }
}

const VARIABLETYPE_STRING = 3;
const VARIABLETYPE_INTEGER = 1;
const KL_MESSAGE = 10201;
const KL_SUCCESS = 10202;
const KL_NOTIFY = 10203;
const KL_WARNING = 10204;
const KL_ERROR = 10205;
const KL_DEBUG = 10206;
const KL_CUSTOM = 10207;
function IPS_VariableProfileExists(string $name): bool
{
    return true;
}

require __DIR__ . '/../WarnHub/module.php';

function callPrivate(object $obj, string $method, array $args = [])
{
    $ref = new ReflectionMethod($obj, $method);
    return $ref->invokeArgs($obj, $args);
}

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? '  ok  - ' : 'FEHLT - ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

// Zusätzliche Objekte für die Statuszeilen (Instanzen 50/60-62, Variablen 501, 701-704)
$GLOBALS['whub_test_objects'] += [
    50 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation nur Wind', 'ObjectIdent' => ''],
    501 => ['ObjectType' => 2, 'ObjectName' => 'Windböe', 'ObjectIdent' => 'windgustmph'],
    60 => ['ObjectType' => 1, 'ObjectName' => 'WebFront Handy', 'ObjectIdent' => ''],
    61 => ['ObjectType' => 1, 'ObjectName' => 'Telegram Bot', 'ObjectIdent' => ''],
    62 => ['ObjectType' => 1, 'ObjectName' => 'Mail Familie', 'ObjectIdent' => ''],
    70 => ['ObjectType' => 1, 'ObjectName' => 'Tessie Model 3', 'ObjectIdent' => ''],
    701 => ['ObjectType' => 2, 'ObjectName' => 'Breitengrad', 'ObjectIdent' => '', 'ParentID' => 70],
    702 => ['ObjectType' => 2, 'ObjectName' => 'Längengrad', 'ObjectIdent' => '', 'ParentID' => 70],
];
$GLOBALS['whub_test_tree'][50] = [501];
$GLOBALS['whub_test_variableProfiles'][501] = '~WindSpeed.kmh';
$GLOBALS['whub_test_values'][101] = 47.5;
$GLOBALS['whub_test_values'][102] = 1.2;
$GLOBALS['whub_test_values'][501] = 33.0;
$GLOBALS['whub_test_values'][701] = 48.1234;
$GLOBALS['whub_test_values'][702] = 8.5678;

function neuerHub(array $props = [], array $attrs = []): WarnHub
{
    $hub = new WarnHub();
    $hub->Create();
    foreach ($props as $k => $v) {
        $hub->SetProp($k, $v);
    }
    foreach ($attrs as $k => $v) {
        $hub->WriteAttributeString($k, $v);
    }
    return $hub;
}
function beginntMit(string $text, string $prefix): bool
{
    return str_starts_with($text, $prefix);
}
function findeName(array $elements, string $name): ?array
{
    foreach ($elements as $el) {
        if (($el['name'] ?? null) === $name) {
            return $el;
        }
        foreach (['items', 'columns'] as $k) {
            if (isset($el[$k]) && is_array($el[$k])) {
                $f = findeName($el[$k], $name);
                if ($f !== null) {
                    return $f;
                }
            }
        }
    }
    return null;
}

echo "== Push-Ziele (webfrontStatusLine) ==\n";
$zeile = fn (array $rows) => callPrivate(neuerHub(['WebFronts' => json_encode($rows)]), 'webfrontStatusLine');
check('keine Ziele -> ℹ️', beginntMit($zeile([]), 'ℹ️'));
$z = $zeile([['InstanceID' => 60, 'Name' => 'WebFront Handy', 'Typ' => 'webfront', 'Aktiv' => false]]);
check('Ziele gefunden, keines aktiv -> ⚠️', beginntMit($z, '⚠️') && str_contains($z, 'keines aktiviert'));
$z = $zeile([['InstanceID' => 60, 'Name' => 'WebFront Handy', 'Typ' => 'webfront', 'Aktiv' => true], ['InstanceID' => 61, 'Name' => 'Telegram Bot', 'Typ' => 'telegram', 'Aktiv' => true]]);
check('alles aktiv und vorhanden -> ✅ mit Namen und Typ', beginntMit($z, '✅ 2 von 2') && str_contains($z, 'WebFront Handy (WebFront)') && str_contains($z, 'Telegram Bot (Telegram)'));
$z = $zeile([['InstanceID' => 60, 'Name' => 'WebFront Handy', 'Typ' => 'webfront', 'Aktiv' => true], ['InstanceID' => 62, 'Name' => 'Mail Familie', 'Typ' => 'email', 'Aktiv' => true, 'Zieladresse' => '']]);
check('aktives E-Mail-Ziel ohne Zieladresse -> ⛔ (auch wenn ein anderes Ziel läuft)', beginntMit($z, '⛔') && str_contains($z, 'Mail Familie') && str_contains($z, 'WebFront Handy'));
$z = $zeile([['InstanceID' => 62, 'Name' => 'Mail Familie', 'Typ' => 'email', 'Aktiv' => true, 'Zieladresse' => 'a@b.de, c@d.de']]);
check('E-Mail mit zwei Adressen -> ✅ und "2 Adressen"', beginntMit($z, '✅') && str_contains($z, '2 Adressen'));
$z = $zeile([['InstanceID' => 999, 'Name' => 'Gelöschtes Ziel', 'Typ' => 'webfront', 'Aktiv' => true], ['InstanceID' => 60, 'Name' => 'WebFront Handy', 'Typ' => 'webfront', 'Aktiv' => true]]);
check('aktives Ziel mit gelöschter Instanz -> ⚠️ mit Namen und Rest-Liste', beginntMit($z, '⚠️') && str_contains($z, 'Gelöschtes Ziel') && str_contains($z, 'existiert nicht mehr') && str_contains($z, 'WebFront Handy'));
$z = $zeile([['InstanceID' => 999, 'Name' => 'Gelöschtes Ziel', 'Typ' => 'webfront', 'Aktiv' => true]]);
check('nur verwaiste Ziele -> ⚠️ "nirgends eine Benachrichtigung"', beginntMit($z, '⚠️') && str_contains($z, 'nirgends'));
$hub = neuerHub(['WebFronts' => '[]']);
$z = callPrivate($hub, 'webfrontStatusLine', [[['InstanceID' => 60, 'Name' => 'WebFront Handy', 'Typ' => 'webfront', 'Aktiv' => true]]]);
check('mit übergebenen $rows (direkt nach der Suche) -> Hinweis "noch nicht gespeichert"', str_contains($z, 'noch nicht gespeichert') && beginntMit($z, '✅'));
check('ohne $rows kein Speicher-Hinweis', !str_contains($zeile([['InstanceID' => 60, 'Name' => 'X', 'Typ' => 'webfront', 'Aktiv' => true]]), 'noch nicht gespeichert'));

echo "\n== Systemstandort (systemLocationStatusLine) ==\n";
$GLOBALS['whub_test_locationInstances'] = [];
$z = callPrivate(neuerHub(), 'systemLocationStatusLine');
check('kein Systemstandort, nichts nötig -> ℹ️', beginntMit($z, 'ℹ️'));
$z = callPrivate(neuerHub(['WetterstationInstanceID' => 10]), 'systemLocationStatusLine');
check('kein Systemstandort, eigene Wetterstation konfiguriert -> ⛔ nennt die Wetterstation', beginntMit($z, '⛔') && str_contains($z, 'Wetterstation'));
$z = callPrivate(neuerHub(['WetterstationWindVariableID' => 501]), 'systemLocationStatusLine');
check('kein Systemstandort, nur manuelle Wind-Variable -> ebenfalls ⛔ (wie Poll())', beginntMit($z, '⛔'));
$z = callPrivate(neuerHub(['HagelschutzPollUrl' => 'https://example.invalid/x']), 'systemLocationStatusLine');
check('kein Systemstandort, Hagelschutz konfiguriert -> ⛔ nennt Hagelschutz', beginntMit($z, '⛔') && str_contains($z, 'Hagelschutz'));
$GLOBALS['whub_test_locationInstances'] = [LOCATION_INSTANCE_ID];
$GLOBALS['whub_test_properties'][LOCATION_INSTANCE_ID]['Location'] = json_encode(['latitude' => 48.4785, 'longitude' => 7.9448]);
$z = callPrivate(neuerHub([], ['HeimLandCode' => 'de']), 'systemLocationStatusLine');
check('Systemstandort da -> ✅ mit Instanz-ID, Koordinaten (Komma) und Land', beginntMit($z, '✅') && str_contains($z, '#' . LOCATION_INSTANCE_ID) && str_contains($z, '48,47850') && str_contains($z, '7,94480') && str_contains($z, 'Deutschland'));
$z = callPrivate(neuerHub([], ['HeimLandCode' => 'at']), 'systemLocationStatusLine');
check('Land at -> "Österreich"', str_contains($z, 'Österreich'));
$z = callPrivate(neuerHub([], ['HeimLandCode' => 'fr']), 'systemLocationStatusLine');
check('Land fr -> Code in Großbuchstaben statt Klartext', str_contains($z, 'FR') && !str_contains($z, 'Deutschland'));
$z = callPrivate(neuerHub(), 'systemLocationStatusLine');
check('Land noch unbekannt -> ehrlicher Hinweis statt leerer Angabe', beginntMit($z, '✅') && str_contains($z, 'wird beim nächsten Abgleich ermittelt'));
$z = callPrivate(neuerHub(['WetterstationInstanceID' => 10]), 'systemLocationStatusLine');
check('Wetterstation konfiguriert -> "Platzierung" wird als Verwendung genannt', str_contains($z, 'Platzierung') && str_contains($z, 'Wetterstation'));

echo "\n== Eigene Wetterstation (wetterstationStatusLine) ==\n";
$ws = fn (int $i, int $w, int $r) => callPrivate(neuerHub(), 'wetterstationStatusLine', [$i, $w, $r]);
check('nichts eingetragen -> ℹ️', beginntMit($ws(0, 0, 0), 'ℹ️'));
$z = $ws(10, 0, 0);
check('Instanz mit Wind+Regen -> ✅ mit Variablenname, ID, Wert und "🔗 automatisch"', beginntMit($z, '✅') && str_contains($z, '47,5 km/h') && str_contains($z, '1,2 mm/h') && str_contains($z, '#101') && str_contains($z, '#102') && str_contains($z, '🔗 automatisch aus Instanz „Wetterstation“ #10'));
$z = $ws(0, 501, 102);
check('nur manuelle Variablen -> ✅ mit "✏️ von Hand gewählt"', beginntMit($z, '✅') && substr_count($z, '✏️ von Hand gewählt') === 2 && !str_contains($z, '🔗'));
$z = $ws(10, 501, 0);
check('Wind von Hand, Regen aus Instanz -> beide Herkünfte getrennt benannt', beginntMit($z, '✅') && str_contains($z, '✏️ von Hand gewählt') && str_contains($z, '🔗 automatisch'));
$z = $ws(50, 0, 0);
check('Instanz nur mit Wind -> ⚠️ teilweise, benennt die fehlende Größe', beginntMit($z, '⚠️') && str_contains($z, 'teilweise') && str_contains($z, 'Regenrate: keine Variable gefunden'));
$z = $ws(11, 0, 0);
check('Instanz ohne passende Variable -> ⚠️ weder Windböe noch Regenrate', beginntMit($z, '⚠️') && str_contains($z, 'weder Windböe noch Regenrate'));
$z = $ws(999, 0, 0);
check('gelöschte Instanz -> ⚠️ mit Hinweis "existiert nicht mehr"', beginntMit($z, '⚠️') && str_contains($z, 'existiert nicht mehr'));
$z = $ws(999, 501, 0);
check('gelöschte Instanz, aber manueller Wind -> ⚠️ teilweise UND Instanz-Hinweis', beginntMit($z, '⚠️') && str_contains($z, 'teilweise') && str_contains($z, 'existiert nicht mehr'));
$GLOBALS['whub_test_values'][201] = 10.0;
$z = $ws(20, 0, 0);
check('m/s-Profil wird auf km/h umgerechnet angezeigt (10 m/s = 36 km/h)', str_contains($z, '36,0 km/h'));
$z = $ws(40, 0, 0);
check('Piezo-Instanz (rrain_piezo) wird als Regenrate erkannt', beginntMit($z, '✅') && str_contains($z, '#402'));

echo "\n== onChange der Wetterstations-Felder ==\n";
$GLOBALS['whub_test_formFieldSets'] = [];
$hub = neuerHub(['WetterstationInstanceID' => 0]);
$hub->OnChangeWetterstation(10, 0, 0);
$set = $GLOBALS['whub_test_formFieldSets'][0] ?? [];
check('OnChangeWetterstation setzt WetterstationStatusLabel/caption', ($set[0] ?? '') === 'WetterstationStatusLabel' && ($set[1] ?? '') === 'caption');
check('... und zeigt die AUSWAHL (Instanz 10), nicht den Speicherstand (0 = "nicht eingerichtet")', beginntMit((string) ($set[2] ?? ''), '✅'));
$GLOBALS['whub_test_formFieldSets'] = [];
$hub->OnChangeWetterstation(0, 0, 0);
check('Auswahl geleert -> ℹ️ nachgeführt', beginntMit((string) ($GLOBALS['whub_test_formFieldSets'][0][2] ?? ''), 'ℹ️'));

echo "\n== Mobile Standorte (mobileStandorteStatusLine) ==\n";
$mob = fn (array $rows) => callPrivate(neuerHub(['Standorte' => json_encode($rows)]), 'mobileStandorteStatusLine');
check('keine Standorte -> ℹ️', beginntMit($mob([]), 'ℹ️'));
check('nur feste Standorte -> ℹ️', beginntMit($mob([['Name' => 'Haus', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => true]]), 'ℹ️'));
$z = $mob([['Name' => 'Auto', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => true, 'QuellVarLat' => 701, 'QuellVarLon' => 702]]);
check('Live-Variablen vorhanden -> ✅ mit Live-Koordinaten, Quelle und Variablen-IDs', beginntMit($z, '✅') && str_contains($z, '48,1234 / 8,5678') && str_contains($z, 'Tessie Model 3') && str_contains($z, '#701/#702') && str_contains($z, '🔗 automatisch'));
check('... die festen Koordinaten (48,0000) stehen NICHT als Live-Wert da', !str_contains($z, '48,0000'));
$z = $mob([['Name' => 'Auto', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => false, 'QuellVarLat' => 701, 'QuellVarLon' => 702]]);
check('inaktiver mobiler Standort wird als [inaktiv] markiert', str_contains($z, '[inaktiv]'));
$z = $mob([['Name' => 'Auto', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => true, 'QuellVarLat' => 9701, 'QuellVarLon' => 9702]]);
check('Live-Variablen gelöscht -> ⚠️ "fehlt oder existiert nicht mehr" + Rückfall auf feste Koordinaten genannt', beginntMit($z, '⚠️') && str_contains($z, 'fehlt oder existiert nicht mehr') && str_contains($z, '48,0000 / 8,0000'));
$z = $mob([['Name' => 'Auto', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => true, 'QuellVarLat' => 701, 'QuellVarLon' => 0]]);
check('nur eine Achse gesetzt -> ⚠️ "nur für eine Achse gesetzt"', beginntMit($z, '⚠️') && str_contains($z, 'nur für eine Achse'));
$z = $mob([
    ['Name' => 'Auto', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => true, 'QuellVarLat' => 701, 'QuellVarLon' => 702],
    ['Name' => 'Roller', 'Lat' => 47.0, 'Lon' => 7.0, 'Aktiv' => true, 'QuellVarLat' => 9701, 'QuellVarLon' => 9702],
    ['Name' => 'Haus', 'Lat' => 46.0, 'Lon' => 6.0, 'Aktiv' => true],
]);
check('gemischt: ein Standort kaputt -> Gesamtzeile ⚠️, beide mobilen genannt, fester Standort nicht', beginntMit($z, '⚠️') && str_contains($z, 'Auto') && str_contains($z, 'Roller') && !str_contains($z, 'Haus'));
$z = callPrivate(neuerHub(['Standorte' => '[]']), 'mobileStandorteStatusLine', [[['Name' => 'Auto', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => true, 'QuellVarLat' => 701, 'QuellVarLon' => 702]]]);
check('mit übergebener Tabelle (direkt nach der Suche) -> Hinweis "noch nicht gespeichert"', str_contains($z, 'noch nicht gespeichert'));

echo "\n== Zeilen im ausgelieferten Formular ==\n";
$GLOBALS['whub_test_locationInstances'] = [LOCATION_INSTANCE_ID];
$hub = neuerHub([
    'WetterstationInstanceID' => 10,
    'WebFronts' => json_encode([['InstanceID' => 60, 'Name' => 'WebFront Handy', 'Typ' => 'webfront', 'Aktiv' => true]]),
    'Standorte' => json_encode([['Name' => 'Auto', 'Lat' => 48.0, 'Lon' => 8.0, 'Aktiv' => true, 'QuellVarLat' => 701, 'QuellVarLon' => 702]]),
], ['HeimLandCode' => 'de']);
$form = json_decode($hub->GetConfigurationForm(), true);
check('GetConfigurationForm() liefert gültiges JSON', is_array($form));
foreach (['SystemLocationStatusLabel', 'MobileStandorteStatusLabel', 'WetterstationStatusLabel', 'WebFrontStatusLabel'] as $name) {
    $el = findeName($form['elements'] ?? [], $name);
    check($name . ' steht als Label im Formular', ($el['type'] ?? null) === 'Label');
    check($name . ' trägt eine Statuszeile mit ✅/⚠️/ℹ️/⛔ als Caption', preg_match('/^(✅|⚠️|ℹ️|⛔)/u', (string) ($el['caption'] ?? '')) === 1);
}
check('Formular zeigt die Wetterstation live (✅ mit Wert)', str_contains((string) findeName($form['elements'], 'WetterstationStatusLabel')['caption'], '47,5 km/h'));
check('Formular zeigt den Live-Standort (✅ mit Koordinaten)', str_contains((string) findeName($form['elements'], 'MobileStandorteStatusLabel')['caption'], '48,1234'));
check('Formular zeigt den Systemstandort (✅ mit Land)', str_contains((string) findeName($form['elements'], 'SystemLocationStatusLabel')['caption'], 'Deutschland'));
foreach (['WetterstationInstanceID', 'WetterstationWindVariableID', 'WetterstationRegenVariableID'] as $name) {
    $el = findeName($form['elements'], $name);
    check($name . ' hat onChange auf WHUB_OnChangeWetterstation mit allen drei Feldwerten', str_contains((string) ($el['onChange'] ?? ''), 'WHUB_OnChangeWetterstation($id, $WetterstationInstanceID, $WetterstationWindVariableID, $WetterstationRegenVariableID)'));
}
check('onChange-Zielmethode OnChangeWetterstation existiert öffentlich (WHUB_-Funktion)', (new ReflectionMethod('WarnHub', 'OnChangeWetterstation'))->isPublic());

echo "\n== Regel 2: kein UpdateFormField('value') auf Eingabefeldern durch die Statuszeilen ==\n";
$GLOBALS['whub_test_formFieldSets'] = [];
$hub = neuerHub(['WetterstationInstanceID' => 10, 'Standorte' => '[]', 'WebFronts' => '[]']);
$hub->OnChangeWetterstation(10, 0, 0);
callPrivate($hub, 'refreshWetterstationStatus');
$nurCaption = true;
foreach ($GLOBALS['whub_test_formFieldSets'] as $s) {
    if ($s[1] !== 'caption') {
        $nurCaption = false;
    }
}
check('OnChange/refresh schreiben ausschließlich "caption" von Labels, nie "value" eines Eingabefelds', $nurCaption && count($GLOBALS['whub_test_formFieldSets']) === 2);

echo "\n" . ($failures === 0 ? "Alle $checks Prüfungen bestanden." : "$failures von $checks Prüfungen FEHLGESCHLAGEN.") . "\n";
exit($failures === 0 ? 0 : 1);
