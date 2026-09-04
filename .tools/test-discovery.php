<?php

/**
 * Prüfstand für die Objektbaum-Suche (WebFront-Discovery + Schutzaktionen-
 * Discovery) gegen einen kleinen, gestubbten Objektbaum -- kein echtes
 * IPS-System nötig.
 *
 *   php .tools/test-discovery.php    # 0 = alle Prüfungen bestanden
 */

const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
const KACHEL_VISU_GUID = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
const OTHER_MODULE_GUID = '{00000000-0000-0000-0000-000000000000}';
// Frei erfundene GUID, unter der die Kachel-Visualisierung in DIESEM Test
// tatsächlich registriert ist -- bewusst NICHT identisch mit
// KACHEL_VISU_GUID, um exakt Dietmars Live-Fund nachzustellen: die
// exakte GUID liefert null Treffer, erst die Namenssuche ("kachel" im
// Modulnamen) findet die echte Instanz.
const KACHEL_VISU_GUID_ACTUAL = '{11111111-1111-1111-1111-111111111111}';
const TELEGRAM_BOT_GUID = '{32464EBD-4CCC-6174-4031-5AA374F7CD8D}';
// Ebenfalls bewusst über eine Fake-GUID + Namenssuche aufgelöst, analog zu
// KACHEL_VISU_GUID_ACTUAL -- prüft den Rückfallpfad auch für den zweiten,
// neu hinzugekommenen Push-Kanal (nicht nur den ersten).
const PUSHOVER_GUID_ACTUAL = '{22222222-2222-2222-2222-222222222222}';

