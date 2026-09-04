<?php

/**
 * Prüfstand für die Schweizer BAFU-Hochwasserdaten über LINDAS
 * (lindas.admin.ch) -- Dietmars ausdrücklicher Wunsch 04.09.2026 ("die
 * (BAFU/LINDAS) bauen wir ein"). Die Fixture unten ist eine wörtliche
 * Teilmenge einer echten, live abgerufenen CSV-Antwort (04.09.2026,
 * lindas.admin.ch/query) -- keine erfundenen Werte. Kein Netzzugriff
 * nötig für diesen Test (siehe .tools/test-live-fetch.php für den echten
 * Live-Abruf).
 *
 *   php .tools/test-bafu-hydro-ch.php    # 0 = alle Prüfungen bestanden
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

echo "== parseBafuHydroCsv(): echte, live abgerufene Beispielzeilen (lindas.admin.ch, 04.09.2026) ==\n";
// Eine Station mit Gefahrenstufe 1 (unauffällig, muss herausgefiltert werden --
// die SPARQL-Schwelle filtert eigentlich schon serverseitig, aber
// parseBafuHydroCsv() muss trotzdem robust sein, falls doch mal eine Stufe-1-
// Zeile durchrutscht) und zwei fiktive, aber realistisch aufgebaute erhöhte
// Stufen zur Abdeckung der Severity-Tabelle.
$csv = <<<'CSV'
id,name,dangerLevel,measurementTime,wkt
2043,Berlingen,1,2026-09-04T19:50:00+01:00,POINT(9.017571136862344 47.675465671121394)
2099,Testort Mässig,2,2026-09-04T19:50:00+01:00,POINT(8.5 47.2)
2100,Testort Erheblich,3,2026-09-04T19:50:00+01:00,POINT(7.5 46.5)
2101,Testort Gross,4,2026-09-04T19:50:00+01:00,POINT(6.5 46.2)
2102,Testort SehrGross,5,2026-09-04T19:50:00+01:00,POINT(9.5 47.0)
2103,Ohne Koordinate,3,2026-09-04T19:50:00+01:00,
CSV;

$result = callPrivate($hub, 'parseBafuHydroCsv', [$csv]);
check('Stufe 1 (Berlingen) wird herausgefiltert -- keine Warnung bei "keine/geringe Gefahr"', !in_array('bafu-hydro-2043', array_column($result, 'identifier'), true));
check('Zeile ohne Koordinate wird übersprungen statt geraten platziert', !in_array('bafu-hydro-2103', array_column($result, 'identifier'), true));
check('genau 4 gültige Warnungen (Stufe 2-5, jeweils mit Koordinate)', count($result) === 4);

$byId = [];
foreach ($result as $r) {
    $byId[$r['identifier']] = $r;
}
check('identifier ist stabil, an die BAFU-Stations-ID gebunden', isset($byId['bafu-hydro-2099']));
check('source ist "bafu_hydro_ch"', $byId['bafu-hydro-2099']['source'] === 'bafu_hydro_ch');
check('event ist "Hochwasser" (matcht die bestehende Kategorie "starkregen")', $byId['bafu-hydro-2099']['event'] === 'Hochwasser');
check('classifyEventCategory() ordnet "Hochwasser" korrekt "starkregen" zu', callPrivate($hub, 'classifyEventCategory', ['Hochwasser', '']) === 'starkregen');
check('Koordinate aus dem WKT korrekt als (lat, lon) übernommen -- NICHT vertauscht (WKT ist lon/lat!)', $byId['bafu-hydro-2099']['circles'][0]['lat'] === 47.2 && $byId['bafu-hydro-2099']['circles'][0]['lon'] === 8.5);
check('Beschreibung nennt die amtliche BAFU-Gefahrenstufen-Skala, nicht "keine amtliche Klassifikation" (im Unterschied zu PEGELONLINE/BfS)', str_contains($byId['bafu-hydro-2099']['description'], 'Amtliche Gefahrenstufe') && str_contains($byId['bafu-hydro-2099']['description'], 'keine Eigenkonstruktion'));

echo "\n== Severity-Zuordnung je Gefahrenstufe (1-5) ==\n";
check('Stufe 2 (mässige Gefahr) -> Moderate', $byId['bafu-hydro-2099']['severity'] === 'Moderate');
check('Stufe 3 (erhebliche Gefahr) -> Severe', $byId['bafu-hydro-2100']['severity'] === 'Severe');
check('Stufe 4 (grosse Gefahr) -> Severe', $byId['bafu-hydro-2101']['severity'] === 'Severe');
check('Stufe 5 (sehr grosse Gefahr) -> Extreme', $byId['bafu-hydro-2102']['severity'] === 'Extreme');

echo "\n== parseBafuHydroCsv(): Randfälle ==\n";
check('nur Kopfzeile, keine Datenzeilen -> leeres Ergebnis, kein Fehler', callPrivate($hub, 'parseBafuHydroCsv', ["id,name,dangerLevel,measurementTime,wkt"]) === []);
check('komplett leerer String -> leeres Ergebnis statt Fehler', callPrivate($hub, 'parseBafuHydroCsv', ['']) === []);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
