<?php

/**
 * Prüfstand für die Kategorie "Rauch/Gefahrstoffe" und den Aktionstyp
 * "Variable auf Wert setzen" (Praxis-Wunsch mike256, Symcon-Forum,
 * 17.10.2026: bei einer NINA-Meldung wie "Rauchgase, Fenster schließen" die
 * Stufe der Wohnraumlüftung auf 0 setzen). Vorher gab es keine Kategorie
 * dafür -- Rauch-Meldungen landeten in "sonstige", und "keine Kategorie
 * angekreuzt" heißt "jede Warnung".
 *
 *   php .tools/test-kategorie-rauch.php    # 0 = alle Prüfungen bestanden
 */

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
$GLOBALS['whub_test_variableValues'] = [];
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


$hub = new WarnHub();
$hub->Create();
$klassen = fn (string $event, string $headline = '') => callPrivate($hub, 'classifyEventCategory', [$event, $headline]);

echo "\n== Klassifikation ==\n";
check('"Rauchgase" -> rauch', $klassen('Gefahreninformation', 'Warnung vor Rauchgasen') === 'rauch');
check('"Rauchentwicklung" -> rauch', $klassen('Brand', 'Brand mit starker Rauchentwicklung') === 'rauch');
check('"Gefahrstoffaustritt" -> rauch', $klassen('Gefahrstoffaustritt', '') === 'rauch');
check('"Gasaustritt" -> rauch', $klassen('', 'Gasaustritt in der Innenstadt') === 'rauch');
check('Rauch-Meldung mit "Hinweis" im Titel bleibt rauch (das schnee-Stichwort "eis" steckt in "Hinweis")', $klassen('Rauchgase', 'Hinweis: Fenster schließen') === 'rauch');
check('"Sturmböen" bleibt sturm', $klassen('Sturm', 'Sturmböen') === 'sturm');
check('"Hochwasser" bleibt starkregen', $klassen('Hochwasser', '') === 'starkregen');
check('"Glatteis" bleibt schnee', $klassen('Glatteis', '') === 'schnee');
check('"Großbrand" allein ist bewusst KEINE Rauch-Kategorie (sonstige)', $klassen('Großbrand', '') === 'sonstige');
check('Feuerverbot (Alertswiss) bleibt sonstige', $klassen('Feuerverbot', 'Absolutes Feuerverbot') === 'sonstige');

echo "\n== Aktionstyp und Spalte ==\n";
$optionen = array_column(callPrivate($hub, 'actionTypeOptions'), 'value');
check('Aktionstyp "wert" ist wählbar', in_array('wert', $optionen, true));

echo "\n== Ende zu Ende: Lüftung auf 0 nur bei Rauch-Meldung ==\n";
$standort = ['Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true];
$luftung = ['Name' => 'Lüftung aus', 'Aktiv' => true, 'Typ' => 'wert',
    'KatSturm' => false, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'KatRauch' => true,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 501, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0];
$GLOBALS['whub_test_variableValues'][501] = 3.0;
$warnung = fn (string $id, string $event, string $headline) => [
    'identifier' => $id, 'source' => 'test', 'msgType' => 'Alert', 'event' => $event, 'headline' => $headline,
    'description' => '', 'instruction' => '', 'severity' => 'Severe', 'effective' => date('c'), 'onset' => date('c'), 'expires' => null,
    'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
];
$lauf = function (array $aktion, array $warnungen) use ($standort) {
    $h = new WarnHub();
    $h->Create();
    $h->SetProp('Standorte', json_encode([$standort]));
    $h->SetProp('Schutzaktionen', json_encode([$aktion]));
    $h->SetProp('PushAktiv', false);
    $GLOBALS['whub_test_requestActionCalls'] = [];
    $r = callPrivate($h, 'processWarnings', [$warnungen]);
    return [$r['actionsTriggered'], $GLOBALS['whub_test_requestActionCalls']];
};
[$n, $calls] = $lauf($luftung, [$warnung('r-1', 'Gefahreninformation', 'Rauchgase -- Fenster schließen')]);
check('Rauch-Meldung löst die Aktion aus', $n === 1);
check('RequestAction setzt die Lüftungs-Variable (501) auf den Zielwert 0', ($calls[0][0] ?? null) === 501 && (float) ($calls[0][1] ?? 1) === 0.0);
[$n, $calls] = $lauf($luftung, [$warnung('s-1', 'Sturm', 'Sturmböen')]);
check('eine Sturm-Meldung löst die Rauch-Aktion NICHT aus', $n === 0 && count($calls) === 0);
$ohneFilter = $luftung;
$ohneFilter['KatRauch'] = false;
[$n] = $lauf($ohneFilter, [$warnung('s-2', 'Sturm', 'Sturmböen')]);
check('ohne angekreuzte Kategorie gilt die Aktion für jede Meldung (bestehendes Verhalten, deshalb der neue Filter)', $n === 1);
$altesFormat = $luftung;
unset($altesFormat['KatRauch']);
$altesFormat['KatSturm'] = true;
[$n] = $lauf($altesFormat, [$warnung('r-2', 'Gefahreninformation', 'Rauchgase')]);
check('gespeicherte Zeile ohne KatRauch-Feld (vor diesem Update) wird gelesen, Rauch zählt dort als nicht angekreuzt', $n === 0);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
