<?php

/**
 * Prüfstand für die Alertswiss-Anbindung (alert.swiss/BABS) -- Schweizer
 * Pendant zu NINA, Anfrage baslerleckerli, Symcon-Forum, 22.09.2026. Die
 * drei Fixtures unten ("feuer"/"strasse"/"test") sind wörtlich der echte
 * Live-Feed vom 22.09.2026 (Polygon bei "feuer" auf 5 Punkte gekürzt, alle
 * übrigen Felder unverändert) -- keine erfundenen Werte. Kein Netzzugriff
 * nötig für diesen Test (siehe .tools/test-live-fetch.php für den echten
 * Live-Abruf).
 *
 *   php .tools/test-alertswiss-ch.php    # 0 = alle Prüfungen bestanden
 */

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return [];
}
function IPS_GetModuleList(): array
{
    return [];
}
function IPS_LogMessage(string $sender, string $message): void
{
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

$hub = new WarnHub();
$hub->Create();

// Wörtlicher Live-Feed vom 22.09.2026 (polygons bei "feuer" auf 5 Punkte
// gekürzt), abgerufen von self::ALERTSWISS_URL.
$feuer = [
    'identifier' => 'POA-1347592675-10',
    'title' => ['title' => 'Absolutes Feuerverbot'],
    'description' => ['description' => "Das betroffene Gebiet wurde angepasst.\n\nDer Ausbruch von Bränden ist jederzeit möglich."],
    'nationWide' => false,
    'instructions' => [
        ['text' => 'Das Feuern ausserhalb des Siedlungsraums ist absolut verboten!'],
        ['text' => 'Auch fest eingerichtete Feuerstellen dürfen nicht benutzt werden!'],
    ],
    'publisherName' => 'Kanton Graubünden - Chantun Grischun',
    'event' => 'Feuerverbot',
    'allClear' => false,
    'severity' => 'moderate',
    'testAlert' => false,
    'technicalTestAlert' => false,
    'areas' => [[
        'description' => ['description' => 'Churer Rheintal/ Prättigau/ Surselva/ Heinzenberg/ Domleschg'],
        'polygons' => [['coordinates' => [
            ['46.57502', '9.23277'], ['46.57689', '9.24041'], ['46.57506', '9.24291'],
            ['46.57877', '9.26868'], ['46.58290', '9.27976'],
        ]]],
        'circles' => [],
    ]],
    'reference' => 'info@alertswiss.ch,POA-1347592675-10,2026-09-14T14:53:10+02:00',
];
$strasse = [
    'identifier' => 'POA-1368907708-1',
    'title' => ['title' => 'Bristenstrasse: Verkehrsregime ab 14. September 2026'],
    'description' => ['description' => 'Regime C gültig ...'],
    'nationWide' => false,
    'instructions' => [],
    'publisherName' => 'Kanton Uri',
    'event' => 'Anderes Ereignis',
    'allClear' => false,
    'severity' => 'minor',
    'testAlert' => false,
    'technicalTestAlert' => false,
    'areas' => [[
        'description' => ['description' => 'Bristenstrasse'],
        'polygons' => [],
        'circles' => [['centerPosition' => ['46.76970', '8.67591'], 'radius' => '0.266602233']],
    ]],
    'reference' => 'info@alertswiss.ch,POA-1368907708-1,2026-09-11T20:27:37+02:00',
];
$test = [
    'identifier' => 'TEST-333',
    'title' => ['title' => '*** Test Message *** This is a test of the Alertswiss system'],
    'description' => ['description' => ''],
    'nationWide' => false,
    'instructions' => [['text' => 'No measures necessary']],
    'event' => 'Cap Test Event',
    'allClear' => false,
    'severity' => 'unknown',
    'testAlert' => false,
    'technicalTestAlert' => true,
    'areas' => [],
    'reference' => 'info@alertswiss.ch,TEST-333,2026-09-22T09:23:01+02:00',
];

echo "== parseAlertSwissJson(): echter Live-Feed vom 22.09.2026 ==\n";
$out = callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [$feuer, $strasse, $test]]]);
check('Testalarm (technicalTestAlert) wird verworfen -- nur 2 von 3 Einträgen bleiben', count($out) === 2);
$byId = [];
foreach ($out as $w) {
    $byId[$w['identifier']] = $w;
}
check('Feuerverbot-Identifier mit Präfix "alertswiss-"', isset($byId['alertswiss-POA-1347592675-10']));
$f = $byId['alertswiss-POA-1347592675-10'] ?? [];
check('source = alertswiss_ch', ($f['source'] ?? null) === 'alertswiss_ch');
check('msgType = Alert (allClear=false)', ($f['msgType'] ?? null) === 'Alert');
check('headline = Titel ("Absolutes Feuerverbot")', ($f['headline'] ?? null) === 'Absolutes Feuerverbot');
check('event = "Feuerverbot" (unübersetzt, Quelle liefert schon Deutsch)', ($f['event'] ?? null) === 'Feuerverbot');
check('severity moderate -> "Moderate" (Groß-/Kleinschreibung an SEVERITY_RANK angepasst)', ($f['severity'] ?? null) === 'Moderate');
check('Publisher-Name im Beschreibungstext ("Quelle: Kanton Graubünden ...")', str_contains((string) ($f['description'] ?? ''), 'Kanton Graubünden'));
check('Handlungsempfehlungen mit Zeilenumbruch verbunden', str_contains((string) ($f['instruction'] ?? ''), "verboten!\nAuch"));
check('areaDesc aus der Gebietsbeschreibung übernommen', ($f['areaDesc'] ?? null) === 'Churer Rheintal/ Prättigau/ Surselva/ Heinzenberg/ Domleschg');
check('5 Polygonpunkte übernommen, LAT zuerst (46.x vor 9.x)', count($f['rings'][0] ?? []) === 5 && ($f['rings'][0][0][0] ?? null) === 46.57502 && ($f['rings'][0][0][1] ?? null) === 9.23277);
check('keine Kreise bei der Polygon-Meldung', count($f['circles'] ?? ['x']) === 0);
check('effective/onset aus dem "reference"-Feld (ISO 2026-09-14T14:53:10+02:00)', strtotime((string) ($f['effective'] ?? '')) === strtotime('2026-09-14T14:53:10+02:00'));
check('effective und onset sind identisch (Alertswiss liefert nur einen Zeitpunkt)', ($f['effective'] ?? 'a') === ($f['onset'] ?? 'b'));
check('kein "expires" -- Alertswiss liefert kein Gültig-bis-Datum (wie BAFU/SED)', array_key_exists('expires', $f) && $f['expires'] === null);

