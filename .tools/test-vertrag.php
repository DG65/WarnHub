<?php

/**
 * Prüfstand für den Warnungs-Vertrag 1.0 (WarnHub 1.21.0): Gefahrencode
 * "hazard", Zeitstempel onsetTs/expiresTs, Historien-Art "ende" (still
 * beendete Episoden), Quellenausfall ("unbekannt ist nicht vorbei"),
 * fester standortKey und isSystemLocation. Erster Abnehmer: FrigateHub
 * (Konzept Umweltbeobachtung 9.1, abgestimmt 10.11.2026). Kein Netzzugriff.
 *
 *   php .tools/test-vertrag.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_locationInstances'] = [];
$GLOBALS['whub_test_properties'] = [];
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return $guid === '{45E97A63-F870-408A-B259-2933F7EABF74}' ? $GLOBALS['whub_test_locationInstances'] : [];
}
function IPS_GetKernelVersion(): float
{
    return 9.0;
}
function IPS_GetProperty(int $id, string $name)
{
    return $GLOBALS['whub_test_properties'][$id][$name] ?? '';
}
function IPS_GetModuleList(): array
{
    return [];
}
function IPS_LogMessage(string $sender, string $message): void
{
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
$GLOBALS['whub_test_requestActionCalls'] = [];
function RequestAction(int $variableID, $value): bool
{
    $GLOBALS['whub_test_requestActionCalls'][] = [$variableID, $value];
    return true;
}

class IPSModule
{
    public function Translate($t)
    {
        // Wie Symcon: Text über locale.json in die Systemsprache (Prüfstand: Deutsch, WHUB_SPRACHE=en für Englisch).
        static $tabelle = null;
        if ($tabelle === null) {
            $tabelle = json_decode((string) @file_get_contents(__DIR__ . '/../WarnHub/locale.json'), true)['translations'][getenv('WHUB_SPRACHE') ?: 'de'] ?? [];
        }
        return $tabelle[$t] ?? $t;
    }
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
    public function ApplyChanges()
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


// ------------------------------------------------------------------
// Hilfen
// ------------------------------------------------------------------
function neuerHub(array $standorte): WarnHub
{
    $hub = new WarnHub();
    $hub->Create();
    $hub->SetProp('Standorte', json_encode($standorte));
    $hub->SetProp('PushAktiv', false);
    return $hub;
}
function zeile(string $name, float $lat, float $lon, array $extra = []): array
{
    return $extra + [
        'Name' => $name, 'Ort' => '', 'Lat' => $lat, 'Lon' => $lon, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
        'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true,
    ];
}
function warnung(array $extra = []): array
{
    return $extra + [
        'identifier' => 'test-1', 'source' => 'test', 'msgType' => 'Alert', 'event' => 'FROST',
        'headline' => 'Amtliche WARNUNG vor FROST', 'description' => '', 'instruction' => '', 'severity' => 'Minor',
        'effective' => date('c', time() - 600), 'onset' => date('c', time() + 3600), 'expires' => date('c', time() + 7200),
        'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
    ];
}
function capXml(string $event, array $groups): string
{
    $codes = '';
    foreach ($groups as $g) {
        $codes .= "<eventCode><valueName>GROUP</valueName><value>$g</value></eventCode>";
    }
    return '<?xml version="1.0" encoding="UTF-8"?><alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">'
        . '<identifier>2.49.0.0.276.0.DWD.PVW.1</identifier><status>Actual</status><msgType>Alert</msgType>'
        . '<info><language>de-DE</language><event>' . $event . '</event>'
        . '<eventCode><valueName>PROFILE_VERSION</valueName><value>2.1.14</value></eventCode>' . $codes
        . '<eventCode><valueName>II</valueName><value>22</value></eventCode>'
        . '<severity>Minor</severity><headline>Amtliche WARNUNG vor ' . $event . '</headline>'
        . '<area><areaDesc>Gemeinde X</areaDesc><polygon>48.0,7.0 48.0,8.0 49.0,8.0 49.0,7.0 48.0,7.0</polygon></area></info></alert>';
}
function historie(WarnHub $hub): array
{
    return json_decode($hub->GetHistory(0), true);
}
$home = zeile('Zuhause', 48.4785, 7.9448);

// ------------------------------------------------------------------
echo "== Gefahrencode aus DWD eventCode GROUP (parseCapXml) ==\n";
$hub = neuerHub([$home]);
$p = callPrivate($hub, 'parseCapXml', [capXml('FROST', ['FROST'])]);
check('FROST/GROUP FROST -> frost', ($p['hazard'] ?? null) === 'frost');
$p = callPrivate($hub, 'parseCapXml', [capXml('NEBEL', ['FOG'])]);
check('NEBEL/GROUP FOG -> fog (Kategorie wäre "sonstige")', ($p['hazard'] ?? null) === 'fog');
$p = callPrivate($hub, 'parseCapXml', [capXml('GLÄTTE', ['FROST'])]);
check('GLÄTTE geht vor GROUP FROST -> ice', ($p['hazard'] ?? null) === 'ice');
$p = callPrivate($hub, 'parseCapXml', [capXml('STARKES GEWITTER', ['RAIN', 'HAIL', 'THUNDERSTORM', 'WIND'])]);
check('Gewitter mit RAIN/HAIL/WIND -> thunderstorm (Vorrang, nicht Reihenfolge im CAP)', ($p['hazard'] ?? null) === 'thunderstorm');
$p = callPrivate($hub, 'parseCapXml', [capXml('STURMBÖEN', ['WIND'])]);
check('STURMBÖEN/GROUP WIND -> wind', ($p['hazard'] ?? null) === 'wind');
$p = callPrivate($hub, 'parseCapXml', [capXml('UV-INDEX', ['UV'])]);
check('unbekannte GROUP (UV) -> leer, deriveHazard fällt zurück', ($p['hazard'] ?? null) === '');
check('… und landet bei "other"', callPrivate($hub, 'deriveHazard', [$p, 'sonstige']) === 'other');

echo "\n== Gefahrencode: Meteoalarm-Warntyp, GeoSphere, eigene Quellen, Stichwörter ==\n";
check('Meteoalarm Low-temperature -> frost', callPrivate($hub, 'meteoalarmHazard', ['Yellow Low-temperature Warning issued for Bern']) === 'frost');
check('Meteoalarm Fog -> fog', callPrivate($hub, 'meteoalarmHazard', ['Orange Fog Warning issued for Lombardia']) === 'fog');
check('Meteoalarm Flooding -> flood', callPrivate($hub, 'meteoalarmHazard', ['Red Flooding Warning issued for X']) === 'flood');
check('Meteoalarm Forest-fire -> other', callPrivate($hub, 'meteoalarmHazard', ['Yellow Forest-fire Warning issued for X']) === 'other');
$snowIce = ['source' => 'meteoalarm', 'event' => 'black ice', 'headline' => 'Yellow Snow-ice Warning issued for X', 'hazard' => callPrivate($hub, 'meteoalarmHazard', ['Yellow Snow-ice Warning issued for X'])];
check('Meteoalarm Snow-ice + Ereignis "black ice" -> ice (Ereignis entscheidet)', callPrivate($hub, 'deriveHazard', [$snowIce, 'schnee']) === 'ice');
$snowIce['event'] = 'snowfall';
check('Meteoalarm Snow-ice + Ereignis "snowfall" -> snow', callPrivate($hub, 'deriveHazard', [$snowIce, 'schnee']) === 'snow');
$geo = ['source' => 'geosphere_at', 'event' => 'Black ice', 'headline' => '', 'hazard' => 'ice'];
check('GeoSphere-Code wird übernommen (Kategorie "schnee" -> trotzdem ice)', callPrivate($hub, 'deriveHazard', [$geo, 'schnee']) === 'ice');
check('Code aus den Quelldaten geht vor, auch ohne passendes Stichwort im Text', callPrivate($hub, 'deriveHazard', [['source' => 'meteoalarm', 'event' => 'reduced visibility', 'headline' => '', 'hazard' => 'fog'], 'sonstige']) === 'fog');
check('Ungültiger Code im Datensatz wird ignoriert', callPrivate($hub, 'deriveHazard', [['source' => 'x', 'event' => '', 'headline' => '', 'hazard' => 'blizzard'], 'schnee']) === 'snow');
check('Pegel -> flood (Kategorie "starkregen" ergäbe heavy_rain)', callPrivate($hub, 'deriveHazard', [['source' => 'pegelonline', 'event' => 'Hochwasser', 'headline' => ''], 'starkregen']) === 'flood');
check('Hagel-Signalbox -> hail', callPrivate($hub, 'deriveHazard', [['source' => 'hagelschutz_ch', 'event' => '', 'headline' => ''], 'hagel']) === 'hail');
check('Wetterstation Wind -> wind, auch wenn der Text "Regen" nennt', callPrivate($hub, 'deriveHazard', [['source' => 'wetterstation', 'event' => 'Windböe', 'headline' => 'Böe mit Regen'], 'sturm']) === 'wind');
check('NINA Gefahrstoff mit Windrichtung -> smoke', callPrivate($hub, 'deriveHazard', [['source' => 'nina', 'event' => 'Gefahrstoffaustritt', 'headline' => 'Windrichtung beachten'], 'rauch']) === 'smoke');
check('NINA Sturmflut -> flood (nicht wind)', callPrivate($hub, 'deriveHazard', [['source' => 'nina', 'event' => 'Sturmflut', 'headline' => ''], 'sturm']) === 'flood');
check('BfS/Landkreis -> other (kein "eis" in "Landkreis")', callPrivate($hub, 'deriveHazard', [['source' => 'nina', 'event' => 'Hinweis', 'headline' => 'Landkreis X: Information'], 'sonstige']) === 'other');
check('NINA DWD ohne GROUP: "Glätte" -> ice', callPrivate($hub, 'deriveHazard', [['source' => 'nina', 'event' => 'GLÄTTE', 'headline' => '', 'hazard' => ''], 'schnee']) === 'ice');
check('Police -> other (kein "ice" in "Police")', callPrivate($hub, 'deriveHazard', [['source' => 'alertswiss_ch', 'event' => 'Police information', 'headline' => ''], 'sonstige']) === 'other');

// ------------------------------------------------------------------
echo "\n== Aktive Warnung: Vertrag 1.0 ==\n";
$hub = neuerHub([$home]);
$w = warnung(['hazard' => 'frost']);
$r = callPrivate($hub, 'processWarnings', [[$w]]);
$hub->WriteAttributeString('LastActiveWarningsJson', json_encode($r['active']));
$hub->WriteAttributeInteger('LastPollTs', time());
$aktiv = json_decode($hub->GetActiveWarnings(), true);
check('contractVersion "1.0"', ($aktiv['contractVersion'] ?? null) === '1.0');
check('warnings und generatedAt bleiben', isset($aktiv['warnings'][0]) && ($aktiv['generatedAt'] ?? 0) > 0);
$a0 = $aktiv['warnings'][0] ?? [];
check('hazard "frost"', ($a0['hazard'] ?? null) === 'frost');
check('Kategorie bleibt "sonstige"', ($a0['category'] ?? null) === 'sonstige');
check('standortKey "loc-xxxxxxxx"', preg_match('/^loc-[0-9a-f]{8}$/', (string) ($a0['standortKey'] ?? '')) === 1);
check('onsetTs = onset als Unix', ($a0['onsetTs'] ?? null) === strtotime($w['onset']));
check('expiresTs = expires als Unix', ($a0['expiresTs'] ?? null) === strtotime($w['expires']));
check('onset als Text dabei', ($a0['onset'] ?? null) === $w['onset']);
check('Katalog nennt den Standort mit demselben Schlüssel', ($aktiv['standorte'][0]['standortKey'] ?? null) === $a0['standortKey'] && ($aktiv['standorte'][0]['name'] ?? null) === 'Zuhause');
$wOhne = warnung(['onset' => null, 'expires' => null]);
$r2 = callPrivate(neuerHub([$home]), 'processWarnings', [[$wOhne]]);
check('ohne onset: onsetTs aus effective', ($r2['active'][0]['onsetTs'] ?? null) === strtotime($wOhne['effective']));
check('ohne expires: expiresTs null', array_key_exists('expiresTs', $r2['active'][0]) && $r2['active'][0]['expiresTs'] === null);

echo "\n== Historie: Vertragsfelder und Art \"ende\" ==\n";
$h = historie($hub);
check('Warnung protokolliert mit hazard/standortKey/onsetTs/expiresTs', ($h[0]['kind'] ?? null) === 'warnung' && ($h[0]['hazard'] ?? null) === 'frost' && ($h[0]['standortKey'] ?? null) === $a0['standortKey'] && ($h[0]['onsetTs'] ?? null) === $a0['onsetTs'] && ($h[0]['expiresTs'] ?? null) === $a0['expiresTs']);
$seen = json_decode($hub->ReadAttributeString('SeenWarnings'), true);
$s0 = reset($seen);
check('gemerkter Eintrag trägt Ereignis/Standort/Quelle/Code', ($s0['event'] ?? null) === 'FROST' && ($s0['standort'] ?? null) === 'Zuhause' && ($s0['source'] ?? null) === 'test' && ($s0['hazard'] ?? null) === 'frost');
callPrivate($hub, 'processWarnings', [[]]);
$h = historie($hub);
check('Warnung verschwindet still -> Eintrag "ende"', ($h[0]['kind'] ?? null) === 'ende');
check('"ende" mit endReason "gone" (noch nicht abgelaufen)', ($h[0]['endReason'] ?? null) === 'gone');
check('"ende" trägt Standort, Schlüssel, Code und Zeiten der Warnung', ($h[0]['standort'] ?? null) === 'Zuhause' && ($h[0]['standortKey'] ?? null) === $a0['standortKey'] && ($h[0]['hazard'] ?? null) === 'frost' && ($h[0]['expiresTs'] ?? null) === $a0['expiresTs'] && ($h[0]['event'] ?? null) === 'FROST');
check('genau 2 Einträge (warnung + ende)', count($h) === 2);
callPrivate($hub, 'processWarnings', [[]]);
check('kein zweites "ende" beim nächsten leeren Poll', count(historie($hub)) === 2);

$hub = neuerHub([$home]);
callPrivate($hub, 'processWarnings', [[warnung()]]);
$seen = json_decode($hub->ReadAttributeString('SeenWarnings'), true);
$k = array_key_first($seen);
$seen[$k]['expiresTs'] = time() - 60;
$hub->WriteAttributeString('SeenWarnings', json_encode($seen));
callPrivate($hub, 'processWarnings', [[]]);
$h = historie($hub);
check('abgelaufene Episode -> "ende" mit endReason "expired"', ($h[0]['kind'] ?? null) === 'ende' && ($h[0]['endReason'] ?? null) === 'expired');

$hub = neuerHub([$home]);
$hub->WriteAttributeString('SeenWarnings', json_encode(['test|FROST|Zuhause' => ['identifier' => 'alt', 'msgType' => 'Alert', 'pushedAt' => time() - 100, 'severity' => 'Minor', 'expires' => null]]));
callPrivate($hub, 'processWarnings', [[]]);
check('Eintrag aus der Zeit vor 1.21.0 (ohne Ereignis) verschwindet ohne Phantom-"ende"', historie($hub) === [] && json_decode($hub->ReadAttributeString('SeenWarnings'), true) === []);
$hub->WriteAttributeString('SeenWarnings', json_encode(['test|FROST|Zuhause' => ['identifier' => 'alt', 'msgType' => 'Alert', 'pushedAt' => time() - 100, 'severity' => 'Minor', 'expires' => null]]));
callPrivate($hub, 'processWarnings', [[warnung()]]);
$seen = json_decode($hub->ReadAttributeString('SeenWarnings'), true);
check('alter Eintrag bei weiter aktiver Warnung: kein neuer Push/Eintrag, aber vervollständigt', historie($hub) === [] && ($seen['test|FROST|Zuhause']['event'] ?? null) === 'FROST' && ($seen['test|FROST|Zuhause']['hazard'] ?? null) === 'frost');
callPrivate($hub, 'processWarnings', [[]]);
check('… und endet danach mit "ende"', (historie($hub)[0]['kind'] ?? null) === 'ende');

echo "\n== Entwarnung trägt die Vertragsfelder ==\n";
$hub = neuerHub([$home]);
callPrivate($hub, 'processWarnings', [[warnung()]]);
callPrivate($hub, 'processWarnings', [[warnung(['msgType' => 'Cancel'])]]);
$h = historie($hub);
check('Entwarnung mit hazard und standortKey', ($h[0]['kind'] ?? null) === 'entwarnung' && ($h[0]['hazard'] ?? null) === 'frost' && preg_match('/^loc-/', (string) ($h[0]['standortKey'] ?? '')) === 1);
callPrivate($hub, 'processWarnings', [[]]);
check('nach Entwarnung kein zusätzliches "ende"', count(historie($hub)) === 2);

echo "\n== Quellenausfall: unbekannt ist nicht vorbei ==\n";
$hub = neuerHub([$home]);
callPrivate($hub, 'processWarnings', [[warnung()]]);
callPrivate($hub, 'processWarnings', [[], ['test' => true]]);
check('Quelle ausgefallen: kein "ende"', count(historie($hub)) === 1);
check('… Episode bleibt gemerkt', count(json_decode($hub->ReadAttributeString('SeenWarnings'), true)) === 1);
callPrivate($hub, 'processWarnings', [[warnung(['identifier' => 'test-2'])]]);
check('Quelle wieder da, Warnung noch aktiv: kein erneuter Push/Eintrag', count(historie($hub)) === 1);
callPrivate($hub, 'processWarnings', [[], ['andere_quelle' => true]]);
check('Ausfall einer ANDEREN Quelle hält die Episode nicht', (historie($hub)[0]['kind'] ?? null) === 'ende');
$hub = neuerHub([$home]);
callPrivate($hub, 'processWarnings', [[warnung()]]);
$seen = json_decode($hub->ReadAttributeString('SeenWarnings'), true);
$k = array_key_first($seen);
$seen[$k]['lastSeenTs'] = time() - 7 * 3600;
$hub->WriteAttributeString('SeenWarnings', json_encode($seen));
callPrivate($hub, 'processWarnings', [[], ['test' => true]]);
check('Ausfall länger als 6 h: Episode endet doch', (historie($hub)[0]['kind'] ?? null) === 'ende');
$hub = neuerHub([$home]);
callPrivate($hub, 'processWarnings', [[warnung()]]);
$seen = json_decode($hub->ReadAttributeString('SeenWarnings'), true);
$k = array_key_first($seen);
$seen[$k]['expiresTs'] = time() - 60;
$hub->WriteAttributeString('SeenWarnings', json_encode($seen));
callPrivate($hub, 'processWarnings', [[], ['test' => true]]);
check('Ausfall, aber Warnung abgelaufen: endet mit "expired"', (historie($hub)[0]['endReason'] ?? null) === 'expired');
$hub = neuerHub([$home]);
$hub->WriteAttributeString('FiredActions', json_encode(['test|FROST|Zuhause|0' => time()]));
$hub->WriteAttributeString('SeenWarnings', json_encode(['test|FROST|Zuhause' => ['identifier' => 'x', 'msgType' => 'Alert', 'pushedAt' => time(), 'lastSeenTs' => time(), 'severity' => 'Minor', 'expires' => null, 'source' => 'test', 'event' => 'FROST', 'standort' => 'Zuhause']]));
callPrivate($hub, 'processWarnings', [[], ['test' => true]]);
check('ausgelöste Schutzaktion bleibt während des Ausfalls gemerkt (kein zweites Auslösen)', count(json_decode($hub->ReadAttributeString('FiredActions'), true)) === 1);

echo "\n== fetchFromSource(): Ausfall wird erkannt ==\n";
$hub = neuerHub([$home]);
$failed = [];
$prop = new ReflectionProperty($hub, 'httpFailures');
$args = ['meteoalarm', function () use ($hub, $prop) {
    $prop->setValue($hub, $prop->getValue($hub) + 1);
    return [['identifier' => 'a']];
}, &$failed];
$out = (new ReflectionMethod($hub, 'fetchFromSource'))->invokeArgs($hub, $args);
check('Ergebnis wird durchgereicht', count($out) === 1);
check('Quelle mit gescheitertem Abruf ist markiert', isset($failed['meteoalarm']));
$failed2 = [];
$args = ['nina', fn () => [], &$failed2];
(new ReflectionMethod($hub, 'fetchFromSource'))->invokeArgs($hub, $args);
check('Quelle ohne Fehler bleibt unmarkiert', $failed2 === []);
$code = (string) file_get_contents(__DIR__ . '/../WarnHub/module.php');
check('Poll() reicht die Ausfälle an processWarnings() weiter', str_contains($code, '$this->processWarnings($warnings, $failedSources)'));

echo "\n== GetHistory() bleibt Liste, GetWarningHistory() ist der Vertrag ==\n";
$hub = neuerHub([$home]);
callPrivate($hub, 'processWarnings', [[warnung()]]);
callPrivate($hub, 'processWarnings', [[]]);
$liste = json_decode($hub->GetHistory(), true);
check('GetHistory(): weiterhin reine Liste', array_is_list($liste) && ($liste[0]['kind'] ?? null) === 'ende');
$v = json_decode($hub->GetWarningHistory(), true);
check('GetWarningHistory(): contractVersion "1.0"', ($v['contractVersion'] ?? null) === '1.0');
check('GetWarningHistory(): entries newest first', ($v['entries'][0]['kind'] ?? null) === 'ende' && ($v['entries'][1]['kind'] ?? null) === 'warnung');
check('GetWarningHistory(): Standort-Katalog dabei', ($v['standorte'][0]['name'] ?? null) === 'Zuhause');
check('Limit 1', count(json_decode($hub->GetWarningHistory(1), true)['entries']) === 1);
$log = json_decode($hub->ReadAttributeString('WarnHistory'), true);
$log[0]['ts'] = time() - 86400;
$hub->WriteAttributeString('WarnHistory', json_encode($log));
$ab = json_decode($hub->GetWarningHistory(0, time() - 3600), true)['entries'];
check('SinceTs filtert ältere Einträge heraus', count($ab) === 1 && $ab[0]['kind'] === 'ende');

// ------------------------------------------------------------------
echo "\n== standortKey: fest, umbenennbar ==\n";
$hub = neuerHub([$home, zeile('Büro', 48.0, 7.8)]);
$k1 = callPrivate($hub, 'syncStandortKeys');
check('zwei Standorte, zwei verschiedene Schlüssel', count(array_unique($k1)) === 2);
check('stabil beim nächsten Aufruf', callPrivate($hub, 'syncStandortKeys') === $k1);
$hub->SetProp('Standorte', json_encode([zeile('Daheim', 48.4785, 7.9448), zeile('Büro', 48.0, 7.8)]));
$k2 = callPrivate($hub, 'syncStandortKeys');
check('umbenannt (gleiche Koordinaten) -> Schlüssel bleibt', ($k2['Daheim'] ?? null) === $k1['Zuhause']);
$hub->SetProp('Standorte', json_encode([zeile('Daheim', 48.4785, 7.9448), zeile('Büro', 48.1, 7.9)]));
check('verschoben (gleicher Name) -> Schlüssel bleibt', (callPrivate($hub, 'syncStandortKeys')['Büro'] ?? null) === $k1['Büro']);
$hub->SetProp('Standorte', json_encode([zeile('Daheim', 48.4785, 7.9448), zeile('Werkstatt', 47.5, 7.6)]));
$k3 = callPrivate($hub, 'syncStandortKeys');
check('Name UND Ort neu -> neuer Schlüssel', ($k3['Werkstatt'] ?? null) !== $k1['Büro'] && preg_match('/^loc-/', $k3['Werkstatt']) === 1);
$hub->SetProp('Standorte', json_encode([zeile('Daheim', 48.4785, 7.9448), zeile('Werkstatt', 47.5, 7.6), zeile('Büro', 48.1, 7.9)]));
check('entfernte Zeile kommt zurück -> alter Schlüssel', (callPrivate($hub, 'syncStandortKeys')['Büro'] ?? null) === $k1['Büro']);
$auto = zeile('Auto', 0, 0, ['QuellVarLat' => 111, 'QuellVarLon' => 112]);
$hub = neuerHub([$auto]);
$ka = callPrivate($hub, 'syncStandortKeys')['Auto'];
$hub->SetProp('Standorte', json_encode([zeile('Tesla', 0, 0, ['QuellVarLat' => 111, 'QuellVarLon' => 112])]));
check('mobiler Standort umbenannt (gleiche Live-Variablen) -> Schlüssel bleibt', (callPrivate($hub, 'syncStandortKeys')['Tesla'] ?? null) === $ka);

echo "\n== isSystemLocation ==\n";
$GLOBALS['whub_test_locationInstances'] = [4711];
$GLOBALS['whub_test_properties'][4711]['Location'] = json_encode(['latitude' => 48.4800, 'longitude' => 7.9450]);
$hub = neuerHub([zeile('Büro', 48.0, 7.8), $home, zeile('Auto', 48.48, 7.945, ['QuellVarLat' => 111, 'QuellVarLon' => 112]), zeile('Nachbar', 48.4850, 7.9448)]);
$kat = [];
foreach (callPrivate($hub, 'standortCatalog') as $e) {
    $kat[$e['name']] = $e;
}
check('nächstgelegener fester Standort ist Systemstandort', $kat['Zuhause']['isSystemLocation'] === true);
check('weiter entfernter (0,6 km) nicht -- nur einer', $kat['Nachbar']['isSystemLocation'] === false);
check('ferner Standort nicht', $kat['Büro']['isSystemLocation'] === false);
check('mobiler Standort nie, auch wenn er gerade zuhause steht', $kat['Auto']['isSystemLocation'] === false && $kat['Auto']['mobile'] === true);
$GLOBALS['whub_test_properties'][4711]['Location'] = json_encode(['latitude' => 52.52, 'longitude' => 13.40]);
$keiner = array_filter(callPrivate($hub, 'standortCatalog'), fn ($e) => $e['isSystemLocation']);
check('kein Standort im Umkreis von 1 km -> keiner', $keiner === []);
$GLOBALS['whub_test_locationInstances'] = [];
$keiner = array_filter(callPrivate($hub, 'standortCatalog'), fn ($e) => $e['isSystemLocation']);
check('kein Symcon-Standort konfiguriert -> keiner', $keiner === []);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
