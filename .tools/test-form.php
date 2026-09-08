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
    public function LogMessage($Message, $Type)
    {
        $GLOBALS['whub_test_logMessageCalls'][] = [$Message, $Type];
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
check('"QuelleSedErdbebenCh"-Checkbox vorhanden (Schweizer Erdbeben)', findByName($decoded['elements'], 'QuelleSedErdbebenCh') !== null);
check('"QuelleWaldbrandDe"-Checkbox vorhanden (Waldbrandgefahrenindex)', findByName($decoded['elements'], 'QuelleWaldbrandDe') !== null);
check('Schwellwert-Feld "WaldbrandDeSchwelle" vorhanden', findByName($decoded['elements'], 'WaldbrandDeSchwelle') !== null);

$hagelschutzPanel = null;
foreach ($decoded['elements'] as $el) {
    if (str_contains($el['caption'] ?? '', 'BETA: Hagelschutz Schweiz')) {
        $hagelschutzPanel = $el;
        break;
    }
}
check('eigenes, klar als BETA gekennzeichnetes Panel "Hagelschutz Schweiz" vorhanden', $hagelschutzPanel !== null);
check('Feld "HagelschutzPollUrl" steht in diesem Panel', findByName($hagelschutzPanel['items'] ?? [], 'HagelschutzPollUrl') !== null);

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

$schutzaktionenPanel = null;
foreach ($decoded['elements'] as $el) {
    if (str_contains($el['caption'] ?? '', 'Schutzaktionen (Jalousien')) {
        $schutzaktionenPanel = $el;
        break;
    }
}
$testRow = null;
foreach ($schutzaktionenPanel['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'RowLayout') {
        $testRow = $item;
        break;
    }
}
check('eine Zeile mit Test-Schaltflächen je Alarmtyp steht im Schutzaktionen-Panel', $testRow !== null);
$testCaptions = array_column($testRow['items'] ?? [], 'caption');
check('"testen"-Schaltfläche für jeden der 6 Alarmtypen vorhanden', count(array_filter($testCaptions, fn ($c) => str_contains($c, 'testen'))) === 6);
$testOnClicks = array_column($testRow['items'] ?? [], 'onClick');
check('jede Schaltfläche ruft WHUB_TestSchutzaktionen mit ihrem eigenen Kategorie-Schlüssel auf', count(array_filter($testOnClicks, fn ($c) => str_contains($c, "WHUB_TestSchutzaktionen(\$id, 'sturm')"))) === 1
    && count(array_filter($testOnClicks, fn ($c) => str_contains($c, "WHUB_TestSchutzaktionen(\$id, 'hagel')"))) === 1);

// Sicherheitshinweis Fenster/Kofferraum (Einklemmgefahr, keine Hinderniserkennung)
// muss als EIGENES, immer sichtbares Panel ganz oben stehen -- nicht nur
// versteckt im "Welche Felder brauche ich?"-Popup, das ein Nutzer aktiv
// aufklappen muss. Dietmars ausdrücklicher Wunsch 07.09.2026: "so stark
// hervorheben, dass der Fokus des Nutzers auf diese Einstellung gelenkt wird".
check('Sicherheitshinweis-Panel ist das ERSTE Element im Schutzaktionen-Panel (maximale Sichtbarkeit)', ($schutzaktionenPanel['items'][0]['type'] ?? null) === 'ExpansionPanel' && str_contains($schutzaktionenPanel['items'][0]['caption'] ?? '', 'SICHERHEITSHINWEIS'));
$sicherheitsPanel = $schutzaktionenPanel['items'][0] ?? [];
check('Sicherheitshinweis-Panel ist standardmäßig AUFGEKLAPPT, nicht hinter einem Klick versteckt', ($sicherheitsPanel['expanded'] ?? false) === true);
$sicherheitsText = implode(' ', array_column($sicherheitsPanel['items'] ?? [], 'caption'));
check('nennt explizit beide betroffenen Aktionstypen (Fenster schließen, Kofferraum/Heckklappe schließen)', str_contains($sicherheitsText, 'Fenster schließen') && str_contains($sicherheitsText, 'Kofferraum/Heckklappe schließen'));
check('beschreibt die konkrete Gefahr: weder Fahrzeug noch WarnHub erkennen eine Person im Bewegungsbereich', str_contains($sicherheitsText, 'Person') && str_contains($sicherheitsText, 'Bewegungsbereich') && str_contains($sicherheitsText, 'KEINE zuverlässige Einklemmschutz'));
check('enthält den ausdrücklichen Hinweis, die Funktion im Zweifel NICHT zu nutzen', str_contains($sicherheitsText, 'IM ZWEIFEL VERZICHTE AUF DIESE FUNKTION'));

$webfrontsListe = findByName($decoded['elements'], 'WebFronts');
$pushTypSpalte = null;
foreach ($webfrontsListe['columns'] ?? [] as $col) {
    if (($col['name'] ?? null) === 'Typ') {
        $pushTypSpalte = $col;
        break;
    }
}
$pushTypOptions = array_column($pushTypSpalte['edit']['options'] ?? [], 'value');
check('Push-Ziel-Typ "telegram" steht zur Auswahl (Mehrkanal-Push)', in_array('telegram', $pushTypOptions, true));
check('Push-Ziel-Typ "pushover" steht zur Auswahl (Mehrkanal-Push)', in_array('pushover', $pushTypOptions, true));
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

$windPopup = null;
foreach ($datenquellenPanel['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'PopupButton' && str_contains($item['caption'] ?? '', 'Welchen Schwellwert')) {
        $windPopup = $item;
        break;
    }
}
check('Popup "Welchen Schwellwert wähle ich?" (Windböen-Einordnung) steht im Datenquellen-Panel', $windPopup !== null);
check('Popup erklärt alle drei Stufen (Moderate/Severe/Extreme)', $windPopup !== null && count($windPopup['popup']['items'] ?? []) >= 4);

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

// Dietmars Nachfrage 07.09.2026: "Kannst du dazu schreiben, um welche
// Module es sich handelt die wir angebunden haben?" -- eigenes Label mit
// allen sechs unterstützten Quellen samt Fundstelle (Store-Name/GitHub),
// nicht nur in Code-Kommentaren/CHANGELOG.
$standorteText = implode(' ', array_column($standortePanel['items'] ?? [], 'caption'));
check('nennt alle sechs mobilen-Standort-Quellen namentlich (Tessie, Geofency, Stellantis Vehicles, Smartcar, BMW Connected Drive, Hyundai/Kia Bluelink)', str_contains($standorteText, 'Tessie') && str_contains($standorteText, 'Geofency') && str_contains($standorteText, 'Stellantis Vehicles') && str_contains($standorteText, 'Smartcar') && str_contains($standorteText, 'BMW Connected Drive') && str_contains($standorteText, 'Hyundai/Kia Bluelink'));
check('nennt die GitHub-Fundstellen der drei nicht im Store-Suchnamen eindeutigen Community-Module', str_contains($standorteText, 'github.com/slausch/Symcon-Stellantis-Vehicles') && str_contains($standorteText, 'github.com/mb-stern/Smartcar') && str_contains($standorteText, 'github.com/da8ter/Bluelink'));

check('Feld "WetterstationInstanceID" (eigene Wetterstation) vorhanden', findByName($decoded['elements'], 'WetterstationInstanceID') !== null);
check('manuelles Feld "WetterstationWindVariableID" (andere Fabrikate, z. B. KNX) vorhanden', findByName($decoded['elements'], 'WetterstationWindVariableID') !== null);
check('manuelles Feld "WetterstationRegenVariableID" (andere Fabrikate, z. B. KNX) vorhanden', findByName($decoded['elements'], 'WetterstationRegenVariableID') !== null);
check('Schwellwert-Feld "WetterstationWindSchwelleModerate" vorhanden', findByName($decoded['elements'], 'WetterstationWindSchwelleModerate') !== null);
check('Schwellwert-Feld "WetterstationWindSchwelleSevere" vorhanden', findByName($decoded['elements'], 'WetterstationWindSchwelleSevere') !== null);
check('Schwellwert-Feld "WetterstationWindSchwelleExtreme" vorhanden', findByName($decoded['elements'], 'WetterstationWindSchwelleExtreme') !== null);
check('Schwellwert-Feld "WetterstationRegenSchwelleModerate" vorhanden', findByName($decoded['elements'], 'WetterstationRegenSchwelleModerate') !== null);
check('Schwellwert-Feld "WetterstationRegenSchwelleSevere" vorhanden', findByName($decoded['elements'], 'WetterstationRegenSchwelleSevere') !== null);
check('Schwellwert-Feld "WetterstationRegenSchwelleExtreme" vorhanden', findByName($decoded['elements'], 'WetterstationRegenSchwelleExtreme') !== null);
check('Auto-Rückstellungs-Checkbox "WetterstationAutoRueckstellung" vorhanden', findByName($decoded['elements'], 'WetterstationAutoRueckstellung') !== null);
$wetterstationBtn = null;
foreach ($datenquellenPanel['items'] ?? [] as $item) {
    if (($item['type'] ?? '') === 'Button' && str_contains($item['onClick'] ?? '', 'WHUB_DiscoverWetterstation')) {
        $wetterstationBtn = $item;
        break;
    }
}
check('Button "Wetterstation suchen" steht im Datenquellen-Panel', $wetterstationBtn !== null);

check('Feld "SchutzaktionVorlaufMinuten" (Vorlauf vor Gültigkeitsbeginn) im Schutzaktionen-Panel vorhanden', findByName($decoded['elements'], 'SchutzaktionVorlaufMinuten') !== null);

$pruefungPanel = null;
foreach ($decoded['elements'] as $el) {
    if (str_contains($el['caption'] ?? '', 'Prüfung & Status')) {
        $pruefungPanel = $el;
        break;
    }
}
$kachelHinweis = null;
foreach ($pruefungPanel['items'] ?? [] as $item) {
    if (str_contains($item['caption'] ?? '', 'Kachel (kompakt)')) {
        $kachelHinweis = $item;
        break;
    }
}
check('Hinweis auf die fertigen WebFront-Kacheln steht im Prüfung & Status-Panel', $kachelHinweis !== null);

check('Auswahlfeld "KartenkachelStandort" NICHT mehr vorhanden (Kachel zeigt jetzt alle Standorte gleichzeitig statt einer festen Konsolen-Auswahl, Dietmars Fund 07.09.2026)', findByName($decoded['elements'], 'KartenkachelStandort') === null);
check('Höhenfeld "KartenkachelHoehePx" (für die Karten-Kachel) vorhanden', findByName($decoded['elements'], 'KartenkachelHoehePx') !== null);
check('Höhenfeld "ZamgKachelHoehePx" (für die ZAMG-Warnkarte, eigene Property, derselbe Fund wie bei der Karten-Kachel) vorhanden', findByName($decoded['elements'], 'ZamgKachelHoehePx') !== null);

$fensterPanel = null;
foreach ($decoded['elements'] as $el) {
    if (str_contains($el['caption'] ?? '', 'Fenster-/Tür-Überwachung')) {
        $fensterPanel = $el;
        break;
    }
}
check('Panel "Fenster-/Tür-Überwachung" vorhanden', $fensterPanel !== null);
$fensterListe = findByName($decoded['elements'], 'Fensterkontakte');
check('Liste "Fensterkontakte" vorhanden', $fensterListe !== null);
check('Liste hat eine Spalte "Kontakt-Variable" (SelectVariable)', (findByName($fensterListe['columns'] ?? [], 'VariableID')['edit']['type'] ?? null) === 'SelectVariable');

echo "\n== LogError(): nutzt \$this->LogMessage() statt IPS_LogMessage() (Symcon-Store-Review-Hinweis 07.09.2026: liefert automatisch den korrekten Instanz-Kontext) ==\n";
$GLOBALS['whub_test_logMessageCalls'] = [];
$logErrorRef = new ReflectionMethod($hub, 'LogError');
$logErrorRef->invokeArgs($hub, ['TestContext', 'Test-Fehlermeldung']);
check('genau ein LogMessage()-Aufruf', count($GLOBALS['whub_test_logMessageCalls']) === 1);
check('Nachricht enthält Kontext und Meldung', ($GLOBALS['whub_test_logMessageCalls'][0][0] ?? '') === 'TestContext: Test-Fehlermeldung');
check('Typ ist KL_ERROR', ($GLOBALS['whub_test_logMessageCalls'][0][1] ?? null) === KL_ERROR);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
