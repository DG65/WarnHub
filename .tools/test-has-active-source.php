<?php

/**
 * Prüfstand für hasAnyActiveSource() (ApplyChanges()' "hat diese Instanz
 * überhaupt eine aktive Datenquelle?"-Prüfung) -- Praxis-Fund hfichtinger,
 * Symcon-Forum, 09.09.2026: schaltet man NINA+DWD bewusst ab (z. B. in
 * Österreich, stattdessen GeoSphere Austria aktiv), legte die bis 1.12.2
 * hart-codierte Prüfung `QuelleNina || QuelleDwd` die KOMPLETTE Instanz
 * lahm (SetStatus(104), Poll-Timer 0), obwohl eine echte Quelle lief --
 * kein Netzzugriff nötig.
 *
 *   php .tools/test-has-active-source.php    # 0 = alle Prüfungen bestanden
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
    /** Testhilfe: alle registrierten Properties samt Default -- für den Drift-Test unten. */
    public function getAllProps(): array
    {
        return $this->props;
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
function readConst(string $name)
{
    $ref = new ReflectionClass('WarnHub');
    return $ref->getConstant($name);
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

echo "== hasAnyActiveSource(): Standardzustand nach Create() (QuelleNina/QuelleDwd defaulten auf true) ==\n";
$hub = new WarnHub();
$hub->Create();
check('frisch angelegte Instanz hat eine aktive Quelle (NINA+DWD Default an)', callPrivate($hub, 'hasAnyActiveSource') === true);

echo "\n== hasAnyActiveSource(): NINA+DWD aus, GAR NICHTS sonst an -> false ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$hub2->SetProp('QuelleNina', false);
$hub2->SetProp('QuelleDwd', false);
check('keine Quelle aktiv -> false (SetStatus(104)/Poll-Timer 0 gerechtfertigt)', callPrivate($hub2, 'hasAnyActiveSource') === false);

echo "\n== hasAnyActiveSource(): Praxis-Fund hfichtinger -- NINA+DWD aus, aber GeoSphere Austria an -> weiterhin true ==\n";
$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('QuelleNina', false);
$hub3->SetProp('QuelleDwd', false);
$hub3->SetProp('QuelleGeosphereAt', true);
check('GeoSphere Austria zählt als aktive Quelle, obwohl NINA/DWD aus sind (genau hfichtingers Fall)', callPrivate($hub3, 'hasAnyActiveSource') === true);

echo "\n== hasAnyActiveSource(): jede einzelne der übrigen Ein/Aus-Quellen zählt für sich allein (NINA+DWD aus) ==\n";
$restlicheQuellen = ['QuellePegelonline', 'QuelleBfsOdl', 'QuelleMeteoalarm', 'QuelleGeosphereAt', 'QuelleBafuHydroCh', 'QuelleSedErdbebenCh', 'QuelleWaldbrandDe', 'QuelleOzonDe'];
foreach ($restlicheQuellen as $prop) {
    $h = new WarnHub();
    $h->Create();
    $h->SetProp('QuelleNina', false);
    $h->SetProp('QuelleDwd', false);
    $h->SetProp($prop, true);
    check("$prop allein aktiv (Rest aus) -> hasAnyActiveSource() true", callPrivate($h, 'hasAnyActiveSource') === true);
}

echo "\n== hasAnyActiveSource(): die zwei Quellen OHNE einfachen Bool-Schalter (Hagelschutz-URL, Wetterstation-Instanz) ==\n";
$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('QuelleNina', false);
$hub4->SetProp('QuelleDwd', false);
$hub4->SetProp('HagelschutzPollUrl', 'https://meteo.netitservices.com/beispiel');
check('gesetzte Hagelschutz-CH-URL zählt als aktive Quelle', callPrivate($hub4, 'hasAnyActiveSource') === true);

$hub5 = new WarnHub();
$hub5->Create();
$hub5->SetProp('QuelleNina', false);
$hub5->SetProp('QuelleDwd', false);
$hub5->SetProp('WetterstationInstanceID', 12345);
check('konfigurierte Wetterstation-Instanz-ID zählt als aktive Quelle', callPrivate($hub5, 'hasAnyActiveSource') === true);

$hub6 = new WarnHub();
$hub6->Create();
$hub6->SetProp('QuelleNina', false);
$hub6->SetProp('QuelleDwd', false);
$hub6->SetProp('HagelschutzPollUrl', '   '); // nur Leerraum -- zählt NICHT als gesetzt
check('reine Leerraum-URL bei Hagelschutz-CH zählt NICHT als aktive Quelle', callPrivate($hub6, 'hasAnyActiveSource') === false);

echo "\n== Drift-Schutz: JEDE registrierte 'Quelle*'-Property MUSS entweder in SOURCE_TOGGLE_PROPERTIES stehen ODER explizit in hasAnyActiveSource() separat geprüft werden ==\n";
// Genau der Fehler, der hfichtinger getroffen hat: eine neue Datenquelle
// wird in Create() registriert, aber die "gibt's überhaupt eine aktive
// Quelle"-Prüfung wird nicht mitgezogen. Dieser Test schlägt automatisch
// fehl, sobald künftig eine neue 'Quelle*'-Property dazukommt, ohne in
// SOURCE_TOGGLE_PROPERTIES aufgenommen zu werden -- KEIN manuelles
// Nachpflegen dieser Liste hier nötig.
$hub7 = new WarnHub();
$hub7->Create();
$alleProps = array_keys($hub7->getAllProps());
$quelleProps = array_values(array_filter($alleProps, fn ($p) => str_starts_with($p, 'Quelle')));
$bekannt = readConst('SOURCE_TOGGLE_PROPERTIES');
sort($quelleProps);
$bekanntSorted = $bekannt;
sort($bekanntSorted);
check('SOURCE_TOGGLE_PROPERTIES enthält EXAKT alle registrierten Quelle*-Properties (' . count($quelleProps) . ' gefunden)', $quelleProps === $bekanntSorted);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
