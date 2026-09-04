<?php

/**
 * Prüfstand für den Vorlauf vor Gültigkeitsbeginn (SchutzaktionVorlaufMinuten)
 * -- Dietmars Nachfrage 04.09.2026: "Würde bei Wetterwarnung auf 16:00 Uhr
 * die Markise auf 16:00 Uhr eingefahren werden, auch wenn die Meldung um
 * 09:00 Uhr eingeht?" Vorher: ja (Fehler). Jetzt: Schutzaktionen warten bis
 * kurz vor 'onset'/'effective' der Warnung, die Push-Benachrichtigung
 * selbst bleibt sofort (informieren ja, aber nicht schon vorzeitig
 * einfahren). Kein Netzzugriff nötig.
 *
 *   php .tools/test-onset-vorlauf.php    # 0 = alle Prüfungen bestanden
 */

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return [];
}
function IPS_GetModuleList(): array
{
    return [];
}
$GLOBALS['whub_test_requestActionCalls'] = [];
function RequestAction(int $variableID, $value): bool
{
    $GLOBALS['whub_test_requestActionCalls'][] = [$variableID, $value];
    return true;
}
$GLOBALS['whub_test_variableValues'] = [];
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

echo "== isActionDueByOnset(): Standard-Vorlauf 30 Minuten ==\n";
check('onset in 5 Stunden -> NOCH NICHT dran', callPrivate($hub, 'isActionDueByOnset', [['onset' => date('c', time() + 5 * 3600), 'effective' => null]]) === false);
check('onset in 20 Minuten (< 30 Min. Vorlauf) -> JETZT dran', callPrivate($hub, 'isActionDueByOnset', [['onset' => date('c', time() + 20 * 60), 'effective' => null]]) === true);
check('onset genau in 30 Minuten (Vorlauf-Grenze) -> JETZT dran', callPrivate($hub, 'isActionDueByOnset', [['onset' => date('c', time() + 30 * 60), 'effective' => null]]) === true);
check('onset vor 1 Stunde (akute/laufende Warnung) -> JETZT dran', callPrivate($hub, 'isActionDueByOnset', [['onset' => date('c', time() - 3600), 'effective' => null]]) === true);
check('kein onset, aber effective in 5 Stunden -> NOCH NICHT dran (Ersatzwert wird genutzt)', callPrivate($hub, 'isActionDueByOnset', [['onset' => null, 'effective' => date('c', time() + 5 * 3600)]]) === false);
check('weder onset noch effective -> sofort dran (bisheriges Verhalten, nichts zum Abwarten)', callPrivate($hub, 'isActionDueByOnset', [['onset' => null, 'effective' => null]]) === true);
check('onset nicht parsebarer Text -> sicherheitshalber sofort dran (nicht: nie)', callPrivate($hub, 'isActionDueByOnset', [['onset' => 'kein-datum', 'effective' => null]]) === true);

echo "\n== isActionDueByOnset(): eigener (abweichender) Vorlauf wird respektiert ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$hub2->SetProp('SchutzaktionVorlaufMinuten', 120);
check('onset in 90 Minuten, aber 120 Minuten Vorlauf konfiguriert -> JETZT schon dran', callPrivate($hub2, 'isActionDueByOnset', [['onset' => date('c', time() + 90 * 60), 'effective' => null]]) === true);
check('onset in 150 Minuten, 120 Minuten Vorlauf -> NOCH NICHT dran', callPrivate($hub2, 'isActionDueByOnset', [['onset' => date('c', time() + 150 * 60), 'effective' => null]]) === false);
$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('SchutzaktionVorlaufMinuten', 0);
check('Vorlauf 0 Minuten -> exakt bis zum Beginn abwarten, keine Sekunde früher', callPrivate($hub3, 'isActionDueByOnset', [['onset' => date('c', time() + 5 * 60), 'effective' => null]]) === false);

