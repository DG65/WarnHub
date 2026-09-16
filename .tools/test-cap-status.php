<?php

/**
 * Prüft isCapStatusActual() sowie parseCapXml() (direkte DWD-CAP-Anbindung)
 * offline, ohne Netzzugriff -- Praxis-Fund ralf, Symcon-Forum, 16.09.2026:
 * eine Übungs-/Testmeldung ("ACHTUNG! TEST TEST ... Heute scheint der
 * Mond.") kam unverändert als echte Warnung durch, weil WarnHub das
 * CAP-Standardfeld `status` (Actual/Exercise/System/Test/Draft) bisher an
 * keiner Stelle prüfte. Live gegen NINA ("status":"Actual" im JSON) und
 * Meteoalarm (<cap:status>Actual</cap:status> im Atom-Feed) verifiziert,
 * dass das Feld tatsächlich vorhanden ist -- siehe test-meteoalarm.php für
 * die entsprechende Prüfung der Meteoalarm-Anbindung.
 *
 *   php .tools/test-cap-status.php    # 0 = alle Prüfungen bestanden
 */

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

echo "== isCapStatusActual(): reine Logik ==\n";
check('"Actual" -> true', callPrivate($hub, 'isCapStatusActual', ['Actual']) === true);
check('Groß-/Kleinschreibung egal ("actual")', callPrivate($hub, 'isCapStatusActual', ['actual']) === true);
check('umgebender Leerraum egal (" Actual ")', callPrivate($hub, 'isCapStatusActual', [' Actual ']) === true);
check('null (Feld fehlt ganz) -> true (Rückwärtskompatibilität)', callPrivate($hub, 'isCapStatusActual', [null]) === true);
check('leerer String -> true (Rückwärtskompatibilität)', callPrivate($hub, 'isCapStatusActual', ['']) === true);
check('"Exercise" -> false', callPrivate($hub, 'isCapStatusActual', ['Exercise']) === false);
check('"Test" -> false', callPrivate($hub, 'isCapStatusActual', ['Test']) === false);
check('"System" -> false', callPrivate($hub, 'isCapStatusActual', ['System']) === false);
check('"Draft" -> false', callPrivate($hub, 'isCapStatusActual', ['Draft']) === false);

function machCapXml(string $status): string
{
    $statusTag = $status !== '' ? "<status>{$status}</status>" : '';
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
  <identifier>test-dwd-cap-1</identifier>
  <sender>opendata@dwd.de</sender>
  <sent>2026-09-16T12:00:00+02:00</sent>
  {$statusTag}
  <msgType>Alert</msgType>
  <scope>Public</scope>
  <info>
    <language>de-DE</language>
    <event>STARKES GEWITTER</event>
    <severity>Moderate</severity>
    <headline>Amtliche WARNUNG vor STARKEM GEWITTER</headline>
    <description>Beschreibung.</description>
    <instruction>Handlungsempfehlung.</instruction>
    <onset>2026-09-16T12:00:00+02:00</onset>
    <expires>2026-09-16T18:00:00+02:00</expires>
    <area>
      <areaDesc>Ortenaukreis</areaDesc>
      <polygon>48.0,7.0 49.0,7.0 49.0,8.0 48.0,8.0</polygon>
    </area>
  </info>
</alert>
XML;
}

echo "\n== parseCapXml(): status=Actual wird normal geparst ==\n";
$capActual = callPrivate($hub, 'parseCapXml', [machCapXml('Actual')]);
check('liefert ein Ergebnis (kein null)', $capActual !== null);
check('identifier korrekt', ($capActual['identifier'] ?? null) === 'test-dwd-cap-1');
check('event korrekt', ($capActual['event'] ?? null) === 'STARKES GEWITTER');

echo "\n== parseCapXml(): OHNE status-Feld wird weiterhin normal geparst (Rückwärtskompatibilität) ==\n";
$capOhne = callPrivate($hub, 'parseCapXml', [machCapXml('')]);
check('liefert ein Ergebnis (kein null)', $capOhne !== null);

echo "\n== parseCapXml(): Übungs-/Testmeldungen werden verworfen statt als echte Warnung geparst ==\n";
check('status=Exercise -> null', callPrivate($hub, 'parseCapXml', [machCapXml('Exercise')]) === null);
check('status=Test -> null', callPrivate($hub, 'parseCapXml', [machCapXml('Test')]) === null);
check('status=System -> null', callPrivate($hub, 'parseCapXml', [machCapXml('System')]) === null);
check('status=Draft -> null', callPrivate($hub, 'parseCapXml', [machCapXml('Draft')]) === null);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