$s = $byId['alertswiss-POA-1368907708-1'] ?? [];
check('Kreis-Meldung: severity minor -> "Minor"', ($s['severity'] ?? null) === 'Minor');
check('Kreis-Koordinaten übernommen (Lat 46.7697, Lon 8.67591)', abs(($s['circles'][0]['lat'] ?? 0) - 46.76970) < 0.0001 && abs(($s['circles'][0]['lon'] ?? 0) - 8.67591) < 0.0001);
check('Kreis-Radius in km übernommen (0.2666...)', abs(($s['circles'][0]['radiusKm'] ?? 0) - 0.266602233) < 0.0000001);
check('leere Handlungsempfehlungen -> leerer String, kein Fehler', ($s['instruction'] ?? 'x') === '');

echo "\n== Randfälle ==\n";
check('fehlender identifier -> Eintrag wird übersprungen', callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [['event' => 'Ohne ID']]]]) === []);
check('kein "alerts"-Schlüssel -> leeres Ergebnis, kein Fehler', callPrivate($hub, 'parseAlertSwissJson', [['heartbeatAgeInMillis' => 123]]) === []);
$allClear = ['identifier' => 'X-1', 'event' => 'Feuerverbot', 'allClear' => true, 'areas' => [], 'reference' => ''];
$outClear = callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [$allClear]]]);
check('allClear=true -> msgType "Cancel"', ($outClear[0]['msgType'] ?? null) === 'Cancel');
$unbekannt = ['identifier' => 'X-2', 'event' => 'Sonstiges', 'severity' => 'katastrophal', 'areas' => [], 'reference' => ''];
$outUnbekannt = callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [$unbekannt]]]);
check('unbekannter severity-Text -> "Unknown" statt Rateversuch', ($outUnbekannt[0]['severity'] ?? null) === 'Unknown');
$ohneHeadline = ['identifier' => 'X-3', 'event' => 'Sturm', 'areas' => [], 'reference' => ''];
$outOhneTitel = callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [$ohneHeadline]]]);
check('kein Titel -> event als Ersatz-Überschrift ("Sturm")', ($outOhneTitel[0]['headline'] ?? null) === 'Sturm');
$landesweit = ['identifier' => 'X-4', 'event' => 'Alarm', 'nationWide' => true, 'areas' => [], 'reference' => ''];
$outLandesweit = callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [$landesweit]]]);
check('landesweit ohne eigene Geometrie -> Näherungs-Kreis über die Schweiz statt Verwerfen', count($outLandesweit[0]['circles'] ?? []) === 1 && ($outLandesweit[0]['circles'][0]['radiusKm'] ?? 0) === 150.0);
$ohneGeoNichtLandesweit = ['identifier' => 'X-5', 'event' => 'Alarm', 'nationWide' => false, 'areas' => [], 'reference' => ''];
$outOhneGeo = callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [$ohneGeoNichtLandesweit]]]);
check('keine Geometrie und NICHT landesweit -> kein Näherungs-Kreis (bleibt ungenau statt vorgetäuscht)', count($outOhneGeo[0]['circles'] ?? ['x']) === 0 && count($outOhneGeo[0]['rings'] ?? ['x']) === 0);
$zuWenigPunkte = ['identifier' => 'X-6', 'event' => 'Alarm', 'areas' => [['description' => ['description' => 'X'], 'polygons' => [['coordinates' => [['46.0', '8.0'], ['46.1', '8.1']]]], 'circles' => []]], 'reference' => ''];
$outZuWenig = callPrivate($hub, 'parseAlertSwissJson', [['alerts' => [$zuWenigPunkte]]]);
check('Polygon mit nur 2 Punkten (kein gültiges Polygon) wird verworfen', count($outZuWenig[0]['rings'] ?? ['x']) === 0);

echo "\n== parseAlertSwissReferenceTimestamp() ==\n";
$ts = fn (string $ref) => callPrivate($hub, 'parseAlertSwissReferenceTimestamp', [$ref]);
check('gültige Referenz -> ISO-Zeitstempel', strtotime((string) $ts('info@alertswiss.ch,TEST-333,2026-09-22T09:23:01+02:00')) === strtotime('2026-09-22T09:23:01+02:00'));
check('Identifier mit Komma darin -- letztes Feld bleibt maßgeblich (Regex verankert am Ende)', strtotime((string) $ts('info@alertswiss.ch,ABC,123,2026-09-22T09:23:01+02:00')) === strtotime('2026-09-22T09:23:01+02:00'));
check('leerer String -> null', $ts('') === null);
check('kein ISO-Zeitstempel am Ende -> null', $ts('info@alertswiss.ch,TEST-333,irgendwas') === null);

echo "\n" . ($failures === 0 ? "Alle $checks Prüfungen bestanden." : "$failures von $checks Prüfungen FEHLGESCHLAGEN.") . "\n";
exit($failures === 0 ? 0 : 1);
