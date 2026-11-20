<?php

/**
 * Prüfstand Push-Flut, Bündelung und Ruhezeit (WarnHub 1.21.0) -- Live-Fund
 * Privat 18.11.2026: STURMBÖEN 0-5 Uhr bei JEDEM Abruf (alle 10 Minuten)
 * erneut gepusht, an drei Standorte am selben Ort und je zwei Ziele; das
 * Push-Tageslimit der Anlage (250, Notification Control) war erschöpft.
 * Ursache: zwei gleichzeitige DWD-Warnungen desselben Ereignisses (jetzt und
 * später, mit Lücke) -- das gemerkte Ende kippte zwischen beiden, die spätere
 * galt jedes Mal als "neu nach Lücke". Dazu Dietmars Wunsch: nachts nur ab
 * Severe. Kein Netzzugriff.
 *
 *   php .tools/test-push-flut.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_requestActionCalls'] = [];
$GLOBALS['whub_test_variableValues'] = [];

function IPS_VariableExists(int $id): bool
{
    return array_key_exists($id, $GLOBALS['whub_test_variableValues']);
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_variableValues'][$id] ?? 0;
}
function RequestAction(int $variableID, $value): bool
{
    $GLOBALS['whub_test_requestActionCalls'][] = [$variableID, $value];
    return true;
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
function IPS_LogMessage(string $sender, string $message): void
{
}

class IPSModule
{
    public function Translate($t)
    {
        // Wie Symcon: Text über locale.json in die Systemsprache (Prüfstand: Deutsch, WHUB_SPRACHE=en für Englisch).
        static $tabelle = null;
        if ($tabelle === null) {
            $tabelle = json_decode((string) @file_get_contents(__DIR__ . '/../WarnHub/locale.json'), true)['translations'][getenv('WHUB_SPRACHE') ?: 'de'] ?? [];
        }
        return $tabelle[$t] ?? $t;
    }
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

$standort = [
    'Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true,
];
$webfronts = [['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true]];

/** Simuliert eine DWD-PVW-artige Meldung: eigene, wechselnde identifier je Reissue, aber stabiles event. */
function machReissue(string $identifier, string $onsetOffsetSec, string $expiresOffsetSec): array
{
    return [
        'identifier' => $identifier, 'source' => 'dwd_direct', 'msgType' => 'Alert', 'event' => 'STARKES GEWITTER',
        'headline' => 'Amtliche WARNUNG vor STARKEM GEWITTER', 'description' => '', 'instruction' => '', 'severity' => 'Moderate',
        'effective' => date('c', time() + (int) $onsetOffsetSec), 'onset' => date('c', time() + (int) $onsetOffsetSec),
        'expires' => date('c', time() + (int) $expiresOffsetSec),
        'areaDesc' => 'Ortenaukreis', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
    ];
}

function neuerHub(array $standorte, array $props = []): WarnHub
{
    global $webfronts;
    $hub = new WarnHub();
    $hub->Create();
    $hub->SetProp('Standorte', json_encode($standorte));
    $hub->SetProp('WebFronts', json_encode($webfronts));
    $hub->SetProp('PushAktiv', true);
    foreach ($props as $k => $v) {
        $hub->SetProp($k, $v);
    }
    return $hub;
}
function sturm(string $id, int $onset, int $expires, string $severity = 'Moderate'): array
{
    $w = machReissue($id, (string) $onset, (string) $expires);
    $w['event'] = 'STURMBÖEN';
    $w['headline'] = 'Amtliche WARNUNG vor STURMBÖEN';
    $w['severity'] = $severity;
    return $w;
}
function pushes(): int
{
    return count($GLOBALS['whub_test_pushCalls']);
}
$jetztStunde = (int) date('G');
$ruheJetzt = ['RuhezeitAktiv' => true, 'RuhezeitVon' => $jetztStunde, 'RuhezeitBis' => ($jetztStunde + 1) % 24, 'RuhezeitMinSeverity' => 3];
$ruheSpaeter = ['RuhezeitVon' => ($jetztStunde + 2) % 24, 'RuhezeitBis' => ($jetztStunde + 3) % 24];

