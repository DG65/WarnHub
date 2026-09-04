<?php

/**
 * Integrationstest gegen die ECHTEN Live-APIs (NINA + DWD-CAP) -- kein
 * IPS-System noetig. Ruft die private Abruf-/Matching-Logik per Reflection
 * auf einem leichtgewichtigen IPSModule-Stub auf (Muster: MeterHub
 * .tools/test-*.php) und prueft, dass mindestens EINE reale, aktuell aktive
 * Warnung korrekt geparst und einem nahegelegenen Test-Standort zugeordnet
 * wird. Braucht Internetzugang -- kein Teil der CI (siehe check-style.yml),
 * manuell laufen lassen:
 *
 *   php .tools/test-live-fetch.php
 */

// Steuerbare Stubs fuer die Standort-Herkunfts-Tests (Block "Symcon-Systemstandort" unten).
$GLOBALS['whub_test_instancesByModule'] = [];
$GLOBALS['whub_test_properties'] = [];
$GLOBALS['whub_test_kernelVersion'] = 9.0;

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return $GLOBALS['whub_test_instancesByModule'][$guid] ?? [];
}
function IPS_GetKernelVersion(): float
{
    return $GLOBALS['whub_test_kernelVersion'];
}
function IPS_GetProperty(int $id, string $name)
{
    return $GLOBALS['whub_test_properties'][$id][$name] ?? '';
}

class IPSModule
{
    public int $InstanceID = 999999;
    protected array $props = [];
    protected array $attrs = [];

    public function RegisterPropertyString(string $n, string $v): void
    {
        $this->props[$n] ??= $v;
    }
    public function RegisterPropertyBoolean(string $n, bool $v): void
    {
        $this->props[$n] ??= $v;
    }
    public function RegisterPropertyInteger(string $n, int $v): void
    {
        $this->props[$n] ??= $v;
    }
    public function RegisterPropertyFloat(string $n, float $v): void
    {
        $this->props[$n] ??= $v;
    }
    public function ReadPropertyFloat(string $n): float
    {
        return (float) ($this->props[$n] ?? 0);
    }
    public function ReadPropertyString(string $n): string
    {
        return (string) ($this->props[$n] ?? '');
    }
    public function ReadPropertyBoolean(string $n): bool
    {
        return (bool) ($this->props[$n] ?? false);
    }
    public function ReadPropertyInteger(string $n): int
    {
        return (int) ($this->props[$n] ?? 0);
    }
    public function SetProp(string $n, $v): void
    {
        $this->props[$n] = $v;
    }

    public function RegisterAttributeString(string $n, string $v): void
    {
        $this->attrs[$n] ??= $v;
    }
    public function RegisterAttributeBoolean(string $n, bool $v): void
    {
        $this->attrs[$n] ??= $v;
    }
    public function RegisterAttributeInteger(string $n, int $v): void
    {
        $this->attrs[$n] ??= $v;
    }
    public function ReadAttributeString(string $n): string
    {
        return (string) ($this->attrs[$n] ?? '');
    }
    public function ReadAttributeInteger(string $n): int
    {
        return (int) ($this->attrs[$n] ?? 0);
    }
    public function ReadAttributeBoolean(string $n): bool
    {
        return (bool) ($this->attrs[$n] ?? false);
    }
    public function WriteAttributeString(string $n, string $v): void
    {
        $this->attrs[$n] = $v;
    }
    public function WriteAttributeInteger(string $n, int $v): void
    {
        $this->attrs[$n] = $v;
    }
    public function WriteAttributeBoolean(string $n, bool $v): void
    {
        $this->attrs[$n] = $v;
    }

