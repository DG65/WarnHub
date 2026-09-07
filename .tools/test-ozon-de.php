<?php

/**
 * Prüfstand für die Umweltbundesamt (UBA) Ozon-Datenquelle -- dritte von
 * drei gemeinsam recherchierten neuen Datenquellen (nach Erdbeben Schweiz/
 * Waldbrandgefahrenindex Deutschland), Dietmars Wunsch 07.09.2026. Die
 * Fixtures unten sind wörtliche Teilmengen echter, live abgerufener
 * Antworten (07.09.2026, luftdaten.umweltbundesamt.de) -- keine erfundenen
 * Werte/Strukturen. Kein Netzzugriff nötig für diesen Test (siehe
 * .tools/test-live-fetch.php für den echten Live-Abruf).
 *
 *   php .tools/test-ozon-de.php    # 0 = alle Prüfungen bestanden
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

echo "== parseUbaStations(): echte Stationsliste (live abgerufen 07.09.2026, Auszug) ==\n";
$stationsFixture = json_encode([
    'request' => ['lang' => 'de'],
    'indices' => ['station id', 'station code', 'station name', 'station city', 'station synonym', 'station active from', 'station active to', 'station longitude', 'station latitude', 'network id'],
    'data' => [
        // Historische, nicht mehr aktive Station (echtes Feld "station active to" gesetzt) -- MUSS ausgeschlossen werden.
        '3' => ['3', 'DEBB003', 'Brandenburg, Gertrud-Pieter-Platz', 'Brandenburg', 'BRPI', '1991-03-01', '2001-06-07', '12.5444', '52.4121', '4'],
        // Aktive Station (echtes Feld "station active to" = null).
        '42' => ['42', 'DEBB044', 'Cottbus, Bahnhofstr.', 'Cottbus', 'COBA', '2012-09-25', null, '14.3280', '51.7563', '4'],
    ],
]);
$stations = callPrivate($hub, 'parseUbaStations', [$stationsFixture]);
check('nur die aktive Station wird übernommen (historische mit "aktiv bis" wird ausgeschlossen)', count($stations) === 1 && isset($stations['42']) && !isset($stations['3']));
check('Name/Koordinaten korrekt übernommen', $stations['42']['name'] === 'Cottbus, Bahnhofstr.' && abs($stations['42']['lat'] - 51.7563) < 0.001 && abs($stations['42']['lon'] - 14.3280) < 0.001);

echo "\n== parseUbaAirquality(): echte Luftqualitätsdaten (live abgerufen 07.09.2026, Station 31, Auszug) ==\n";
// Struktur laut offizieller Doku (github.com/bundesAPI/luftqualitaet-api):
// [Ende-Zeitstempel, Gesamtindex 0-4, Unvollständig-Flag, [Komponente, Wert, Komponenten-Index, Anteil], ...]
// Komponente 3 = Ozon (bestätigt über /components/json).
$airqualityFixture = json_encode([
    'data' => [
        '31' => [
            '2026-09-06 00:00:00' => ['2026-09-06 01:00:00', 0, 0, [3, 57, 0, '0.95'], [5, 3, 0, '0.15'], [1, 9, 0, '0.45']],
            '2026-09-07 05:00:00' => ['2026-09-07 06:00:00', 3, 0, [3, 190, 3, '3.17'], [5, 18, 0, '0.9'], [1, 12, 0, '0.6']],
        ],
        // Station ohne Ozon-Messung (nur NO2/PM10) -- muss übersprungen werden.
        '999' => [
            '2026-09-07 05:00:00' => ['2026-09-07 06:00:00', 1, 0, [5, 20, 1, '1.0']],
        ],
    ],
]);
$readings = callPrivate($hub, 'parseUbaAirquality', [$airqualityFixture]);
check('genau 1 Station mit Ozon-Wert (999 ohne Ozon wird übersprungen)', count($readings) === 1 && isset($readings['31']) && !isset($readings['999']));
check('nimmt den NEUESTEN Zeitstempel, nicht den ersten (chronologisch aufsteigende Einfügereihenfolge)', $readings['31']['time'] === '2026-09-07 05:00:00');
check('Ozonwert (190 µg/m³) und Komponenten-Index (3) korrekt aus dem richtigen Zeitstempel gelesen', $readings['31']['value'] === 190.0 && $readings['31']['index'] === 3);

echo "\n== parseUbaAirquality(): robust gegen unerwartete Strukturen ==\n";
check('leere data -> leeres Ergebnis', callPrivate($hub, 'parseUbaAirquality', [json_encode(['data' => []])]) === []);
check('kein "data"-Schlüssel -> leeres Ergebnis, kein Fehler', callPrivate($hub, 'parseUbaAirquality', [json_encode(['request' => []])]) === []);
check('ungültiges JSON -> leeres Ergebnis', callPrivate($hub, 'parseUbaAirquality', ['nicht-json']) === []);

echo "\n== UBA_LQI_SEVERITY/UBA_LQI_LABEL: alle 5 amtlichen Stufen (0-4) abgedeckt ==\n";
$ref = new ReflectionClass('WarnHub');
$severityMap = $ref->getConstant('UBA_LQI_SEVERITY');
$labelMap = $ref->getConstant('UBA_LQI_LABEL');
for ($level = 0; $level <= 4; $level++) {
    check("Stufe $level hat eine Severity-Zuordnung", isset($severityMap[$level]));
    check("Stufe $level hat ein Label", isset($labelMap[$level]));
}
check('Stufe 4 (sehr schlecht) -> Extreme', $severityMap[4] === 'Extreme');
check('Stufe 0 (sehr gut) -> Minor', $severityMap[0] === 'Minor');
check('Label 3 lautet "schlecht" (amtliche UBA-Bezeichnung)', $labelMap[3] === 'schlecht');

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
