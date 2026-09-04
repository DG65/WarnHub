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
const LOCATION_CONTROL_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
const LOCATION_INSTANCE_ID = 900;

// Fake-Objektbaum für DiscoverWetterstation():
//   10 Instanz "Wetterstation" (exakte Froggit-GUID) -> 101 "Windböe",
//      102 "Regenrate", 103 "Windböe (Max.) Tag" (Dekoy, exakter
//      Namensabgleich darf sie NICHT mit 101 verwechseln)
//   11 Instanz "Andere Wetterstation" (nur über Namenssuche "froggit"
//      auffindbar, KEINE Windböe/Regenrate-Variable -- muss trotz
//      Namenstreffer abgelehnt werden)
//   20 Instanz "Sainlogic" (Wolbolar/IPSymconWeatherStation, exakte GUID)
//      -> 201 Ident "Windgust" (Anzeigename "Wind gust", NICHT "Windböe" --
//         der Treffer muss über den Ident laufen, nicht den Namen),
//         202 Ident "rainin" (Anzeigename "Rain")
$GLOBALS['whub_test_tree'] = [
    10 => [101, 102, 103],
    11 => [111],
    20 => [201, 202],
];
$GLOBALS['whub_test_objects'] = [
    10 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation', 'ObjectIdent' => ''],
    11 => ['ObjectType' => 1, 'ObjectName' => 'Andere Wetterstation', 'ObjectIdent' => ''],
    20 => ['ObjectType' => 1, 'ObjectName' => 'Sainlogic', 'ObjectIdent' => ''],
    101 => ['ObjectType' => 2, 'ObjectName' => 'Windböe', 'ObjectIdent' => ''],
    102 => ['ObjectType' => 2, 'ObjectName' => 'Regenrate', 'ObjectIdent' => ''],
    103 => ['ObjectType' => 2, 'ObjectName' => 'Windböe (Max.) Tag', 'ObjectIdent' => ''],
    111 => ['ObjectType' => 2, 'ObjectName' => 'Innentemperatur', 'ObjectIdent' => ''],
    201 => ['ObjectType' => 2, 'ObjectName' => 'Wind gust', 'ObjectIdent' => 'Windgust'],
    202 => ['ObjectType' => 2, 'ObjectName' => 'Rain', 'ObjectIdent' => 'rainin'],
];
$GLOBALS['whub_test_instancesByModule'] = [
    FROGGIT_GUID => [10],
    WEATHERSTATION_WU_GUID => [20],
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
$hub5->SetProp('WetterstationWindboeSchwelle', 100.0);
$GLOBALS['whub_test_values'] = [101 => 90.0, 102 => 0.0]; // unter dem hochgesetzten eigenen Schwellwert
check('hochgesetzter eigener Schwellwert (100 km/h) -> 90 km/h löst NICHT mehr aus', callPrivate($hub5, 'fetchWetterstation') === []);

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

echo "\n== fetchWetterstation(): liest Wolbolar-Instanz korrekt über Windgust/rainin-Ident ==\n";
$hub9 = new WarnHub();
$hub9->Create();
$hub9->SetProp('WetterstationInstanceID', 20);
$GLOBALS['whub_test_values'] = [201 => 85.0, 202 => 30.0]; // beide über dem Standard-Schwellwert
$result3 = callPrivate($hub9, 'fetchWetterstation');
check('genau zwei Warnungen (Wind über Ident 201, Regen über Ident 202)', count($result3) === 2);
check('identifier bleibt wie beim Froggit-Pfad an die INSTANZ gebunden, nicht an die Variable', $result3[0]['identifier'] === 'wetterstation-windboe-20');

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

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
