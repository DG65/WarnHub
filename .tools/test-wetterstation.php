<?php

/**
 * Prüfstand für die eigene Wetterstation (Froggit) als unabhängige,
 * lokale Warnquelle -- Dietmars Wunsch 04.09.2026: "die Unwetterwarnungen
 * können sich ja auch irren". Deckt fetchWetterstation() (Schwellwert-
 * Logik, Platzierung am Symcon-Systemstandort) UND DiscoverWetterstation()
 * (Objektbaum-Suche, inkl. Ablehnung einer namensähnlichen, aber
 * ungeeigneten Instanz ohne die benötigten Felder) ab. Kein Netzzugriff
 * nötig.
 *
 *   php .tools/test-wetterstation.php    # 0 = alle Prüfungen bestanden
 */

const FROGGIT_GUID = '{499F8100-B051-E713-CEC0-499D795B2639}';
const OTHER_FROGGIT_MODULE_GUID = '{22222222-2222-2222-2222-222222222222}';
const WEATHERSTATION_WU_GUID = '{FBDB2770-0232-43D2-F40B-1240CEAF6CD4}';
const METEOBRIDGE_GUID = '{24A6FC41-748D-4843-BEF9-0606DBB95CD3}';
const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
const LOCATION_INSTANCE_ID = 900;

// Fake-Objektbaum für DiscoverWetterstation():
//   10 Instanz "Wetterstation" (exakte Froggit-GUID) -> 101 "Windböe"
//      (Profil ~WindSpeed.kmh), 102 "Regenrate" (~Rainfall), 103 "Windböe
//      (Max.) Tag" (Dekoy, exakter Namensabgleich darf sie NICHT mit 101
//      verwechseln)
//   11 Instanz "Andere Wetterstation" (nur über Namenssuche "froggit"
//      auffindbar, KEINE Windböe/Regenrate-Variable -- muss trotz
//      Namenstreffer abgelehnt werden)
//   20 Instanz "Sainlogic" (Wolbolar/IPSymconWeatherStation, exakte GUID)
//      -> 201 Ident "Windgust" (Anzeigename "Wind gust", NICHT "Windböe" --
//         der Treffer muss über den Ident laufen, nicht den Namen; echtes
//         Profil laut Quellcode ~WindSpeed.ms, NICHT km/h -- prüft die
//         Einheiten-Umrechnung), 202 Ident "rainin" (Anzeigename "Rain",
//         ~Rainfall)
//   30 Instanz "Meteobridge" (elueckel/Symcon_Meteobridge_Meteohub, exakte
//      GUID) -> 301 Ident "Wind_Gust_KmH" (~WindSpeed.kmh, schon km/h),
//      302 Ident "Rain_Rate" (~Rainfall)
$GLOBALS['whub_test_tree'] = [
    10 => [101, 102, 103],
    11 => [111],
    20 => [201, 202],
    30 => [301, 302],
];
$GLOBALS['whub_test_objects'] = [
    10 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation', 'ObjectIdent' => ''],
    11 => ['ObjectType' => 1, 'ObjectName' => 'Andere Wetterstation', 'ObjectIdent' => ''],
    20 => ['ObjectType' => 1, 'ObjectName' => 'Sainlogic', 'ObjectIdent' => ''],
    30 => ['ObjectType' => 1, 'ObjectName' => 'Meteobridge', 'ObjectIdent' => ''],
    101 => ['ObjectType' => 2, 'ObjectName' => 'Windböe', 'ObjectIdent' => ''],
    102 => ['ObjectType' => 2, 'ObjectName' => 'Regenrate', 'ObjectIdent' => ''],
    103 => ['ObjectType' => 2, 'ObjectName' => 'Windböe (Max.) Tag', 'ObjectIdent' => ''],
    111 => ['ObjectType' => 2, 'ObjectName' => 'Innentemperatur', 'ObjectIdent' => ''],
    201 => ['ObjectType' => 2, 'ObjectName' => 'Wind gust', 'ObjectIdent' => 'Windgust'],
    202 => ['ObjectType' => 2, 'ObjectName' => 'Rain', 'ObjectIdent' => 'rainin'],
    301 => ['ObjectType' => 2, 'ObjectName' => 'Wind Gust km/h', 'ObjectIdent' => 'Wind_Gust_KmH'],
    302 => ['ObjectType' => 2, 'ObjectName' => 'Rain Rate', 'ObjectIdent' => 'Rain_Rate'],
];
$GLOBALS['whub_test_variableProfiles'] = [
    101 => '~WindSpeed.kmh',
    102 => '~Rainfall',
    201 => '~WindSpeed.ms', // echtes Wolbolar-Verhalten -- KEIN km/h
    202 => '~Rainfall',
    301 => '~WindSpeed.kmh',
    302 => '~Rainfall',
];
$GLOBALS['whub_test_instancesByModule'] = [
    FROGGIT_GUID => [10],
    WEATHERSTATION_WU_GUID => [20],
    METEOBRIDGE_GUID => [30],
];
$GLOBALS['whub_test_moduleNames'] = [
    OTHER_FROGGIT_MODULE_GUID => 'Froggit Legacy',
];
$GLOBALS['whub_test_instancesByOtherModule'] = [
    OTHER_FROGGIT_MODULE_GUID => [11],
];
$GLOBALS['whub_test_values'] = [];
$GLOBALS['whub_test_properties'] = [];
$GLOBALS['whub_test_kernelVersion'] = 9.0;
$GLOBALS['whub_test_formFieldSets'] = [];

