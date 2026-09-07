<?php

/**
 * Prüfstand für den Schweizerischen Erdbebendienst (SED, ETH Zürich) --
 * Dietmars Wunsch 07.09.2026. Die Fixture unten ist eine wörtliche
 * Teilmenge einer echten, live abgerufenen Antwort (07.09.2026,
 * eida.ethz.ch/fdsnws/event/1/query?format=text) -- keine erfundenen
 * Werte, u. a. zwei echte Beben bei Bourg-Saint-Pierre VS vom 06.09.2026.
 * Kein Netzzugriff nötig für diesen Test (siehe .tools/test-live-fetch.php
 * für den echten Live-Abruf).
 *
 *   php .tools/test-sed-erdbeben-ch.php    # 0 = alle Prüfungen bestanden
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

$hub = new WarnHub();
$hub->Create();

echo "== sedMagnitudeSeverity(): eigene Stufung, keine amtliche Klassifikation ==\n";
check('Magnitude 2.5 (Untergrenze) -> Minor', callPrivate($hub, 'sedMagnitudeSeverity', [2.5])['severity'] === 'Minor');
check('Magnitude 3.4 -> weiterhin Minor', callPrivate($hub, 'sedMagnitudeSeverity', [3.4])['severity'] === 'Minor');
check('Magnitude 3.5 -> Moderate', callPrivate($hub, 'sedMagnitudeSeverity', [3.5])['severity'] === 'Moderate');
check('Magnitude 4.5 -> Severe', callPrivate($hub, 'sedMagnitudeSeverity', [4.5])['severity'] === 'Severe');
check('Magnitude 6.0 -> Extreme', callPrivate($hub, 'sedMagnitudeSeverity', [6.0])['severity'] === 'Extreme');
check('Radius wächst mit dem Schweregrad (15/30/60/120 km)', callPrivate($hub, 'sedMagnitudeSeverity', [2.5])['radiusKm'] === 15.0
    && callPrivate($hub, 'sedMagnitudeSeverity', [3.5])['radiusKm'] === 30.0
    && callPrivate($hub, 'sedMagnitudeSeverity', [4.5])['radiusKm'] === 60.0
    && callPrivate($hub, 'sedMagnitudeSeverity', [6.0])['radiusKm'] === 120.0);

echo "\n== parseSedResponse(): echte SED-Antwort (live abgerufen 07.09.2026) ==\n";
$fixture = <<<TXT
#EventID|Time|Latitude|Longitude|Depth/km|Author|Catalog|Contributor|ContributorID|MagType|Magnitude|MagAuthor|EventLocationName|EventType
smi:ch.ethz.sed/sc25a/Event/2026rqydud|2026-09-06T11:21:25.019679|45.92005896379557|7.041901354583593|7.384667968750002|toledo@sc25ag||SED|smi:ch.ethz.sed/sc25a/Event/2026rqydud|MLhc|1.5333786921733654|toledo@sc25ag|Bourg-Saint-Pierre VS|earthquake
smi:ch.ethz.sed/sc25a/Event/2026rqsxsc|2026-09-06T08:44:28.561823|45.92077524427154|7.044335594218195|6.799755859375003|toledo@sc25ag||SED|smi:ch.ethz.sed/sc25a/Event/2026rqsxsc|MLhc|2.2258464614830746|toledo@sc25ag|Bourg-Saint-Pierre VS|earthquake
smi:ch.ethz.sed/sc25a/Event/2026rpiofk|2026-09-05T14:34:00.947621|46.7045907365499|10.2726117690924|6.654736328125002|toledo@sc25ag||SED|smi:ch.ethz.sed/sc25a/Event/2026rpiofk|MLhc|2.0643050618972603|toledo@sc25ag|Scuol GR|earthquake
TXT;
$result = callPrivate($hub, 'parseSedResponse', [$fixture]);
check('liefert genau 3 Ereignisse', count($result) === 3);
check('identifier ist stabil, an die echte EventID gebunden', $result[0]['identifier'] === 'sed-smi:ch.ethz.sed/sc25a/Event/2026rqydud');
check('source ist "sed_ch"', $result[0]['source'] === 'sed_ch');
check('event ist "Erdbeben"', $result[0]['event'] === 'Erdbeben');
check('headline nennt Magnitude und Ort (Bourg-Saint-Pierre VS)', str_contains($result[0]['headline'], '1.5') && str_contains($result[0]['headline'], 'Bourg-Saint-Pierre VS'));
check('Magnitude 1.5 liegt UNTER der Meldeschwelle 2.5 -- wird trotzdem geparst (Filterung passiert live per minmagnitude-Parameter, nicht hier)', $result[0]['severity'] === 'Minor');
check('Magnitude 2.2 (Ereignis 2) -> ebenfalls Minor', $result[1]['severity'] === 'Minor');
check('areaDesc übernimmt den Ortsnamen', $result[2]['areaDesc'] === 'Scuol GR');
check('KEIN Polygon -- ein Kreis um das Epizentrum, Radius aus sedMagnitudeSeverity()', $result[0]['rings'] === [] && count($result[0]['circles']) === 1 && $result[0]['circles'][0]['radiusKm'] === 15.0);
check('Kreis-Koordinaten entsprechen Latitude/Longitude aus der Antwort', abs($result[0]['circles'][0]['lat'] - 45.92005896379557) < 0.0001 && abs($result[0]['circles'][0]['lon'] - 7.041901354583593) < 0.0001);
check('expires liegt SED_ACTIVE_SECONDS (2 Std.) nach dem Ereigniszeitpunkt', strtotime($result[0]['expires']) - strtotime($result[0]['effective']) === 7200);
check('effective/onset entsprechen dem Ereigniszeitpunkt (Erdbeben = Momentereignis, kein Vorlauf)', $result[0]['effective'] === $result[0]['onset']);

echo "\n== parseSedResponse(): robust gegen unerwartete Zeilen ==\n";
$resultEmpty = callPrivate($hub, 'parseSedResponse', ["#Kopfzeile\n"]);
check('nur Kopfzeile -> leeres Ergebnis, kein Fehler', $resultEmpty === []);
$resultLeer = callPrivate($hub, 'parseSedResponse', [""]);
check('leerer Body -> leeres Ergebnis', $resultLeer === []);
$fixtureAndernEventType = "#Kopf\nsmi:x|2026-09-06T11:21:25|45.9|7.0|5.0|A||SED|x|MLhc|3.0|A|Ort|explosion\n";
check('EventType ungleich "earthquake" (z. B. Sprengung) wird ignoriert', callPrivate($hub, 'parseSedResponse', [$fixtureAndernEventType]) === []);
$fixtureZuKurz = "#Kopf\nzu|wenig|spalten\n";
check('Zeile mit zu wenigen Spalten wird übersprungen statt zu crashen', callPrivate($hub, 'parseSedResponse', [$fixtureZuKurz]) === []);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
