<?php

/**
 * Prüfstand für die BETA-Anbindung der VKF-Hagelschutz-Signalbox
 * (hagelschutz-einfach-automatisch.ch, meteo.netitservices.com) --
 * Dietmars ausdrücklicher Wunsch 04.09.2026, AUSDRÜCKLICH als Beta
 * gekennzeichnet: gebaut aus der offiziellen VKF-PDF-Doku und dem
 * Quellcode des aktiven ioBroker-Adapters ice987987/ioBroker.hagelschutz
 * (identisches Protokoll bestätigt, 04.09.2026), aber OHNE eigene
 * Signalbox nicht live gegenprüfbar. Kein Netzzugriff nötig für diesen
 * Test.
 *
 *   php .tools/test-hagelschutz-ch.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_locationInstances'] = [];
$GLOBALS['whub_test_properties'] = [];
const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

function IPS_GetInstanceListByModuleID(string $guid): array
{
    if ($guid === LOCATION_CONTROL_GUID) {
        return $GLOBALS['whub_test_locationInstances'];
    }
    return [];
}
function IPS_GetModuleList(): array
{
    return [];
}
function IPS_GetKernelVersion(): float
{
    return 9.0;
}
function IPS_GetProperty(int $id, string $name)
{
    return $GLOBALS['whub_test_properties'][$id][$name] ?? '';
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

echo "== parseHagelschutzChResponse(): currentState-Werte laut offizieller VKF-Doku ==\n";
$GLOBALS['whub_test_locationInstances'] = [900];
$GLOBALS['whub_test_properties'][900]['Location'] = json_encode(['latitude' => 47.3769, 'longitude' => 8.5417]); // Zürich

$hub = new WarnHub();
$hub->Create();

check('currentState 0 (kein Hagel) -> leeres Ergebnis', callPrivate($hub, 'parseHagelschutzChResponse', [['currentState' => 0]]) === []);

$r1 = callPrivate($hub, 'parseHagelschutzChResponse', [['currentState' => 1]]);
check('currentState 1 (Hagel) -> genau eine Warnung', count($r1) === 1);
check('source ist "hagelschutz_ch"', $r1[0]['source'] === 'hagelschutz_ch');
check('event ist "Hagel" (matcht die bestehende Kategorie "hagel")', $r1[0]['event'] === 'Hagel');
check('classifyEventCategory() ordnet korrekt "hagel" zu', callPrivate($hub, 'classifyEventCategory', [$r1[0]['event'], $r1[0]['headline']]) === 'hagel');
check('identifier ist an die eigene Instanz gebunden (nur EIN Gerät je WarnHub-Instanz möglich)', $r1[0]['identifier'] === 'hagelschutz-ch-999999');
check('Kreis liegt am Symcon-Systemstandort (Zürich)', $r1[0]['circles'][0]['lat'] === 47.3769 && $r1[0]['circles'][0]['lon'] === 8.5417);
check('headline nennt KEIN "(Testalarm)" bei echtem Alarm (state=1)', !str_contains($r1[0]['headline'], 'Testalarm'));

$r2 = callPrivate($hub, 'parseHagelschutzChResponse', [['currentState' => 2]]);
check('currentState 2 (Testalarm) wird laut Doku-Empfehlung GENAUSO wie state 1 behandelt (non-zero)', count($r2) === 1);
check('headline kennzeichnet den Testalarm ausdrücklich', str_contains($r2[0]['headline'], 'Testalarm'));

echo "\n== parseHagelschutzChResponse(): Randfälle ==\n";
check('kein JSON (null, z. B. HTTP-Fehler) -> leeres Ergebnis statt Fehler', callPrivate($hub, 'parseHagelschutzChResponse', [null]) === []);
check('JSON ohne "currentState"-Feld -> leeres Ergebnis statt Fehler', callPrivate($hub, 'parseHagelschutzChResponse', [['irgendwas' => 1]]) === []);

echo "\n== parseHagelschutzChResponse(): kein Symcon-Systemstandort konfiguriert ==\n";
$GLOBALS['whub_test_locationInstances'] = [];
$hub2 = new WarnHub();
$hub2->Create();
check('Hagel erkannt, aber ohne Systemstandort -> leeres Ergebnis (keine geratene Platzierung)', callPrivate($hub2, 'parseHagelschutzChResponse', [['currentState' => 1]]) === []);

echo "\n== fetchHagelschutzCh(): URL-Validierung (kein Abruf bei erkennbar falscher URL) ==\n";
$hub3 = new WarnHub();
$hub3->Create();
check('leere URL -> kein Abruf, leeres Ergebnis', callPrivate($hub3, 'fetchHagelschutzCh') === []);
$hub3->SetProp('HagelschutzPollUrl', 'https://example.com/nicht-die-richtige-domain');
check('URL zeigt erkennbar NICHT auf meteo.netitservices.com -> kein Abruf, leeres Ergebnis statt Fehlversuch', callPrivate($hub3, 'fetchHagelschutzCh') === []);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
