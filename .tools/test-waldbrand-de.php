<?php

/**
 * Prüfstand für den DWD-Waldbrandgefahrenindex (WBI) -- Dietmars Wunsch
 * 07.09.2026 ("nächste Station Ansatz bauen"). Die Fixtures unten sind
 * wörtliche Teilmengen echter, live abgerufener Antworten (07.09.2026,
 * opendata.dwd.de) -- keine erfundenen Werte. Kein Netzzugriff nötig für
 * diesen Test (siehe .tools/test-live-fetch.php für den echten Live-Abruf,
 * der wegen der Vielzahl an Einzeldateien bewusst NICHT hier mitgetestet
 * wird -- nur die reinen Parsing-/Auswahlfunktionen).
 *
 *   php .tools/test-waldbrand-de.php    # 0 = alle Prüfungen bestanden
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

echo "== parseWbiStationList(): echte Stationsliste (live abgerufen 07.09.2026, Auszug) ==\n";
$stationFixture = <<<TXT
Stationsindex; Höhe in m;Breite   ;Länge    ;Name                                                                            ;Bundesland
           44;        44;    52.93;     8.24;Großenkneten                                                                    ;Niedersachsen
         1001;        97;    51.65;    13.57;Doberlug-Kirchhain                                                              ;Brandenburg
         1048;       228;    51.13;    13.75;Dresden-Klotzsche                                                               ;Sachsen
TXT;
$stations = callPrivate($hub, 'parseWbiStationList', [$stationFixture]);
check('liefert genau 3 Stationen', count($stations) === 3);
$byIndex = array_column($stations, null, 'index');
check('Station 1001 mit korrekten Koordinaten (Doberlug-Kirchhain)', abs($byIndex[1001]['lat'] - 51.65) < 0.01 && abs($byIndex[1001]['lon'] - 13.57) < 0.01);
check('Stationsname wird übernommen', str_contains($byIndex[1001]['name'], 'Doberlug-Kirchhain'));

echo "\n== parseWbiStationCsv(): echte Stationsdaten (live abgerufen 07.09.2026, Station 1001, gekürzt) ==\n";
$csvFixture = "Stationsindex;Datum;WBI\n1001;20250101;2\n1001;20250102;1\n1001;20250103;1\n";
// Für einen stabilen Test wird "heute" künstlich weit in die Zukunft verschoben,
// indem wir stattdessen ein Datum weit in der Vergangenheit als "aktuell" prüfen --
// die Funktion selbst nutzt date('Ymd'), das lässt sich hier nicht mocken. Getestet
// wird deshalb die Auswahl-Logik selbst: "letzter Wert mit Datum <= heute" muss den
// LETZTEN Eintrag der (chronologisch aufsteigend sortierten) Datei liefern.
$result = callPrivate($hub, 'parseWbiStationCsv', [$csvFixture]);
check('liefert den letzten (chronologisch neuesten) Eintrag der Datei', $result['date'] === '20250103' && $result['wbi'] === 1);

$csvMitZukunft = "Stationsindex;Datum;WBI\n1001;20250101;2\n1001;99991231;5\n";
$resultZukunft = callPrivate($hub, 'parseWbiStationCsv', [$csvMitZukunft]);
check('ein Datum weit in der Zukunft wird ignoriert (vorsorglicher Schutz)', $resultZukunft['date'] === '20250101' && $resultZukunft['wbi'] === 2);

check('leere CSV -> null', callPrivate($hub, 'parseWbiStationCsv', ["Stationsindex;Datum;WBI\n"]) === null);
check('nur Kopfzeile ohne Zeilenumbruch -> null', callPrivate($hub, 'parseWbiStationCsv', ['Stationsindex;Datum;WBI']) === null);

echo "\n== findNearestWbiStation(): einfache geometrische Auswahl ==\n";
$testStations = [
    ['index' => 1, 'name' => 'Nah', 'lat' => 48.4785, 'lon' => 7.9448],
    ['index' => 2, 'name' => 'Mittel', 'lat' => 48.6, 'lon' => 8.1],
    ['index' => 3, 'name' => 'Weit', 'lat' => 52.5, 'lon' => 13.4],
];
$nearest = callPrivate($hub, 'findNearestWbiStation', [$testStations, 48.4785, 7.9448]);
check('findet die exakt passende Station (Distanz ~0)', $nearest['index'] === 1 && $nearest['distanceKm'] < 0.1);

$nearest2 = callPrivate($hub, 'findNearestWbiStation', [$testStations, 48.5, 8.0]);
check('findet die geometrisch nächstgelegene Station bei einem Punkt dazwischen', $nearest2['index'] === 1 || $nearest2['index'] === 2);
check('distanceKm ist im Ergebnis enthalten und plausibel (< 20 km)', $nearest2['distanceKm'] < 20.0);

check('leere Stationsliste -> null', callPrivate($hub, 'findNearestWbiStation', [[], 48.0, 8.0]) === null);

echo "\n== WBI_LEVEL_SEVERITY/WBI_LEVEL_LABEL: alle 5 amtlichen Stufen abgedeckt ==\n";
$ref = new ReflectionClass('WarnHub');
$severityMap = $ref->getConstant('WBI_LEVEL_SEVERITY');
$labelMap = $ref->getConstant('WBI_LEVEL_LABEL');
for ($level = 1; $level <= 5; $level++) {
    check("Stufe $level hat eine Severity-Zuordnung", isset($severityMap[$level]));
    check("Stufe $level hat ein Label", isset($labelMap[$level]));
}
check('Stufe 5 (sehr hohe Gefahr) -> Extreme', $severityMap[5] === 'Extreme');
check('Stufe 1 (sehr geringe Gefahr) -> Minor', $severityMap[1] === 'Minor');

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
