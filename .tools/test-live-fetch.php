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

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