// Kleiner Fake-Objektbaum:
//   0 (Root)
//    +- 10 Kategorie "Geräte"
//    |   +- 11 Instanz "Raffstore Wohnzimmer" -> 111 Var "Position" (aktionsfähig)
//    |   +- 12 Instanz "Sirene Außen" -> 121 Var "Ein/Aus" (aktionsfähig)
//    |   +- 13 Instanz "Garagentor" -> 131 Var "Status" (NICHT aktionsfähig), 132 Var "Steuerung" (aktionsfähig)
//    |   +- 14 Instanz "Wetterstation" (kein Treffer)
//    |   +- 15 Instanz "Markise Terrasse" -> 151 Var "Position" (aktionsfähig)
//    |   +- 16 Instanz "Schneeflocke" (Auto) -> 161 Var "Hupe" (aktionsfähig, direktes Kind)
//    |   +- 17 Instanz "Kohlekasten" (Auto) -> 171 Var "Hupe" (aktionsfähig, direktes Kind)
//    |   +- 18 Instanz "Trabbi" (Auto) -> 180 Kategorie "Steuerung" -> 181 Var "Hupe"
//    |         (aktionsfähig, ZWEI Ebenen tief -- prüft das Hochlaufen bis
//    |         zur echten Instanz, nicht nur bis zum direkten Elternknoten)
//    +- 20 Instanz "WebFront Familie" (Modul WebFront, exakte GUID liefert Treffer)
//    +- 21 Instanz "WebFront Gast" (Modul WebFront, exakte GUID liefert Treffer)
//    +- 22 Instanz "Dietmar" (Kachel-Visualisierung, NUR über Namenssuche auffindbar)
//    +- 26 Instanz "Familie Bot" (Telegram, exakte GUID liefert Treffer)
//    +- 27 Instanz "Dietmar Pushover" (Pushover, NUR über Namenssuche auffindbar)
//    +- 23 Instanz "Blitz" (Auto, wie Tessies TessieVehicle)
//        +- 231 Var "Fenster schließen" (aktionsfähig -- Treffer, Typ fenster)
//        +- 232 Var "Fenster" (aktionsfähig, ABER ohne "schließen" -- KEIN
//              Treffer, belegt die Stichwort-Spezifität: sonst würden auch
//              reine Fenster-offen-SENSOREN mit anfassen, siehe DISCOVERY_KEYWORDS)
//        +- 233 Var "Heckklappe öffnen/schließen" (aktionsfähig -- Treffer,
//              Typ kofferraum), 234 Var "Tür-/Klappenstatus" (NICHT
//              aktionsfähig, wird als Zustands-Variable automatisch verlinkt
//              -- exakt Tessies reale Feldnamen, live verifiziert 04.09.2026)
//    +- 25 Instanz "Schneemobil" (Auto OHNE Klappenstatus-Variable)
//        +- 251 Var "Heckklappe öffnen/schließen" (aktionsfähig -- Treffer,
//              aber KEINE Zustands-Variable auffindbar -> Zeile bleibt
//              inaktiv, Sicherheitssperre statt Raten)
$GLOBALS['whub_test_tree'] = [
    0 => [10, 20, 21, 22, 26, 27, 23, 25],
    10 => [11, 12, 13, 14, 15, 16, 17, 18],
    11 => [111],
    12 => [121],
    13 => [131, 132],
    14 => [],
    15 => [151],
    16 => [161],
    17 => [171],
    18 => [180],
    180 => [181],
    20 => [],
    21 => [],
    22 => [],
    26 => [],
    27 => [],
    23 => [231, 232, 233, 234],
    25 => [251],
];
$GLOBALS['whub_test_objects'] = [
    10 => ['ObjectType' => 0, 'ObjectName' => 'Geräte'],
    11 => ['ObjectType' => 1, 'ObjectName' => 'Raffstore Wohnzimmer'],
    12 => ['ObjectType' => 1, 'ObjectName' => 'Sirene Außen'],
    13 => ['ObjectType' => 1, 'ObjectName' => 'Garagentor'],
    14 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation'],
    15 => ['ObjectType' => 1, 'ObjectName' => 'Markise Terrasse'],
    16 => ['ObjectType' => 1, 'ObjectName' => 'Schneeflocke'],
    17 => ['ObjectType' => 1, 'ObjectName' => 'Kohlekasten'],
    18 => ['ObjectType' => 1, 'ObjectName' => 'Trabbi'],
    180 => ['ObjectType' => 0, 'ObjectName' => 'Steuerung'], // Zwischenkategorie, KEINE Instanz
    20 => ['ObjectType' => 1, 'ObjectName' => 'WebFront Familie'],
    21 => ['ObjectType' => 1, 'ObjectName' => 'WebFront Gast'],
    22 => ['ObjectType' => 1, 'ObjectName' => 'Dietmar'],
    26 => ['ObjectType' => 1, 'ObjectName' => 'Familie Bot'],
    27 => ['ObjectType' => 1, 'ObjectName' => 'Dietmar Pushover'],
    23 => ['ObjectType' => 1, 'ObjectName' => 'Blitz'],
    25 => ['ObjectType' => 1, 'ObjectName' => 'Schneemobil'],
    111 => ['ObjectType' => 2, 'ObjectName' => 'Position'],
    121 => ['ObjectType' => 2, 'ObjectName' => 'Ein/Aus'],
    131 => ['ObjectType' => 2, 'ObjectName' => 'Status'],
    132 => ['ObjectType' => 2, 'ObjectName' => 'Steuerung'],
    151 => ['ObjectType' => 2, 'ObjectName' => 'Position'],
    161 => ['ObjectType' => 2, 'ObjectName' => 'Hupe'],
    171 => ['ObjectType' => 2, 'ObjectName' => 'Hupe'],
    181 => ['ObjectType' => 2, 'ObjectName' => 'Hupe'],
    231 => ['ObjectType' => 2, 'ObjectName' => 'Fenster schließen'],
    232 => ['ObjectType' => 2, 'ObjectName' => 'Fenster'],
    233 => ['ObjectType' => 2, 'ObjectName' => 'Heckklappe öffnen/schließen'],
    234 => ['ObjectType' => 2, 'ObjectName' => 'Tür-/Klappenstatus'],
    251 => ['ObjectType' => 2, 'ObjectName' => 'Heckklappe öffnen/schließen'],
];
$GLOBALS['whub_test_variables'] = [
    111 => ['VariableAction' => 1],
    121 => ['VariableAction' => 1],
    131 => ['VariableAction' => 0], // reine Anzeige, keine Aktion -- darf NICHT vorgeschlagen werden
    132 => ['VariableAction' => 1],
    151 => ['VariableAction' => 1],
    161 => ['VariableAction' => 1],
    171 => ['VariableAction' => 1],
    181 => ['VariableAction' => 1],
    231 => ['VariableAction' => 1],
    232 => ['VariableAction' => 1],
    233 => ['VariableAction' => 1],
    234 => ['VariableAction' => 0],
    251 => ['VariableAction' => 1],
];
$GLOBALS['whub_test_instancesByModule'] = [
    WEBFRONT_GUID => [20, 21],
    KACHEL_VISU_GUID => [], // exakte GUID liefert bewusst NICHTS -- Namenssuche muss greifen
    KACHEL_VISU_GUID_ACTUAL => [22],
    TELEGRAM_BOT_GUID => [26],
    PUSHOVER_GUID_ACTUAL => [27],
];
$GLOBALS['whub_test_moduleNames'] = [
    WEBFRONT_GUID => 'WebFront',
    KACHEL_VISU_GUID_ACTUAL => 'Kachel Visualisierung',
    PUSHOVER_GUID_ACTUAL => 'Pushover',
    OTHER_MODULE_GUID => 'Irgendwas',
];

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
    return $GLOBALS['whub_test_variables'][$id] ?? false;
}
function IPS_GetName(int $id): string
{
    return $GLOBALS['whub_test_objects'][$id]['ObjectName'] ?? '';
}
function IPS_GetParent(int $id): int
{
    foreach ($GLOBALS['whub_test_tree'] as $parentID => $children) {
        if (in_array($id, $children, true)) {
            return $parentID;
        }
    }
    return 0;
}
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return $GLOBALS['whub_test_instancesByModule'][$guid] ?? [];
}
function IPS_GetModuleList(): array
{
    return array_keys($GLOBALS['whub_test_moduleNames']);
}
function IPS_GetModule(string $guid): array
{
    return ['ModuleName' => $GLOBALS['whub_test_moduleNames'][$guid] ?? 'Irgendwas'];
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
    public array $formFieldUpdates = [];
    public function UpdateFormField($n, $k, $v)
    {
        $this->formFieldUpdates[] = [$n, $k, $v];
    }
    /** Letzter 'values'-Schreibzugriff auf ein bestimmtes Listenfeld -- ein Discovery-Aufruf schreibt inzwischen ZWEI Felder ('values' und 'rowCount'), 'values' ist das für die Tests relevante. */
    public function lastValuesUpdate(string $fieldName): ?array
    {
        for ($i = count($this->formFieldUpdates) - 1; $i >= 0; $i--) {
            [$n, $k, $v] = $this->formFieldUpdates[$i];
            if ($n === $fieldName && $k === 'values') {
                return [$n, $k, $v];
            }
        }
        return null;
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

$hub = new WarnHub();
$hub->Create();

echo "== WebFront-/Kachel-Visualisierung-/Telegram-/Pushover-Discovery ==\n";
$msg = $hub->DiscoverWebFronts();
check('meldet 5 neue Ziele (2× WebFront + 1× Kachel-Visu + 1× Telegram exakte GUID + 1× Pushover nur über Namenssuche)', str_contains($msg, '5 neue'));
[$field, $key, $valuesJson] = $hub->lastValuesUpdate('WebFronts');
check('schreibt in das Feld "WebFronts"', $field === 'WebFronts');
$rows = json_decode($valuesJson, true);
check('5 Zeilen gefunden', count($rows) === 5);
check('alle fünf standardmäßig aktiv', count(array_filter($rows, fn ($r) => $r['Aktiv'] === true)) === 5);
$byId = array_column($rows, null, 'InstanceID');
check('Instanz 20/21 als Typ "webfront" erkannt (exakte GUID)', ($byId[20]['Typ'] ?? null) === 'webfront' && ($byId[21]['Typ'] ?? null) === 'webfront');
check('Instanz 22 ("Dietmar") als Typ "kachel" erkannt, obwohl nur über Namenssuche auffindbar (exakte GUID lieferte 0 Treffer)', ($byId[22]['Typ'] ?? null) === 'kachel');
check('Instanz 26 ("Familie Bot") als Typ "telegram" erkannt (exakte GUID)', ($byId[26]['Typ'] ?? null) === 'telegram');
check('Instanz 27 ("Dietmar Pushover") als Typ "pushover" erkannt, obwohl nur über Namenssuche auffindbar (exakte GUID lieferte 0 Treffer)', ($byId[27]['Typ'] ?? null) === 'pushover');

// Nutzer deaktiviert "WebFront Gast" (id 21) und speichert (Property).
foreach ($rows as &$r) {
    if ($r['InstanceID'] === 21) {
        $r['Aktiv'] = false;
    }
}
unset($r);
$hub->SetProp('WebFronts', json_encode($rows));

// Erneute Suche darf die Abwahl NICHT rückgängig machen.
$msg2 = $hub->DiscoverWebFronts();
check('erneute Suche findet keine NEUEN Instanzen mehr', str_contains($msg2, 'Keine neuen'));
[, , $valuesJson2] = $hub->lastValuesUpdate('WebFronts');
$rows2 = json_decode($valuesJson2, true);
$gast = array_values(array_filter($rows2, fn ($r) => $r['InstanceID'] === 21))[0];
check('"WebFront Gast" bleibt deaktiviert nach erneuter Suche', $gast['Aktiv'] === false);

echo "== Schutzaktionen-Discovery ==\n";
$hub2 = new WarnHub();
$hub2->Create();
$msg3 = $hub2->DiscoverSchutzaktionen();
check('meldet 10 neue Schutzaktionen (Raffstore + Markise + Sirene-Instanz + Garage + 3× Auto-Hupe + Fenster schließen + 2× Heckklappe)', str_contains($msg3, '10 neue'));
[$field3, , $valuesJson3] = $hub2->lastValuesUpdate('Schutzaktionen');
check('schreibt in das Feld "Schutzaktionen"', $field3 === 'Schutzaktionen');
$actions = json_decode($valuesJson3, true);
check('genau 10 Zeilen (Wetterstation kein Treffer, Status-Var nicht aktionsfähig, "Fenster" ohne "schließen" kein Treffer)', count($actions) === 10);
check('Auto-Hupen sind über den Fahrzeugnamen unterscheidbar statt alle nur "Hupe" zu heißen (Dietmars Live-Fund)', in_array('Schneeflocke – Hupe', array_column($actions, 'Name'), true) && in_array('Kohlekasten – Hupe', array_column($actions, 'Name'), true) && !in_array('Hupe', array_column($actions, 'Name'), true));
check('Zwei Ebenen tief (Trabbi > Steuerung > Hupe) findet trotzdem die ECHTE Instanz "Trabbi", nicht die Zwischenkategorie "Steuerung" (Dietmars Nachfrage 04.09.2026)', in_array('Trabbi – Hupe', array_column($actions, 'Name'), true) && !in_array('Steuerung – Hupe', array_column($actions, 'Name'), true));

$byName = [];
foreach ($actions as $a) {
    $byName[$a['Name']] = $a;
}
check('Raffstore Wohnzimmer -> EINE Zeile, Sturm UND Hagel beide angekreuzt, Ziel-Variable 111', ($byName['Raffstore Wohnzimmer']['KatSturm'] ?? false) === true && ($byName['Raffstore Wohnzimmer']['KatHagel'] ?? false) === true && ($byName['Raffstore Wohnzimmer']['ZielVariableID'] ?? null) === 111);
check('Markise Terrasse -> ebenso Sturm UND Hagel angekreuzt, Ziel-Variable 151', ($byName['Markise Terrasse']['Typ'] ?? null) === 'markise' && ($byName['Markise Terrasse']['KatSturm'] ?? false) === true && ($byName['Markise Terrasse']['KatHagel'] ?? false) === true && ($byName['Markise Terrasse']['ZielVariableID'] ?? null) === 151);
check('Sirene Außen -> Typ sirene, kein Kästchen angekreuzt (gilt für jede Kategorie), MinSeverity 4', ($byName['Sirene Außen']['Typ'] ?? null) === 'sirene' && ($byName['Sirene Außen']['KatSturm'] ?? false) === false && ($byName['Sirene Außen']['MinSeverity'] ?? null) === 4);
check('Garagentor -> Typ garage, Ziel-Variable 132 (Steuerung, NICHT die nicht-aktionsfähige Status-Variable 131)', ($byName['Garagentor']['Typ'] ?? null) === 'garage' && ($byName['Garagentor']['ZielVariableID'] ?? null) === 132);
check('Blitz – Fenster schließen -> Typ fenster, Sturm+Hagel+Starkregen angekreuzt, Ziel-Variable 231 (wie Tessies eigene Tesla-Aktion)', ($byName['Blitz – Fenster schließen']['Typ'] ?? null) === 'fenster' && ($byName['Blitz – Fenster schließen']['KatSturm'] ?? false) === true && ($byName['Blitz – Fenster schließen']['KatHagel'] ?? false) === true && ($byName['Blitz – Fenster schließen']['KatStarkregen'] ?? false) === true && ($byName['Blitz – Fenster schließen']['ZielVariableID'] ?? null) === 231);
check('"Fenster" ohne "schließen" wird NICHT vorgeschlagen (Stichwort-Spezifität -- sonst auch Fenster-offen-Sensoren betroffen)', !in_array('Blitz – Fenster', array_column($actions, 'Name'), true) && !in_array(232, array_column($actions, 'ZielVariableID'), true));
check('Blitz – Heckklappe öffnen/schließen -> Typ kofferraum, Zustands-Variable automatisch verlinkt (234, "Tür-/Klappenstatus"), AKTIV', ($byName['Blitz – Heckklappe öffnen/schließen']['Typ'] ?? null) === 'kofferraum' && ($byName['Blitz – Heckklappe öffnen/schließen']['ZielVariableID'] ?? null) === 233 && ($byName['Blitz – Heckklappe öffnen/schließen']['ZustandsVariableID'] ?? null) === 234 && ($byName['Blitz – Heckklappe öffnen/schließen']['Aktiv'] ?? null) === true);
check('Schneemobil – Heckklappe öffnen/schließen -> OHNE auffindbare Zustands-Variable bleibt die Zeile INAKTIV (Sicherheitssperre statt Raten)', ($byName['Schneemobil – Heckklappe öffnen/schließen']['Typ'] ?? null) === 'kofferraum' && ($byName['Schneemobil – Heckklappe öffnen/schließen']['ZustandsVariableID'] ?? null) === 0 && ($byName['Schneemobil – Heckklappe öffnen/schließen']['Aktiv'] ?? null) === false);
check('neun der zehn Treffer standardmäßig aktiv (nur Schneemobil-Heckklappe bewusst nicht, mangels Zustands-Variable)', count(array_filter($actions, fn ($a) => $a['Aktiv'] === true)) === 9);

// Ende-zu-Ende: decodeSchutzaktionen() muss die angekreuzten Kästchen korrekt
// in die normalisierte 'Kategorien'-Liste übersetzen (für die Zuordnungslogik
// beim Poll).
$hub2->SetProp('Schutzaktionen', $valuesJson3);
$decoded = callPrivate($hub2, 'decodeSchutzaktionen');
$decodedByName = [];
foreach ($decoded as $a) {
    $decodedByName[$a['Name']] = $a;
}
sort($decodedByName['Raffstore Wohnzimmer']['Kategorien']);
check('decodeSchutzaktionen() liest Raffstore-Kästchen korrekt als ["hagel","sturm"]', $decodedByName['Raffstore Wohnzimmer']['Kategorien'] === ['hagel', 'sturm']);
check('decodeSchutzaktionen() liest "kein Kästchen" korrekt als leere Liste (Sirene)', $decodedByName['Sirene Außen']['Kategorien'] === []);

// Nutzer klickt "Übernehmen" (Property = aktueller Formularstand) -- erst
// danach kann eine erneute Suche gegen den gespeicherten Stand deduplizieren.
$hub2->SetProp('Schutzaktionen', $valuesJson3);
$msg4 = $hub2->DiscoverSchutzaktionen();
check('erneute Suche nach "Übernehmen" findet keine neuen Treffer mehr (kein Duplikat)', str_contains($msg4, 'Keine neuen'));

echo "== Byte-sichere Titel-/Text-Kürzung ==\n";
$hub3 = new WarnHub();
$hub3->Create();
// Emoji (4 Byte in UTF-8) + Umlaute: Dietmars hartnäckiger Live-Fund
// 04.09.2026 -- ein Titel kann unter 32 ZEICHEN liegen, aber trotzdem über
// 32 BYTES, wenn Symcons eigene Längenprüfung (undokumentiert) in Bytes
// statt Zeichen rechnet.
$emoji = '🧪 WarnHub Testbenachrichtigung'; // 30 Zeichen, aber 33 Byte
check('Test-Ausgangswert liegt unter der Zeichen-, aber über der Byte-Grenze (belegt das eigentliche Problem)', mb_strlen($emoji) <= 32 && strlen($emoji) > 32);
$truncated = callPrivate($hub3, 'truncateBytes', [$emoji, 32]);
check('truncateBytes() hält die 32-Byte-Grenze ein', strlen($truncated) <= 32);
check('truncateBytes() erzeugt gültiges UTF-8 (kein Byte mitten im Mehrbyte-Zeichen abgeschnitten)', mb_check_encoding($truncated, 'UTF-8'));
check('truncateBytes() lässt kurze Strings unverändert', callPrivate($hub3, 'truncateBytes', ['kurz', 32]) === 'kurz');
$umlaut = str_repeat('ö', 20); // jedes 'ö' = 2 Byte -> 40 Byte bei 20 Zeichen
check('truncateBytes() kürzt auch reine Umlaut-Strings byte-sicher (16 Zeichen bei 32-Byte-Limit, da "ö" = 2 Byte)', mb_strlen(callPrivate($hub3, 'truncateBytes', [$umlaut, 32])) === 16);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
