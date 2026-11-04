<?php

/**
 * Sprache und Übersetzung (seit 1.20.0): Quelltexte im Code sind englisch,
 * die deutsche Fassung steht in WarnHub/locale.json. Geprüft wird
 *   1. jeder $this->Translate('...')-Text hat eine deutsche Fassung,
 *   2. Platzhalter (%s/%d/%.1f ...) stimmen zwischen Englisch und Deutsch überein,
 *   3. Formular, Kacheln und Statuszeilen zeigen auf Englisch keine deutschen Reste
 *      (Warnungsinhalt aus deutschen Quellen ausgenommen -- der bleibt bewusst in der Sprache der Quelle),
 *   4. auf Deutsch steht das Formular weiterhin deutsch da (Stichproben).
 *
 *   php .tools/test-locale.php    # 0 = alle Prüfungen bestanden
 */

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return [];
}
function IPS_GetModuleList(): array
{
    return [];
}

class IPSModule
{
    public function Translate($t)
    {
        // Wie Symcon: Text über locale.json in die Systemsprache (Prüfstand: Deutsch, WHUB_SPRACHE=en für Englisch).
        return $GLOBALS['WHUB_LOCALE'][$GLOBALS['WHUB_SPRACHE']][$t] ?? $t;
    }
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
        $GLOBALS['whub_test_logMessageCalls'][] = [$Message, $Type];
    }
    public function UpdateFormField($n, $k, $v)
    {
        $GLOBALS['whub_test_updateFormField'][] = [$n, $k, $v];
    }
    public function SetProp($n, $v)
    {
        $this->props[$n] = $v;
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



function callPrivate(object $obj, string $method, array $args = [])
{
    $ref = new ReflectionMethod($obj, $method);
    return $ref->invokeArgs($obj, $args);
}

$GLOBALS['WHUB_LOCALE'] = json_decode((string) file_get_contents(__DIR__ . '/../WarnHub/locale.json'), true)['translations'] ?? [];
$GLOBALS['WHUB_SPRACHE'] = 'de';
$de = $GLOBALS['WHUB_LOCALE']['de'] ?? [];

echo "== locale.json ==\n";
check('locale.json ist gültiges JSON mit deutscher Tabelle (> 300 Einträge)', is_array($de) && count($de) > 300);
check('locale.json ist UTF-8 ohne BOM', substr((string) file_get_contents(__DIR__ . '/../WarnHub/locale.json'), 0, 3) !== "\xEF\xBB\xBF");

// Alle Translate('...')-Literale aus module.php (Token-basiert, wie RoboHub).
$tokens = token_get_all((string) file_get_contents(__DIR__ . '/../WarnHub/module.php'));
$literale = [];
for ($i = 0; $i < count($tokens) - 3; $i++) {
    if (is_array($tokens[$i]) && $tokens[$i][1] === 'Translate' && $tokens[$i + 1] === '(' && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING) {
        $roh = $tokens[$i + 2][1];
        $literale[] = $roh[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($roh, 1, -1)) : stripcslashes(substr($roh, 1, -1));
    }
}
$literale = array_values(array_unique($literale));
$fehlt = array_values(array_filter($literale, fn ($t) => !isset($de[$t])));
check('jeder Translate()-Text hat eine deutsche Fassung (' . count($literale) . ' Texte)', $fehlt === []);
if ($fehlt !== []) {
    echo '      fehlt: ' . implode(' | ', array_map(fn ($t) => mb_substr($t, 0, 60), array_slice($fehlt, 0, 8))) . "\n";
}
// Konstanten-Texte, die erst an der Verwendungsstelle übersetzt werden
$ref = new ReflectionClass('WarnHub');
$konst = [];
foreach (['CATEGORY_FIELDS' => 1, 'FORM_PANELS' => null, 'NEWS_VERSIONS' => 'lines'] as $name => $feld) {
    foreach ($ref->getConstant($name) as $k => $v) {
        if ($feld === 1) {
            $konst[] = $v[1];
        } elseif ($feld === 'lines') {
            array_push($konst, ...$v);
        } else {
            $konst[] = $v;
        }
    }
}
foreach ($ref->getConstant('BFS_ODL_REFERENZWERTE') as $r) {
    if ($r['label'] !== '') {
        $konst[] = $r['label'];
    }
}
$fehltKonst = array_values(array_filter($konst, fn ($t) => !isset($de[$t])));
check('jeder Text aus CATEGORY_FIELDS/FORM_PANELS/NEWS_VERSIONS/BFS_ODL_REFERENZWERTE hat eine deutsche Fassung (' . count($konst) . ')', $fehltKonst === []);
if ($fehltKonst !== []) {
    echo '      fehlt: ' . implode(' | ', array_map(fn ($t) => mb_substr($t, 0, 60), array_slice($fehltKonst, 0, 8))) . "\n";
}

$platzhalter = fn (string $t) => preg_match_all('/%(?:\d+\$)?[-+ 0]*\d*(?:\.\d+)?[sdfu]/', str_replace('%%', '', $t), $m) ? $m[0] : [];
$falsch = [];
foreach ($de as $en => $deText) {
    if ($platzhalter($en) !== $platzhalter($deText)) {
        $falsch[] = $en;
    }
}
check('Platzhalter stimmen in jedem Eintrag überein (Reihenfolge und Art)', $falsch === []);
if ($falsch !== []) {
    echo '      falsch: ' . implode(' | ', array_map(fn ($t) => mb_substr($t, 0, 60), array_slice($falsch, 0, 8))) . "\n";
}
check('kein Eintrag ist leer', !in_array('', array_values($de), true));

// ---------------------------------------------------------------
// Englischer Modus: keine deutschen Reste in Formular und Kacheln
// ---------------------------------------------------------------
$texte = function ($x) use (&$texte): array {
    $aus = [];
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            if (in_array($k, ['caption', 'label', 'confirm', 'Panel'], true) && is_string($v)) {
                $aus[] = $v;
            } elseif (is_array($v)) {
                $aus = array_merge($aus, $texte($v));
            }
        }
    }
    return $aus;
};
// Erlaubte deutsche Wörter in englischen Texten: Eigennamen, Original-Bezeichnungen in Anführungszeichen
// (Tessie-Variablennamen, Schlüsselwörter der Objektbaum-Suche, amtliche DWD-Stufen) und die Widmung.
$erlaubt = ['Fahrzeugposition – Breitengrad/Längengrad', '"Fahrzeugposition"', 'Tür-/Klappenstatus', '"Kofferraum"', '"Heckklappe"', '"Raffstore"/"Jalousie"/"Markise"/"Garage"/"Fenster schließen"/"Heckklappe"/"Sirene"',
    '"Sturmböen"', '"schwere Sturmböen"', '"Markante Wetterwarnung"', '"Unwetterwarnung"', '"Warnung vor extremem Unwetter"', '"🚫 Waldbrandgefahr"', '"Kohlekasten"', 'Sepp Lausch', 'Zürich',
    'hagelschutz-einfach-automatisch.ch', 'warnung.bund.de', 'warnungen.zamg.at', 'github.com/slausch/Symcon-Stellantis-Vehicles', 'Bricoleur', 'Österreich'];
