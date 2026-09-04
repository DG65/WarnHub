<?php

/**
 * Prüfstand für zwei am 04.09.2026 ergänzte Verbesserungen an
 * processWarnings()/buildPushText() -- beide beim Durchsehen des Codes auf
 * Dietmars Frage "noch andere Ideen?" gefunden, keine erfundenen Features:
 *   1. Erneute Push-Benachrichtigung, wenn eine bereits gesehene Warnung
 *      HOCHGESTUFT wird (z. B. DWD verschärft Moderate -> Severe) --
 *      vorher blieb eine einmal gepushte Warnung für immer stumm, auch bei
 *      deutlicher Verschlimmerung. Eine Abstufung pusht bewusst NICHT
 *      erneut.
 *   2. Die CAP-Handlungsempfehlung (<instruction>, z. B. "Meiden Sie den
 *      Aufenthalt im Wald") wurde bisher eingelesen, aber nie angezeigt --
 *      steht jetzt im Push-Text.
 *
 *   php .tools/test-escalation.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_pushCalls'] = [];

function IPS_VariableExists(int $id): bool
{
    return false;
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
}

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

function machWarnung(string $severity, string $instruction = ''): array
{
    return [
        'identifier' => 'test-eskalation-1', 'source' => 'test', 'msgType' => 'Update', 'event' => 'Sturm',
        'headline' => 'Sturmböen', 'description' => 'Beschreibung', 'instruction' => $instruction, 'severity' => $severity,
        'effective' => date('c'), 'onset' => date('c'), 'expires' => null,
        'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
    ];
}

$hub = new WarnHub();
$hub->Create();
$hub->SetProp('Standorte', json_encode([$standort]));
$hub->SetProp('WebFronts', json_encode($webfronts));
$hub->SetProp('PushAktiv', true);

echo "== processWarnings(): erste Erkennung (Moderate) pusht wie gewohnt ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$r1 = callPrivate($hub, 'processWarnings', [[machWarnung('Moderate')]]);
check('newlyPushed = 1, escalated = 0', $r1['newlyPushed'] === 1 && $r1['escalated'] === 0);
check('genau ein Push wurde tatsächlich zugestellt', count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== processWarnings(): unveränderter Schweregrad im nächsten Poll -> KEIN erneuter Push ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$r2 = callPrivate($hub, 'processWarnings', [[machWarnung('Moderate')]]);
check('newlyPushed = 0, escalated = 0 (nichts hat sich geändert)', $r2['newlyPushed'] === 0 && $r2['escalated'] === 0);
check('kein Push, da bereits gesehen und Schweregrad gleich geblieben', count($GLOBALS['whub_test_pushCalls']) === 0);

echo "\n== processWarnings(): Hochstufung Moderate -> Severe löst eine ERNEUTE Push-Benachrichtigung aus ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$r3 = callPrivate($hub, 'processWarnings', [[machWarnung('Severe')]]);
check('newlyPushed = 0, escalated = 1 (keine neue Warnung, aber eine Eskalation)', $r3['newlyPushed'] === 0 && $r3['escalated'] === 1);
check('genau ein erneuter Push wurde zugestellt', count($GLOBALS['whub_test_pushCalls']) === 1);
check('Push-Text kennzeichnet die Meldung ausdrücklich als "Hochgestuft"', str_contains($GLOBALS['whub_test_pushCalls'][0][3], 'Hochgestuft'));
check('Push-Titel nutzt bereits das Icon des NEUEN, höheren Schweregrads (🚨 für Severe)', str_contains($GLOBALS['whub_test_pushCalls'][0][2], '🚨'));

echo "\n== processWarnings(): Abstufung Severe -> Moderate pusht bewusst NICHT erneut ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$r4 = callPrivate($hub, 'processWarnings', [[machWarnung('Moderate')]]);
check('newlyPushed = 0, escalated = 0 (eine Abstufung zählt nicht als Eskalation)', $r4['newlyPushed'] === 0 && $r4['escalated'] === 0);
check('kein Push bei einer Abstufung', count($GLOBALS['whub_test_pushCalls']) === 0);

echo "\n== processWarnings(): erneutes Ansteigen auf denselben Wert (Severe) NACH einer Abstufung zählt wieder als Eskalation ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$r5 = callPrivate($hub, 'processWarnings', [[machWarnung('Severe')]]);
check('die gespeicherte Severity wurde bei der Abstufung aktualisiert -- ein erneutes Ansteigen auf Severe zählt deshalb wieder als Eskalation', $r5['escalated'] === 1);
check('erneuter Push wurde zugestellt', count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== processWarnings(): Extreme nach Severe eskaliert ebenfalls ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$r6 = callPrivate($hub, 'processWarnings', [[machWarnung('Extreme')]]);
check('escalated = 1 bei Severe -> Extreme', $r6['escalated'] === 1);
check('Push-Titel nutzt das 🆘-Icon für Extreme', str_contains($GLOBALS['whub_test_pushCalls'][0][2], '🆘'));

echo "\n== buildPushText(): CAP-Handlungsempfehlung (instruction) wird angezeigt ==\n";
$textMit = callPrivate($hub, 'buildPushText', ['Zuhause', machWarnung('Severe', 'Meiden Sie den Aufenthalt im Wald.')]);
check('Handlungsempfehlung steht im Push-Text', str_contains($textMit, 'Meiden Sie den Aufenthalt im Wald.'));

$textOhne = callPrivate($hub, 'buildPushText', ['Zuhause', machWarnung('Severe', '')]);
check('ohne Handlungsempfehlung: kein Artefakt/doppeltes Leerzeichen im Text', !str_contains($textOhne, '  '));
check('ohne Handlungsempfehlung bleibt der Text unverändert kurz (kein leerer Anhang)', mb_strlen($textOhne) < mb_strlen($textMit));

echo "\n== processWarnings(): bereits abgelaufene Warnung wird NICHT mehr als aktiv gewertet ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$hub2->SetProp('Standorte', json_encode([$standort]));
$hub2->SetProp('WebFronts', json_encode($webfronts));
$hub2->SetProp('PushAktiv', true);
$abgelaufeneWarnung = machWarnung('Severe');
$abgelaufeneWarnung['identifier'] = 'test-abgelaufen-1';
$abgelaufeneWarnung['expires'] = date('c', time() - 3600); // vor einer Stunde abgelaufen, aber die Quelle liefert sie noch
$GLOBALS['whub_test_pushCalls'] = [];
$rAbgelaufen = callPrivate($hub2, 'processWarnings', [[$abgelaufeneWarnung]]);
check('activeCount = 0 -- eine abgelaufene Warnung zählt nicht mehr als aktiv', $rAbgelaufen['activeCount'] === 0);
check('kein Push für eine bereits abgelaufene Warnung', count($GLOBALS['whub_test_pushCalls']) === 0);

echo "\n== processWarnings(): Gegenprobe -- eine NOCH gültige Warnung (expires in der Zukunft) bleibt unverändert aktiv ==\n";
$gueltigeWarnung = machWarnung('Severe');
$gueltigeWarnung['identifier'] = 'test-noch-gueltig-1';
$gueltigeWarnung['expires'] = date('c', time() + 3600); // noch eine Stunde gültig
$GLOBALS['whub_test_pushCalls'] = [];
$rGueltig = callPrivate($hub2, 'processWarnings', [[$gueltigeWarnung]]);
check('activeCount = 1 -- eine noch gültige Warnung bleibt unverändert aktiv', $rGueltig['activeCount'] === 1);
check('Push kommt wie gewohnt an', count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== processWarnings(): eine Warnung ohne expires-Angabe bleibt unverändert aktiv (nichts zu prüfen) ==\n";
$ohneExpires = machWarnung('Severe');
$ohneExpires['identifier'] = 'test-ohne-expires-1';
$ohneExpires['expires'] = null;
$rOhne = callPrivate($hub2, 'processWarnings', [[$ohneExpires]]);
check('activeCount = 1 -- keine Ablaufprüfung ohne expires-Feld möglich, also weiterhin aktiv', $rOhne['activeCount'] === 1);

echo "\n== processWarnings(): eine abgelaufene, bereits gesehene Warnung wird beim nächsten Poll aus dem Zustand entfernt (keine Entwarnung nötig) ==\n";
$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('Standorte', json_encode([$standort]));
$hub3->SetProp('WebFronts', json_encode($webfronts));
$hub3->SetProp('PushAktiv', true);
$w1 = machWarnung('Severe');
$w1['identifier'] = 'test-lebenszyklus-1';
$w1['expires'] = date('c', time() + 3600);
callPrivate($hub3, 'processWarnings', [[$w1]]); // zunächst aktiv und gepusht
$w2 = $w1;
$w2['expires'] = date('c', time() - 60); // dieselbe Warnung, jetzt (knapp) abgelaufen
$rLebenszyklus = callPrivate($hub3, 'processWarnings', [[$w2]]);
check('nach Ablauf: nicht mehr aktiv', $rLebenszyklus['activeCount'] === 0);
// Erneutes Auftreten derselben Kennung (z. B. eine neue, spätere Warnung mit
// gleicher ID durch einen Datenfehler) muss wieder als NEUE Warnung zählen,
// da der Ablauf denselben Aufräum-Mechanismus wie ein echtes Cancel nutzt.
$w3 = machWarnung('Severe');
$w3['identifier'] = 'test-lebenszyklus-1';
$w3['expires'] = date('c', time() + 3600);
$GLOBALS['whub_test_pushCalls'] = [];
$rWieder = callPrivate($hub3, 'processWarnings', [[$w3]]);
check('nach dem Ablauf erneut auftauchend -> wieder als NEUE Warnung erkannt und gepusht', $rWieder['newlyPushed'] === 1 && count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
