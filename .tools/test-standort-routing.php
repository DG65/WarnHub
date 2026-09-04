<?php

/**
 * Prüft die beiden am 04.09.2026 ergänzten Standort-Funktionen, komplett
 * ohne echtes IPS-System:
 *   1. Live-Standort aus zwei Variablen (QuellVarLat/QuellVarLon) statt
 *      fester Lat/Lon-Spalten -- Grundlage für einen mobilen Standort aus
 *      Tessie- oder einer Geofency-Bridge-Variable.
 *   2. "Push nur an ..."-Filter je Standort, damit bei mehreren Personen/
 *      Fahrzeugen (Dietmars konkreter Fall: 2 Teslas + 2 Geofency-Instanzen
 *      + 2 WebFronts) nur das jeweils zugehörige WebFront benachrichtigt
 *      wird statt beider gleichzeitig.
 *
 *   php .tools/test-standort-routing.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_variableValues'] = [];
$GLOBALS['whub_test_pushCalls'] = [];

function IPS_VariableExists(int $id): bool
{
    return array_key_exists($id, $GLOBALS['whub_test_variableValues']);
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_variableValues'][$id] ?? 0;
}
function IPS_LogMessage(string $sender, string $message): void
{
}
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
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return [];
}
function IPS_GetModuleList(): array
{
    return [];
}
$GLOBALS['whub_test_requestActionCalls'] = [];
function RequestAction(int $variableID, $value): bool
{
    $GLOBALS['whub_test_requestActionCalls'][] = [$variableID, $value];
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
    public function UpdateFormField($n, $k, $v)
    {
    }
    public function Create()
    {
    }
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

echo "== resolveStandortCoords(): feste Koordinaten (QuellVarLat/Lon = 0) ==\n";
$hub = new WarnHub();
$hub->Create();
$fest = ['Lat' => 48.5, 'Lon' => 7.9, 'QuellVarLat' => 0, 'QuellVarLon' => 0];
$coords = callPrivate($hub, 'resolveStandortCoords', [$fest]);
check('liefert die festen Tabellenwerte unverändert', $coords['lat'] === 48.5 && $coords['lon'] === 7.9);

echo "\n== resolveStandortCoords(): Live-Standort aus Variablen (z. B. Tessie/Geofency) ==\n";
$GLOBALS['whub_test_variableValues'] = [501 => 52.5200, 502 => 13.4050]; // Berlin
$mobil = ['Lat' => 48.5, 'Lon' => 7.9, 'QuellVarLat' => 501, 'QuellVarLon' => 502];
$coords = callPrivate($hub, 'resolveStandortCoords', [$mobil]);
check('liest Lat aus der Variable statt der Tabellenspalte', $coords['lat'] === 52.52);
check('liest Lon aus der Variable statt der Tabellenspalte', $coords['lon'] === 13.405);

echo "\n== resolveStandortCoords(): Variable konfiguriert, existiert aber (noch) nicht -> Fallback auf feste Werte ==\n";
$fehlend = ['Lat' => 48.5, 'Lon' => 7.9, 'QuellVarLat' => 9999, 'QuellVarLon' => 9998];
$coords = callPrivate($hub, 'resolveStandortCoords', [$fehlend]);
check('fällt bei nicht existierender Lat-Variable auf die Tabellenspalte zurück', $coords['lat'] === 48.5);
check('fällt bei nicht existierender Lon-Variable auf die Tabellenspalte zurück', $coords['lon'] === 7.9);

echo "\n== parsePushZielNames(): Komma-getrennter Namensfilter ==\n";
check('leerer Filter -> leere Liste (kein Filter, an alle)', callPrivate($hub, 'parsePushZielNames', ['']) === []);
$parsed = callPrivate($hub, 'parsePushZielNames', ['iPhone Dietmar, Kachel Dietmar ,  ']);
check('trennt an Kommas und trimmt Leerzeichen', $parsed === ['iphone dietmar', 'kachel dietmar']);
check('vergleicht kleingeschrieben (Groß-/Kleinschreibung soll egal sein)', callPrivate($hub, 'parsePushZielNames', ['IPHONE DIETMAR']) === ['iphone dietmar']);

echo "\n== pushToAllWebfronts(): Standort-Filter erreicht nur das genannte Ziel ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$webfronts = [
    ['InstanceID' => 101, 'Name' => 'iPhone Dietmar', 'Typ' => 'kachel', 'Aktiv' => true],
    ['InstanceID' => 102, 'Name' => 'iPhone Partner', 'Typ' => 'kachel', 'Aktiv' => true],
];
$hub2->SetProp('WebFronts', json_encode($webfronts));
$GLOBALS['whub_test_pushCalls'] = [];
$sent = callPrivate($hub2, 'pushToAllWebfronts', ['Titel', 'Text', 'alarm', ['iphone dietmar']]);
check('meldet genau 1 zugestellte Push', $sent === 1);
check('genau 1 tatsächlicher Push-Aufruf fand statt', count($GLOBALS['whub_test_pushCalls']) === 1);
check('der Push ging an die richtige Instanz (101, nicht 102)', ($GLOBALS['whub_test_pushCalls'][0][1] ?? null) === 101);

echo "\n== pushToAllWebfronts(): ohne Filter weiterhin an alle aktivierten Ziele (bisheriges Verhalten) ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$sent = callPrivate($hub2, 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('meldet 2 zugestellte Pushes ohne Filter', $sent === 2);
check('beide Ziele wurden tatsächlich angesprochen', count($GLOBALS['whub_test_pushCalls']) === 2);

echo "\n== Ende-zu-Ende: mobiler Standort mit eigenem Push-Ziel über processWarnings() ==\n";
$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('WebFronts', json_encode($webfronts));
$hub3->SetProp('PushAktiv', true);
$GLOBALS['whub_test_variableValues'] = [601 => 51.0, 602 => 8.0]; // liegt im Warnkreis unten
$standorte = [[
    'Name' => 'Dietmar unterwegs',
    'Ort' => '',
    'Lat' => 0.0,
    'Lon' => 0.0,
    'QuellVarLat' => 601,
    'QuellVarLon' => 602,
    'RadiusKm' => 20.0,
    'MinSeverity' => 1,
    'PushZielFilter' => 'iPhone Dietmar',
    'Aktiv' => true,
]];
$hub3->SetProp('Standorte', json_encode($standorte));
$warning = [
    'identifier' => 'test-1', 'source' => 'test', 'msgType' => 'Alert', 'event' => 'Sturm',
    'headline' => 'Testwarnung', 'description' => '', 'instruction' => '', 'severity' => 'Severe',
    'effective' => null, 'onset' => null, 'expires' => null, 'areaDesc' => '',
    'rings' => [], 'circles' => [['lat' => 51.0, 'lon' => 8.0, 'radiusKm' => 5.0]],
];
$GLOBALS['whub_test_pushCalls'] = [];
$result = callPrivate($hub3, 'processWarnings', [[$warning]]);
check('Warnung wird als aktiv erkannt (Live-Standort lag im Warnkreis)', $result['activeCount'] === 1);
check('genau 1 Push wurde verschickt', count($GLOBALS['whub_test_pushCalls']) === 1);
check('der Push ging NUR an das dem Standort zugeordnete WebFront (101), nicht an 102', ($GLOBALS['whub_test_pushCalls'][0][1] ?? null) === 101);

echo "\n== isStandortMobil() ==\n";
check('fest (QuellVarLat/Lon = 0) gilt nicht als mobil', callPrivate($hub, 'isStandortMobil', [['QuellVarLat' => 0, 'QuellVarLon' => 0]]) === false);
check('mit gebundener Lat-Variable gilt als mobil', callPrivate($hub, 'isStandortMobil', [['QuellVarLat' => 501, 'QuellVarLon' => 0]]) === true);
check('mit gebundener Lon-Variable gilt ebenfalls als mobil', callPrivate($hub, 'isStandortMobil', [['QuellVarLat' => 0, 'QuellVarLon' => 502]]) === true);

echo "\n== Schutzaktionen-Sicherung: Sturm über Hamburg (mobiler Standort) darf NICHT die zuhause verbaute Jalousie auslösen ==\n";
$GLOBALS['whub_test_variableValues'] = [
    701 => 53.5511, 702 => 9.9937, // mobiler Standort "unterwegs" -- aktuell Hamburg
    201 => 0, // Ziel-Variable der Jalousie muss nur existieren, Wert irrelevant
];
$standorteMitMobil = [
    ['Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0, 'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true],
    ['Name' => 'Dietmar unterwegs', 'Ort' => '', 'Lat' => 0.0, 'Lon' => 0.0, 'QuellVarLat' => 701, 'QuellVarLon' => 702, 'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true],
];
$jalousieAktion = [
    'Name' => 'Jalousie Wohnzimmer', 'Aktiv' => true, 'Typ' => 'raffstore',
    'KatSturm' => true, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 201, 'ZielWert' => 0.0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0,
];
$hamburgSturm = [
    'identifier' => 'test-hh-sturm', 'source' => 'test', 'msgType' => 'Alert', 'event' => 'Sturm',
    'headline' => 'Sturmböen Hamburg', 'description' => '', 'instruction' => '', 'severity' => 'Severe',
    'effective' => null, 'onset' => null, 'expires' => null, 'areaDesc' => '',
    'rings' => [], 'circles' => [['lat' => 53.5511, 'lon' => 9.9937, 'radiusKm' => 5.0]],
];

$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('Standorte', json_encode($standorteMitMobil));
$hub4->SetProp('Schutzaktionen', json_encode([$jalousieAktion]));
$hub4->SetProp('PushAktiv', false);
$GLOBALS['whub_test_requestActionCalls'] = [];
$result = callPrivate($hub4, 'processWarnings', [[$hamburgSturm]]);
check('Sturm wird als aktiv erkannt (trifft den mobilen Standort in Hamburg)', $result['activeCount'] === 1);
check('Jalousie-Aktion (leerer Standort-Filter) feuert NICHT für den mobilen Standort', $result['actionsTriggered'] === 0);
check('RequestAction wurde tatsächlich NICHT aufgerufen', count($GLOBALS['whub_test_requestActionCalls']) === 0);

echo "\n== Gegenprobe: derselbe Sturm über dem FESTEN Standort 'Zuhause' löst die Jalousie weiterhin aus ==\n";
$sturmZuhause = $hamburgSturm;
$sturmZuhause['identifier'] = 'test-zuhause-sturm';
$sturmZuhause['circles'] = [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]];
$hub5 = new WarnHub();
$hub5->Create();
$hub5->SetProp('Standorte', json_encode($standorteMitMobil));
$hub5->SetProp('Schutzaktionen', json_encode([$jalousieAktion]));
$hub5->SetProp('PushAktiv', false);
$GLOBALS['whub_test_requestActionCalls'] = [];
$result = callPrivate($hub5, 'processWarnings', [[$sturmZuhause]]);
check('Sturm über dem festen Standort "Zuhause" löst die Jalousie weiterhin automatisch aus', $result['actionsTriggered'] === 1);
check('RequestAction wurde für die richtige Ziel-Variable (201) aufgerufen', ($GLOBALS['whub_test_requestActionCalls'][0][0] ?? null) === 201);

echo "\n== Gegenprobe: ausdrücklicher Standort-Filter auf den mobilen Standort hebt die Sperre gezielt auf ==\n";
$jalousieMobilGefiltert = $jalousieAktion;
$jalousieMobilGefiltert['StandortFilter'] = 'Dietmar unterwegs';
$hub6 = new WarnHub();
$hub6->Create();
$hub6->SetProp('Standorte', json_encode($standorteMitMobil));
$hub6->SetProp('Schutzaktionen', json_encode([$jalousieMobilGefiltert]));
$hub6->SetProp('PushAktiv', false);
$GLOBALS['whub_test_requestActionCalls'] = [];
$result = callPrivate($hub6, 'processWarnings', [[$hamburgSturm]]);
check('mit explizit auf den mobilen Standort gesetztem Filter feuert die Aktion trotzdem (bewusster Opt-in)', $result['actionsTriggered'] === 1);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
