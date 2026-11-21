<?php

/**
 * Prüfstand Schutzaktions-Zustand, Vertrag "protection" 1.0
 * (WHUB_GetProtectionState, WarnHub 1.22.0) -- für Module, die dieselben
 * Geräte bewegen (erster Abnehmer: Verschattung, abgestimmt 20./21.11.2026).
 * Kein Netzzugriff.
 *
 *   php .tools/test-protection.php    # 0 = alle Prüfungen bestanden
 */

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
$webfronts = [['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true]];

/** Simuliert eine DWD-PVW-artige Meldung: eigene, wechselnde identifier je Reissue, aber stabiles event. */
function machReissue(string $identifier, string $onsetOffsetSec, string $expiresOffsetSec): array
{
    return [
        'identifier' => $identifier, 'source' => 'dwd_direct', 'msgType' => 'Alert', 'event' => 'STARKES GEWITTER',
        'headline' => 'Amtliche WARNUNG vor STARKEM GEWITTER', 'description' => '', 'instruction' => '', 'severity' => 'Moderate',
        'effective' => date('c', time() + (int) $onsetOffsetSec), 'onset' => date('c', time() + (int) $onsetOffsetSec),
        'expires' => date('c', time() + (int) $expiresOffsetSec),
        'areaDesc' => 'Ortenaukreis', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
    ];
}

function sturmWarnung(string $id, int $onset, int $expires): array
{
    $w = machReissue($id, (string) $onset, (string) $expires);
    $w['event'] = 'STURMBÖEN';
    $w['headline'] = 'Amtliche WARNUNG vor STURMBÖEN';
    $w['hazard'] = 'wind';
    return $w;
}
function zustand(WarnHub $hub): array
{
    $s = json_decode($hub->GetProtectionState(), true);
    $byName = [];
    foreach ($s['actions'] ?? [] as $a) {
        $byName[$a['name']] = $a;
    }
    return [$s, $byName];
}
$markise = [
    'Name' => 'Markise Terrasse', 'Aktiv' => true, 'Typ' => 'markise',
    'KatSturm' => true, 'KatHagel' => true, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 801, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0,
];
$hagelRaffstore = [
    'Name' => 'Raffstore Süd', 'Aktiv' => true, 'Typ' => 'raffstore',
    'KatSturm' => false, 'KatHagel' => true, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 802, 'ZielWert' => 100.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0,
];
$abgeschaltet = ['Name' => 'Garage', 'Aktiv' => false, 'Typ' => 'garage', 'ZielVariableID' => 803] + $markise;
$GLOBALS['whub_test_variableValues'][801] = 100.0;
$GLOBALS['whub_test_variableValues'][802] = 0.0;
$GLOBALS['whub_test_variableValues'][803] = 0.0;

function neuerSchutzHub(array $aktionen): WarnHub
{
    global $standort;
    $hub = new WarnHub();
    $hub->Create();
    $hub->SetProp('Standorte', json_encode([$standort]));
    $hub->SetProp('Schutzaktionen', json_encode($aktionen));
    $hub->SetProp('PushAktiv', false);
    $hub->SetProp('SchutzaktionVorlaufMinuten', 30);
    return $hub;
}

echo "== Vor jeder Warnung ==\n";
$hub = neuerSchutzHub([$markise, $hagelRaffstore, $abgeschaltet]);
[$s, $a] = zustand($hub);
check('contractVersion "1.0"', ($s['contractVersion'] ?? null) === '1.0');
check('leadTimeMinutes = eingestellter Vorlauf (30)', ($s['leadTimeMinutes'] ?? null) === 30);
check('alle drei Aktionen gelistet, auch die abgeschaltete', count($s['actions']) === 3 && $a['Garage']['enabled'] === false);
check('Zielvariable und Kategorien je Aktion', $a['Markise Terrasse']['targetVariableID'] === 801 && $a['Markise Terrasse']['categories'] === ['sturm', 'hagel'] && $a['Markise Terrasse']['type'] === 'markise');
check('nie ausgelöst: neverFired true, active false, lastFiredAt null', $a['Markise Terrasse']['neverFired'] === true && $a['Markise Terrasse']['active'] === false && $a['Markise Terrasse']['lastFiredAt'] === null);

echo "\n== Warnung beginnt erst in 3 h (außerhalb des Vorlaufs) ==\n";
callPrivate($hub, 'processWarnings', [[sturmWarnung('A', 3 * 3600, 6 * 3600)]]);
[$s, $a] = zustand($hub);
check('noch nicht ausgelöst: weiterhin neverFired, nicht active', $a['Markise Terrasse']['neverFired'] === true && $a['Markise Terrasse']['active'] === false);

echo "\n== Sturm läuft ==\n";
$hub = neuerSchutzHub([$markise, $hagelRaffstore, $abgeschaltet]);
callPrivate($hub, 'processWarnings', [[sturmWarnung('A', -600, 3600)]]);
[$s, $a] = zustand($hub);
$m = $a['Markise Terrasse'];
check('Markise: active true, neverFired false', $m['active'] === true && $m['neverFired'] === false);
check('activeEpisodes nennt Standort, Schlüssel, Ereignis, Code und Ende', ($m['activeEpisodes'][0]['standort'] ?? null) === 'Zuhause' && preg_match('/^loc-[0-9a-f]{8}$/', (string) ($m['activeEpisodes'][0]['standortKey'] ?? '')) === 1 && ($m['activeEpisodes'][0]['event'] ?? null) === 'STURMBÖEN' && ($m['activeEpisodes'][0]['hazard'] ?? null) === 'wind' && ($m['activeEpisodes'][0]['expiresTs'] ?? 0) > time());
check('firedAt und lastFiredAt gesetzt', $m['activeEpisodes'][0]['firedAt'] > 0 && $m['lastFiredAt'] === $m['activeEpisodes'][0]['firedAt']);
check('lastTrigger nennt die auslösende Warnung', ($m['lastTrigger']['event'] ?? null) === 'STURMBÖEN' && ($m['lastTrigger']['category'] ?? null) === 'sturm');
check('Hagel-Raffstore (nur Hagel) bleibt unberührt', $a['Raffstore Süd']['active'] === false && $a['Raffstore Süd']['neverFired'] === true);
check('abgeschaltete Aktion löst nicht aus', $a['Garage']['active'] === false && $a['Garage']['neverFired'] === true);
$erst = $m['lastFiredAt'];
callPrivate($hub, 'processWarnings', [[sturmWarnung('A2', -600, 5400)]]);
[$s, $a] = zustand($hub);
check('Folgemeldung derselben Episode: weiter active, kein neues Auslösen', $a['Markise Terrasse']['active'] === true && $a['Markise Terrasse']['lastFiredAt'] === $erst);

echo "\n== Sturm vorbei ==\n";
callPrivate($hub, 'processWarnings', [[]]);
[$s, $a] = zustand($hub);
check('Warnung weg: active false', $a['Markise Terrasse']['active'] === false && $a['Markise Terrasse']['activeEpisodes'] === []);
check('… aber nicht neverFired, letzte Auslösung bleibt', $a['Markise Terrasse']['neverFired'] === false && $a['Markise Terrasse']['lastFiredAt'] === $erst && ($a['Markise Terrasse']['lastTrigger']['event'] ?? null) === 'STURMBÖEN');

echo "\n== Entwarnung ==\n";
$hub = neuerSchutzHub([$markise]);
callPrivate($hub, 'processWarnings', [[sturmWarnung('A', -600, 3600)]]);
$c = sturmWarnung('A', -600, 3600);
$c['msgType'] = 'Cancel';
callPrivate($hub, 'processWarnings', [[$c]]);
[$s, $a] = zustand($hub);
check('nach Entwarnung: active false', $a['Markise Terrasse']['active'] === false);

echo "\n== Zeilen umsortiert ==\n";
$hub = neuerSchutzHub([$markise, $hagelRaffstore]);
callPrivate($hub, 'processWarnings', [[sturmWarnung('A', -600, 3600)]]);
callPrivate($hub, 'processWarnings', [[]]);
$hub->SetProp('Schutzaktionen', json_encode([$hagelRaffstore, $markise]));
[$s, $a] = zustand($hub);
check('letzte Auslösung bleibt bei der richtigen Aktion', $a['Markise Terrasse']['neverFired'] === false && $a['Raffstore Süd']['neverFired'] === true);

echo "\n== Auto-Rückstellung ==\n";
$hub = neuerSchutzHub([$markise]);
$hub->WriteAttributeString('WetterstationRestoreState', json_encode([0 => ['ZielVariableID' => 801, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => null]]));
[$s, $a] = zustand($hub);
check('ausstehend, Wind noch nicht ruhig: dueAt null', ($a['Markise Terrasse']['autoRestore']['restoreValue'] ?? null) == 100.0 && array_key_exists('dueAt', $a['Markise Terrasse']['autoRestore']) && $a['Markise Terrasse']['autoRestore']['dueAt'] === null);
$ruhig = time() - 300;
$hub->WriteAttributeString('WetterstationRestoreState', json_encode([0 => ['ZielVariableID' => 801, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => $ruhig]]));
[$s, $a] = zustand($hub);
check('Wind ruhig seit 5 min: dueAt = ruhig + 20 min', ($a['Markise Terrasse']['autoRestore']['dueAt'] ?? null) === $ruhig + 1200);
$hub->WriteAttributeString('WetterstationRestoreState', '{}');
[$s, $a] = zustand($hub);
check('keine Rückstellung: autoRestore null', $a['Markise Terrasse']['autoRestore'] === null);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