echo "== Live-Fall: zwei gleichzeitige Warnungen (jetzt + später mit Lücke) ==\n";
$hub = neuerHub([$standort]);
$GLOBALS['whub_test_pushCalls'] = [];
for ($i = 0; $i < 6; $i++) {
    callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600), sturm('B', 7200, 14400)]]);
}
check('6 Abrufe -> genau 1 Push (bis 1.20.1: 7)', pushes() === 1);
$h = json_decode($hub->GetHistory(0), true);
check('Historie: genau 1 "warnung", kein "ende"', count($h) === 1 && $h[0]['kind'] === 'warnung');
$seen = json_decode($hub->ReadAttributeString('SeenWarnings'), true);
check('gemerktes Ende = das spätere der beiden', (reset($seen)['expiresTs'] ?? 0) >= time() + 14000);
$hub = neuerHub([$standort]);
$GLOBALS['whub_test_pushCalls'] = [];
for ($i = 0; $i < 6; $i++) {
    callPrivate($hub, 'processWarnings', [[sturm('B', 7200, 14400), sturm('A', -3600, 3600)]]);
}
check('umgekehrte Reihenfolge: ebenfalls 1 Push', pushes() === 1);

echo "\n== Gleichzeitig unterschiedliche Schweregrade: kein Hochstufen-Pingpong ==\n";
$hub = neuerHub([$standort]);
$GLOBALS['whub_test_pushCalls'] = [];
for ($i = 0; $i < 6; $i++) {
    callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600, 'Moderate'), sturm('B', -600, 7200, 'Severe')]]);
}
check('6 Abrufe Moderate+Severe -> 1 Push, als Severe', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][2], '🚨'));
check('keine "eskalation" in der Historie', count(array_filter(json_decode($hub->GetHistory(0), true), fn ($e) => $e['kind'] === 'eskalation')) === 0);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600, 'Moderate')]]);
check('Severe fällt weg: still abgestuft, kein Push', pushes() === 1);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600, 'Moderate'), sturm('C', -60, 7200, 'Severe')]]);
check('danach wieder Severe: echte Hochstufung pusht', pushes() === 2 && str_contains($GLOBALS['whub_test_pushCalls'][1][3], 'Hochgestuft'));

echo "\n== Echte Lücke pusht weiterhin ==\n";
$hub = neuerHub([$standort]);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 1800)]]);
$seen = json_decode($hub->ReadAttributeString('SeenWarnings'), true);
foreach ($seen as $k => $v) {
    $seen[$k]['expires'] = date('c', time() - 600);
    $seen[$k]['expiresTs'] = time() - 600;
}
$hub->WriteAttributeString('SeenWarnings', json_encode($seen));
callPrivate($hub, 'processWarnings', [[sturm('B', 60, 7200)]]);
check('vorige abgelaufen, neue beginnt später -> zweiter Push', pushes() === 2);

echo "\n== Bündelung: dieselbe Warnung an drei Standorten ==\n";
$s2 = ['Name' => 'Kohlekasten'] + $standort;
$s3 = ['Name' => 'Schneeflocke'] + $standort;
$hub = neuerHub([$standort, $s2, $s3]);
$GLOBALS['whub_test_pushCalls'] = [];
$r = callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
check('3 Episoden, aber 1 Push', $r['newlyPushed'] === 3 && pushes() === 1);
check('Push nennt alle drei Standorte', str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Zuhause, Kohlekasten, Schneeflocke'));
check('Historie weiter je Standort (3 Einträge)', count(json_decode($hub->GetHistory(0), true)) === 3);
$hub = neuerHub([$standort, ['Name' => 'Ferienhaus', 'PushZielFilter' => 'Andere'] + $standort]);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
check('verschiedene "Push nur an"-Filter werden nicht gebündelt', pushes() === 1 && !str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Ferienhaus'));
$hub = neuerHub([$standort, $s2]);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
$GLOBALS['whub_test_pushCalls'] = [];
$c = sturm('A', -3600, 3600);
$c['msgType'] = 'Cancel';
callPrivate($hub, 'processWarnings', [[$c]]);
check('Entwarnung für zwei Standorte: 1 Push mit beiden Namen', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Zuhause, Kohlekasten'));

echo "\n== Ruhezeit ==\n";
$hub = neuerHub([$standort], $ruheJetzt);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200, 'Moderate')]]);
check('nachts Moderate: kein Push', pushes() === 0);
check('… aber in der Historie', count(json_decode($hub->GetHistory(0), true)) === 1);
check('… und für die Sammelmeldung gemerkt', count(json_decode($hub->ReadAttributeString('RuhezeitZurueckgehalten'), true)) === 1);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200, 'Moderate')]]);
check('nächster Abruf nachts: weiter still, kein doppeltes Merken', pushes() === 0 && count(json_decode($hub->ReadAttributeString('RuhezeitZurueckgehalten'), true)) === 1);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200, 'Moderate'), sturm('S', -60, 7200, 'Severe')]]);
check('nachts Severe: pusht (Grenze Severe)', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Hochgestuft'));
foreach ($ruheSpaeter as $k => $v) {
    $hub->SetProp($k, $v);
}
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200, 'Moderate')]]);
check('morgens: genau 1 Sammelmeldung', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][2], 'Nacht'));
check('… nennt Standort und Warnung', str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Zuhause: Amtliche WARNUNG vor STURMBÖEN'));
check('… und ist danach erledigt', json_decode($hub->ReadAttributeString('RuhezeitZurueckgehalten'), true) === []);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200, 'Moderate')]]);
check('kein zweites Mal', pushes() === 1);

