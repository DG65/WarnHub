<?php

/**
 * Prüfstand für die beiden am 04.09.2026 ergänzten WebFront-Kacheln
 * (renderKachelStatus()/renderKachelUebersicht(), Variablen KachelStatus/
 * KachelUebersicht, Profil ~HTMLBox) -- Dietmars ausdrücklicher Wunsch
 * ("eine oder auch mehrere Kacheln", macOS-Tahoe-Optik). Prüft nur die
 * erzeugte HTML-Struktur/den Inhalt (Escaping, Farb-/Icon-Zuordnung,
 * Deckelung), NICHT das tatsächliche Rendering in einem echten WebFront --
 * das lässt sich ohne echtes System nicht gegenprüfen.
 *
 *   php .tools/test-tiles.php    # 0 = alle Prüfungen bestanden
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
    public function ApplyChanges()
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

echo "== renderKachelStatus()/renderKachelUebersicht(): leerer Zustand (keine aktive Warnung) ==\n";
$statusEmpty = callPrivate($hub, 'renderKachelStatus', [[], 1700000000]);
check('kompakte Kachel: enthält den grünen "Keine aktive Warnung"-Text', str_contains($statusEmpty, 'Keine aktive Warnung'));
check('kompakte Kachel: nutzt das ✅-Icon', str_contains($statusEmpty, '✅'));
check('kompakte Kachel: eigener <style>-Block eingebettet (eigenständig, kein externes CSS nötig)', str_contains($statusEmpty, '<style>'));
check('kompakte Kachel: macOS-"Liquid Glass"-Merkmal backdrop-filter vorhanden', str_contains($statusEmpty, 'backdrop-filter'));
check('kompakte Kachel: hell/dunkel-adaptiv (prefers-color-scheme)', str_contains($statusEmpty, 'prefers-color-scheme'));

$uebersichtEmpty = callPrivate($hub, 'renderKachelUebersicht', [[], 1700000000]);
check('Übersichts-Kachel: zeigt denselben leeren Zustand (whub-empty)', str_contains($uebersichtEmpty, 'whub-empty'));
check('Übersichts-Kachel: enthält den Zeitstempel (17:00 Uhr aus dem Test-Unix-Timestamp)', str_contains($uebersichtEmpty, date('H:i', 1700000000) . ' Uhr'));

echo "\n== renderKachelUebersicht(): aktive Warnungen als Karten, HTML-Escaping gegen Injection ==\n";
$active = [
    [
        'identifier' => 'w1', 'standort' => 'Zuhause <script>alert(1)</script>', 'event' => 'Sturm', 'headline' => 'Sturmböen',
        'severity' => 'Extreme', 'category' => 'sturm', 'source' => 'test', 'expires' => date('c', 1700003600),
    ],
    [
        'identifier' => 'w2', 'standort' => 'Zweitwohnsitz "Bergen"', 'event' => 'hagel', 'headline' => 'Hagel',
        'severity' => 'Moderate', 'category' => 'hagel', 'source' => 'test', 'expires' => null,
    ],
];
$html = callPrivate($hub, 'renderKachelUebersicht', [$active, 1700000000]);
check('Standort-Name wird HTML-escaped (kein <script> im Rohtext)', !str_contains($html, '<script>alert(1)</script>'));
check('Standort-Name-Anführungszeichen werden escaped (&quot;)', str_contains($html, '&quot;Bergen&quot;') || str_contains($html, '&#034;Bergen&#034;'));
check('Ereignis "hagel" wird für die Anzeige groß geschrieben ("Hagel")', str_contains($html, 'Hagel'));
check('beide Karten sind enthalten (2× whub-card)', substr_count($html, 'class="whub-card-title"') === 2);
check('Extreme-Warnung nutzt das 🆘-Icon', str_contains($html, '🆘'));
check('Moderate-Warnung nutzt das ⚠️-Icon', str_contains($html, '⚠️'));
check('Karte mit Gültigkeitsende zeigt "bis HH:MM Uhr"', str_contains($html, 'bis ' . date('H:i', 1700003600) . ' Uhr'));

echo "\n== renderKachelUebersicht(): Deckelung bei 8 Karten ==\n";
$viele = [];
for ($i = 0; $i < 12; $i++) {
    $viele[] = ['identifier' => 'w' . $i, 'standort' => 'Standort ' . $i, 'event' => 'Sturm', 'headline' => '', 'severity' => 'Moderate', 'category' => 'sturm', 'source' => 'test', 'expires' => null];
}
$htmlViele = callPrivate($hub, 'renderKachelUebersicht', [$viele, 1700000000]);
check('maximal 8 Karten werden tatsächlich gerendert', substr_count($htmlViele, 'class="whub-card-title"') === 8);
check('Hinweis auf die restlichen 4 wird angezeigt ("+4 weitere")', str_contains($htmlViele, '+4 weitere'));

echo "\n== renderKachelStatus(): Schweregrad-Icon/Text je nach höchster aktiver Warnung ==\n";
$statusSevere = callPrivate($hub, 'renderKachelStatus', [[
    ['identifier' => 'w1', 'severity' => 'Moderate'],
    ['identifier' => 'w2', 'severity' => 'Severe'],
], 1700000000]);
check('nennt die Gesamtzahl (2 aktive Warnungen)', str_contains($statusSevere, '2 aktive Warnungen'));
check('nutzt das Icon des HÖCHSTEN Schweregrads (🚨 für Severe, nicht ⚠️ für Moderate)', str_contains($statusSevere, '🚨'));

$statusOne = callPrivate($hub, 'renderKachelStatus', [[['identifier' => 'w1', 'severity' => 'Minor']], 1700000000]);
check('bei genau 1 aktiver Warnung: Singular "1 aktive Warnung" statt "1 aktive Warnungen"', str_contains($statusOne, '1 aktive Warnung') && !str_contains($statusOne, '1 aktive Warnungen'));

echo "\n== renderKachelStatus(): absolute Uhrzeit statt (kaputter) Relativzeit ==\n";
// Fix Praxis-Fund ralf, Symcon-Forum 05.09.2026: relativeMinutesText() wurde
// IMMER im selben Poll()-Durchlauf berechnet, der LastPollTs erst auf
// time() gesetzt hat -- die Kachel zeigte dadurch bis zum nächsten Poll
// fälschlich dauerhaft "gerade eben geprüft". Jetzt absolute Uhrzeit wie
// die Übersichts-Kachel.
check('zeigt die absolute Uhrzeit (17:00 aus dem Test-Unix-Timestamp), nicht "gerade eben"', str_contains($statusOne, date('H:i', 1700000000) . ' Uhr geprüft'));
$statusNie = callPrivate($hub, 'renderKachelStatus', [[], 0]);
check('Zeitstempel 0 (nie geprüft) -> "noch nie geprüft"', str_contains($statusNie, 'noch nie geprüft'));

echo "\n== hexToRgba(): korrekte Umrechnung für die Icon-Hintergrundfarbe ==\n";
check('#FF453A (systemRed) -> rgba(255,69,58,0.18)', callPrivate($hub, 'hexToRgba', ['#FF453A', 0.18]) === 'rgba(255,69,58,0.18)');

echo "\n== officialMapLinksHtml(): Links zu den drei amtlichen Warnkarten (Dietmars Wunsch 06.09.2026) ==\n";
$mapLinks = callPrivate($hub, 'officialMapLinksHtml', []);
check('enthält den DWD-Link', str_contains($mapLinks, 'dwd.de'));
check('enthält den ZAMG-Link', str_contains($mapLinks, 'zamg.at'));
check('enthält den MeteoSchweiz-Link', str_contains($mapLinks, 'meteoswiss.admin.ch'));
check('öffnet extern (target="_blank", rel="noopener" -- kein iframe, DWD/MeteoSchweiz sperren das per X-Frame-Options)', substr_count($mapLinks, 'target="_blank"') === 3 && substr_count($mapLinks, 'rel="noopener"') === 3);
check('Kachel (kompakt) enthält die Kartenlinks', str_contains($statusOne, 'whub-maplinks') && str_contains($statusOne, 'zamg.at'));
check('Kachel (Übersicht) enthält die Kartenlinks', str_contains($uebersichtEmpty, 'whub-maplinks') && str_contains($uebersichtEmpty, 'dwd.de'));

echo "\n== renderKachelKarte(): Standort-zentrierte OpenStreetMap-Kachel ==\n";
$karteLeer = callPrivate($hub, 'renderKachelKarte', [[], [], false]);
check('ohne ausgewählten Standort: Hinweistext statt Karte', str_contains($karteLeer, 'Kein Standort'));
check('ohne ausgewählten Standort: kein Leaflet-Skript geladen', !str_contains($karteLeer, 'leaflet'));

$standort = ['Name' => 'Zuhause "Test"', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0, 'RadiusKm' => 10, 'MinSeverity' => 2, 'PushZielFilter' => '', 'Aktiv' => true];
$karte = callPrivate($hub, 'renderKachelKarte', [$standort, [['identifier' => 'w1', 'severity' => 'Severe']], false]);
check('lädt Leaflet.js von unpkg.com', str_contains($karte, 'unpkg.com/leaflet'));
check('zentriert auf die Standort-Koordinaten (48.478500, 7.944800)', str_contains($karte, '48.478500, 7.944800'));
check('Marker-Farbe folgt dem höchsten aktiven Schweregrad (TILE_SEVERITY_COLOR[Severe])', str_contains($karte, "color:'#FF9F0A'"));
check('Standort-Name als sicherer JSON-String im Tooltip (Anführungszeichen im Namen escaped, kein rohes ")', str_contains($karte, 'bindTooltip(') && !str_contains($karte, 'bindTooltip("Zuhause "Test""'));

$karteRuhig = callPrivate($hub, 'renderKachelKarte', [$standort, [], false]);
check('keine aktive Warnung -> grüne Markerfarbe (TILE_COLOR_OK)', str_contains($karteRuhig, "color:'#30D158'"));

echo "\n== renderKachelZamg(): eingebettete ZAMG-Warnkarte (nur Österreich, Dietmars Wunsch 06.09.2026) ==\n";
$zamg = callPrivate($hub, 'renderKachelZamg', []);
check('bettet die ZAMG-URL als iframe ein', str_contains($zamg, '<iframe') && str_contains($zamg, 'warnungen.zamg.at'));

echo "\n== findStandortByName(): Standort-Auflösung für die Karten-Kachel-Auswahl ==\n";
$hub->SetProp('Standorte', json_encode([
    ['Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0, 'RadiusKm' => 10, 'MinSeverity' => 2, 'PushZielFilter' => '', 'Aktiv' => true],
]));
check('findet einen konfigurierten Standort per Namen', callPrivate($hub, 'findStandortByName', ['Zuhause'])['Name'] === 'Zuhause');
check('leerer Name -> [] (keine Auswahl getroffen)', callPrivate($hub, 'findStandortByName', ['']) === []);
check('unbekannter Name -> [] (z. B. gelöschter Standort)', callPrivate($hub, 'findStandortByName', ['Nicht vorhanden']) === []);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
