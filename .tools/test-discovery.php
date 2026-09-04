<?php

/**
 * Prüfstand für die Objektbaum-Suche (WebFront-Discovery + Schutzaktionen-
 * Discovery) gegen einen kleinen, gestubbten Objektbaum -- kein echtes
 * IPS-System nötig.
 *
 *   php .tools/test-discovery.php    # 0 = alle Prüfungen bestanden
 */

const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
const OTHER_MODULE_GUID = '{00000000-0000-0000-0000-000000000000}';

// Kleiner Fake-Objektbaum:
//   0 (Root)
//    +- 10 Kategorie "Geräte"
//    |   +- 11 Instanz "Raffstore Wohnzimmer" -> 111 Var "Position" (aktionsfähig)
//    |   +- 12 Instanz "Sirene Außen" -> 121 Var "Ein/Aus" (aktionsfähig)
//    |   +- 13 Instanz "Garagentor" -> 131 Var "Status" (NICHT aktionsfähig), 132 Var "Steuerung" (aktionsfähig)
//    |   +- 14 Instanz "Wetterstation" (kein Treffer)
//    +- 20 Instanz "WebFront Familie" (Modul WebFront)
//    +- 21 Instanz "WebFront Gast" (Modul WebFront)
$GLOBALS['whub_test_tree'] = [
    0 => [10, 20, 21],
    10 => [11, 12, 13, 14],
    11 => [111],
    12 => [121],
    13 => [131, 132],
    14 => [],
    20 => [],
    21 => [],
];
$GLOBALS['whub_test_objects'] = [
    10 => ['ObjectType' => 0, 'ObjectName' => 'Geräte'],
    11 => ['ObjectType' => 1, 'ObjectName' => 'Raffstore Wohnzimmer'],
    12 => ['ObjectType' => 1, 'ObjectName' => 'Sirene Außen'],
    13 => ['ObjectType' => 1, 'ObjectName' => 'Garagentor'],
    14 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation'],
    20 => ['ObjectType' => 1, 'ObjectName' => 'WebFront Familie'],
    21 => ['ObjectType' => 1, 'ObjectName' => 'WebFront Gast'],
    111 => ['ObjectType' => 2, 'ObjectName' => 'Position'],
    121 => ['ObjectType' => 2, 'ObjectName' => 'Ein/Aus'],
    131 => ['ObjectType' => 2, 'ObjectName' => 'Status'],
    132 => ['ObjectType' => 2, 'ObjectName' => 'Steuerung'],
];
$GLOBALS['whub_test_variables'] = [
    111 => ['VariableAction' => 1],
    121 => ['VariableAction' => 1],
    131 => ['VariableAction' => 0], // reine Anzeige, keine Aktion -- darf NICHT vorgeschlagen werden
    132 => ['VariableAction' => 1],
];
$GLOBALS['whub_test_instancesByModule'] = [
    WEBFRONT_GUID => [20, 21],
];

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
    return $GLOBALS['whub_test_variables'][$id] ?? false;
}
function IPS_GetName(int $id): string
{
    return $GLOBALS['whub_test_objects'][$id]['ObjectName'] ?? '';
}
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return $GLOBALS['whub_test_instancesByModule'][$guid] ?? [];
}
function IPS_GetModuleList(): array
{
    return [WEBFRONT_GUID, OTHER_MODULE_GUID];
}
function IPS_GetModule(string $guid): array
{
    return ['ModuleName' => $guid === WEBFRONT_GUID ? 'WebFront' : 'Irgendwas'];
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
    public $lastFormFieldUpdate = null;
    public function UpdateFormField($n, $k, $v)
    {
        $this->lastFormFieldUpdate = [$n, $k, $v];
    }
    public function Create()
    {
    }
}

require __DIR__ . '/../WarnHub/module.php';

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

echo "== WebFront-Discovery ==\n";
$msg = $hub->DiscoverWebFronts();
check('meldet 2 neue Instanzen', str_contains($msg, '2 neue'));
[$field, $key, $valuesJson] = $hub->lastFormFieldUpdate;
check('schreibt in das Feld "WebFronts"', $field === 'WebFronts');
$rows = json_decode($valuesJson, true);
check('2 Zeilen gefunden', count($rows) === 2);
check('beide standardmäßig aktiv', $rows[0]['Aktiv'] === true && $rows[1]['Aktiv'] === true);

// Nutzer deaktiviert "WebFront Gast" (id 21) und speichert (Property).
foreach ($rows as &$r) {
    if ($r['InstanceID'] === 21) {
        $r['Aktiv'] = false;
    }
}
unset($r);
$hub->SetProp('WebFronts', json_encode($rows));

// Erneute Suche darf die Abwahl NICHT rückgängig machen.
$msg2 = $hub->DiscoverWebFronts();
check('erneute Suche findet keine NEUEN Instanzen mehr', str_contains($msg2, 'Keine neuen'));
[, , $valuesJson2] = $hub->lastFormFieldUpdate;
$rows2 = json_decode($valuesJson2, true);
$gast = array_values(array_filter($rows2, fn ($r) => $r['InstanceID'] === 21))[0];
check('"WebFront Gast" bleibt deaktiviert nach erneuter Suche', $gast['Aktiv'] === false);

echo "== Schutzaktionen-Discovery ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$msg3 = $hub2->DiscoverSchutzaktionen();
check('meldet 3 neue Schutzaktionen (Raffstore/Sirene/Garage)', str_contains($msg3, '3 neue'));
[$field3, , $valuesJson3] = $hub2->lastFormFieldUpdate;
check('schreibt in das Feld "Schutzaktionen"', $field3 === 'Schutzaktionen');
$actions = json_decode($valuesJson3, true);
check('genau 3 Zeilen (Wetterstation kein Treffer, Status-Var nicht aktionsfähig)', count($actions) === 3);

$byName = [];
foreach ($actions as $a) {
    $byName[$a['Name']] = $a;
}
check('Raffstore Wohnzimmer -> Typ raffstore, Ziel-Variable 111 (Position)', ($byName['Raffstore Wohnzimmer']['Typ'] ?? null) === 'raffstore' && ($byName['Raffstore Wohnzimmer']['ZielVariableID'] ?? null) === 111);
check('Sirene Außen -> Typ sirene, MinSeverity 4 (Extrem, vorsichtiger Standard)', ($byName['Sirene Außen']['Typ'] ?? null) === 'sirene' && ($byName['Sirene Außen']['MinSeverity'] ?? null) === 4);
check('Garagentor -> Typ garage, Ziel-Variable 132 (Steuerung, NICHT die nicht-aktionsfähige Status-Variable 131)', ($byName['Garagentor']['Typ'] ?? null) === 'garage' && ($byName['Garagentor']['ZielVariableID'] ?? null) === 132);
check('alle drei Treffer standardmäßig aktiv', $byName['Raffstore Wohnzimmer']['Aktiv'] === true && $byName['Sirene Außen']['Aktiv'] === true && $byName['Garagentor']['Aktiv'] === true);

// Nutzer klickt "Übernehmen" (Property = aktueller Formularstand) -- erst
// danach kann eine erneute Suche gegen den gespeicherten Stand deduplizieren.
$hub2->SetProp('Schutzaktionen', $valuesJson3);
$msg4 = $hub2->DiscoverSchutzaktionen();
check('erneute Suche nach "Übernehmen" findet keine neuen Treffer mehr (kein Duplikat)', str_contains($msg4, 'Keine neuen'));

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
