<?php

/**
 * Prüfstand für die direkte GeoSphere-Austria-Anbindung (warnungen.zamg.at)
 * -- koordinatengenaue amtliche Warnungen für Österreich, Dietmars
 * Nachfrage 04.09.2026 ("haben Österreich und die Schweiz keine eigenen
 * Warn-APIs?"). Die Fixture unten ist wörtlich das Beispiel aus der
 * offiziellen OpenAPI-Spezifikation (openapi.hub.geosphere.at/warnapi/v1/),
 * live gegengeprüft 04.09.2026 -- keine erfundenen Werte. Kein Netzzugriff
 * nötig für diesen Test (siehe .tools/test-live-fetch.php für den echten
 * Live-Abruf).
 *
 *   php .tools/test-geosphere-at.php    # 0 = alle Prüfungen bestanden
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

echo "== parseGeosphereAtResponse(): wörtliches Beispiel aus der offiziellen OpenAPI-Spezifikation ==\n";
// Quelle: openapi.hub.geosphere.at/warnapi/v1/ -- Beispielantwort von
// /getWarningsForCoords, live gegengeprüft 04.09.2026 (Schwechat, zwei
// gelbe Windwarnungen an aufeinanderfolgenden Tagen).
$fixture = [
    'type' => 'Feature',
    'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[[[640566, 472707]]]]],
    'properties' => [
        'location' => [
            'type' => 'Municipal',
            'properties' => ['gemeindenr' => 30740, 'name' => 'Schwechat', 'urlname' => 'schwechat'],
        ],
        'warnings' => [
            [
                'type' => 'Warning',
                'properties' => [
                    'warnid' => 4149, 'chgid' => 6, 'verlaufid' => 2, 'warntypid' => 1,
                    'begin' => '27.03.2023 08:00', 'end' => '27.03.2023 18:00',
                    'create' => '2023-03-27 06:00:00+00',
                    'text' => 'Gelbe Windwarnung von Mo, 27.03.2023 08:00 bis Mo, 27.03.2023 18:00',
                    'auswirkungen' => 'Äste können herabstürzen und Gegenstände herumgewirbelt werden.',
                    'empfehlungen' => 'Seien Sie in Wäldern, Parks und Alleen achtsam, rechnen Sie mit herabstürzenden Ästen!',
                    'meteotext' => 'Mit einer nordwestlichen Strömung lebt im Ostalpenraum der Nordwestwind deutlich auf.',
                    'updategrund' => '', 'warnstufeid' => 1,
                    'rawinfo' => ['wtype' => 1, 'wlevel' => 1, 'start' => '1679896800', 'end' => '1679932800'],
                ],
            ],
            [
                'type' => 'Warning',
                'properties' => [
                    'warnid' => 4150, 'chgid' => 2, 'verlaufid' => 1, 'warntypid' => 1,
                    'begin' => '28.03.2023 08:00', 'end' => '28.03.2023 18:00',
                    'create' => '2023-03-27 08:00:00+00',
                    'text' => 'Gelbe Windwarnung von Di, 28.03.2023 08:00 bis Di, 28.03.2023 18:00',
                    'auswirkungen' => 'Äste können herabstürzen und Gegenstände herumgewirbelt werden.',
                    'empfehlungen' => 'Reduzieren Sie im Straßenverkehr auf Brücken die Geschwindigkeit!',
                    'meteotext' => 'Mit einer stürmische Nordwestströmung erreichen Sturmböen etwa 60 bis 80 km/h.',
                    'updategrund' => '', 'warnstufeid' => 1,
                    'rawinfo' => ['wtype' => 1, 'wlevel' => 1, 'start' => '1679983200', 'end' => '1680019200'],
                ],
            ],
        ],
    ],
];

$result = callPrivate($hub, 'parseGeosphereAtResponse', [$fixture, 48.248611, 16.356388]);
check('liefert genau 2 Warnungen (eine je Tag)', count($result) === 2);
check('identifier ist stabil, an die warnid gebunden', $result[0]['identifier'] === 'geosphere-at-4149' && $result[1]['identifier'] === 'geosphere-at-4150');
check('source ist "geosphere_at"', $result[0]['source'] === 'geosphere_at');
check('WarnType 1 (storm) wird korrekt zu "Sturm"', $result[0]['event'] === 'Sturm');
check('WarnLevel 1 (yellow) wird korrekt zu "Moderate"', $result[0]['severity'] === 'Moderate');
check('headline übernimmt den fertigen Text der API', $result[0]['headline'] === 'Gelbe Windwarnung von Mo, 27.03.2023 08:00 bis Mo, 27.03.2023 18:00');
check('description kombiniert Auswirkungen und Empfehlungen', str_contains($result[0]['description'], 'herabstürzen') && str_contains($result[0]['description'], 'achtsam'));
check('onset wird aus dem Unix-Zeitstempel rawinfo.start korrekt umgerechnet', $result[0]['onset'] === date('c', 1679896800));
check('expires wird aus rawinfo.end korrekt umgerechnet', $result[0]['expires'] === date('c', 1679932800));
check('areaDesc übernimmt den Gemeindenamen', $result[0]['areaDesc'] === 'Schwechat');
check('KEIN Polygon -- stattdessen ein winziger Kreis exakt an der abgefragten Koordinate', $result[0]['rings'] === [] && $result[0]['circles'] === [['lat' => 48.248611, 'lon' => 16.356388, 'radiusKm' => 5.0]]);
check('classifyEventCategory() ordnet "Sturm" korrekt der Kategorie "sturm" zu', callPrivate($hub, 'classifyEventCategory', [$result[0]['event'], $result[0]['headline']]) === 'sturm');

echo "\n== parseGeosphereAtResponse(): weitere WarnType-/WarnLevel-Codes ==\n";
$makeFixture = function (int $wtype, int $wlevel): array {
    return [
        'properties' => [
            'location' => ['properties' => ['name' => 'Testgemeinde']],
            'warnings' => [[
                'properties' => [
                    'warnid' => 9999, 'text' => 'Testwarnung', 'auswirkungen' => '', 'empfehlungen' => '',
                    'warnstufeid' => $wlevel,
                    'rawinfo' => ['wtype' => $wtype, 'wlevel' => $wlevel, 'start' => 1700000000, 'end' => 1700003600],
                ],
            ]],
        ],
    ];
};
check('WarnType 2 (rain) -> "Starkregen" (matcht die Kategorie "starkregen", nicht nur "Regen")', callPrivate($hub, 'parseGeosphereAtResponse', [$makeFixture(2, 2), 47.0, 13.0])[0]['event'] === 'Starkregen');
check('WarnType 4 (black ice) -> "Glatteis" (matcht die Kategorie "schnee")', callPrivate($hub, 'classifyEventCategory', [callPrivate($hub, 'parseGeosphereAtResponse', [$makeFixture(4, 2), 47.0, 13.0])[0]['event'], '']) === 'schnee');
check('WarnType 5 (thunderstorm) -> "Gewitter"', callPrivate($hub, 'parseGeosphereAtResponse', [$makeFixture(5, 3), 47.0, 13.0])[0]['event'] === 'Gewitter');
check('WarnLevel 3 (red) -> "Extreme"', callPrivate($hub, 'parseGeosphereAtResponse', [$makeFixture(1, 3), 47.0, 13.0])[0]['severity'] === 'Extreme');
check('WarnLevel 2 (orange) -> "Severe"', callPrivate($hub, 'parseGeosphereAtResponse', [$makeFixture(1, 2), 47.0, 13.0])[0]['severity'] === 'Severe');

echo "\n== parseGeosphereAtResponse(): keine Warnung an dieser Koordinate ==\n";
$leer = ['properties' => ['location' => ['properties' => ['name' => 'Ruhig']], 'warnings' => []]];
check('leeres warnings-Array -> leeres Ergebnis, kein Fehler', callPrivate($hub, 'parseGeosphereAtResponse', [$leer, 47.0, 13.0]) === []);
$ohneFeld = ['properties' => ['location' => ['properties' => ['name' => 'X']]]];
check('fehlendes warnings-Feld ganz -> ebenfalls leeres Ergebnis statt Fehler', callPrivate($hub, 'parseGeosphereAtResponse', [$ohneFeld, 47.0, 13.0]) === []);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
