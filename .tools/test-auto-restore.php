<?php

/**
 * Prüfstand für die automatische Rückstellung nach Windberuhigung -- die
 * EINZIGE Ausnahme von "keine automatische Rückstellung" im ganzen Modul,
 * bewusst nur für durch die eigene Wetterstation ausgelöste Raffstore-/
 * Markisen-/Garagentor-Aktionen erlaubt (Dietmars Vorschlag 04.09.2026,
 * Wetterstation liefert anders als eine amtliche Warnung einen
 * fortlaufenden, lokalen Live-Wert). Deckt rememberWetterstationRestoreState()
 * und checkWetterstationAutoRestore() ab, inkl. der 20-Minuten-Ruhephase
 * (WETTERSTATION_RESTORE_RUHEPHASE_SEKUNDEN). Kein Netzzugriff nötig.
 *
 *   php .tools/test-auto-restore.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_variableValues'] = [];
$GLOBALS['whub_test_variableProfiles'] = [];
$GLOBALS['whub_test_variableExists'] = [];
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_requestActionCalls'] = [];

function IPS_VariableExists(int $id): bool
{
    return $GLOBALS['whub_test_variableExists'][$id] ?? false;
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_variableValues'][$id] ?? 0;
}
function IPS_GetVariable(int $id)
{
    if (!($GLOBALS['whub_test_variableExists'][$id] ?? false)) {
        return false;
    }
    return [
        'VariableProfile' => $GLOBALS['whub_test_variableProfiles'][$id] ?? '',
        'VariableCustomProfile' => '',
    ];
}
function IPS_GetVariableList(): array
{
    return array_keys(array_filter($GLOBALS['whub_test_variableExists']));
}
function IPS_LogMessage(string $sender, string $message): void
{
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

$raffstoreAction = [
    'Name' => 'Raffstore Wohnzimmer', 'Aktiv' => true, 'Typ' => 'raffstore',
    'KatSturm' => true, 'KatHagel' => true, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false,
    'MinSeverity' => 1, 'StandortFilter' => '', 'ZielVariableID' => 151, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0,
];

echo "== rememberWetterstationRestoreState(): merkt den Wert VOR dem Auslösen ==\n";
$hub = new WarnHub();
$hub->Create();
$GLOBALS['whub_test_variableExists'][151] = true;
$GLOBALS['whub_test_variableValues'][151] = 100.0; // Raffstore ist offen (100), bevor der Sturm es einfährt
callPrivate($hub, 'rememberWetterstationRestoreState', [3, $raffstoreAction, 'wetterstation-windboe-var501']);
$state = json_decode($hub->ReadAttributeString('WetterstationRestoreState'), true);
check('Eintrag für Index 3 wurde angelegt', isset($state[3]));
check('RestoreValue = 100.0 (Wert vor dem Auslösen)', (float) ($state[3]['RestoreValue'] ?? null) === 100.0);
check('FiredValue = 0.0 (ZielWert der Aktion, wird beim Zurückstellen zur Sicherheitsprüfung genutzt)', (float) ($state[3]['FiredValue'] ?? null) === 0.0);
check('Source korrekt als "windboe" erkannt', ($state[3]['Source'] ?? null) === 'windboe');
check('CalmSinceTs startet leer (null)', $state[3]['CalmSinceTs'] === null);

echo "\n== rememberWetterstationRestoreState(): überschreibt einen bereits verfolgten Eintrag NICHT ==\n";
$GLOBALS['whub_test_variableValues'][151] = 30.0; // Raffstore ist inzwischen (durch die Schutzaktion) eingefahren
callPrivate($hub, 'rememberWetterstationRestoreState', [3, $raffstoreAction, 'wetterstation-windboe-var501']);
$state2 = json_decode($hub->ReadAttributeString('WetterstationRestoreState'), true);
check('RestoreValue bleibt 100.0 (der ursprüngliche Wert, nicht der bereits geschützte)', (float) ($state2[3]['RestoreValue'] ?? null) === 100.0);

echo "\n== checkWetterstationAutoRestore(): keine Einträge -> stiller No-Op ==\n";
$hub2 = new WarnHub();
$hub2->Create();
callPrivate($hub2, 'checkWetterstationAutoRestore');
check('kein Fehler, keine Aktion (ReadAttributeString liefert weiterhin das leere Standard-"{}")', $hub2->ReadAttributeString('WetterstationRestoreState') === '{}');

echo "\n== checkWetterstationAutoRestore(): Wind noch NICHT ruhig -> CalmSinceTs bleibt leer, keine Rückstellung ==\n";
$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('WetterstationWindVariableID', 501);
$GLOBALS['whub_test_variableExists'][501] = true;
$GLOBALS['whub_test_variableProfiles'][501] = '~WindSpeed.kmh';
$GLOBALS['whub_test_variableValues'][501] = 80.0; // weiterhin über der Moderate-Schwelle (40)
$GLOBALS['whub_test_variableValues'][151] = 0.0; // Raffstore steht noch auf dem von der Aktion gesetzten Wert (FiredValue) -- niemand hat es angefasst
$hub3->WriteAttributeString('WetterstationRestoreState', json_encode([3 => ['ZielVariableID' => 151, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => null]]));
$GLOBALS['whub_test_variableExists'][151] = true;
$GLOBALS['whub_test_requestActionCalls'] = [];
callPrivate($hub3, 'checkWetterstationAutoRestore');
$state3 = json_decode($hub3->ReadAttributeString('WetterstationRestoreState'), true);
check('Eintrag bleibt bestehen (Wind weiterhin über der Schwelle)', isset($state3[3]));
check('CalmSinceTs bleibt null', $state3[3]['CalmSinceTs'] === null);
check('keine Rückstellung ausgelöst', count($GLOBALS['whub_test_requestActionCalls']) === 0);

echo "\n== checkWetterstationAutoRestore(): Wind wird ruhig -> CalmSinceTs wird gesetzt, aber noch KEINE Rückstellung (Ruhephase läuft erst an) ==\n";
$GLOBALS['whub_test_variableValues'][501] = 10.0; // jetzt unter der Moderate-Schwelle
$GLOBALS['whub_test_requestActionCalls'] = [];
callPrivate($hub3, 'checkWetterstationAutoRestore');
$state4 = json_decode($hub3->ReadAttributeString('WetterstationRestoreState'), true);
check('CalmSinceTs ist jetzt gesetzt (ca. jetzt)', $state4[3]['CalmSinceTs'] !== null && abs($state4[3]['CalmSinceTs'] - time()) < 5);
check('noch keine Rückstellung (Ruhephase beginnt erst)', count($GLOBALS['whub_test_requestActionCalls']) === 0);

echo "\n== checkWetterstationAutoRestore(): erneuter Windanstieg VOR Ablauf der Ruhephase setzt CalmSinceTs zurück ==\n";
$GLOBALS['whub_test_variableValues'][501] = 80.0; // kurzer erneuter Windstoß
$GLOBALS['whub_test_requestActionCalls'] = [];
callPrivate($hub3, 'checkWetterstationAutoRestore');
$state5 = json_decode($hub3->ReadAttributeString('WetterstationRestoreState'), true);
check('CalmSinceTs wird wieder auf null zurückgesetzt (Ruhephase unterbrochen)', $state5[3]['CalmSinceTs'] === null);
check('keine Rückstellung ausgelöst', count($GLOBALS['whub_test_requestActionCalls']) === 0);

echo "\n== checkWetterstationAutoRestore(): nach vollen 20 Minuten DURCHGEHENDER Ruhe wird tatsächlich zurückgestellt ==\n";
$hub3->SetProp('PushAktiv', true);
$hub3->SetProp('WebFronts', json_encode([['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true]]));
$GLOBALS['whub_test_variableValues'][151] = 0.0; // unverändert seit dem Schützen
$hub3->WriteAttributeString('WetterstationRestoreState', json_encode([3 => ['ZielVariableID' => 151, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => time() - 1199]])); // 1 Sekunde zu kurz
$GLOBALS['whub_test_variableValues'][501] = 10.0;
$GLOBALS['whub_test_requestActionCalls'] = [];
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub3, 'checkWetterstationAutoRestore');
check('bei 1199 Sekunden (knapp unter 20 Min.) noch KEINE Rückstellung', count($GLOBALS['whub_test_requestActionCalls']) === 0);

$hub3->WriteAttributeString('WetterstationRestoreState', json_encode([3 => ['ZielVariableID' => 151, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => time() - 1200]]));
callPrivate($hub3, 'checkWetterstationAutoRestore');
check('bei genau 1200 Sekunden (20 Min.) wird zurückgestellt', count($GLOBALS['whub_test_requestActionCalls']) === 1);
check('RequestAction erhält die ZielVariableID (151) und den ursprünglichen Wert (100.0)', ($GLOBALS['whub_test_requestActionCalls'][0][0] ?? null) === 151 && (float) ($GLOBALS['whub_test_requestActionCalls'][0][1] ?? null) === 100.0);
$stateNachher = json_decode($hub3->ReadAttributeString('WetterstationRestoreState'), true);
check('der Eintrag wird nach der Rückstellung entfernt', !isset($stateNachher[3]));
check('eine Push-Benachrichtigung über die Rückstellung wird gesendet (PushAktiv, nicht pausiert)', count($GLOBALS['whub_test_pushCalls']) === 1);

echo "\n== checkWetterstationAutoRestore(): NutzerIn hat die Position inzwischen selbst verändert -> KEINE Überschreibung ==\n";
$hub3b = new WarnHub();
$hub3b->Create();
$hub3b->SetProp('WetterstationWindVariableID', 501);
$GLOBALS['whub_test_variableValues'][501] = 10.0; // ruhig
$GLOBALS['whub_test_variableValues'][151] = 55.0; // NutzerIn hat den Raffstore inzwischen selbst auf 55 gestellt -- NICHT mehr der FiredValue (0.0)
$hub3b->WriteAttributeString('WetterstationRestoreState', json_encode([3 => ['ZielVariableID' => 151, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => time() - 1200]]));
$GLOBALS['whub_test_requestActionCalls'] = [];
callPrivate($hub3b, 'checkWetterstationAutoRestore');
check('KEINE Rückstellung -- die manuelle Änderung des Nutzers wird respektiert, nicht überschrieben', count($GLOBALS['whub_test_requestActionCalls']) === 0);
$stateManuell = json_decode($hub3b->ReadAttributeString('WetterstationRestoreState'), true);
check('der Eintrag wird trotzdem aufgeräumt (keine dauerhafte Verfolgung einer bereits divergierten Variable)', !isset($stateManuell[3]));

echo "\n== checkWetterstationAutoRestore(): keine Push-Benachrichtigung während einer aktiven Ruhephase (Snooze) ==\n";
$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('WetterstationWindVariableID', 501);
$hub4->SetProp('PushAktiv', true);
$hub4->SetProp('WebFronts', json_encode([['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true]]));
$hub4->SnoozePush(60);
$GLOBALS['whub_test_variableValues'][151] = 0.0;
$hub4->WriteAttributeString('WetterstationRestoreState', json_encode([5 => ['ZielVariableID' => 151, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => time() - 1200]]));
$GLOBALS['whub_test_variableValues'][501] = 10.0;
$GLOBALS['whub_test_requestActionCalls'] = [];
$GLOBALS['whub_test_pushCalls'] = [];
callPrivate($hub4, 'checkWetterstationAutoRestore');
check('Rückstellung findet trotz Push-Pause statt (Sachschutz ist nicht dasselbe wie Benachrichtigung)', count($GLOBALS['whub_test_requestActionCalls']) === 1);
check('aber KEINE Push-Benachrichtigung während der Pause', count($GLOBALS['whub_test_pushCalls']) === 0);

echo "\n== checkWetterstationAutoRestore(): Regen-Quelle prüft die Regenrate, nicht den Wind ==\n";
$hub5 = new WarnHub();
$hub5->Create();
$hub5->SetProp('WetterstationRegenVariableID', 502);
$GLOBALS['whub_test_variableExists'][502] = true;
$GLOBALS['whub_test_variableProfiles'][502] = '~Rainfall';
$GLOBALS['whub_test_variableValues'][502] = 5.0; // unter der Moderate-Regenschwelle (15)
$GLOBALS['whub_test_variableValues'][151] = 0.0;
$hub5->WriteAttributeString('WetterstationRestoreState', json_encode([7 => ['ZielVariableID' => 151, 'RestoreValue' => 100.0, 'FiredValue' => 0.0, 'Source' => 'regenrate', 'CalmSinceTs' => time() - 1200]]));
$GLOBALS['whub_test_requestActionCalls'] = [];
callPrivate($hub5, 'checkWetterstationAutoRestore');
check('Regen-Quelle wird korrekt gegen die Regenrate geprüft und zurückgestellt', count($GLOBALS['whub_test_requestActionCalls']) === 1);

echo "\n== checkWetterstationAutoRestore(): Zielvariable existiert nicht mehr -> Eintrag wird verworfen, kein Fehler ==\n";
$hub6 = new WarnHub();
$hub6->Create();
$hub6->SetProp('WetterstationWindVariableID', 501);
$GLOBALS['whub_test_variableValues'][501] = 10.0;
$hub6->WriteAttributeString('WetterstationRestoreState', json_encode([9 => ['ZielVariableID' => 999999, 'RestoreValue' => 1.0, 'FiredValue' => 0.0, 'Source' => 'windboe', 'CalmSinceTs' => time() - 1200]]));
$GLOBALS['whub_test_requestActionCalls'] = [];
callPrivate($hub6, 'checkWetterstationAutoRestore');
check('keine RequestAction auf eine nicht mehr existierende Variable', count($GLOBALS['whub_test_requestActionCalls']) === 0);
$stateWeg = json_decode($hub6->ReadAttributeString('WetterstationRestoreState'), true);
check('der verwaiste Eintrag wird trotzdem aufgeräumt', !isset($stateWeg[9]));

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
