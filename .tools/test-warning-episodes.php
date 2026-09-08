<?php

/**
 * Prüfstand für die episodenbasierte Push-/Schutzaktions-Entkopplung von der
 * rohen CAP-`identifier` (warningEpisodeKey(), processWarnings()) -- Dietmars
 * Meldung 08.09.2026: "Mit jedem Push 10 Meldungen auf einmal", verursacht
 * durch den DWD, der bei "Vorabinformationen vor Unwetter" (PVW) für
 * dieselbe andauernde Gefahr alle 15-30 Minuten eine NEUE `identifier`
 * vergibt statt eines Updates der alten (live beobachtet: 6 verschiedene
 * Identifier für "Starkes Gewitter" binnen einer Stunde, an 3 Standorten =
 * bis zu 18 Einzel-Pushes in kurzer Folge). Betrifft sowohl Push-
 * Benachrichtigungen als auch das (wiederholte!) Auslösen von
 * Schutzaktionen -- kein Netzzugriff nötig.
 *
 *   php .tools/test-warning-episodes.php    # 0 = alle Prüfungen bestanden
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

echo "== Reissue mit fortlaufender/überlappender Gültigkeit: NUR EIN Push trotz 3 verschiedener Identifier ==\n";
$hub = new WarnHub();
$hub->Create();
$hub->SetProp('Standorte', json_encode([$standort]));
$hub->SetProp('WebFronts', json_encode($webfronts));
$hub->SetProp('PushAktiv', true);

$GLOBALS['whub_test_pushCalls'] = [];
$r1 = callPrivate($hub, 'processWarnings', [[machReissue('dwd-pvw-a', -60, 1800)]]); // gültig bis in 30 Min.
check('erste Meldung: newlyPushed = 1', $r1['newlyPushed'] === 1);
check('erste Meldung: genau ein Push', count($GLOBALS['whub_test_pushCalls']) === 1);

// Reissue 2: NEUE identifier, aber Gültigkeit schließt nahtlos an die erste an (kein Gap).
$r2 = callPrivate($hub, 'processWarnings', [[machReissue('dwd-pvw-b', 1700, 3600)]]);
check('Reissue 2 (neue identifier, überlappende Gültigkeit): KEIN erneuter Push', $r2['newlyPushed'] === 0 && $r2['escalated'] === 0);
check('weiterhin insgesamt nur EIN Push zugestellt', count($GLOBALS['whub_test_pushCalls']) === 1);

// Reissue 3: wieder eine neue identifier, wieder nahtlos anschließend.
$r3 = callPrivate($hub, 'processWarnings', [[machReissue('dwd-pvw-c', 3500, 5400)]]);
check('Reissue 3 (dritte identifier, weiterhin überlappend): KEIN erneuter Push', $r3['newlyPushed'] === 0 && $r3['escalated'] === 0);
check('nach 3 Identifiern für dieselbe Episode: immer noch nur EIN Push (statt 3)', count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== Echte Lücke (vorige Episode war bereits abgelaufen, bevor die neue beginnt): erneuter Push ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$hub2->SetProp('Standorte', json_encode([$standort]));
$hub2->SetProp('WebFronts', json_encode($webfronts));
$hub2->SetProp('PushAktiv', true);

$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub2, 'processWarnings', [[machReissue('dwd-pvw-x', -60, 1800)]]); // gültig, endet in 30 Min.
check('erste Episode wurde einmal gepusht', count($GLOBALS['whub_test_pushCalls']) === 1);

// Zeit "vorspulen" statt auf echtes Verstreichen zu warten: die gespeicherte
// Episode gilt inzwischen als abgelaufen (simuliert einen späteren Poll,
// nachdem die erste Gewitterzelle durchgezogen ist).
$seen = json_decode($hub2->ReadAttributeString('SeenWarnings'), true);
foreach ($seen as $k => $v) {
    $seen[$k]['expires'] = date('c', time() - 1800);
}
$hub2->WriteAttributeString('SeenWarnings', json_encode($seen));

$GLOBALS['whub_test_pushCalls'] = [];
// Neue Episode desselben Ereignistyps beginnt spuerbar NACH dem (simulierten) Ende der vorigen -- z. B. ein zweites, unabhaengiges Gewitter am selben Abend.
$rGap = callPrivate($hub2, 'processWarnings', [[machReissue('dwd-pvw-y', 60, 5400)]]);
check('nach echter Lücke: wieder als neue Warnung gezählt', $rGap['newlyPushed'] === 1);
check('nach echter Lücke: erneuter Push wird zugestellt', count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== Schutzaktion wird bei Reissues NUR EINMAL ausgelöst, nicht bei jeder neuen Identifier ==\n";
$sireneAktion = [
    'Name' => 'Sirene Garten', 'Aktiv' => true, 'Typ' => 'sirene',
    'KatSturm' => true, 'KatHagel' => true, 'KatStarkregen' => true, 'KatGewitter' => true, 'KatSchnee' => false, 'KatHitze' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 701, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0,
];
$GLOBALS['whub_test_variableValues'][701] = 0.0;

$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('Standorte', json_encode([$standort]));
$hub3->SetProp('Schutzaktionen', json_encode([$sireneAktion]));
$hub3->SetProp('PushAktiv', false);

$GLOBALS['whub_test_requestActionCalls'] = [];
// onset in der Vergangenheit -> sofort faellig (kein Vorlauf-Warten in diesem Test relevant).
$ra1 = callPrivate($hub3, 'processWarnings', [[machReissue('dwd-pvw-fire-a', -60, 1800)]]);
check('erste Episode: Schutzaktion löst aus', $ra1['actionsTriggered'] === 1 && count($GLOBALS['whub_test_requestActionCalls']) === 1);

$ra2 = callPrivate($hub3, 'processWarnings', [[machReissue('dwd-pvw-fire-b', 1700, 3600)]]);
check('Reissue (neue identifier, überlappend): Schutzaktion löst NICHT erneut aus', $ra2['actionsTriggered'] === 0);
check('insgesamt weiterhin nur EIN RequestAction-Aufruf', count($GLOBALS['whub_test_requestActionCalls']) === 1);

$ra3 = callPrivate($hub3, 'processWarnings', [[machReissue('dwd-pvw-fire-c', 3500, 5400)]]);
check('zweiter Reissue: ebenfalls kein erneutes Auslösen', $ra3['actionsTriggered'] === 0 && count($GLOBALS['whub_test_requestActionCalls']) === 1);

echo "\n== Cancel trifft die Episode auch dann, wenn die Cancel-Meldung eine ANDERE identifier als der letzte Alert trägt ==\n";
$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('Standorte', json_encode([$standort]));
$hub4->SetProp('WebFronts', json_encode($webfronts));
$hub4->SetProp('PushAktiv', true);
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub4, 'processWarnings', [[machReissue('dwd-pvw-cancel-a', -60, 1800)]]);
$GLOBALS['whub_test_pushCalls'] = [];
$cancelMsg = machReissue('dwd-pvw-cancel-b', -60, 1800); // eigene, abweichende identifier -- wie beim echten DWD üblich
$cancelMsg['msgType'] = 'Cancel';
$rCancel = callPrivate($hub4, 'processWarnings', [[$cancelMsg]]);
check('Entwarnung wird erkannt, obwohl die Cancel-Meldung eine andere identifier trägt', $rCancel['cancelled'] === 1);
check('eine "✅ Entwarnung"-Push wird zugestellt', count($GLOBALS['whub_test_pushCalls']) === 1 && str_contains($GLOBALS['whub_test_pushCalls'][0][2], 'Entwarnung'));

echo "\n== Gegenprobe: zwei verschiedene Standorte bleiben unabhängige Episoden (keine Vermischung) ==\n";
$standort2 = $standort;
$standort2['Name'] = 'Zweitwohnsitz';
$hub5 = new WarnHub();
$hub5->Create();
$hub5->SetProp('Standorte', json_encode([$standort, $standort2]));
$hub5->SetProp('WebFronts', json_encode($webfronts));
$hub5->SetProp('PushAktiv', true);
$GLOBALS['whub_test_pushCalls'] = [];
$rBeide = callPrivate($hub5, 'processWarnings', [[machReissue('dwd-pvw-multi', -60, 1800)]]);
check('dieselbe Meldung an 2 Standorten: 2 separate Pushes (je Standort eine eigene Episode)', $rBeide['newlyPushed'] === 2 && count($GLOBALS['whub_test_pushCalls']) === 2);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
