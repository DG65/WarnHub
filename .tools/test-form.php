<?php

/**
 * Prüft, dass GetConfigurationForm() gültiges JSON mit den erwarteten
 * Panels/Feldern in der von Dietmar festgelegten Reihenfolge liefert
 * (Wozu -> Neu in Version X.Y -> Dokumentation & Hilfe -> Fachpanels ->
 * Prüfung & Status -> Feedback -> Über dieses Modul).
 *
 *   php .tools/test-form.php    # 0 = alle Prüfungen bestanden
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
$json = $hub->GetConfigurationForm();
$decoded = json_decode($json, true);
check('GetConfigurationForm() liefert gültiges JSON', $decoded !== null);

$captions = array_map(fn ($e) => $e['caption'] ?? '', $decoded['elements']);
$idx = fn (string $needle) => array_search(true, array_map(fn ($c) => str_contains($c, $needle), $captions), true);

$iWozu = $idx('Wozu dieses Modul');
$iDoku = $idx('Dokumentation & Hilfe');
$iStandorte = $idx('Standorte (Umkreis');
$iPruefung = $idx('Prüfung & Status');
$iFeedback = $idx('Feedback im Symcon-Forum');
$iLizenz = $idx('Über dieses Modul');

check('"Wozu dieses Modul?" ist vorhanden', $iWozu !== false);
check('"Dokumentation & Hilfe" ist vorhanden', $iDoku !== false);
check('"Wozu" steht vor "Dokumentation & Hilfe"', $iWozu !== false && $iDoku !== false && $iWozu < $iDoku);
check('"Dokumentation & Hilfe" steht vor den Fachpanels (Standorte)', $iDoku !== false && $iStandorte !== false && $iDoku < $iStandorte);
check('"Prüfung & Status" steht NACH den Fachpanels (Standorte)', $iPruefung !== false && $iStandorte !== false && $iPruefung > $iStandorte);
check('"Prüfung & Status" steht vor "Feedback im Symcon-Forum"', $iPruefung !== false && $iFeedback !== false && $iPruefung < $iFeedback);
check('"Feedback im Symcon-Forum" steht vor "Über dieses Modul"', $iFeedback !== false && $iLizenz !== false && $iFeedback < $iLizenz);

function findByName(array $elements, string $name): ?array
{
    foreach ($elements as $el) {
        if (($el['name'] ?? null) === $name) {
            return $el;
        }
        foreach (['items', 'columns'] as $k) {
            if (isset($el[$k]) && is_array($el[$k])) {
                $found = findByName($el[$k], $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }
    }
    return null;
}

$kartenfeld = findByName($decoded['elements'], 'KartenStandort');
check('SelectLocation-Kartenfeld "KartenStandort" vorhanden', ($kartenfeld['type'] ?? null) === 'SelectLocation');
check('Kartenfeld-value ist ein JSON-String, kein verschachteltes Objekt (WebFront erwartet String, siehe Live-Fund "[object Object] is not valid JSON")', is_string($kartenfeld['value'] ?? null));
$kartenwert = json_decode($kartenfeld['value'] ?? '', true) ?? [];
check('Kartenfeld startet NICHT bei 0/0 ("Null Island"/Atlantik)', ($kartenwert['latitude'] ?? 0.0) !== 0.0 || ($kartenwert['longitude'] ?? 0.0) !== 0.0);
check('"WebFronts"-Liste vorhanden', findByName($decoded['elements'], 'WebFronts') !== null);
check('"Schutzaktionen"-Liste vorhanden', findByName($decoded['elements'], 'Schutzaktionen') !== null);
check('"Standorte"-Liste vorhanden', findByName($decoded['elements'], 'Standorte') !== null);
check('Standorte: Live-Standort-Spalte "QuellVarLat" vorhanden (mobiler Standort, z. B. Tessie/Geofency)', findByName($decoded['elements'], 'QuellVarLat') !== null);
check('Standorte: Live-Standort-Spalte "QuellVarLon" vorhanden', findByName($decoded['elements'], 'QuellVarLon') !== null);
check('Standorte: "Push nur an"-Filterspalte "PushZielFilter" vorhanden (mehrere Personen/WebFronts)', findByName($decoded['elements'], 'PushZielFilter') !== null);
check('"QuelleMeteoalarm"-Checkbox vorhanden (europaweite Wetterwarnungen)', findByName($decoded['elements'], 'QuelleMeteoalarm') !== null);
check('"QuelleGeosphereAt"-Checkbox vorhanden (koordinatengenaue Österreich-Warnungen)', findByName($decoded['elements'], 'QuelleGeosphereAt') !== null);
check('"QuelleBafuHydroCh"-Checkbox vorhanden (Schweizer Hochwassergefahr)', findByName($decoded['elements'], 'QuelleBafuHydroCh') !== null);
check('Schwellwert-Feld "BafuHydroSchwelle" vorhanden', findByName($decoded['elements'], 'BafuHydroSchwelle') !== null);

$schutzaktionenListe = findByName($decoded['elements'], 'Schutzaktionen');
$typSpalte = null;
foreach ($schutzaktionenListe['columns'] ?? [] as $col) {
    if (($col['name'] ?? null) === 'Typ') {
        $typSpalte = $col;
        break;
    }
}
$typOptions = array_column($typSpalte['edit']['options'] ?? [], 'value');
check('Schutzaktionstyp "fenster" (Fenster schließen, z. B. Tesla) steht zur Auswahl', in_array('fenster', $typOptions, true));
check('Schutzaktionstyp "kofferraum" (Kofferraum/Heckklappe schließen) steht zur Auswahl', in_array('kofferraum', $typOptions, true));
check('Schutzaktionen: Spalte "ZustandsVariableID" vorhanden (Sicherheitsprüfung vor dem Kofferraum-Umschalten)', findByName($schutzaktionenListe['columns'], 'ZustandsVariableID') !== null);

$datenquellenPanel = null;
foreach ($decoded['elements'] as $el) {
    if (($el['caption'] ?? '') === '🌐  Datenquellen') {
        $datenquellenPanel = $el;
        break;
    }
}
$bfsPopup = null;
foreach ($datenquellenPanel['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'PopupButton' && str_contains($item['caption'] ?? '', 'bedeutet dieser Wert')) {
        $bfsPopup = $item;
        break;
    }
}
check('Popup "Was bedeutet dieser Wert?" (Dosisleistung/Verweildauer) steht im Datenquellen-Panel', $bfsPopup !== null);
check('Popup enthält mindestens 5 Einordnungszeilen', $bfsPopup !== null && count($bfsPopup['popup']['items'] ?? []) >= 5);

$standortePanel = null;
foreach ($decoded['elements'] as $el) {
    if (($el['caption'] ?? '') === '📍  Standorte (Umkreis-Definition)') {
        $standortePanel = $el;
        break;
    }
}
$mobilBtn = null;
foreach ($standortePanel['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'Button' && str_contains($item['onClick'] ?? '', 'WHUB_DiscoverMobileStandorte')) {
        $mobilBtn = $item;
        break;
    }
}
check('Button "Fahrzeug-/Standort-Variablen suchen" (mobiler Standort) steht im Standorte-Panel', $mobilBtn !== null);

check('Feld "WetterstationInstanceID" (eigene Wetterstation) vorhanden', findByName($decoded['elements'], 'WetterstationInstanceID') !== null);
check('Schwellwert-Feld "WetterstationWindboeSchwelle" vorhanden', findByName($decoded['elements'], 'WetterstationWindboeSchwelle') !== null);
check('Schwellwert-Feld "WetterstationRegenrateSchwelle" vorhanden', findByName($decoded['elements'], 'WetterstationRegenrateSchwelle') !== null);
$wetterstationBtn = null;
foreach ($datenquellenPanel['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'Button' && str_contains($item['onClick'] ?? '', 'WHUB_DiscoverWetterstation')) {
        $wetterstationBtn = $item;
        break;
    }
}
check('Button "Wetterstation suchen" steht im Datenquellen-Panel', $wetterstationBtn !== null);

check('Feld "SchutzaktionVorlaufMinuten" (Vorlauf vor Gültigkeitsbeginn) im Schutzaktionen-Panel vorhanden', findByName($decoded['elements'], 'SchutzaktionVorlaufMinuten') !== null);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
