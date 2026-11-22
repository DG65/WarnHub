<?php

/**
 * Prüfstand Durchsage auf HomePods (WarnHub 1.23.0) -- Dietmars Wunsch
 * 21.11.2026 ("Ja, bau es, nachts nur Extrem"): Warnungen über das Modul
 * AirPlay-Steuerung (AIRP_SayAsync) ansagen, mit eigener Schwelle, nachts nur
 * Extrem. Kein Netzzugriff.
 *
 *   php .tools/test-durchsage.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_sayCalls'] = [];
$GLOBALS['whub_test_sayResult'] = true;
$GLOBALS['whub_test_airplayInstances'] = [];
function AIRP_SayAsync(int $id, string $text, int $volume, bool $chime): bool
{
    $GLOBALS['whub_test_sayCalls'][] = [$id, $text, $volume, $chime];
    return $GLOBALS['whub_test_sayResult'];
}
function IPS_GetName(int $id): string
{
    return 'HomePod ' . $id;
}
function IPS_GetInstanceList(): array
{
    return [];
}
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_requestActionCalls'] = [];
$GLOBALS['whub_test_variableValues'] = [];

function IPS_VariableExists(int $id): bool
{
    return array_key_exists($id, $GLOBALS['whub_test_variableValues']);
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_variableValues'][$id] ?? 0;
}
function RequestAction(int $variableID, $value): bool
{
    $GLOBALS['whub_test_requestActionCalls'][] = [$variableID, $value];
    return true;
}
function WFC_PushNotification(int $id, string $title, string $text, string $sound, int $senderId): bool
{
    $GLOBALS['whub_test_pushCalls'][] = ['webfront', $id, $title, $text, $sound];
    return true;
}
function VISU_PostNotificationEx(int $id, string $title, string $text, string $icon, string $sound, int $targetId): bool
{
    $GLOBALS['whub_test_pushCalls'][] = ['kachel', $id, $title, $text, $sound];
    return true;
}
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return $guid === '{2BB8B63A-9A5A-4704-8230-C18259F9F3E7}' ? $GLOBALS['whub_test_airplayInstances'] : [];
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
    public function Translate($t)
    {
        // Wie Symcon: Text über locale.json in die Systemsprache (Prüfstand: Deutsch, WHUB_SPRACHE=en für Englisch).
        static $tabelle = null;
        if ($tabelle === null) {
            $tabelle = json_decode((string) @file_get_contents(__DIR__ . '/../WarnHub/locale.json'), true)['translations'][getenv('WHUB_SPRACHE') ?: 'de'] ?? [];
        }
        return $tabelle[$t] ?? $t;
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

$standort = [
    'Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true,
];
$handy = ['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true];
$kueche = ['InstanceID' => 701, 'Name' => 'HomePod Küche', 'Typ' => 'durchsage', 'Aktiv' => true];
$h = (int) date('G');
$tag = ['RuhezeitVon' => ($h + 2) % 24, 'RuhezeitBis' => ($h + 3) % 24];
$nacht = ['RuhezeitVon' => $h, 'RuhezeitBis' => ($h + 1) % 24];

function warnung(string $id, string $severity, int $onset = -3600, int $expires = 7200, string $event = 'STURMBÖEN'): array
{
    return [
        'identifier' => $id, 'source' => 'dwd_direct', 'msgType' => 'Alert', 'event' => $event,
        'headline' => 'Amtliche WARNUNG vor ' . $event, 'description' => 'Beschreibung', 'instruction' => 'Bleiben Sie im Haus.', 'severity' => $severity,
        'effective' => date('c', time() + $onset), 'onset' => date('c', time() + $onset), 'expires' => date('c', time() + $expires),
        'areaDesc' => 'Ortenaukreis', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
    ];
}
function hub(array $props = [], ?array $ziele = null, ?array $standorte = null): WarnHub
{
    global $standort, $handy, $kueche;
    $hub = new WarnHub();
    $hub->Create();
    $hub->SetProp('Standorte', json_encode($standorte ?? [$standort]));
    $hub->SetProp('WebFronts', json_encode($ziele ?? [$handy, $kueche]));
    $hub->SetProp('PushAktiv', true);
    foreach ($props as $k => $v) {
        $hub->SetProp($k, $v);
    }
    $GLOBALS['whub_test_sayCalls'] = [];
    $GLOBALS['whub_test_pushCalls'] = [];
    return $hub;
}
function says(): array
{
    return $GLOBALS['whub_test_sayCalls'];
}
function lauf(WarnHub $hub, array $warnungen): void
{
    callPrivate($hub, 'processWarnings', [$warnungen]);
}

echo "== Erkennung ==\n";
$GLOBALS['whub_test_airplayInstances'] = [701];
$hub = hub([], []);
$ziele = callPrivate($hub, 'discoverPushTargets');
check('AirPlay-Instanz als Typ "durchsage" gefunden', count(array_filter($ziele, fn ($z) => $z['Typ'] === 'durchsage' && $z['InstanceID'] === 701)) === 1);
$msg = $hub->DiscoverWebFronts();
check('Suche meldet: HomePod INAKTIV', str_contains($msg, 'INAKTIV') && str_contains($msg, 'HomePod'));
$GLOBALS['whub_test_airplayInstances'] = [];

echo "\n== Standardwerte ==\n";
$hub = hub();
check('Schwelle tags Severe (3), nachts Extrem (4)', $hub->ReadPropertyInteger('DurchsageMinSeverity') === 3 && $hub->ReadPropertyInteger('DurchsageNachtMinSeverity') === 4);
check('Gong an, Entwarnung aus', $hub->ReadPropertyBoolean('DurchsageGong') && !$hub->ReadPropertyBoolean('DurchsageEntwarnung'));

echo "\n== Tagsüber ==\n";
$hub = hub($tag);
lauf($hub, [warnung('A', 'Severe')]);
check('Severe: genau 1 Durchsage auf HomePod 701', count(says()) === 1 && says()[0][0] === 701);
check('… Lautstärke der Instanz (-1), Gong an', says()[0][2] === -1 && says()[0][3] === true);
check('… Text: "Achtung, schwere Warnung für Zuhause: Sturmböen."', str_starts_with(says()[0][1], 'Achtung, schwere Warnung für Zuhause: Sturmböen.'));
check('… mit "Bis …"', str_contains(says()[0][1], ' Bis '));
check('… ohne Handlungsempfehlung und Beschreibung (nur Severe)', !str_contains(says()[0][1], 'Bleiben') && !str_contains(says()[0][1], 'Beschreibung'));
check('Push geht weiter nur an die Kachel (1 Push, nicht an 701)', count($GLOBALS['whub_test_pushCalls']) === 1 && $GLOBALS['whub_test_pushCalls'][0][1] === 601);
lauf($hub, [warnung('A', 'Severe')]);
check('nächster Abruf derselben Warnung: keine zweite Durchsage', count(says()) === 1);

$hub = hub($tag);
lauf($hub, [warnung('A', 'Moderate')]);
check('Moderate: keine Durchsage, aber Push', count(says()) === 0 && count($GLOBALS['whub_test_pushCalls']) === 1);
lauf($hub, [warnung('A', 'Moderate'), warnung('B', 'Severe')]);
check('Hochstufung auf Severe: Durchsage "Warnung hochgestuft."', count(says()) === 1 && str_starts_with(says()[0][1], 'Warnung hochgestuft. Achtung, schwere Warnung'));

$hub = hub($tag + ['DurchsageGong' => false]);
lauf($hub, [warnung('A', 'Severe')]);
check('Gong abgewählt: chime=false', count(says()) === 1 && says()[0][3] === false);

$hub = hub($tag + ['DurchsageMinSeverity' => 5]);
lauf($hub, [warnung('A', 'Extreme')]);
check('Schwelle "Nie": keine Durchsage, auch bei Extrem', count(says()) === 0);

$hub = hub($tag + ['DurchsageMinSeverity' => 1]);
lauf($hub, [warnung('A', 'Minor')]);
check('Schwelle Minor: "Hinweis für Zuhause"', count(says()) === 1 && str_starts_with(says()[0][1], 'Hinweis für Zuhause: Sturmböen.'));

echo "\n== Nachts (Zeitfenster der Ruhezeit, Ruhezeit selbst aus) ==\n";
$hub = hub($nacht);
lauf($hub, [warnung('A', 'Severe')]);
check('Severe nachts: keine Durchsage', count(says()) === 0);
check('… Push trotzdem (Ruhezeit für Pushes aus)', count($GLOBALS['whub_test_pushCalls']) === 1);
$hub = hub($nacht);
lauf($hub, [warnung('A', 'Extreme')]);
check('Extrem nachts: Durchsage', count(says()) === 1 && str_starts_with(says()[0][1], 'Achtung! Extreme Warnung für Zuhause'));
check('… mit Handlungsempfehlung, ohne Beschreibung', str_contains(says()[0][1], 'Bleiben Sie im Haus.') && !str_contains(says()[0][1], 'Beschreibung'));
$hub = hub($nacht + ['DurchsageNachtMinSeverity' => 5]);
lauf($hub, [warnung('A', 'Extreme')]);
check('Nachtschwelle "Nie": nachts gar nichts', count(says()) === 0);
$hub = hub($nacht + ['DurchsageMinSeverity' => 4, 'DurchsageNachtMinSeverity' => 3]);
lauf($hub, [warnung('A', 'Severe')]);
check('nachts nie lauter als tags: Tag Extrem + Nacht Severe -> Severe still', count(says()) === 0);
$hub = hub($nacht + ['RuhezeitAktiv' => true, 'RuhezeitMinSeverity' => 3]);
lauf($hub, [warnung('A', 'Moderate')]);
check('Ruhezeit an, Moderate nachts: weder Push noch Durchsage', count(says()) === 0 && count($GLOBALS['whub_test_pushCalls']) === 0);
lauf($hub, [warnung('A', 'Moderate'), warnung('B', 'Extreme')]);
check('… Hochstufung auf Extrem: Push und Durchsage', count(says()) === 1 && count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== Entwarnung ==\n";
$cancel = warnung('A', 'Severe');
$cancel['msgType'] = 'Cancel';
$hub = hub($tag);
lauf($hub, [warnung('A', 'Severe')]);
$GLOBALS['whub_test_sayCalls'] = [];
lauf($hub, [$cancel]);
check('Entwarnung standardmäßig nicht angesagt', count(says()) === 0);
$hub = hub($tag + ['DurchsageEntwarnung' => true]);
lauf($hub, [warnung('A', 'Severe')]);
$GLOBALS['whub_test_sayCalls'] = [];
lauf($hub, [$cancel]);
check('mit Häkchen: "Entwarnung für Zuhause: Sturmböen ist vorbei."', count(says()) === 1 && says()[0][1] === 'Entwarnung für Zuhause: Sturmböen ist vorbei.');

echo "\n== Ziele und Bündelung ==\n";
$hub = hub($tag, [$handy, ['Aktiv' => false] + $kueche]);
lauf($hub, [warnung('A', 'Extreme')]);
check('inaktiver HomePod: keine Durchsage', count(says()) === 0);
$hub = hub($tag, [$handy, $kueche, ['InstanceID' => 702, 'Name' => 'HomePod Bad'] + $kueche]);
lauf($hub, [warnung('A', 'Severe')]);
check('zwei HomePods: je eine Durchsage', count(says()) === 2 && says()[0][0] === 701 && says()[1][0] === 702);
$hub = hub($tag, null, [$standort, ['Name' => 'Kohlekasten'] + $standort]);
lauf($hub, [warnung('A', 'Severe')]);
check('zwei Standorte: eine Durchsage "Zuhause und Kohlekasten"', count(says()) === 1 && str_contains(says()[0][1], 'für Zuhause und Kohlekasten:'));
$hub = hub($tag, null, [['PushZielFilter' => 'Handy'] + $standort]);
lauf($hub, [warnung('A', 'Severe')]);
check('Standort mit "Push nur an Handy": keine Durchsage', count(says()) === 0 && count($GLOBALS['whub_test_pushCalls']) === 1);
$hub = hub($tag, null, [['PushZielFilter' => 'HomePod Küche'] + $standort]);
lauf($hub, [warnung('A', 'Severe')]);
check('Standort mit "Push nur an HomePod Küche": nur Durchsage', count(says()) === 1 && count($GLOBALS['whub_test_pushCalls']) === 0);
$hub = hub($tag + ['PushAktiv' => false]);
$hub->SetProp('PushAktiv', false);
lauf($hub, [warnung('A', 'Extreme')]);
check('Benachrichtigung aus: keine Durchsage', count(says()) === 0);
$hub = hub($tag);
$hub->WriteAttributeInteger('PushSnoozeUntilTs', time() + 3600);
lauf($hub, [warnung('A', 'Extreme')]);
check('Pause aktiv: keine Durchsage', count(says()) === 0);

echo "\n== Sprechtext ==\n";
$hub = hub($tag);
$w = warnung('A', 'Severe', 3 * 3600, 6 * 3600, '⚡ Gewitter {uhrzeit} siehe https://example.org/x');
$t = callPrivate($hub, 'buildSpokenText', ['warnung', ['Zuhause'], $w, time()]);
check('Emojis, Links und {} entfernt', !str_contains($t, '⚡') && !str_contains($t, 'http') && !str_contains($t, '{') && !str_contains($t, '}'));
check('Beginn in der Zukunft: "Von … bis …"', str_contains($t, ' Von ') && str_contains($t, ' bis '));
$mittag = mktime(12, 0, 0);
check('volle Stunde: "18 Uhr"', callPrivate($hub, 'spokenTime', [mktime(18, 0, 0), $mittag]) === '18 Uhr');
check('mit Minuten: "18 Uhr 5"', callPrivate($hub, 'spokenTime', [mktime(18, 5, 0), $mittag]) === '18 Uhr 5');
check('morgen: "morgen 6 Uhr"', callPrivate($hub, 'spokenTime', [mktime(6, 0, 0, (int) date('n'), (int) date('j') + 1), $mittag]) === 'morgen 6 Uhr');
$uebermorgen = mktime(9, 30, 0, (int) date('n'), (int) date('j') + 2);
$wt = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][(int) date('w', $uebermorgen)];
check('übermorgen: Wochentag', callPrivate($hub, 'spokenTime', [$uebermorgen, $mittag]) === $wt . ' 9 Uhr 30');
$lang = warnung('A', 'Extreme');
$lang['instruction'] = str_repeat('Sehr lange Empfehlung. ', 80);
check('höchstens 900 Zeichen', mb_strlen(callPrivate($hub, 'buildSpokenText', ['warnung', ['Zuhause'], $lang, time()])) <= 900);
check('Ereignis in Normalschreibung bleibt', str_contains(callPrivate($hub, 'buildSpokenText', ['warnung', ['Zuhause'], warnung('A', 'Severe', -60, 600, 'Hochwasser Rhein'), time()]), 'Hochwasser Rhein.'));

echo "\n== Test-Knopf und Fehler ==\n";
$hub = hub($tag, [$kueche]);
$r = $hub->TestPush();
check('Test-Knopf spricht auf dem HomePod', count(says()) === 1 && str_contains(says()[0][1], 'Testdurchsage'));
check('… und meldet Erfolg 1 von 1', str_starts_with($r, '✅') && str_contains($r, '1 von 1'));
$GLOBALS['whub_test_sayResult'] = false;
$hub = hub($tag, [$kueche]);
$r = $hub->TestPush();
check('Einreihen scheitert: Test meldet Fehler', str_starts_with($r, '⚠️'));
$hub = hub($tag);
lauf($hub, [warnung('A', 'Severe')]);
check('… im Abruf: kein Abbruch, Push läuft', count($GLOBALS['whub_test_pushCalls']) === 1);
$GLOBALS['whub_test_sayResult'] = true;

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden." : "❌ $failures von $checks Prüfungen fehlgeschlagen.") . "\n";
exit($failures === 0 ? 0 : 1);