function IPS_GetChildrenIDs(int $id): array
{
    return $GLOBALS['whub_test_tree'][$id] ?? [];
}
function IPS_GetObject(int $id)
{
    return $GLOBALS['whub_test_objects'][$id] ?? false;
}
function IPS_GetVariable(int $id)
{
    if (!isset($GLOBALS['whub_test_objects'][$id]) || $GLOBALS['whub_test_objects'][$id]['ObjectType'] !== 2) {
        return false;
    }
    return [
        'VariableProfile' => $GLOBALS['whub_test_variableProfiles'][$id] ?? '',
        'VariableCustomProfile' => '',
    ];
}
function IPS_GetVariableList(): array
{
    return array_keys(array_filter($GLOBALS['whub_test_objects'], fn ($o) => $o['ObjectType'] === 2));
}
function IPS_GetName(int $id): string
{
    return $GLOBALS['whub_test_objects'][$id]['ObjectName'] ?? '';
}
function IPS_GetInstanceListByModuleID(string $guid): array
{
    if (isset($GLOBALS['whub_test_instancesByModule'][$guid])) {
        return $GLOBALS['whub_test_instancesByModule'][$guid];
    }
    if ($guid === LOCATION_CONTROL_GUID) {
        return $GLOBALS['whub_test_locationInstances'] ?? [];
    }
    return $GLOBALS['whub_test_instancesByOtherModule'][$guid] ?? [];
}
function IPS_VariableExists(int $id): bool
{
    return isset($GLOBALS['whub_test_objects'][$id]) && $GLOBALS['whub_test_objects'][$id]['ObjectType'] === 2;
}
function IPS_GetModuleList(): array
{
    return array_keys($GLOBALS['whub_test_moduleNames']);
}
function IPS_GetModule(string $guid): array
{
    return ['ModuleName' => $GLOBALS['whub_test_moduleNames'][$guid] ?? 'Irgendwas'];
}
function IPS_InstanceExists(int $id): bool
{
    return isset($GLOBALS['whub_test_objects'][$id]) && $GLOBALS['whub_test_objects'][$id]['ObjectType'] === 1;
}
function IPS_GetKernelVersion(): float
{
    return $GLOBALS['whub_test_kernelVersion'];
}
function IPS_GetProperty(int $id, string $name)
{
    return $GLOBALS['whub_test_properties'][$id][$name] ?? '';
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_values'][$id] ?? 0;
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
        $GLOBALS['whub_test_formFieldSets'][] = [$n, $k, $v];
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

echo "== fetchWetterstation(): keine Instanz konfiguriert ==\n";
$hub = new WarnHub();
$hub->Create();
check('WetterstationInstanceID = 0 -> leeres Ergebnis, kein Fehler', callPrivate($hub, 'fetchWetterstation') === []);

echo "\n== fetchWetterstation(): konfigurierte Instanz existiert nicht mehr ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$hub2->SetProp('WetterstationInstanceID', 999888);
check('nicht (mehr) existierende Instanz -> leeres Ergebnis statt Fehler', callPrivate($hub2, 'fetchWetterstation') === []);

echo "\n== fetchWetterstation(): kein Symcon-Systemstandort konfiguriert ==\n";
$GLOBALS['whub_test_locationInstances'] = []; // keine Standort-Kerninstanz
$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('WetterstationInstanceID', 10);
check('ohne Systemstandort -> leeres Ergebnis (keine geratene Platzierung)', callPrivate($hub3, 'fetchWetterstation') === []);

echo "\n== fetchWetterstation(): mit Systemstandort, Werte unter Schwelle ==\n";
$GLOBALS['whub_test_locationInstances'] = [LOCATION_INSTANCE_ID];
$GLOBALS['whub_test_properties'][LOCATION_INSTANCE_ID]['Location'] = json_encode(['latitude' => 48.4785, 'longitude' => 7.9448]);
$GLOBALS['whub_test_values'] = [101 => 10.0, 102 => 0.5]; // Windböe 10 km/h, Regenrate 0.5 mm/h -- beide unauffällig
$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('WetterstationInstanceID', 10);
check('Werte unter beiden Schwellwerten -> keine Warnung', callPrivate($hub4, 'fetchWetterstation') === []);

echo "\n== fetchWetterstation(): Windböe über Schwelle ==\n";
$GLOBALS['whub_test_values'] = [101 => 85.0, 102 => 0.5]; // Windböe 85 km/h > Standard-Schwelle 70
$result = callPrivate($hub4, 'fetchWetterstation');
check('genau eine Warnung (nur Windböe über Schwelle)', count($result) === 1);
check('identifier ist stabil (an die Instanz-ID gebunden, kein Zeitstempel)', $result[0]['identifier'] === 'wetterstation-windboe-10');
check('source ist "wetterstation"', $result[0]['source'] === 'wetterstation');
check('event enthält "Sturm" (für classifyEventCategory())', str_contains($result[0]['event'], 'Sturm'));
check('Kreis liegt exakt am Symcon-Systemstandort, Radius 3 km', $result[0]['circles'][0]['lat'] === 48.4785 && $result[0]['circles'][0]['lon'] === 7.9448 && $result[0]['circles'][0]['radiusKm'] === 3.0);
check('Beschreibung kennzeichnet den Wert als eigenen Schwellwert, keine amtliche Klassifikation', str_contains($result[0]['description'], 'keine amtliche Klassifikation'));
check('classifyEventCategory() ordnet die Meldung korrekt der Kategorie "sturm" zu (Schutzaktionen greifen)', callPrivate($hub4, 'classifyEventCategory', [$result[0]['event'], $result[0]['headline']]) === 'sturm');

echo "\n== fetchWetterstation(): Regenrate über Schwelle ==\n";
$GLOBALS['whub_test_values'] = [101 => 10.0, 102 => 30.0]; // Regenrate 30 mm/h > Standard-Schwelle 25
$result = callPrivate($hub4, 'fetchWetterstation');
check('genau eine Warnung (nur Regenrate über Schwelle)', count($result) === 1);
check('identifier ist stabil', $result[0]['identifier'] === 'wetterstation-regenrate-10');
check('event enthält "Starkregen"', str_contains($result[0]['event'], 'Starkregen'));
check('classifyEventCategory() ordnet korrekt "starkregen" zu', callPrivate($hub4, 'classifyEventCategory', [$result[0]['event'], $result[0]['headline']]) === 'starkregen');

echo "\n== fetchWetterstation(): beide Werte gleichzeitig über Schwelle ==\n";
$GLOBALS['whub_test_values'] = [101 => 90.0, 102 => 40.0];
check('genau zwei unabhängige Warnungen (Windböe UND Regenrate)', count(callPrivate($hub4, 'fetchWetterstation')) === 2);

echo "\n== fetchWetterstation(): eigener Schwellwert wird respektiert ==\n";
$hub5 = new WarnHub();
$hub5->Create();
$hub5->SetProp('WetterstationInstanceID', 10);
// Alle drei Stufen müssen angehoben werden -- sie wirken unabhängig
// voneinander, eine einzelne höhere Moderate-Stufe allein würde die
// niedrigere Severe-/Extreme-Stufe nicht überschreiben.
$hub5->SetProp('WetterstationWindSchwelleModerate', 120.0);
$hub5->SetProp('WetterstationWindSchwelleSevere', 150.0);
$hub5->SetProp('WetterstationWindSchwelleExtreme', 200.0);
$GLOBALS['whub_test_values'] = [101 => 90.0, 102 => 0.0]; // unter allen drei hochgesetzten Schwellwerten
check('alle drei Stufen hochgesetzt -> 90 km/h löst NICHT mehr aus', callPrivate($hub5, 'fetchWetterstation') === []);

echo "\n== windSeverityForSpeed(): drei Stufen, Standardwerte 40/65/90 km/h ==\n";
$hub5b = new WarnHub();
$hub5b->Create();
check('39 km/h -> unter der niedrigsten Stufe, keine Warnung (null)', callPrivate($hub5b, 'windSeverityForSpeed', [39.0]) === null);
check('40 km/h (Moderate-Grenze) -> "Moderate"', callPrivate($hub5b, 'windSeverityForSpeed', [40.0]) === 'Moderate');
check('64.9 km/h -> weiterhin "Moderate" (noch unter Severe)', callPrivate($hub5b, 'windSeverityForSpeed', [64.9]) === 'Moderate');
check('65 km/h (Severe-Grenze) -> "Severe"', callPrivate($hub5b, 'windSeverityForSpeed', [65.0]) === 'Severe');
check('89.9 km/h -> weiterhin "Severe" (noch unter Extreme)', callPrivate($hub5b, 'windSeverityForSpeed', [89.9]) === 'Severe');
check('90 km/h (Extreme-Grenze) -> "Extreme"', callPrivate($hub5b, 'windSeverityForSpeed', [90.0]) === 'Extreme');
check('150 km/h -> weiterhin "Extreme" (Deckel, keine höhere Stufe)', callPrivate($hub5b, 'windSeverityForSpeed', [150.0]) === 'Extreme');

echo "\n== regenSeverityForRate(): drei Stufen, Standardwerte 15/25/40 mm/h (DWDs eigene Starkregen-Warnstufen) ==\n";
check('14.9 mm/h -> unter der niedrigsten Stufe, keine Warnung (null)', callPrivate($hub5b, 'regenSeverityForRate', [14.9]) === null);
check('15 mm/h (Moderate-Grenze) -> "Moderate"', callPrivate($hub5b, 'regenSeverityForRate', [15.0]) === 'Moderate');
check('24.9 mm/h -> weiterhin "Moderate" (noch unter Severe)', callPrivate($hub5b, 'regenSeverityForRate', [24.9]) === 'Moderate');
check('25 mm/h (Severe-Grenze) -> "Severe"', callPrivate($hub5b, 'regenSeverityForRate', [25.0]) === 'Severe');
check('39.9 mm/h -> weiterhin "Severe" (noch unter Extreme)', callPrivate($hub5b, 'regenSeverityForRate', [39.9]) === 'Severe');
check('40 mm/h (Extreme-Grenze) -> "Extreme"', callPrivate($hub5b, 'regenSeverityForRate', [40.0]) === 'Extreme');
check('100 mm/h -> weiterhin "Extreme" (Deckel, keine höhere Stufe)', callPrivate($hub5b, 'regenSeverityForRate', [100.0]) === 'Extreme');

echo "\n== fetchWetterstation(): meldet die tatsächlich erreichte Stufe als severity, nicht mehr pauschal Severe ==\n";
$hub5c = new WarnHub();
$hub5c->Create();
$hub5c->SetProp('WetterstationInstanceID', 10);
$GLOBALS['whub_test_values'] = [101 => 45.0, 102 => 0.0]; // 45 km/h -> Moderate, nicht Severe
$resultModerate = callPrivate($hub5c, 'fetchWetterstation');
check('45 km/h löst bereits aus (Moderate-Stufe, Standard 40 km/h) -- vorher hätte der pauschale 70-km/h-Wert das NICHT gemeldet', count($resultModerate) === 1);
check('severity ist "Moderate", nicht mehr pauschal "Severe"', $resultModerate[0]['severity'] === 'Moderate');
check('Beschreibung nennt die Stufe beim Namen', str_contains($resultModerate[0]['description'], 'Moderate'));

echo "\n== fetchWetterstation(): Regenrate meldet ebenfalls die tatsächlich erreichte Stufe ==\n";
$hub5d = new WarnHub();
$hub5d->Create();
$hub5d->SetProp('WetterstationInstanceID', 10);
$GLOBALS['whub_test_values'] = [101 => 0.0, 102 => 18.0]; // 18 mm/h -> Moderate (Standard 15), nicht mehr Severe (Standard bisher 25)
$resultRegenModerate = callPrivate($hub5d, 'fetchWetterstation');
check('18 mm/h löst bereits aus (Moderate-Stufe, Standard 15 mm/h) -- vorher hätte der pauschale 25-mm/h-Wert das NICHT gemeldet', count($resultRegenModerate) === 1);
check('severity ist "Moderate"', $resultRegenModerate[0]['severity'] === 'Moderate');
check('Beschreibung nennt die Stufe beim Namen', str_contains($resultRegenModerate[0]['description'], 'Moderate'));

echo "\n== DiscoverWetterstation(): korrekte Instanz über exakte Froggit-GUID gefunden ==\n";
$hub6 = new WarnHub();
$hub6->Create();
$GLOBALS['whub_test_formFieldSets'] = [];
$msg = $hub6->DiscoverWetterstation();
check('meldet Erfolg mit Instanznamen', str_contains($msg, 'Wetterstation') && str_contains($msg, 'gefunden'));
$setCalls = array_filter($GLOBALS['whub_test_formFieldSets'], fn ($c) => $c[0] === 'WetterstationInstanceID');
check('schreibt Instanz-ID 10 ins Formularfeld "WetterstationInstanceID"', count($setCalls) === 1 && array_values($setCalls)[0][2] === 10);

echo "\n== DiscoverWetterstation(): namensähnliche, aber UNGEEIGNETE Instanz wird abgelehnt ==\n";
$GLOBALS['whub_test_instancesByModule'][FROGGIT_GUID] = []; // exakte GUID liefert diesmal nichts -> Namenssuche "froggit" greift, findet Modul "Froggit Legacy" -> Instanz 11
$GLOBALS['whub_test_instancesByModule'][WEATHERSTATION_WU_GUID] = []; // auch die zweite unterstützte Wetterstation darf hier nicht mehr gefunden werden
$GLOBALS['whub_test_instancesByModule'][METEOBRIDGE_GUID] = []; // und auch die dritte nicht
$hub7 = new WarnHub();
$hub7->Create();
$GLOBALS['whub_test_formFieldSets'] = [];
$msg2 = $hub7->DiscoverWetterstation();
check('meldet KEINEN Treffer, obwohl der Modulname "froggit" passt (Instanz 11 hat weder Windböe noch Regenrate)', str_contains($msg2, 'Keine unterstützte Wetterstations-Instanz'));
check('schreibt NICHTS ins Formularfeld (kein Fehltreffer übernommen)', count(array_filter($GLOBALS['whub_test_formFieldSets'], fn ($c) => $c[0] === 'WetterstationInstanceID')) === 0);

echo "\n== DiscoverWetterstation(): zweites unterstütztes Modul (Wolbolar/IPSymconWeatherStation, Sainlogic/ELV via Wunderground-Protokoll) über Ident statt Anzeigename ==\n";
$GLOBALS['whub_test_instancesByModule'][FROGGIT_GUID] = []; // kein Froggit im System -- die zweite Quelle muss trotzdem gefunden werden
$GLOBALS['whub_test_instancesByModule'][WEATHERSTATION_WU_GUID] = [20];
$hub8 = new WarnHub();
$hub8->Create();
$GLOBALS['whub_test_formFieldSets'] = [];
$msg3 = $hub8->DiscoverWetterstation();
check('meldet Erfolg (Sainlogic/ELV, Windgust/rainin)', str_contains($msg3, 'gefunden') && str_contains($msg3, 'Sainlogic'));
$setCalls3 = array_filter($GLOBALS['whub_test_formFieldSets'], fn ($c) => $c[0] === 'WetterstationInstanceID');
check('schreibt Instanz-ID 20 ins Formularfeld -- gefunden über den Ident "Windgust", NICHT den Anzeigenamen "Wind gust"', count($setCalls3) === 1 && array_values($setCalls3)[0][2] === 20);

echo "\n== fetchWetterstation(): liest Wolbolar-Instanz korrekt über Windgust/rainin-Ident, MIT Einheiten-Umrechnung m/s -> km/h ==\n";
$hub9 = new WarnHub();
$hub9->Create();
$hub9->SetProp('WetterstationInstanceID', 20);
// 201 (Windgust) traegt laut Fixture das Profil ~WindSpeed.ms (echtes
// Wolbolar-Verhalten) -- 20 m/s = 72 km/h, ueber dem Standard-Schwellwert
// (70 km/h) NUR nach korrekter Umrechnung, nicht als Rohwert (20 waere
// weit darunter). 202 (rainin) liegt ueber dem Regen-Schwellwert.
$GLOBALS['whub_test_values'] = [201 => 20.0, 202 => 30.0];
$result3 = callPrivate($hub9, 'fetchWetterstation');
check('genau zwei Warnungen (Wind über Ident 201 NACH m/s->km/h-Umrechnung, Regen über Ident 202)', count($result3) === 2);
check('identifier bleibt wie beim Froggit-Pfad an die INSTANZ gebunden, nicht an die Variable', $result3[0]['identifier'] === 'wetterstation-windboe-20');
$windEintrag = array_values(array_filter($result3, fn ($w) => $w['event'] === 'Sturm (eigene Messung)'))[0] ?? null;
check('Meldungstext nennt den umgerechneten km/h-Wert (72,0), nicht den rohen m/s-Wert (20,0)', $windEintrag !== null && str_contains($windEintrag['headline'], '72,0'));

echo "\n== readWindSpeedKmh(): Einheiten-Umrechnung isoliert geprüft ==\n";
check('~WindSpeed.kmh (Froggit, 101) -- Rohwert bleibt unverändert', callPrivate($hub9, 'readWindSpeedKmh', [101]) === 0.0); // kein Wert gesetzt -> 0
$GLOBALS['whub_test_values'][101] = 44.5;
check('~WindSpeed.kmh -- 44.5 bleibt 44.5 (keine Umrechnung)', callPrivate($hub9, 'readWindSpeedKmh', [101]) === 44.5);
$GLOBALS['whub_test_values'][201] = 10.0;
check('~WindSpeed.ms -- 10.0 m/s wird zu 36.0 km/h umgerechnet (Faktor 3.6)', callPrivate($hub9, 'readWindSpeedKmh', [201]) === 36.0);
$GLOBALS['whub_test_values'][111] = 50.0;
check('Variable ohne Profil (z. B. reine KNX-Variable ohne Profilzuweisung) -- Rohwert wird als km/h angenommen, keine Umrechnung', callPrivate($hub9, 'readWindSpeedKmh', [111]) === 50.0);

echo "\n== fetchWetterstation(): liest Meteobridge-Instanz korrekt (Wind_Gust_KmH bereits in km/h) ==\n";
$hub9b = new WarnHub();
$hub9b->Create();
$hub9b->SetProp('WetterstationInstanceID', 30);
$GLOBALS['whub_test_values'] = [301 => 85.0, 302 => 30.0];
$result3b = callPrivate($hub9b, 'fetchWetterstation');
check('genau zwei Warnungen (Meteobridge, bereits km/h, keine Umrechnung nötig)', count($result3b) === 2);
check('identifier ist instanzbasiert', $result3b[0]['identifier'] === 'wetterstation-windboe-30');

echo "\n== fetchWetterstation(): manuelle Wind-/Regen-Variable hat Vorrang vor der Instanz (z. B. KNX) ==\n";
$hub10 = new WarnHub();
$hub10->Create();
$hub10->SetProp('WetterstationInstanceID', 10); // Froggit-Instanz weiterhin konfiguriert
$hub10->SetProp('WetterstationWindVariableID', 111); // manuelle Variable überschreibt sie für Wind
$GLOBALS['whub_test_values'] = [101 => 5.0, 102 => 0.0, 111 => 95.0]; // Froggit-Windböe (101) wäre zu niedrig, manuelle Variable (111) liegt über dem Schwellwert
$result4 = callPrivate($hub10, 'fetchWetterstation');
check('genau eine Warnung (nur Wind, über die manuelle Variable ausgelöst)', count($result4) === 1);
check('identifier trägt den neuen "var<ID>"-Suffix (unterscheidbar vom instanzbasierten Format)', $result4[0]['identifier'] === 'wetterstation-windboe-var111');

echo "\n== fetchWetterstation(): gemischte Quellen -- Wind manuell (z. B. KNX), Regen weiterhin von der Froggit-Instanz ==\n";
$hub11 = new WarnHub();
$hub11->Create();
$hub11->SetProp('WetterstationInstanceID', 10);
$hub11->SetProp('WetterstationWindVariableID', 111);
$GLOBALS['whub_test_values'] = [101 => 5.0, 102 => 40.0, 111 => 95.0]; // Regenrate (102) über dem Standard-Schwellwert
$result5 = callPrivate($hub11, 'fetchWetterstation');
check('zwei Warnungen: Wind von der manuellen Variable, Regen weiterhin von der Froggit-Instanz', count($result5) === 2);
$byEvent = array_column($result5, null, 'event');
check('Regen-Identifier bleibt instanzbasiert (unverändertes Format)', ($byEvent['Starkregen (eigene Messung)']['identifier'] ?? null) === 'wetterstation-regenrate-10');

echo "\n== fetchWetterstation(): nur manuelle Variablen konfiguriert, KEINE Instanz (reiner KNX-Fall) ==\n";
$hub12 = new WarnHub();
$hub12->Create();
$hub12->SetProp('WetterstationWindVariableID', 111);
$GLOBALS['whub_test_values'] = [111 => 95.0];
$result6 = callPrivate($hub12, 'fetchWetterstation');
check('funktioniert auch komplett ohne Wetterstations-Instanz', count($result6) === 1 && $result6[0]['identifier'] === 'wetterstation-windboe-var111');

echo "\n== fetchWetterstation(): weder Instanz noch manuelle Variable konfiguriert -> kein Fehler, leeres Ergebnis ==\n";
$hub13 = new WarnHub();
$hub13->Create();
check('leeres Ergebnis statt Fehler', callPrivate($hub13, 'fetchWetterstation') === []);

echo "\n== DiscoverWetterstation(): drittes unterstütztes Modul (Meteobridge/Meteohub) über Ident ==\n";
$GLOBALS['whub_test_instancesByModule'][FROGGIT_GUID] = [];
$GLOBALS['whub_test_instancesByModule'][WEATHERSTATION_WU_GUID] = [];
$GLOBALS['whub_test_instancesByModule'][METEOBRIDGE_GUID] = [30];
$hub14 = new WarnHub();
$hub14->Create();
$GLOBALS['whub_test_formFieldSets'] = [];
$msg4 = $hub14->DiscoverWetterstation();
check('meldet Erfolg (Meteobridge/Meteohub, Wind_Gust_KmH/Rain_Rate)', str_contains($msg4, 'gefunden') && str_contains($msg4, 'Meteobridge'));
$setCalls4 = array_filter($GLOBALS['whub_test_formFieldSets'], fn ($c) => $c[0] === 'WetterstationInstanceID');
check('schreibt Instanz-ID 30 ins Formularfeld', count($setCalls4) === 1 && array_values($setCalls4)[0][2] === 30);

echo "\n== DiscoverWetterstation(): letzter Rückfall -- eindeutiger systemweiter Profil-Treffer (z. B. profilierte KNX-Variable) wird in die manuelle Auswahl übernommen ==\n";
$GLOBALS['whub_test_instancesByModule'][FROGGIT_GUID] = [];
$GLOBALS['whub_test_instancesByModule'][WEATHERSTATION_WU_GUID] = [];
$GLOBALS['whub_test_instancesByModule'][METEOBRIDGE_GUID] = [];
// Isolierter Fake-Baum: nur diese EINE zusätzliche profilierte Wind-Variable
// im ganzen System (401, z. B. eine KNX-Gruppenadresse mit manuell
// zugewiesenem Profil) -- die drei bekannten Module liefern hier nichts.
$origObjects = $GLOBALS['whub_test_objects'];
$origProfiles = $GLOBALS['whub_test_variableProfiles'];
$GLOBALS['whub_test_tree'] = []; // keine der bekannten Instanzen hat mehr Kinder -> ihre Ident-/Namenssuche findet nichts
$GLOBALS['whub_test_objects'] = [401 => ['ObjectType' => 2, 'ObjectName' => 'KNX Windgeschwindigkeit', 'ObjectIdent' => '']];
$GLOBALS['whub_test_variableProfiles'] = [401 => '~WindSpeed.kmh'];
$hub15 = new WarnHub();
$hub15->Create();
$GLOBALS['whub_test_formFieldSets'] = [];
$msg5 = $hub15->DiscoverWetterstation();
check('meldet Erfolg über den Profil-Rückfall (kein bekanntes Modul, aber eindeutige Wind-Variable im System)', str_contains($msg5, 'Standard-Profil'));
$setCalls5 = array_filter($GLOBALS['whub_test_formFieldSets'], fn ($c) => $c[0] === 'WetterstationWindVariableID');
check('schreibt die gefundene Variable (401) in die manuelle Wind-Auswahl', count($setCalls5) === 1 && array_values($setCalls5)[0][2] === 401);

echo "\n== DiscoverWetterstation(): Profil-Rückfall rät NICHT bei mehreren Kandidaten ==\n";
$GLOBALS['whub_test_objects'][402] = ['ObjectType' => 2, 'ObjectName' => 'Zweite Windgeschwindigkeit', 'ObjectIdent' => ''];
$GLOBALS['whub_test_variableProfiles'][402] = '~WindSpeed.ms';
$hub16 = new WarnHub();
$hub16->Create();
$GLOBALS['whub_test_formFieldSets'] = [];
$msg6 = $hub16->DiscoverWetterstation();
check('bei mehreren Kandidaten im System: kein Rateversuch, klare "nichts gefunden"-Rückmeldung', str_contains($msg6, 'Keine unterstützte Wetterstations-Instanz'));
check('schreibt NICHTS in die manuelle Auswahl', count(array_filter($GLOBALS['whub_test_formFieldSets'], fn ($c) => in_array($c[0], ['WetterstationWindVariableID', 'WetterstationRegenVariableID'], true))) === 0);
// Fixture wieder in den Ausgangszustand versetzen.
$GLOBALS['whub_test_objects'] = $origObjects;
$GLOBALS['whub_test_variableProfiles'] = $origProfiles;
$GLOBALS['whub_test_tree'] = [10 => [101, 102, 103], 11 => [111], 20 => [201, 202], 30 => [301, 302]];

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