$hub = neuerHub([$standort], $ruheJetzt);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200)]]);
callPrivate($hub, 'processWarnings', [[]]);
foreach ($ruheSpaeter as $k => $v) {
    $hub->SetProp($k, $v);
}
callPrivate($hub, 'processWarnings', [[]]);
check('nachts gekommen und gegangen: morgens keine Sammelmeldung', pushes() === 0);

$hub = neuerHub([$standort], $ruheJetzt + ['RuhezeitSammelmeldung' => false]);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200)]]);
check('Sammelmeldung aus: nichts gemerkt', json_decode($hub->ReadAttributeString('RuhezeitZurueckgehalten') ?: '[]', true) === []);

$hub = neuerHub([$standort], ['RuhezeitAktiv' => false] + $ruheJetzt);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200)]]);
check('Ruhezeit aus: Moderate pusht wie bisher', pushes() === 1);

$hub = neuerHub([$standort], $ruheJetzt);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 7200)]]);
$GLOBALS['whub_test_pushCalls'] = [];
$c = sturm('A', -3600, 7200);
$c['msgType'] = 'Cancel';
callPrivate($hub, 'processWarnings', [[$c]]);
check('nachts leichte Entwarnung: still und gemerkte Warnung gestrichen', pushes() === 0 && json_decode($hub->ReadAttributeString('RuhezeitZurueckgehalten'), true) === []);

echo "\n== Zeitfenster ==\n";
$hub = neuerHub([$standort], ['RuhezeitAktiv' => true, 'RuhezeitVon' => 22, 'RuhezeitBis' => 7]);
$tag = strtotime('2026-11-18 00:00:00');
check('22-7 Uhr: 23 Uhr ist Ruhezeit', callPrivate($hub, 'isQuietHours', [$tag + 23 * 3600]) === true);
check('22-7 Uhr: 3 Uhr ist Ruhezeit', callPrivate($hub, 'isQuietHours', [$tag + 3 * 3600]) === true);
check('22-7 Uhr: 7 Uhr ist keine Ruhezeit mehr', callPrivate($hub, 'isQuietHours', [$tag + 7 * 3600]) === false);
check('22-7 Uhr: 12 Uhr keine Ruhezeit', callPrivate($hub, 'isQuietHours', [$tag + 12 * 3600]) === false);
$hub->SetProp('RuhezeitVon', 13);
$hub->SetProp('RuhezeitBis', 15);
check('13-15 Uhr (Mittagsruhe): 14 Uhr ja, 15 Uhr nein', callPrivate($hub, 'isQuietHours', [$tag + 14 * 3600]) === true && callPrivate($hub, 'isQuietHours', [$tag + 15 * 3600]) === false);
$hub->SetProp('RuhezeitBis', 13);
check('Von = Bis: nie Ruhezeit', callPrivate($hub, 'isQuietHours', [$tag + 13 * 3600]) === false);

echo "\n== Ton ==\n";
$opt = callPrivate($hub, 'soundOptions');
check('Auswahl "Standardton des Handys" (leer) steht vorn', ($opt[0]['value'] ?? null) === '');
check('Voreinstellung bleibt "alarm" (keine stille Änderung für Bestandsnutzer)', (new ReflectionMethod($hub, 'Create')) && str_contains((string) file_get_contents(__DIR__ . '/../WarnHub/module.php'), "RegisterPropertyString('PushSound', 'alarm')"));