// Längste Ausnahme zuerst -- sonst entfernt z. B. "Heckklappe" ein Stück der langen Liste, und die passt danach nicht mehr.
usort($erlaubt, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
$deutsch = function (string $s) use ($erlaubt): bool {
    $s = str_replace($erlaubt, '', $s);
    return (bool) preg_match('/[äöüÄÖÜß]|\b(und|der|die|das|nicht|bitte|oder|mit|für|wird|ist|keine?|Standort|Standorte|Warnung|Warnungen|Prüfung|Übernehmen|Kachel|Schutzaktion|Benachrichtigung|Fenster|Zielwert|Aktiv|Schwellwert)\b/u', $s);
};
$GLOBALS['WHUB_SPRACHE'] = 'en';
$hubEn = new WarnHub();
$hubEn->Create();
$formEn = json_decode($hubEn->GetConfigurationForm(), true);
$reste = array_values(array_unique(array_filter($texte($formEn['elements'] ?? []), $deutsch)));
check('Formular auf Englisch: keine deutschen Reste (' . count($texte($formEn['elements'] ?? [])) . ' Texte geprüft)', $reste === []);
if ($reste !== []) {
    foreach (array_slice($reste, 0, 12) as $r) {
        echo '      Rest: ' . mb_substr($r, 0, 110) . "\n";
    }
}
$aktiv = [
    ['identifier' => 'w1', 'standort' => 'Home', 'event' => 'Sturm', 'headline' => 'x', 'severity' => 'Severe', 'category' => 'sturm', 'source' => 'test', 'expires' => date('c', 1700003600), 'effective' => date('c', 1700000000), 'distanceKm' => 8.3, 'instruction' => 'x', 'description' => 'x'],
];
$kachelReste = [];
foreach (['renderKachelStatus' => [[], 0], 'renderKachelUebersicht' => [$aktiv, 1700000000], 'renderKachelAlleWarnungen' => [$aktiv, 1700000000, true], 'renderKachelKarte' => [[], false]] as $fn => $args) {
    $html = callPrivate($hubEn, $fn, $args);
    $sichtbar = html_entity_decode(strip_tags(preg_replace('#<(style|script)[^>]*>.*?</\1>#s', '', $html)));
    $sichtbar = str_replace('Sturm', '', $sichtbar); // Ereignisname aus der Quelle (Prüfdaten), kein WarnHub-Text
    if ($deutsch($sichtbar) || preg_match('/\bUhr\b|\bbis\b|entfernt|Alle\b/u', $sichtbar)) {
        $kachelReste[] = $fn . ': ' . mb_substr(preg_replace('/\s+/', ' ', trim($sichtbar)), 0, 140);
    }
}
check('Kacheln auf Englisch: keine deutschen Reste in sichtbarem Text', $kachelReste === []);
foreach ($kachelReste as $r) {
    echo '      Rest: ' . $r . "\n";
}
$statusEn = [callPrivate($hubEn, 'webfrontStatusLine', [[]]), callPrivate($hubEn, 'systemLocationStatusLine'), callPrivate($hubEn, 'wetterstationStatusLine', [0, 0, 0]), callPrivate($hubEn, 'mobileStandorteStatusLine', [[]]), callPrivate($hubEn, 'getPollStatusLine'), $hubEn->ResetFormLayout()];
$statusReste = array_values(array_filter($statusEn, $deutsch));
check('Statuszeilen und Rückmeldungen auf Englisch: keine deutschen Reste', $statusReste === []);
foreach ($statusReste as $r) {
    echo '      Rest: ' . mb_substr($r, 0, 140) . "\n";
}
$pushTitle = callPrivate($hubEn, 'buildPushText', ['Home', ['headline' => 'Gale-force gusts', 'instruction' => '', 'expires' => '2026-11-04T18:00:00+01:00', 'description' => ''], true]);
check('Push-Text auf Englisch: "Valid until" und Hinweis zum Namensabgleich englisch', str_contains($pushTitle, 'Valid until') && str_contains($pushTitle, 'Name match') && !$deutsch($pushTitle));

// ---------------------------------------------------------------
// Deutscher Modus: Stichproben, dass deutsche Nutzer nichts merken
// ---------------------------------------------------------------
$GLOBALS['WHUB_SPRACHE'] = 'de';
$hubDe = new WarnHub();
$hubDe->Create();
$formDe = json_decode($hubDe->GetConfigurationForm(), true);
$alleDe = implode("\n", $texte($formDe['elements'] ?? []));
foreach (['📍  Standorte (Umkreis-Definition)', '🌐  Datenquellen', '🔔  Benachrichtigung', '🔎  Prüfung & Status', '🪟  Formular: Aufbau', '💬  Feedback im Symcon-Forum', '🧡  Über dieses Modul', 'Breitengrad (Lat)', '🌪️ Sturm testen'] as $soll) {
    check('Deutsch: "' . $soll . '" steht im Formular', str_contains($alleDe, $soll));
}
$englischImDeutschen = array_values(array_filter($texte($formDe['elements'] ?? []), fn ($t) => isset($de[$t]) && $de[$t] !== $t));
check('Deutsch: kein übersetzbarer englischer Quelltext bleibt im deutschen Formular stehen', $englischImDeutschen === []);
foreach (array_slice($englischImDeutschen, 0, 6) as $r) {
    echo '      Rest: ' . mb_substr($r, 0, 110) . "\n";
}

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
