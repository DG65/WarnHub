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
    public function LogMessage($Message, $Type)
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

echo "\n== renderKachelUebersicht(): Deckelung sortiert nach Schweregrad, statt nach Zufall der Warnungs-Reihenfolge (Dietmars Einwand 07.09.2026: 'die 10. Meldung ist die wichtigste ... kann dann ausgerechnet die nicht erreichen') ==\n";
$vieleGemischt = [];
for ($i = 0; $i < 9; $i++) {
    $vieleGemischt[] = ['identifier' => 'w' . $i, 'standort' => 'Standort ' . $i, 'event' => 'Wind', 'headline' => '', 'severity' => 'Moderate', 'category' => 'sturm', 'source' => 'test', 'expires' => null];
}
// Die WICHTIGSTE Warnung steht absichtlich ganz hinten (Position 10 von 10)
// -- ohne Sortierung würde sie hinter "+N weitere" verschwinden.
$vieleGemischt[] = ['identifier' => 'extreme', 'standort' => 'Wichtigster Standort', 'event' => 'Orkan', 'headline' => '', 'severity' => 'Extreme', 'category' => 'sturm', 'source' => 'test', 'expires' => null];
$htmlSortiert = callPrivate($hub, 'renderKachelUebersicht', [$vieleGemischt, 1700000000]);
check('die wichtigste Warnung (Extreme) ist trotz letzter Position in $active TROTZDEM unter den angezeigten 8 Karten', str_contains($htmlSortiert, 'Orkan') && str_contains($htmlSortiert, 'Wichtigster Standort'));
check('sie steht an ERSTER Stelle (vor allen Moderate-Warnungen)', strpos($htmlSortiert, 'Orkan') < strpos($htmlSortiert, 'Wind'));
check('Gesamtzahl der ausgeblendeten bleibt korrekt ("+2 weitere" -- 10 gesamt, 8 gezeigt)', str_contains($htmlSortiert, '+2 weitere'));

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

echo "\n== renderKachelAlleWarnungen(): scrollbare Liste OHNE 8er-Deckel + Ausblenden/Filter (Dietmars Einwand 07.09.2026: 'die 100. Meldung noch ansehen können') ==\n";
$alleLeer = callPrivate($hub, 'renderKachelAlleWarnungen', [[], 1700000000, false]);
check('ohne aktive Warnung: Hinweistext statt Liste', str_contains($alleLeer, 'Keine aktive Warnung'));
check('ohne aktive Warnung: keine Filterleiste/kein Ausblenden-Skript geladen', !str_contains($alleLeer, 'whub-allwarn-dismissed'));