echo "\n== Ein Alarm für Autos zuhause (MobilZusammenfassenKm, Dietmars Wunsch 20.11.2026) ==\n";
// Auto mit Live-Position: Variablen 301/302 (lat/lon). Zuhause 48.4785/7.9448.
function auto(string $name, int $varLat, int $varLon): array
{
    global $standort;
    return ['Name' => $name, 'QuellVarLat' => $varLat, 'QuellVarLon' => $varLon] + $standort;
}
function autoPosition(int $varLat, int $varLon, float $lat, float $lon): void
{
    $GLOBALS['whub_test_variableValues'][$varLat] = $lat;
    $GLOBALS['whub_test_variableValues'][$varLon] = $lon;
}
autoPosition(301, 302, 48.4800, 7.9450); // ca. 170 m von zuhause
autoPosition(303, 304, 48.5200, 7.9448); // ca. 4,6 km von zuhause
$hub = neuerHub([$standort, auto('Schneeflocke', 301, 302)]);
$GLOBALS['whub_test_pushCalls'] = [];
$r = callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
check('Auto zuhause (170 m, Radius Standard 1 km): 1 Push nur für Zuhause', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Zuhause:') && !str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Schneeflocke'));
check('… Historie und Episoden weiter für beide', $r['newlyPushed'] === 2 && count(json_decode($hub->GetHistory(0), true)) === 2);
$hub = neuerHub([$standort, auto('Kohlekasten', 303, 304)]);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
check('Auto 4,6 km weg: eigener Name im (gebündelten) Push', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Zuhause, Kohlekasten'));
$hub = neuerHub([$standort, auto('Kohlekasten', 303, 304)], ['MobilZusammenfassenKm' => 5.0]);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
check('Radius 5 km: Auto in 4,6 km zählt als zuhause', pushes() === 1 && !str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Kohlekasten'));
$hub = neuerHub([$standort, auto('Schneeflocke', 301, 302)], ['MobilZusammenfassenKm' => 0.0]);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
check('Radius 0 = aus: Auto wieder genannt', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Schneeflocke'));
$hub = neuerHub([['MinSeverity' => 3] + $standort, auto('Schneeflocke', 301, 302)]);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600, 'Moderate')]]);
check('Zuhause hat die Warnung nicht (eigene Schwelle): Auto bekommt seinen Alarm', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Schneeflocke:'));
$ohne = ['Lat' => 0.0, 'Lon' => 0.0] + auto('Ohne Position', 305, 306);
$hub = neuerHub([$standort, $ohne, auto('Schneeflocke', 301, 302)]);
$zuordnung = callPrivate($hub, 'mobileAtFixedLocations', [[$standort, $ohne, auto('Schneeflocke', 301, 302)]]);
check('Auto ohne Live-Position (0/0) gilt nicht als zuhause, Schneeflocke schon', !isset($zuordnung['Ohne Position']) && ($zuordnung['Schneeflocke'] ?? null) === 'Zuhause');
$fernFest = ['Name' => 'Büro', 'Lat' => 48.5200, 'Lon' => 7.9448] + $standort;
$hub->SetProp('MobilZusammenfassenKm', 5.0);
$zuordnung = callPrivate($hub, 'mobileAtFixedLocations', [[$fernFest, $standort, auto('Kohlekasten', 303, 304)]]);
check('nächstgelegener fester Standort gewinnt (Büro 0 km statt Zuhause 4,6 km, Radius 5)', ($zuordnung['Kohlekasten'] ?? null) === 'Büro');
$hub = neuerHub([$standort, auto('Schneeflocke', 301, 302)]);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
$GLOBALS['whub_test_pushCalls'] = [];
$c = sturm('A', -3600, 3600);
$c['msgType'] = 'Cancel';
callPrivate($hub, 'processWarnings', [[$c]]);
check('Entwarnung: ebenfalls nur ein Push für Zuhause', pushes() === 1 && !str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Schneeflocke'));
$hub = neuerHub([$standort, auto('Schneeflocke', 301, 302)]);
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600)]]);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub, 'processWarnings', [[sturm('A', -3600, 3600), ['event' => 'GLÄTTE', 'headline' => 'Amtliche WARNUNG vor GLÄTTE'] + sturm('G', -60, 3600)]]);
check('neue Warnung im selben Abruf für beide: 1 Push, nur Zuhause', pushes() === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'GLÄTTE') && !str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Schneeflocke'));

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