echo "\n== Ende-zu-Ende über processWarnings(): Dietmars konkretes Beispiel (Meldung 09:00 Uhr, Beginn 16:00 Uhr) ==\n";
$standort = [
    'Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true,
];
$markiseAktion = [
    'Name' => 'Markise Terrasse', 'Aktiv' => true, 'Typ' => 'markise',
    'KatSturm' => true, 'KatHagel' => true, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 501, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0,
];
$GLOBALS['whub_test_variableValues'][501] = 100.0; // Ziel-Variable muss nur existieren

$warnungFruehVormittag = [
    'identifier' => 'test-16uhr-sturm', 'source' => 'test', 'msgType' => 'Alert', 'event' => 'Sturm',
    'headline' => 'Sturmböen ab dem Nachmittag', 'description' => '', 'instruction' => '', 'severity' => 'Severe',
    // Meldung "kommt um 09:00 Uhr" (jetzt, im Testlauf), gültig aber erst ab 16:00 Uhr -- hier als 7h in der Zukunft simuliert
    'effective' => date('c'), 'onset' => date('c', time() + 7 * 3600), 'expires' => null,
    'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
];

$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('Standorte', json_encode([$standort]));
$hub4->SetProp('Schutzaktionen', json_encode([$markiseAktion]));
$hub4->SetProp('PushAktiv', true);
$GLOBALS['whub_test_requestActionCalls'] = [];
$result = callPrivate($hub4, 'processWarnings', [[$warnungFruehVormittag]]);
check('die Meldung wird als aktiv erkannt (geometrisch trifft sie zu)', $result['activeCount'] === 1);
check('die Markise fährt NICHT sofort um 09:00 Uhr ein (Warnung gilt erst ab 16:00 Uhr)', $result['actionsTriggered'] === 0 && count($GLOBALS['whub_test_requestActionCalls']) === 0);

echo "\n== Gegenprobe: dieselbe Warnung, jetzt kurz vor bzw. nach Gültigkeitsbeginn -> Markise fährt ein ==\n";
$warnungKurzVorher = $warnungFruehVormittag;
$warnungKurzVorher['onset'] = date('c', time() + 10 * 60); // nur noch 10 Min. bis Beginn, < 30 Min. Standard-Vorlauf
$hub5 = new WarnHub();
$hub5->Create();
$hub5->SetProp('Standorte', json_encode([$standort]));
$hub5->SetProp('Schutzaktionen', json_encode([$markiseAktion]));
$hub5->SetProp('PushAktiv', false);
$GLOBALS['whub_test_requestActionCalls'] = [];
$result2 = callPrivate($hub5, 'processWarnings', [[$warnungKurzVorher]]);
check('kurz vor Gültigkeitsbeginn (innerhalb des Vorlauf-Fensters) fährt die Markise jetzt ein', $result2['actionsTriggered'] === 1 && count($GLOBALS['whub_test_requestActionCalls']) === 1);
check('RequestAction schaltet die richtige Ziel-Variable (501)', ($GLOBALS['whub_test_requestActionCalls'][0][0] ?? null) === 501);

echo "\n== Push-Benachrichtigung bleibt trotzdem SOFORT (informieren ja, physisch handeln erst kurz vorher) ==\n";
$webfronts = [['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true]];
$hub6 = new WarnHub();
$hub6->Create();
$hub6->SetProp('Standorte', json_encode([$standort]));
$hub6->SetProp('Schutzaktionen', json_encode([$markiseAktion]));
$hub6->SetProp('WebFronts', json_encode($webfronts));
$hub6->SetProp('PushAktiv', true);
$GLOBALS['whub_test_requestActionCalls'] = [];
$result3 = callPrivate($hub6, 'processWarnings', [[$warnungFruehVormittag]]);
check('Warnung ist als "neu gepusht" gezählt, obwohl die Schutzaktion noch nicht auslöst', $result3['newlyPushed'] === 1 && $result3['actionsTriggered'] === 0);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
