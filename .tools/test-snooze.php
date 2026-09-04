<?php

/**
 * Prüfstand für die Push-Ruhephase (Snooze) -- Dietmars Wunsch 04.09.2026:
 * Push-Benachrichtigung für eine Weile pausieren (Urlaub, Feier,
 * Nachtruhe), OHNE Erkennung/Warnungs-Historie/Schutzaktionen
 * abzuschalten. WHUB_TestPush() bleibt bewusst von der Pause ausgenommen
 * (ein expliziter manueller Test soll immer ankommen). Kein Netzzugriff
 * nötig.
 *
 *   php .tools/test-snooze.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_variableValues'] = [];
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_requestActionCalls'] = [];
$GLOBALS['whub_test_setValueCalls'] = [];
$GLOBALS['whub_test_formFieldSets'] = [];

function IPS_VariableExists(int $id): bool
{
    return array_key_exists($id, $GLOBALS['whub_test_variableValues']);
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_variableValues'][$id] ?? 0;
}
function IPS_LogMessage(string $sender, string $message): void
{
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
function RequestAction(int $variableID, $value): bool
{
    $GLOBALS['whub_test_requestActionCalls'][] = [$variableID, $value];
    return true;
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
        $GLOBALS['whub_test_formFieldSets'][] = [$n, $k, $v];
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
        $GLOBALS['whub_test_setValueCalls'][$ident] = $value;
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

echo "== SnoozePush()/CancelSnooze(): Grundfunktion ==\n";
$hub = new WarnHub();
$hub->Create();
check('vor dem Pausieren: isPushSnoozed() = false', callPrivate($hub, 'isPushSnoozed') === false);
$msg = $hub->SnoozePush(60);
check('meldet Erfolg mit Uhrzeit', str_contains($msg, 'pausiert bis'));
check('isPushSnoozed() = true direkt nach dem Setzen', callPrivate($hub, 'isPushSnoozed') === true);
check('PushSnoozeUntilTs liegt ca. 60 Minuten in der Zukunft', abs($hub->ReadAttributeInteger('PushSnoozeUntilTs') - (time() + 3600)) < 5);

$msg2 = $hub->CancelSnooze();
check('CancelSnooze() meldet Erfolg', str_contains($msg2, 'Pause aufgehoben'));
check('isPushSnoozed() = false nach CancelSnooze()', callPrivate($hub, 'isPushSnoozed') === false);

$msg3 = $hub->CancelSnooze();
check('CancelSnooze() ohne aktive Pause meldet das verständlich statt stumm nichts zu tun', str_contains($msg3, 'nicht pausiert'));

check('SnoozePush(0) wird abgelehnt (Dauer muss > 0 sein)', str_contains($hub->SnoozePush(0), '⚠️'));
check('SnoozePush(-5) wird abgelehnt', str_contains($hub->SnoozePush(-5), '⚠️'));

echo "\n== processWarnings(): Ruhephase pausiert NUR die Zustellung, nicht Erkennung/Historie/Schutzaktionen ==\n";
$standort = [
    'Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true,
];
$markiseAktion = [
    'Name' => 'Markise Terrasse', 'Aktiv' => true, 'Typ' => 'markise',
    'KatSturm' => true, 'KatHagel' => true, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 501, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0,
];
$GLOBALS['whub_test_variableValues'][501] = 100.0;
$warnung = [
    'identifier' => 'test-sturm-snooze', 'source' => 'test', 'msgType' => 'Alert', 'event' => 'Sturm',
    'headline' => 'Schwere Sturmböen', 'description' => '', 'instruction' => '', 'severity' => 'Severe',
    'effective' => date('c'), 'onset' => date('c'), 'expires' => null,
    'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
];

$webfronts = [['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true]];

$hub2 = new WarnHub();
$hub2->Create();
$hub2->SetProp('Standorte', json_encode([$standort]));
$hub2->SetProp('Schutzaktionen', json_encode([$markiseAktion]));
$hub2->SetProp('WebFronts', json_encode($webfronts));
$hub2->SetProp('PushAktiv', true);
$hub2->SnoozePush(60);
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_requestActionCalls'] = [];
$result = callPrivate($hub2, 'processWarnings', [[$warnung]]);
check('Warnung wird trotz Pause als aktiv erkannt (Erkennung läuft weiter)', $result['activeCount'] === 1);
check('Schutzaktion feuert trotz Pause weiterhin (Sachschutz bleibt aktiv)', $result['actionsTriggered'] === 1 && count($GLOBALS['whub_test_requestActionCalls']) === 1);
check('KEINE Push-Zustellung während der Pause', count($GLOBALS['whub_test_pushCalls']) === 0);
$history = json_decode($hub2->GetHistory(), true);
check('Warnungs-Historie wird trotz Pause weiter protokolliert', count($history) === 1);

echo "\n== Gegenprobe: ohne aktive Pause kommt die Push-Benachrichtigung wie gewohnt an ==\n";
$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('Standorte', json_encode([$standort]));
$hub3->SetProp('Schutzaktionen', json_encode([$markiseAktion]));
$hub3->SetProp('WebFronts', json_encode($webfronts));
$hub3->SetProp('PushAktiv', true);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub3, 'processWarnings', [[$warnung]]);
check('ohne Pause: Push kommt normal an', count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== TestPush(): bleibt auch während einer aktiven Pause funktionsfähig ==\n";
$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('WebFronts', json_encode($webfronts));
$hub4->SnoozePush(60);
$GLOBALS['whub_test_pushCalls'] = [];
$testMsg = $hub4->TestPush();
check('WHUB_TestPush() ignoriert die Pause bewusst (expliziter manueller Test)', str_contains($testMsg, '✅') && count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== refreshStatusVariables()/Kacheln: zeigen die Pause sichtbar an ==\n";
$hub5 = new WarnHub();
$hub5->Create();
$hub5->SnoozePush(60);
$GLOBALS['whub_test_setValueCalls'] = [];
callPrivate($hub5, 'refreshStatusVariables');
check('StatusText nennt die Pause samt Uhrzeit', str_contains((string) ($GLOBALS['whub_test_setValueCalls']['StatusText'] ?? ''), '🔕 Push pausiert bis'));
check('KachelStatus zeigt das 🔕-Symbol', str_contains((string) ($GLOBALS['whub_test_setValueCalls']['KachelStatus'] ?? ''), '🔕'));
check('KachelUebersicht zeigt das 🔕-Symbol', str_contains((string) ($GLOBALS['whub_test_setValueCalls']['KachelUebersicht'] ?? ''), '🔕'));

$hub5->CancelSnooze();
$GLOBALS['whub_test_setValueCalls'] = [];
callPrivate($hub5, 'refreshStatusVariables');
check('nach Pause-Ende: StatusText enthält KEIN 🔕 mehr', !str_contains((string) ($GLOBALS['whub_test_setValueCalls']['StatusText'] ?? ''), '🔕'));
check('nach Pause-Ende: KachelStatus enthält KEIN 🔕 mehr', !str_contains((string) ($GLOBALS['whub_test_setValueCalls']['KachelStatus'] ?? ''), '🔕'));

echo "\n== Formular: Snooze-Schaltflächen und Statuszeile vorhanden ==\n";
$hub6 = new WarnHub();
$hub6->Create();
$json = $hub6->GetConfigurationForm();
$decoded = json_decode($json, true);
$benachrichtigungPanel = null;
foreach ($decoded['elements'] as $el) {
    if (($el['caption'] ?? '') === '🔔  Benachrichtigung') {
        $benachrichtigungPanel = $el;
        break;
    }
}
check('"🔔  Benachrichtigung"-Panel gefunden', $benachrichtigungPanel !== null);
$snoozeLabel = null;
foreach ($benachrichtigungPanel['items'] ?? [] as $item) {
    if (($item['name'] ?? null) === 'SnoozeStatusLabel') {
        $snoozeLabel = $item;
        break;
    }
}
check('Snooze-Statuszeile "SnoozeStatusLabel" steht im Formular', $snoozeLabel !== null);
$snoozeRow = null;
foreach ($benachrichtigungPanel['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'RowLayout') {
        $captions = array_column($item['items'] ?? [], 'caption');
        if (array_filter($captions, fn ($c) => str_contains($c, 'pausieren'))) {
            $snoozeRow = $item;
            break;
        }
    }
}
check('Zeile mit Snooze-Schaltflächen (1/4/24 Std. pausieren) gefunden', $snoozeRow !== null);
check('Schaltfläche "🔔 Pause aufheben" ist Teil der Zeile', $snoozeRow !== null && in_array('🔔 Pause aufheben', array_column($snoozeRow['items'], 'caption'), true));

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
