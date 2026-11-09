<?php

/**
 * Prüfstand für die Kategorie-Zuordnung (1.20.1):
 *   - eigene Quellen und Meteoalarm setzen ihre Kategorie selbst, damit sie
 *     nicht am (übersetzten) Anzeigetext hängt (SUITE.md Punkt 9),
 *   - Meteoalarm: Kategorie aus dem genormten Warntyp im Titel (bis 1.20.0
 *     immer "sonstige", die Ereignisnamen sind englisch),
 *   - Stichwort "eis" nur am Wortanfang (bis 1.20.0 traf es "Landkreis",
 *     "Hinweis", "Ortsdosisleistung" -> "Schnee").
 *
 *   php .tools/test-kategorien.php    # 0 = alle Prüfungen bestanden
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

$hub = new WarnHub();
$hub->Create();
$klassen = fn (string $event, string $headline = '') => callPrivate($hub, 'classifyEventCategory', [$event, $headline]);

echo "== Stichwort \"eis\" nur am Wortanfang ==\n";
check('"Großbrand im Landkreis Lörrach" ist NICHT Schnee ("Landkreis" enthält "eis")', $klassen('Gefahreninformation', 'Großbrand im Landkreis Lörrach') === 'sonstige');
check('"Erhöhte Ortsdosisleistung" ist NICHT Schnee', $klassen('Erhöhte Radioaktivität', 'Erhöhte Ortsdosisleistung: Freiburg') === 'sonstige');
check('"Hinweis" allein ist NICHT Schnee', $klassen('Hinweis', 'Hinweis zur Trinkwasserversorgung') === 'sonstige');
check('"Eisglätte" bleibt Schnee', $klassen('Eisglätte', '') === 'schnee');
check('"Eisregen" bleibt Schnee', $klassen('Amtliche Warnung', 'Gefahr durch Eisregen') === 'schnee');
check('"Glatteis" bleibt Schnee', $klassen('Glatteis', '') === 'schnee');
check('"Schneeverwehungen" bleibt Schnee', $klassen('Schneeverwehungen', '') === 'schnee');
check('Großschreibung egal: "EIS" am Wortanfang -> Schnee', $klassen('EIS', '') === 'schnee');

echo "\n== Meteoalarm: Kategorie aus dem genormten Warntyp ==\n";
$ma = fn (string $title) => callPrivate($hub, 'meteoalarmCategory', [$title]);
check('"Orange Wind Warning issued for Germany - Kreis Osterholz" -> Sturm', $ma('Orange Wind Warning issued for Germany - Kreis Osterholz') === 'sturm');
check('"Yellow Thunderstorm Warning ..." -> Gewitter', $ma('Yellow Thunderstorm Warning issued for Switzerland - Basel') === 'gewitter');
check('"Red Rain Warning ..." -> Starkregen', $ma('Red Rain Warning issued for Italy - X') === 'starkregen');
check('"Orange Rain-flood Warning ..." -> Starkregen', $ma('Orange Rain-flood Warning issued for Spain - X') === 'starkregen');
check('"Yellow Snow-ice Warning ..." -> Schnee', $ma('Yellow Snow-ice Warning issued for Austria - X') === 'schnee');
check('"Orange High-temperature Warning ..." -> Hitze', $ma('Orange High-temperature Warning issued for France - X') === 'hitze');
check('"Yellow Fog Warning ..." -> sonstige', $ma('Yellow Fog Warning issued for Netherlands - X') === 'sonstige');
check('"Yellow Low-temperature Warning ..." -> sonstige', $ma('Yellow Low-temperature Warning issued for Poland - X') === 'sonstige');
check('unbekanntes Titelformat -> sonstige (nichts geraten)', $ma('Gale force wind') === 'sonstige');
$fixture = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:cap="urn:oasis:names:tc:emergency:cap:1.2">
  <entry>
    <cap:areaDesc>Basel</cap:areaDesc>
    <cap:event>Gale force wind</cap:event>
    <cap:severity>Moderate</cap:severity>
    <cap:message_type>Alert</cap:message_type>
    <cap:status>Actual</cap:status>
    <cap:identifier>2.49.0.1.756.0.TEST</cap:identifier>
    <title>Orange Wind Warning issued for Switzerland - Basel</title>
  </entry>
</feed>
XML;
$parsed = callPrivate($hub, 'parseMeteoalarmAtom', [$fixture, 'switzerland']);
check('parseMeteoalarmAtom setzt category "sturm" (englischer Ereignisname "Gale force wind" traf bis 1.20.0 kein deutsches Stichwort)', ($parsed[0]['category'] ?? null) === 'sturm');

echo "\n== Eigene Quellen setzen ihre Kategorie selbst ==\n";
$csv = "id,name,dangerLevel,measurementTime,wkt\n2100,Testort,3,2026-09-04T19:50:00+01:00,POINT(7.5 46.5)";
$bafu = callPrivate($hub, 'parseBafuHydroCsv', [$csv]);
check('BAFU-Hochwasser -> category "starkregen"', ($bafu[0]['category'] ?? null) === 'starkregen');
// Statisch: jeder Rückgabe-Block einer eigenen Quelle trägt eine feste Kategorie
$code = (string) file_get_contents(__DIR__ . '/../WarnHub/module.php');
foreach (['pegelonline' => 'starkregen', 'bfs_odl' => 'sonstige', 'wetterstation' => null, 'bafu_hydro_ch' => 'starkregen', 'sed_ch' => 'sonstige',
    'waldbrand_de' => 'sonstige', 'ozon_de' => 'sonstige', 'hagelschutz_ch' => 'hagel'] as $quelle => $kat) {
    preg_match_all("/'source' => '" . $quelle . "',\s*\n\s*'category' => '([a-z]+)'/", $code, $m);
    $anzahl = substr_count($code, "'source' => '" . $quelle . "',");
    check("Quelle $quelle: jeder Block (" . $anzahl . ") setzt eine Kategorie" . ($kat !== null ? " ($kat)" : ''), $anzahl > 0 && count($m[1]) === $anzahl && ($kat === null || array_unique($m[1]) === [$kat]));
}
check('Wetterstation: Windböe -> sturm, Regenrate -> starkregen (je Meldung + Entwarnung)', substr_count($code, "'source' => 'wetterstation',\n                    'category' => 'sturm',") === 2 && substr_count($code, "'source' => 'wetterstation',\n                    'category' => 'starkregen',") === 2);
check('GeoSphere: Kategorie je Warntyp aus GEOSPHERE_AT_WARNTYPE_CATEGORY (alle 7 Typen)', count((new ReflectionClass('WarnHub'))->getConstant('GEOSPHERE_AT_WARNTYPE_CATEGORY')) === 7);

echo "\n== Ende zu Ende: die gesetzte Kategorie schlägt die Stichwortsuche ==\n";
$standort = ['Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true];
$sturmAktion = ['Name' => 'Markise', 'Aktiv' => true, 'Typ' => 'wert',
    'KatSturm' => true, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'KatRauch' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 501, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0];
$schneeAktion = $sturmAktion;
$schneeAktion['KatSturm'] = false;
$schneeAktion['KatSchnee'] = true;
$GLOBALS['whub_test_variableValues'][501] = 3.0;
$warnung = function (string $id, string $event, string $headline, ?string $kat): array {
    $w = [
        'identifier' => $id, 'source' => 'test', 'msgType' => 'Alert', 'event' => $event, 'headline' => $headline,
        'description' => '', 'instruction' => '', 'severity' => 'Severe', 'effective' => date('c'), 'onset' => date('c'), 'expires' => null,
        'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
    ];
    if ($kat !== null) {
        $w['category'] = $kat; // wie bei den eigenen Quellen; ohne = Stichwortsuche (NINA/DWD/Alertswiss)
    }
    return $w;
};
$lauf = function (array $aktion, array $warnungen) use ($standort) {
    $h = new WarnHub();
    $h->Create();
    $h->SetProp('Standorte', json_encode([$standort]));
    $h->SetProp('Schutzaktionen', json_encode([$aktion]));
    $h->SetProp('PushAktiv', false);
    $GLOBALS['whub_test_requestActionCalls'] = [];
    return callPrivate($h, 'processWarnings', [$warnungen])['actionsTriggered'];
};
check('Meteoalarm "Gale force wind" mit category sturm löst die Sturm-Aktion aus (bis 1.20.0: nie)', $lauf($sturmAktion, [$warnung('m-1', 'Gale force wind', 'Orange Wind Warning issued for Switzerland - Basel', 'sturm')]) === 1);
check('dieselbe Meldung OHNE gesetzte Kategorie: die Stichwortsuche findet nichts (so war es bis 1.20.0)', $lauf($sturmAktion, [$warnung('m-2', 'Gale force wind', 'x', null)]) === 0);
check('BfS-Strahlung (category sonstige) löst eine Schnee-Aktion NICHT aus (bis 1.20.0: doch, über "Ortsdosisleistung")', $lauf($schneeAktion, [$warnung('b-1', 'Erhöhte Radioaktivität', 'Erhöhte Ortsdosisleistung: X', 'sonstige')]) === 0);
check('NINA-Meldung ohne gesetzte Kategorie läuft weiter über die Stichwörter ("Sturmböen" -> sturm)', $lauf($sturmAktion, [$warnung('n-1', 'Sturm', 'Amtliche Warnung vor Sturmböen', null)]) === 1);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
