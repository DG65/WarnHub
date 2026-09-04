<?php

/**
 * Prüfstand für zwei am 04.09.2026 ergänzte Funktionen, komplett ohne
 * echtes IPS-System:
 *   1. IPSView-taugliche Statusvariablen (AktiveWarnungen/HoechsterSchweregrad/
 *      StatusText/LetztePruefung) -- WarnHub ist ohne eigene Variablen komplett
 *      "headless" (nur Push + Konsolen-Statuszeile), IPSView baut eigene Views
 *      aber ausschließlich aus VORHANDENEN Symcon-Objekt-IDs zusammen (Live-
 *      Fund: IPSViewConnect hat keine eigene Push-/Geräteregistrierung, nur
 *      einen View-Cache) -- diese vier Variablen sind der Anknüpfungspunkt.
 *   2. WHUB_TestSchutzaktionen($id, $kategorie): je Alarmtyp einzeln testbar,
 *      löst SOFORT alle aktiven, zum Alarmtyp passenden Schutzaktionen aus --
 *      unabhängig von einer echten Warnung, Standort-Filter oder
 *      Mindest-Schweregrad (reiner Aktor-Test). Dietmars ausdrücklicher
 *      Wunsch 04.09.2026, direkt im Anschluss an die IPSView-Arbeit.
 *
 *   php .tools/test-ipsview-status.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_maintainCalls'] = [];
$GLOBALS['whub_test_setValueCalls'] = [];
$GLOBALS['whub_test_requestActionCalls'] = [];
$GLOBALS['whub_test_variableValues'] = [];

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
        $GLOBALS['whub_test_maintainCalls'][] = [$ident, $name, $type, $profile, $position, $keep];
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

echo "== ApplyChanges(): IPSView-taugliche Statusvariablen werden gepflegt ==\n";
$hub = new WarnHub();
$hub->Create();
$GLOBALS['whub_test_maintainCalls'] = [];
$hub->ApplyChanges();
$idents = array_column($GLOBALS['whub_test_maintainCalls'], 0);
check('"AktiveWarnungen" wird gepflegt', in_array('AktiveWarnungen', $idents, true));
check('"HoechsterSchweregrad" wird gepflegt', in_array('HoechsterSchweregrad', $idents, true));
check('"StatusText" wird gepflegt', in_array('StatusText', $idents, true));
check('"LetztePruefung" wird gepflegt', in_array('LetztePruefung', $idents, true));
$byIdent = array_column($GLOBALS['whub_test_maintainCalls'], null, 0);
check('"HoechsterSchweregrad" nutzt das WHUB.Schweregrad-Profil', ($byIdent['HoechsterSchweregrad'][3] ?? null) === 'WHUB.Schweregrad');
check('"LetztePruefung" nutzt das Standard-Zeitstempel-Profil', ($byIdent['LetztePruefung'][3] ?? null) === '~UnixTimestamp');
check('alle vier werden dauerhaft gehalten (keep=true, nicht an ein Property gekoppelt)', $byIdent['AktiveWarnungen'][5] === true);

echo "\n== ApplyChanges(): Statusvariablen spiegeln sofort den letzten bekannten Stand (kein Warten auf den nächsten Poll) ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$hub2->WriteAttributeInteger('LastPollTs', 1700000000);
$hub2->WriteAttributeString('LastActiveWarningsJson', json_encode([
    ['identifier' => 'w1', 'severity' => 'Moderate'],
    ['identifier' => 'w2', 'severity' => 'Severe'],
]));
$GLOBALS['whub_test_setValueCalls'] = [];
$hub2->ApplyChanges();
check('"AktiveWarnungen" = 2', ($GLOBALS['whub_test_setValueCalls']['AktiveWarnungen'] ?? null) === 2);
check('"HoechsterSchweregrad" = 3 (Severe, der höhere der beiden aktiven Warnungen)', ($GLOBALS['whub_test_setValueCalls']['HoechsterSchweregrad'] ?? null) === 3);
check('"StatusText" nennt die Anzahl', str_contains((string) ($GLOBALS['whub_test_setValueCalls']['StatusText'] ?? ''), '2 aktive'));
check('"LetztePruefung" übernimmt LastPollTs unverändert', ($GLOBALS['whub_test_setValueCalls']['LetztePruefung'] ?? null) === 1700000000);

echo "\n== refreshStatusVariables(): Randfälle ==\n";
$hub3 = new WarnHub();
$hub3->Create();
$GLOBALS['whub_test_setValueCalls'] = [];
callPrivate($hub3, 'refreshStatusVariables');
check('ohne jemals durchgeführte Prüfung: "AktiveWarnungen" = 0', ($GLOBALS['whub_test_setValueCalls']['AktiveWarnungen'] ?? null) === 0);
check('ohne jemals durchgeführte Prüfung: "HoechsterSchweregrad" = 0 (keine aktive Warnung)', ($GLOBALS['whub_test_setValueCalls']['HoechsterSchweregrad'] ?? null) === 0);
check('ohne jemals durchgeführte Prüfung: "StatusText" nennt das explizit', str_contains((string) ($GLOBALS['whub_test_setValueCalls']['StatusText'] ?? ''), 'keine Prüfung'));
check('ohne jemals durchgeführte Prüfung: "LetztePruefung" = 0', ($GLOBALS['whub_test_setValueCalls']['LetztePruefung'] ?? null) === 0);

echo "\n== TestSchutzaktionen(): je Alarmtyp einzeln testbar ==\n";
$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('Schutzaktionen', json_encode([
    // Raffstore: Sturm+Hagel angekreuzt -- feuert bei 'sturm' und 'hagel', NICHT bei 'schnee'.
    ['Name' => 'Raffstore Wohnzimmer', 'Aktiv' => true, 'Typ' => 'raffstore', 'KatSturm' => true, 'KatHagel' => true, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 3, 'StandortFilter' => '', 'ZielVariableID' => 111, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0],
    // Sirene: kein Kästchen angekreuzt -- gilt für JEDEN Alarmtyp.
    ['Name' => 'Sirene Außen', 'Aktiv' => true, 'Typ' => 'sirene', 'KatSturm' => false, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 4, 'StandortFilter' => '', 'ZielVariableID' => 121, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 60],
    // Deaktivierte Zeile -- darf NIE feuern, auch nicht bei passender Kategorie.
    ['Name' => 'Deaktivierte Markise', 'Aktiv' => false, 'Typ' => 'markise', 'KatSturm' => true, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 3, 'StandortFilter' => '', 'ZielVariableID' => 131, 'ZielWert' => 0.0, 'ZustandsVariableID' => 0, 'ZielSkriptID' => 0, 'AutoOffSekunden' => 0],
]));
$GLOBALS['whub_test_variableValues'] = [111 => 0, 121 => 0, 131 => 0];

$GLOBALS['whub_test_requestActionCalls'] = [];
$msg = $hub4->TestSchutzaktionen('sturm');
check('Sturm: meldet 2 ausgelöste Aktionen (Raffstore + Sirene, NICHT die deaktivierte Markise)', str_contains($msg, '2 Schutzaktion'));
check('Sturm: nennt beide Namen in der Rückmeldung', str_contains($msg, 'Raffstore Wohnzimmer') && str_contains($msg, 'Sirene Außen'));
check('Sturm: Raffstore UND Sirene wurden tatsächlich angesteuert (RequestAction)', count($GLOBALS['whub_test_requestActionCalls']) === 2);
$angesteuert = array_column($GLOBALS['whub_test_requestActionCalls'], 0);
check('Sturm: die deaktivierte Markise (131) wurde NICHT angesteuert', !in_array(131, $angesteuert, true));

$GLOBALS['whub_test_requestActionCalls'] = [];
$msg = $hub4->TestSchutzaktionen('schnee');
check('Schnee: nur die Sirene feuert (kein Kästchen = jede Kategorie), das Raffstore (nur Sturm+Hagel) nicht', str_contains($msg, '1 Schutzaktion') && str_contains($msg, 'Sirene Außen') && !str_contains($msg, 'Raffstore'));

echo "\n== TestSchutzaktionen(): Randfälle ==\n";
check('unbekannter Alarmtyp liefert eine verständliche Fehlermeldung statt eines Fehlers', str_contains($hub4->TestSchutzaktionen('vulkanausbruch'), 'Unbekannter Alarmtyp'));
$hub5 = new WarnHub();
$hub5->Create();
check('keine Schutzaktionen konfiguriert -> verständliche Rückmeldung statt stiller 0', str_contains($hub5->TestSchutzaktionen('sturm'), 'Keine aktive Schutzaktion'));

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