    public function RegisterTimer(string $n, int $ms, string $code): void
    {
    }
    public function SetTimerInterval(string $n, int $ms): void
    {
    }
    public function SetStatus(int $code): void
    {
    }
    public function SendDebug(string $sender, string $msg, int $fmt): void
    {
        if (getenv('WHUB_TEST_VERBOSE')) {
            fwrite(STDERR, "[DEBUG $sender] $msg\n");
        }
    }
    public function UpdateFormField(string $n, string $k, $v): void
    {
    }
    public function Create()
    {
    }
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
$hub->SetProp('QuelleNina', true);
$hub->SetProp('QuelleDwd', true);

echo "== Live-Abruf NINA ohne dwd-Kanal (warnung.bund.de) ==\n";
$ninaChannels = array_diff(['mowas', 'katwarn', 'biwapp', 'dwd', 'lhp', 'police'], ['dwd']);
$ninaWarnings = callPrivate($hub, 'fetchNina', [$ninaChannels]);
check('mindestens eine NINA-Warnung wurde geladen (' . count($ninaWarnings) . ')', count($ninaWarnings) > 0);
check('kein "dwd"-Kanal in den geladenen NINA-Warnungen (Vermeidung der DWD-Dopplung)', count(array_filter($ninaWarnings, fn ($w) => $w['channel'] === 'dwd')) === 0);
if (count($ninaWarnings) > 0) {
    $w = $ninaWarnings[0];
    foreach (['identifier', 'source', 'msgType', 'event', 'headline', 'severity', 'rings', 'circles'] as $field) {
        check("erste Warnung hat Feld '$field'", array_key_exists($field, $w));
    }
}

echo "== Live-Abruf DWD-CAP (opendata.dwd.de) ==\n";
$dwdWarnings = callPrivate($hub, 'fetchDwdCap');
check('mindestens eine DWD-CAP-Warnung wurde geladen (' . count($dwdWarnings) . ')', count($dwdWarnings) > 0);
if (count($dwdWarnings) > 0) {
    $withPolygon = array_filter($dwdWarnings, fn ($w) => count($w['rings']) > 0);
    check('mindestens eine DWD-CAP-Warnung trägt ein Polygon (' . count($withPolygon) . ' von ' . count($dwdWarnings) . ')', count($withPolygon) > 0);
}

$merged = array_merge($ninaWarnings, $dwdWarnings);

echo "== Ende-zu-Ende-Matching gegen einen Standort in einem betroffenen Gebiet ==\n";
// Wernigerode lag zum Zeitpunkt der Recherche (03./04.09.2026) in einer aktiven
// Orkanböen-Warnung (Severe) -- als Standort mit großzügigem Umkreis genutzt,
// damit der Test robust bleibt, auch wenn sich das exakte Warngebiet seither
// leicht verschoben hat. Ergebnis ist informativ, kein harter Fehlschlag,
// falls die reale Wetterlage sich inzwischen beruhigt hat.
$standorte = [[
    'Name' => 'Testort',
    'Ort' => 'Wernigerode',
    'Lat' => 51.8272,
    'Lon' => 10.7865,
    'RadiusKm' => 30.0,
    'MinSeverity' => 1,
    'Aktiv' => true,
]];
$hub->SetProp('Standorte', json_encode($standorte));
$hub->SetProp('PushAktiv', false); // kein echter Push in diesem Test

$result = callPrivate($hub, 'processWarnings', [$merged]);
check('processWarnings liefert eine strukturierte Zusammenfassung', isset($result['active'], $result['activeCount']));
if ($result['activeCount'] > 0) {
    echo "  Info: {$result['activeCount']} aktive Warnung(en) für Wernigerode gefunden:\n";
    foreach ($result['active'] as $a) {
        echo "    - [{$a['severity']}] {$a['event']} ({$a['source']}, " . ($a['distanceKm'] ?? '?') . " km)\n";
    }
} else {
    echo "  Info: aktuell keine passende aktive Warnung für Wernigerode (Wetterlage kann sich geändert haben) -- kein Fehlschlag.\n";
}

echo "== Symcon-Systemstandort (Location Control) -- gestubbt, kein Netz nötig ==\n";
const LOC_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

// Fall 1: keine Standort-Instanz vorhanden
$GLOBALS['whub_test_instancesByModule'] = [];
check('kein Standort-Instanz -> AddStandortFromSystemLocation meldet Fehlschlag',
    str_starts_with(callPrivate($hub, 'AddStandortFromSystemLocation'), '⚠️'));

// Fall 2: Standort-Instanz vorhanden, aber nicht konfiguriert (0/0)
$GLOBALS['whub_test_instancesByModule'] = [LOC_GUID => [55555]];
$GLOBALS['whub_test_properties'] = [55555 => ['Location' => json_encode(['latitude' => 0, 'longitude' => 0])]];
check('Standort-Instanz mit 0/0 -> ebenfalls Fehlschlag (nicht konfiguriert)',
    str_starts_with(callPrivate($hub, 'AddStandortFromSystemLocation'), '⚠️'));

// Fall 3: echte Koordinaten (Symcon >= 5.0, kombinierte Location-Property)
$GLOBALS['whub_test_properties'] = [55555 => ['Location' => json_encode(['latitude' => 48.4700, 'longitude' => 7.9400])]];
$hub->SetProp('Standorte', '[]');
$msg = callPrivate($hub, 'AddStandortFromSystemLocation');
check('echte Koordinaten -> Erfolgsmeldung', str_starts_with($msg, '✅'));
$rowsAfter = json_decode($hub->ReadPropertyString('Standorte') === '[]' ? '[]' : $hub->ReadPropertyString('Standorte'), true);
// AddStandortFromSystemLocation schreibt bewusst NUR in die offene Formularmaske
// (UpdateFormField), nicht in die Property -- die Property bleibt bis "Übernehmen" unverändert.
check('Property bleibt bis "Übernehmen" unverändert (schreibt nur in die offene Maske)', $hub->ReadPropertyString('Standorte') === '[]');

// Fall 4: alte Symcon-Version (<5.0) mit getrennten Latitude/Longitude-Properties
$GLOBALS['whub_test_kernelVersion'] = 4.4;
$GLOBALS['whub_test_properties'] = [55555 => ['Latitude' => 52.5200, 'Longitude' => 13.4050]];
$msg2 = callPrivate($hub, 'AddStandortFromSystemLocation');
check('Fallback für Symcon < 5.0 (getrennte Latitude/Longitude) funktioniert', str_starts_with($msg2, '✅') && str_contains($msg2, '52.52'));
$GLOBALS['whub_test_kernelVersion'] = 9.0;

echo "== Live-Abruf PEGELONLINE (wsv.de) ==\n";
$pegelWarnings = callPrivate($hub, 'fetchPegelonline');
check('fetchPegelonline() liefert ein Array (auch bei 0 aktuell erhöhten Pegeln kein Fehler)', is_array($pegelWarnings));
echo "  Info: " . count($pegelWarnings) . " Pegel aktuell über MHW/HSW.\n";
if (count($pegelWarnings) > 0) {
    $w = $pegelWarnings[0];
    foreach (['identifier', 'source', 'msgType', 'event', 'headline', 'severity', 'circles'] as $field) {
        check("erste Pegel-Warnung hat Feld '$field'", array_key_exists($field, $w));
    }
    check('erste Pegel-Warnung hat genau einen Kreis (Stationsposition)', count($w['circles']) === 1);
    check('Pegel-Warnung klassifiziert automatisch als Kategorie "starkregen" (Schlüsselwort "Hochwasser")', callPrivate($hub, 'classifyEventCategory', [$w['event'], $w['headline']]) === 'starkregen');
}

echo "== Live-Abruf BfS ODL-Info (imis.bfs.de) ==\n";
$hub->SetProp('BfsOdlSchwellwert', 0.3);
$odlWarnings = callPrivate($hub, 'fetchBfsOdl');
check('fetchBfsOdl() liefert ein Array (auch bei 0 Überschreitungen kein Fehler)', is_array($odlWarnings));
echo "  Info: " . count($odlWarnings) . " Messstellen aktuell über dem Schwellwert 0,3 µSv/h.\n";
if (count($odlWarnings) > 0) {
    $w = $odlWarnings[0];
    foreach (['identifier', 'source', 'msgType', 'event', 'headline', 'severity', 'circles'] as $field) {
        check("erste ODL-Warnung hat Feld '$field'", array_key_exists($field, $w));
    }
    check('erste ODL-Warnung hat Severity "Severe"', $w['severity'] === 'Severe');
}

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