$vieleFuerAlle = [];
for ($i = 0; $i < 12; $i++) {
    $vieleFuerAlle[] = ['identifier' => 'a' . $i, 'standort' => 'Standort ' . $i, 'event' => 'Sturm', 'headline' => '', 'severity' => 'Moderate', 'category' => 'sturm', 'source' => 'test', 'expires' => null];
}
// Wichtigste Warnung absichtlich ganz hinten UND mit einem eigenen,
// abweichenden Ereignistyp (für den Filter-Chip-Test) sowie
// Anführungszeichen im Standortnamen (Escaping-Test).
$vieleFuerAlle[] = ['identifier' => 'extreme', 'standort' => 'Zuhause "Test"', 'event' => 'Waldbrandgefahr', 'headline' => '', 'severity' => 'Extreme', 'category' => 'sonstige', 'source' => 'test', 'expires' => null];
$htmlAlle = callPrivate($hub, 'renderKachelAlleWarnungen', [$vieleFuerAlle, 1700000000, false]);
check('ALLE 13 Warnungen werden gerendert, KEIN 8er-Deckel (anders als "Kachel (Übersicht)")', substr_count($htmlAlle, 'class="whub-card-title"') === 13);
check('kein "+N weitere"-Hinweis (den gibt es nur bei der gedeckelten Kachel)', !str_contains($htmlAlle, 'weitere'));
check('nach Schweregrad sortiert -- die Extreme-Warnung steht trotz letzter Position in $active ganz oben', strpos($htmlAlle, 'Waldbrandgefahr') < strpos($htmlAlle, 'Sturm'));
check('jede Karte trägt einen stabilen data-key (identifier|standort), sicher escaped (Anführungszeichen im Namen)', str_contains($htmlAlle, 'data-key="extreme|Zuhause &quot;Test&quot;"'));
check('jede Karte trägt den Ereignistyp als data-event fürs Filtern', str_contains($htmlAlle, 'data-event="Sturm"') && str_contains($htmlAlle, 'data-event="Waldbrandgefahr"'));
check('jede Karte hat einen eigenen Ausblenden-Button', substr_count($htmlAlle, 'class="whub-card-dismiss"') === 13);
check('Filterleiste zeigt genau EINEN Chip je DISTINKTEM Ereignistyp (12x Sturm + 1x Waldbrandgefahr -> 2 Chips, nicht 13)', substr_count($htmlAlle, 'whub-map-pill" data-event=') === 2);
check('Filter-Chip-Beschriftung ("🚫 Sturm", "🚫 Waldbrandgefahr")', str_contains($htmlAlle, '🚫 Sturm') && str_contains($htmlAlle, '🚫 Waldbrandgefahr'));
check('Ausblenden/Filtern rein clientseitig in localStorage -- kein Rückkanal zu Symcon (Dietmars Entscheidung: pro Browser/Gerät, wie beim Zoom-Merken der Karte)', str_contains($htmlAlle, 'whub-allwarn-dismissed-') && str_contains($htmlAlle, 'whub-allwarn-muted-') && str_contains($htmlAlle, 'function applyVisibility'));
check('gespeicherte Ausblend-Schlüssel werden beim Rendern auf noch existierende Karten bereinigt (kein unbegrenztes Anwachsen)', str_contains($htmlAlle, 'currentKeys.indexOf(k) !== -1'));
check('"Alle wieder einblenden"-Reset vorhanden und clientseitig verdrahtet', str_contains($htmlAlle, 'Alle wieder einblenden') && str_contains($htmlAlle, 'resetBtn.addEventListener'));
// Live im Browser gefunden, bevor es ausgeliefert wurde: .whub-card und
// .whub-empty setzen selbst display:flex -- ohne eine [hidden]-Regel mit
// höherer Spezifität gewinnt das gegen das ausblendende hidden-Attribut
// (c.hidden = true), das Ausblenden hätte optisch GAR NICHTS bewirkt.
check('.whub-card[hidden]/.whub-empty[hidden] überschreiben deren eigenes display:flex -- sonst bewirkt c.hidden=true optisch nichts', str_contains($htmlAlle, '.whub-card[hidden],.whub-empty[hidden]{display:none;}'));

echo "\n== renderKachelKarte(): Übersichts-Kachel mit ALLEN aktiven Standorten + Legende (Dietmars Fund 07.09.2026: eine Instanz-Variable geht an jeden Betrachter gleich) ==\n";
$hub->SetProp('Standorte', json_encode([]));
$karteLeer = callPrivate($hub, 'renderKachelKarte', [[], false]);
check('kein aktiver Standort konfiguriert: Hinweistext statt Karte', str_contains($karteLeer, 'Kein aktiver Standort konfiguriert'));
check('kein aktiver Standort konfiguriert: kein Leaflet-Skript geladen', !str_contains($karteLeer, 'leaflet'));

