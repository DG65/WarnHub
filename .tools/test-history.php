<?php

/**
 * Prüfstand für die Warnungs-Historie (Attribut WarnHistory,
 * WHUB_GetHistory()) -- dritter und letzter Teil von Dietmars Wunsch
 * 04.09.2026 "IPSView, Mehrkanal-Push und eine Warnungs-Historie packen
 * wir nun an." Protokolliert jede NEUE Warnung und jede Entwarnung
 * unabhängig davon, ob Push gerade aktiv ist -- Deckel bei 500 Einträgen,
 * älteste zuerst raus (identisches Prinzip wie EMS' SpecialEventsLog).
 * Kein Netzzugriff nötig.
 *
 *   php .tools/test-history.php    # 0 = alle Prüfungen bestanden
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
$GLOBALS['whub_test_variableValues'] = [];
function IPS_VariableExists(int $id): bool
{
    return array_key_exists($id, $GLOBALS['whub_test_variableValues']);
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_variableValues'][$id] ?? 0;
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
    public function ApplyChanges()
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
$warnung = [
    'identifier' => 'test-sturm-1', 'source' => 'test', 'msgType' => 'Alert', 'event' => 'Sturm',
    'headline' => 'Schwere Sturmböen', 'description' => '', 'instruction' => '', 'severity' => 'Severe',
    'effective' => date('c'), 'onset' => date('c'), 'expires' => null,
    'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
];

echo "== logHistory(): neue Warnung wird protokolliert, auch bei ausgeschaltetem Push ==\n";
$hub = new WarnHub();
$hub->Create();
$hub->SetProp('Standorte', json_encode([$standort]));
$hub->SetProp('PushAktiv', false);
callPrivate($hub, 'processWarnings', [[$warnung]]);
$history = json_decode($hub->GetHistory(), true);
check('genau 1 Verlaufseintrag nach der ersten Erkennung', count($history) === 1);
check('Eintrag ist vom Typ "warnung"', ($history[0]['kind'] ?? null) === 'warnung');
check('Eintrag nennt den richtigen Standort', ($history[0]['standort'] ?? null) === 'Zuhause');
check('Eintrag nennt Ereignis, Schweregrad und Kategorie', ($history[0]['event'] ?? null) === 'Sturm' && ($history[0]['severity'] ?? null) === 'Severe' && ($history[0]['category'] ?? null) === 'sturm');
check('Eintrag trägt einen Zeitstempel', ($history[0]['ts'] ?? 0) > 0);

echo "\n== logHistory(): dieselbe, bereits gesehene Warnung erzeugt KEINEN zweiten Eintrag beim nächsten Poll ==\n";
callPrivate($hub, 'processWarnings', [[$warnung]]);
check('weiterhin genau 1 Verlaufseintrag (kein Duplikat)', count(json_decode($hub->GetHistory(), true)) === 1);

echo "\n== logHistory(): Entwarnung wird ebenfalls protokolliert ==\n";
$entwarnung = $warnung;
$entwarnung['msgType'] = 'Cancel';
callPrivate($hub, 'processWarnings', [[$entwarnung]]);
$history2 = json_decode($hub->GetHistory(), true);
check('jetzt 2 Verlaufseinträge (Warnung + Entwarnung)', count($history2) === 2);
check('neuester Eintrag zuerst (GetHistory() liefert newest-first) und ist die Entwarnung', ($history2[0]['kind'] ?? null) === 'entwarnung');
check('älterer Eintrag an zweiter Stelle ist weiterhin die ursprüngliche Warnung', ($history2[1]['kind'] ?? null) === 'warnung');

echo "\n== WHUB_GetHistory(): limit begrenzt die Rückgabe ==\n";
check('limit=1 liefert nur den neuesten Eintrag', count(json_decode($hub->GetHistory(1), true)) === 1);
check('limit=0 liefert die volle Liste ohne Begrenzung', count(json_decode($hub->GetHistory(0), true)) === 2);

echo "\n== logHistory(): Deckel bei 500 Einträgen, älteste zuerst raus ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$vollesLog = [];
for ($i = 0; $i < 500; $i++) {
    $vollesLog[] = ['ts' => $i, 'kind' => 'warnung', 'standort' => 'Test', 'event' => 'Alt', 'headline' => '', 'severity' => 'Minor', 'category' => 'sturm', 'source' => 'test'];
}
$hub2->WriteAttributeString('WarnHistory', json_encode($vollesLog));
callPrivate($hub2, 'logHistory', ['warnung', 'Zuhause', $warnung['event'], $warnung['headline'], $warnung['severity'], 'sturm', $warnung['source']]);
$capped = json_decode($hub2->ReadAttributeString('WarnHistory'), true);
check('bleibt bei genau 500 Einträgen (nicht 501)', count($capped) === 500);
check('der neue Eintrag ist tatsächlich dabei (am Ende)', ($capped[499]['standort'] ?? null) === 'Zuhause');
check('der älteste ursprüngliche Eintrag (ts=0) wurde verdrängt', $capped[0]['ts'] !== 0);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
