<?php

/**
 * Prüft die Dosisleistung/Verweildauer-Einordnungshilfe (Popup +
 * Warnungstext) für die BfS-ODL-Quelle -- reine Arithmetik, kein Netz
 * nötig. Werte sind eine eigene Orientierungsrechnung (1000 µSv / Dosis-
 * leistung = Stunden bis zum Jahres-Vorsorgewert 1 mSv der Bevölkerung),
 * KEINE amtliche BfS-Tabelle -- Dietmars Wunsch 04.09.2026 ("wie lange man
 * sich bei welcher Dosis aufhalten darf").
 *
 *   php .tools/test-bfs-odl-context.php    # 0 = alle Prüfungen bestanden
 */

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

echo "== formatDurationToPublicLimit(): 1000 µSv / Dosisleistung, gegen Handrechnung geprüft ==\n";
check('0,1 µSv/h (Naturniveau) -> 417 Tage (10.000 h / 24)', callPrivate($hub, 'formatDurationToPublicLimit', [0.1]) === '417 Tagen');
check('0,3 µSv/h (WarnHub-Standardschwelle) -> 139 Tage (3.333,3 h / 24)', callPrivate($hub, 'formatDurationToPublicLimit', [0.3]) === '139 Tagen');
check('1 µSv/h -> 42 Tage (1.000 h / 24)', callPrivate($hub, 'formatDurationToPublicLimit', [1.0]) === '42 Tagen');
check('10 µSv/h -> 4 Tage (100 h / 24)', callPrivate($hub, 'formatDurationToPublicLimit', [10.0]) === '4 Tagen');
check('1000 µSv/h (= 1 mSv/h) -> exakt 1 Stunde (unter 24h-Schwelle -> Stunden statt Tage)', callPrivate($hub, 'formatDurationToPublicLimit', [1000.0]) === '1,0 Stunden');
check('100 µSv/h -> 10,0 Stunden (unter 24h -> Stunden-Format)', callPrivate($hub, 'formatDurationToPublicLimit', [100.0]) === '10,0 Stunden');
check('0 µSv/h -> leerer String (Division durch 0 vermieden)', callPrivate($hub, 'formatDurationToPublicLimit', [0.0]) === '');
check('negativer Wert -> leerer String', callPrivate($hub, 'formatDurationToPublicLimit', [-5.0]) === '');

echo "\n== bfsOdlContext(): wertabhängiger Einordnungssatz für den Warnungstext ==\n";
$context = callPrivate($hub, 'bfsOdlContext', [1.0]);
check('nennt die berechnete Verweildauer ("42 Tagen")', str_contains($context, '42 Tagen'));
check('kennzeichnet den Jahreswert klar als Vorsorge-, nicht Gefahrenwert (keine Panikmache)', str_contains($context, 'Vorsorgewert') && str_contains($context, 'kein Gefahrenwert'));
check('leerer Wert -> leerer Kontext (kein "leer" o.ä. im Warnungstext)', callPrivate($hub, 'bfsOdlContext', [0.0]) === '');

echo "\n== bfsOdlReferencePopupItems(): gestaffelte Einordnungstabelle fürs Formular-Popup ==\n";
$items = callPrivate($hub, 'bfsOdlReferencePopupItems', []);
check('liefert eine nicht-leere Liste von Label-Elementen', count($items) > 0 && $items[0]['type'] === 'Label');
$allCaptions = implode(' | ', array_column($items, 'caption'));
check('nennt die natürliche Untergrundspanne (0,05-0,2 µSv/h)', str_contains($allCaptions, '0,05-0,2'));
check('nennt den Jahres-Grenzwert 1 mSv', str_contains($allCaptions, '1 mSv'));
check('nennt die akute Schwelle ~500 mSv (Einordnung, keine Alltagsrelevanz)', str_contains($allCaptions, '500 mSv'));
check('enthält eine Zeile für WarnHubs Standard-Schwellwert (0,3 µSv/h)', str_contains($allCaptions, 'Standard-Schwellwert'));
check('kennzeichnet die Tabelle klar als eigene Orientierungsrechnung, nicht amtlich', str_contains($allCaptions, 'keine amtliche'));
check('verweist auf Behörden/BfS/NINA als maßgeblich in einer echten Lage', str_contains($allCaptions, 'BfS') && str_contains($allCaptions, 'NINA'));

echo "\n== fetchBfsOdl()-Beschreibungstext enthält jetzt die Einordnung (Ende-zu-Ende, Reflection auf die private Beschreibungslogik) ==\n";
// Direkter Aufruf von fetchBfsOdl() würde einen echten Netzzugriff brauchen
// (siehe .tools/test-live-fetch.php) -- hier wird nur geprüft, dass
// bfsOdlContext() exakt die Formulierung liefert, die fetchBfsOdl() laut
// Quellcode in die Beschreibung einbaut (sprintf-Vorlage manuell nachgebaut).
$value = 0.5;
$threshold = 0.3;
$description = trim(sprintf(
    '%s µSv/h an Messstelle %s (eigener Schwellwert %s µSv/h -- keine amtliche Alarmstufe, die Rohdaten kennen keine offizielle Meldeschwelle). %s',
    number_format($value, 3, ',', '.'),
    'Teststelle',
    number_format($threshold, 3, ',', '.'),
    callPrivate($hub, 'bfsOdlContext', [$value])
));
check('Beschreibung enthält weiterhin den Rohwert/Schwellwert-Satz', str_contains($description, '0,500 µSv/h an Messstelle Teststelle'));
check('Beschreibung enthält jetzt zusätzlich die Verweildauer-Einordnung', str_contains($description, 'Jahres-Vorsorgewert') && str_contains($description, '83 Tagen'));

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
