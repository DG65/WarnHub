<?php

/**
 * Prüfstand für die Sprachauswahl bei NINA-aggregierten CAP-Meldungen
 * (WarnHub::selectGermanCapInfo) -- kein IPS-System, kein Netzwerk nötig.
 *
 * Hintergrund: eine live beobachtete Gewitterwarnung (Ortenaukreis, über
 * NINA aggregiert) erschien komplett auf Englisch, weil der bisherige
 * exakte Vergleich auf 'de-DE'/'de' den vorhandenen deutschen info-Eintrag
 * verfehlte und stillschweigend auf info[0] zurückfiel.
 *
 *   php .tools/test-nina-language.php    # 0 = alle Prüfungen bestanden
 */

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

$hub = new WarnHub();
$hub->Create();

echo "== selectGermanCapInfo ==\n";

// Regressionsfall: deutscher Eintrag steht NICHT an Index 0 -- der frühere
// Code brach nach dem ersten Nicht-Treffer nicht sauber ab, sondern verglich
// weiter; dieser Fall bestand also schon vorher. Prüft trotzdem den
// Grundfall ab.
$infoEnDe = [
    ['language' => 'en-GB', 'event' => 'Heavy thunderstorm'],
    ['language' => 'de-DE', 'event' => 'Starkes Gewitter'],
];
$result = callPrivate($hub, 'selectGermanCapInfo', [$infoEnDe]);
check('findet de-DE auch wenn nicht an Index 0', $result['event'] === 'Starkes Gewitter');

// Der eigentliche live gefundene Bug: NINA liefert eine abweichende
// Schreibweise (Großbuchstaben) statt exakt 'de-DE'/'de' -- alter Code
// verfehlte das und zeigte den englischen Eintrag.
$infoUppercase = [
    ['language' => 'en-GB', 'event' => 'Heavy thunderstorms with gale- or storm-force gusts'],
    ['language' => 'DE', 'event' => 'Starkes Gewitter'],
];
$result = callPrivate($hub, 'selectGermanCapInfo', [$infoUppercase]);
check('findet "DE" (Großschreibung) statt englisch zurückzufallen', $result['event'] === 'Starkes Gewitter');

// Leerraum-Variante ('de-DE ' o.ä.) -- ebenfalls kein exakter String-Treffer.
$infoWhitespace = [
    ['language' => ' de-DE ', 'event' => 'Starkes Gewitter'],
];
$result = callPrivate($hub, 'selectGermanCapInfo', [$infoWhitespace]);
check('findet "de-DE" mit umgebendem Leerraum', $result['event'] === 'Starkes Gewitter');

// Kein deutscher Eintrag vorhanden -- Sicherheitswarnung wird NICHT
// unterschlagen, sondern (mangels Alternative) der erste Eintrag gezeigt.
$infoOnlyEnglish = [
    ['language' => 'en-GB', 'event' => 'Heavy thunderstorm'],
];
$result = callPrivate($hub, 'selectGermanCapInfo', [$infoOnlyEnglish]);
check('fällt ohne deutschen Eintrag auf den ersten zurück statt null', $result !== null && $result['event'] === 'Heavy thunderstorm');

// Leeres Array -- kein Absturz, sauberes null.
$result = callPrivate($hub, 'selectGermanCapInfo', [[]]);
check('leeres info[]-Array liefert null statt Fehler', $result === null);

echo "\n";
if ($failures > 0) {
    echo "❌ $failures von $checks Prüfungen fehlgeschlagen.\n";
    exit(1);
}
echo "✅ Alle $checks Prüfungen bestanden.\n";
exit(0);