$standorteMulti = [
    ['Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0, 'RadiusKm' => 10, 'MinSeverity' => 2, 'PushZielFilter' => '', 'Aktiv' => true],
    ['Name' => 'Kohlekasten "Auto"', 'Ort' => '', 'Lat' => 52.5200, 'Lon' => 13.4050, 'QuellVarLat' => 0, 'QuellVarLon' => 0, 'RadiusKm' => 10, 'MinSeverity' => 2, 'PushZielFilter' => '', 'Aktiv' => true],
    ['Name' => 'Deaktiviert', 'Ort' => '', 'Lat' => 50.0, 'Lon' => 8.0, 'QuellVarLat' => 0, 'QuellVarLon' => 0, 'RadiusKm' => 10, 'MinSeverity' => 2, 'PushZielFilter' => '', 'Aktiv' => false],
];
$hub->SetProp('Standorte', json_encode($standorteMulti));
$activeMulti = [
    ['identifier' => 'w1', 'standort' => 'Zuhause', 'severity' => 'Severe'],
    ['identifier' => 'w2', 'standort' => 'Kohlekasten "Auto"', 'severity' => 'Minor'],
];
$karte = callPrivate($hub, 'renderKachelKarte', [$activeMulti, false]);
check('lädt Leaflet.js von unpkg.com', str_contains($karte, 'unpkg.com/leaflet'));
check('lädt Kartenkacheln von Esri, NICHT von OSMs eigenen Tile-Servern (Praxis-Fund ruan/Andreas: Referer-Pflicht blockierte Firefox mit HTTP 403)', str_contains($karte, 'server.arcgisonline.com') && !str_contains($karte, 'tile.openstreetmap.org'));
check('Esri-Kachel-URL in der korrekten Reihenfolge z/y/x (nicht Leaflets übliches z/x/y)', str_contains($karte, '/tile/{z}/{y}/{x}'));
check('zeigt eine Esri-Attribution (Nutzungsbedingung, deshalb attributionControl NICHT abgeschaltet)', str_contains($karte, 'Esri'));

preg_match('/var markers = (\[.*?\]);/s', $karte, $markersMatch);
$markersDecoded = json_decode($markersMatch[1] ?? '', true);
check('Marker-JSON gefunden und gültig', is_array($markersDecoded));
check('genau die 2 AKTIVEN Standorte als Marker (der deaktivierte Standort fehlt)', count($markersDecoded) === 2);
check('Marker-Namen korrekt, auch mit Anführungszeichen im Namen (sicher JSON-escaped statt String-Interpolation)', $markersDecoded[0]['name'] === 'Zuhause' && $markersDecoded[1]['name'] === 'Kohlekasten "Auto"');
check('Marker-Koordinaten stimmen je Standort (48.4785/7.9448 bzw. 52.52/13.405)', abs($markersDecoded[0]['lat'] - 48.4785) < 0.0001 && abs($markersDecoded[1]['lon'] - 13.405) < 0.0001);
check('jeder Standort bekommt SEINE EIGENE höchste Schweregrad-Farbe, nicht die instanzweit höchste (Zuhause=Severe/orange, Kohlekasten=Minor/blau, nicht beide orange)', $markersDecoded[0]['color'] === '#FF9F0A' && $markersDecoded[1]['color'] === '#0A84FF');
check('kein Marker-Eintrag für den deaktivierten Standort "Deaktiviert"', !in_array('Deaktiviert', array_column($markersDecoded, 'name'), true));

check('Legende-Container vorhanden', str_contains($karte, 'whub-map-legend'));
check('Legende: "Alle"-Eintrag zum Zurücksetzen auf die Gesamtübersicht', str_contains($karte, "'🌍 Alle'") && str_contains($karte, 'focusAll'));
check('Legende: Klick auf einen Pin/Legenden-Eintrag fokussiert diesen Standort (fitBounds nur ohne Fokus)', str_contains($karte, 'function focusOn(name)') && str_contains($karte, 'function applyView(name, animate)') && str_contains($karte, 'fitBounds'));
check('welcher Standort fokussiert ist, wird PRO BROWSER in localStorage gemerkt (whub-map-focus-), nicht instanzweit', str_contains($karte, 'whub-map-focus-') && str_contains($karte, 'focusKey'));
check('Startansicht ohne gemerkte Wahl ist "alle Standorte" (Dietmars Wunsch 07.09.2026), kein fest konfigurierter Standard-Standort', str_contains($karte, "applyView(savedFocus, false);") && !str_contains($karte, 'KartenkachelStandort'));

check('jeder localStorage-Zugriff (Zoom UND Fokus, lesend wie schreibend) steht in try/catch (Regression aus 1.6.1/1.6.2 darf sich nicht wiederholen)', substr_count($karte, 'try {') >= 4 && substr_count($karte, 'catch (e) {}') >= 4);
check('merkt sich die Zoomstufe je Kachel in localStorage (Praxis-Fund ruan/Andreas: Zoom ging bei jedem Refresh verloren)', str_contains($karte, 'whub-map-zoom-') && str_contains($karte, "localStorage.getItem(zoomKey)") && str_contains($karte, "map.on('zoomend'"));
check('Startzoom ohne gespeicherten Wert bleibt 11 (unverändertes Standardverhalten)', str_contains($karte, 'var startZoom = 11;') && str_contains($karte, 'if (savedZoom >= 1 && savedZoom <= 19) { startZoom = savedZoom; }'));

$karteRuhig = callPrivate($hub, 'renderKachelKarte', [[], false]);
preg_match('/var markers = (\[.*?\]);/s', $karteRuhig, $markersRuhigMatch);
$markersRuhigDecoded = json_decode($markersRuhigMatch[1] ?? '', true);
check('keine aktive Warnung an einem Standort -> grüne Markerfarbe (TILE_COLOR_OK) für diesen', $markersRuhigDecoded[0]['color'] === '#30D158' && $markersRuhigDecoded[1]['color'] === '#30D158');

echo "\n== renderKachelKarte(): Höhe automatisch vs. feste Pixelzahl (Praxis-Fund kronos/Bricoleur, 07.09.2026) ==\n";
check('Standardwert (0) -> äußerer Rahmen bekommt automatische Höhe (100%)', str_contains($karte, 'whub-status" style="padding:0;overflow:hidden;display:flex;flex-direction:column;height:100%;'));
$hub->SetProp('KartenkachelHoehePx', 350);
$karteFest = callPrivate($hub, 'renderKachelKarte', [[], false]);
check('gesetzte Pixelzahl -> äußerer Rahmen bekommt feste Höhe statt 100%', str_contains($karteFest, 'whub-status" style="padding:0;overflow:hidden;display:flex;flex-direction:column;height:350px;'));
check('Legende sitzt als EIGENE ZEILE unter der Karte (flex-Kind, kein schwebendes Overlay -- verdeckt keinen Marker, kein Leaflet-Pane-Stacking-Konflikt)', str_contains($karteFest, "flex:1 1 auto;min-height:160px;") && str_contains($karteFest, 'whub-map-legend'));
$hub->SetProp('KartenkachelHoehePx', 0);

echo "\n== renderKachelZamg(): eingebettete ZAMG-Warnkarte (nur Österreich, Dietmars Wunsch 06.09.2026) ==\n";
$zamg = callPrivate($hub, 'renderKachelZamg', []);
check('bettet die ZAMG-URL als iframe ein', str_contains($zamg, '<iframe') && str_contains($zamg, 'warnungen.zamg.at'));
check('Standardwert (0) -> äußerer Rahmen bekommt automatische Höhe (100%), derselbe Fund wie bei "Kachel (Karte)" (Praxis-Fund ruan/Andreas)', str_contains($zamg, 'whub-status" style="padding:0;overflow:hidden;height:100%;'));
$hub->SetProp('ZamgKachelHoehePx', 400);
$zamgFest = callPrivate($hub, 'renderKachelZamg', []);
check('gesetzte Pixelzahl -> äußerer Rahmen bekommt feste Höhe statt 100% (eigene Property, unabhängig von KartenkachelHoehePx)', str_contains($zamgFest, 'whub-status" style="padding:0;overflow:hidden;height:400px;'));
check('iframe bleibt bei height:100% (relativ zum -- jetzt festen -- äußeren Rahmen)', str_contains($zamgFest, 'height:100%;min-height:200px;border:0'));
$hub->SetProp('ZamgKachelHoehePx', 0);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
