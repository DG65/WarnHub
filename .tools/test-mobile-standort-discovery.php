<?php

/**
 * Prüfstand für DiscoverMobileStandorte() -- die automatische Suche nach
 * Fahrzeug-/Standort-Variablenpaaren (Tessie "Fahrzeugposition –
 * Breitengrad/Längengrad", Geofency "Current Latitude/Longitude") gegen
 * einen kleinen, gestubbten Objektbaum. Dietmars Nachfrage 04.09.2026:
 * "Warum kannst du die Zuordnung nicht auch gleich ... übernehmen?" --
 * dieser Test belegt insbesondere, dass die Suche Teslas NAVIGATIONSZIEL
 * ("Zielposition") nicht mit der aktuellen Position verwechselt, und dass
 * Geofencys zusätzliche, gleichnamige "Latitude"/"Longitude" (ohne
 * "Current") NICHT mit anfasst werden.
 *
 *   php .tools/test-mobile-standort-discovery.php    # 0 = alle Prüfungen bestanden
 */

// Kleiner Fake-Objektbaum:
//   0 (Root)
//    +- 10 Instanz "Kohlekasten" (Auto, wie Tessies TessieVehicle)
//        +- 101 Var "Fahrzeugposition – Breitengrad" (48.5)
//        +- 102 Var "Fahrzeugposition – Längengrad" (7.9)
//        +- 103 Var "Zielposition – Breitengrad" (52.0) -- Navigationsziel,
//              DARF NICHT als aktuelle Position gefunden werden
//        +- 104 Var "Zielposition – Längengrad" (13.0) -- s.o.
//    +- 11 Instanz "Schneeflocke" (zweites Auto)
//        +- 111 Var "Fahrzeugposition – Breitengrad" (50.1)
//        +- 112 Var "Fahrzeugposition – Längengrad" (8.6)
//    +- 12 Instanz "Dietmar Geofency" (Geofency-Profil)
//        +- 121 Var "Current Latitude" (53.5)
//        +- 122 Var "Current Longitude" (10.0)
//        +- 123 Var "Latitude" (1.1) -- vermutlich Geofence-Zentrum, KEINE
//              "current"-Kennzeichnung, DARF NICHT gefunden werden
//        +- 124 Var "Longitude" (2.2) -- s.o.
//    +- 13 Instanz "Wetterstation" (kein Treffer, unbeteiligte Variable)
//        +- 131 Var "Außentemperatur" (20.0)
//    +- 14 Instanz "Astra Electric" (Stellantis-Fahrzeug, community Modul
//          slausch/Symcon-Stellantis-Vehicles) -- gefunden über die GUID
//          UND die stabilen Idents "Latitude"/"Longitude" (NICHT über den
//          Namen: anders als bei Tessie liegen "Breitengrad"/"Längengrad"
//          hier OHNE unterscheidenden "Fahrzeugposition"-Prefix direkt
//          unter der Instanz -- ein Namensabgleich wäre hier zweideutig,
//          Ident-Abgleich verifiziert 07.09.2026 gegen den echten
//          Quellcode)
//        +- 141 Var "Breitengrad", Ident "Latitude" (51.0)
//        +- 142 Var "Längengrad", Ident "Longitude" (9.0)
//    +- 15 Instanz "208 GT" (Smartcar, community Modul mb-stern/Smartcar)
//        +- 151 Var "Breitengrad", Ident "Latitude" (47.0)
//        +- 152 Var "Längengrad", Ident "Longitude" (8.0)
//    +- 16 Instanz "iX" (BMW ConnectedDrive) -- eigene, markenspezifische
//          Idents ("bmw_current_latitude"/"...longitude"), nicht die
//          generischen "Latitude"/"Longitude" der anderen drei Module
//        +- 161 Var "current latitude", Ident "bmw_current_latitude" (49.0)
//        +- 162 Var "current longitude", Ident "bmw_current_longitude" (11.0)
//    +- 17 Instanz "EV6" (Hyundai/Kia Bluelink, community Modul da8ter/Bluelink)
//        +- 171 Var "Latitude", Ident "Latitude" (53.0)
//        +- 172 Var "Longitude", Ident "Longitude" (12.0)
$GLOBALS['whub_test_tree'] = [
    0 => [10, 11, 12, 13, 14, 15, 16, 17],
    10 => [101, 102, 103, 104],
    11 => [111, 112],
    12 => [121, 122, 123, 124],
    13 => [131],
    14 => [141, 142],
    15 => [151, 152],
    16 => [161, 162],
    17 => [171, 172],
];
$GLOBALS['whub_test_objects'] = [
    10 => ['ObjectType' => 1, 'ObjectName' => 'Kohlekasten', 'ParentID' => 0],
    11 => ['ObjectType' => 1, 'ObjectName' => 'Schneeflocke', 'ParentID' => 0],
    12 => ['ObjectType' => 1, 'ObjectName' => 'Dietmar Geofency', 'ParentID' => 0],
    13 => ['ObjectType' => 1, 'ObjectName' => 'Wetterstation', 'ParentID' => 0],
    14 => ['ObjectType' => 1, 'ObjectName' => 'Astra Electric', 'ParentID' => 0],
    15 => ['ObjectType' => 1, 'ObjectName' => '208 GT', 'ParentID' => 0],
    16 => ['ObjectType' => 1, 'ObjectName' => 'iX', 'ParentID' => 0],
    17 => ['ObjectType' => 1, 'ObjectName' => 'EV6', 'ParentID' => 0],
    101 => ['ObjectType' => 2, 'ObjectName' => 'Fahrzeugposition – Breitengrad', 'ParentID' => 10],
    102 => ['ObjectType' => 2, 'ObjectName' => 'Fahrzeugposition – Längengrad', 'ParentID' => 10],
    103 => ['ObjectType' => 2, 'ObjectName' => 'Zielposition – Breitengrad', 'ParentID' => 10],
    104 => ['ObjectType' => 2, 'ObjectName' => 'Zielposition – Längengrad', 'ParentID' => 10],
    111 => ['ObjectType' => 2, 'ObjectName' => 'Fahrzeugposition – Breitengrad', 'ParentID' => 11],
    112 => ['ObjectType' => 2, 'ObjectName' => 'Fahrzeugposition – Längengrad', 'ParentID' => 11],
    121 => ['ObjectType' => 2, 'ObjectName' => 'Current Latitude', 'ParentID' => 12],
    122 => ['ObjectType' => 2, 'ObjectName' => 'Current Longitude', 'ParentID' => 12],
    123 => ['ObjectType' => 2, 'ObjectName' => 'Latitude', 'ParentID' => 12],
    124 => ['ObjectType' => 2, 'ObjectName' => 'Longitude', 'ParentID' => 12],
    131 => ['ObjectType' => 2, 'ObjectName' => 'Außentemperatur', 'ParentID' => 13],
    141 => ['ObjectType' => 2, 'ObjectName' => 'Breitengrad', 'ParentID' => 14, 'ObjectIdent' => 'Latitude'],
    142 => ['ObjectType' => 2, 'ObjectName' => 'Längengrad', 'ParentID' => 14, 'ObjectIdent' => 'Longitude'],
    151 => ['ObjectType' => 2, 'ObjectName' => 'Breitengrad', 'ParentID' => 15, 'ObjectIdent' => 'Latitude'],
    152 => ['ObjectType' => 2, 'ObjectName' => 'Längengrad', 'ParentID' => 15, 'ObjectIdent' => 'Longitude'],
    161 => ['ObjectType' => 2, 'ObjectName' => 'current latitude', 'ParentID' => 16, 'ObjectIdent' => 'bmw_current_latitude'],
    162 => ['ObjectType' => 2, 'ObjectName' => 'current longitude', 'ParentID' => 16, 'ObjectIdent' => 'bmw_current_longitude'],
    171 => ['ObjectType' => 2, 'ObjectName' => 'Latitude', 'ParentID' => 17, 'ObjectIdent' => 'Latitude'],
    172 => ['ObjectType' => 2, 'ObjectName' => 'Longitude', 'ParentID' => 17, 'ObjectIdent' => 'Longitude'],
];
$GLOBALS['whub_test_values'] = [
    101 => 48.5, 102 => 7.9, 103 => 52.0, 104 => 13.0,
    111 => 50.1, 112 => 8.6,
    121 => 53.5, 122 => 10.0, 123 => 1.1, 124 => 2.2,
    131 => 20.0,
    141 => 51.0, 142 => 9.0,
    151 => 47.0, 152 => 8.0,
    161 => 49.0, 162 => 11.0,
    171 => 53.0, 172 => 12.0,
];
$GLOBALS['whub_test_instancesByModule'] = [
    '{55719996-CD7E-4825-8B64-294601469EB5}' => [14],
    '{1E1B7C9A-2D4F-4E8A-9C3B-7F6D5A4E2B10}' => [15],
    '{8FD2A163-E07A-A2A2-58CC-974155FAEE33}' => [16],
    '{C3D4E5F6-789A-BCDE-F012-3456789ABCDE}' => [17],
];

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
function GetValue(int $id)
{
    return $GLOBALS['whub_test_values'][$id] ?? 0;
}

class IPSModule
{
    public int $InstanceID = 999999;
    protected array $props = [];
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
    protected array $attrs = [];
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
    public array $formFieldUpdates = [];
    public function UpdateFormField($n, $k, $v)
    {
        $this->formFieldUpdates[] = [$n, $k, $v];
    }
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
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return $GLOBALS['whub_test_instancesByModule'][$guid] ?? [];
}
function IPS_GetModuleList(): array
{
    return [];
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

echo "== DiscoverMobileStandorte(): Erstlauf ==\n";
$msg = $hub->DiscoverMobileStandorte();
check('meldet 7 gefundene mobile Standorte (2x Tessie + 1x Geofency + Stellantis + Smartcar + BMW + Bluelink)', str_contains($msg, '7 mobile'));
[$field, , $valuesJson] = $hub->lastValuesUpdate('Standorte');
check('schreibt in das Feld "Standorte"', $field === 'Standorte');
$rows = json_decode($valuesJson, true);
check('genau 7 Zeilen (Wetterstation ohne Treffer)', count($rows) === 7);

$byName = [];
foreach ($rows as $r) {
    $byName[$r['Name']] = $r;
}

check('Kohlekasten -> Live-Standort korrekt mit Fahrzeugposition (101/102) verknüpft, NICHT mit Zielposition (103/104)', ($byName['Kohlekasten']['QuellVarLat'] ?? null) === 101 && ($byName['Kohlekasten']['QuellVarLon'] ?? null) === 102);
check('Kohlekasten -> Startwert aus dem aktuellen Variablenwert übernommen (48.5/7.9)', abs(($byName['Kohlekasten']['Lat'] ?? 0) - 48.5) < 0.0001 && abs(($byName['Kohlekasten']['Lon'] ?? 0) - 7.9) < 0.0001);
check('Kohlekasten -> direkt aktiviert, Umkreis/Schweregrad mit sinnvollem Standardwert', ($byName['Kohlekasten']['Aktiv'] ?? null) === true && (float) ($byName['Kohlekasten']['RadiusKm'] ?? 0) === 20.0);
check('Schneeflocke -> eigenes, unabhängiges Variablenpaar (111/112) -- beide Autos gleichzeitig gefunden', ($byName['Schneeflocke']['QuellVarLat'] ?? null) === 111 && ($byName['Schneeflocke']['QuellVarLon'] ?? null) === 112);
check('"Dietmar Geofency" -> mit "Current Latitude/Longitude" (121/122) verknüpft, NICHT mit dem gleichnamigen Latitude/Longitude ohne "Current" (123/124)', ($byName['Dietmar Geofency']['QuellVarLat'] ?? null) === 121 && ($byName['Dietmar Geofency']['QuellVarLon'] ?? null) === 122);
check('"Astra Electric" (Stellantis) -> über Ident statt Name gefunden (141/142), obwohl "Breitengrad"/"Längengrad" hier OHNE "Fahrzeugposition"-Prefix stehen', ($byName['Astra Electric']['QuellVarLat'] ?? null) === 141 && ($byName['Astra Electric']['QuellVarLon'] ?? null) === 142);
check('"Astra Electric" -> Startwert aus dem aktuellen Variablenwert übernommen (51.0/9.0)', abs(($byName['Astra Electric']['Lat'] ?? 0) - 51.0) < 0.0001 && abs(($byName['Astra Electric']['Lon'] ?? 0) - 9.0) < 0.0001);
check('"208 GT" (Smartcar) -> über Ident gefunden (151/152)', ($byName['208 GT']['QuellVarLat'] ?? null) === 151 && ($byName['208 GT']['QuellVarLon'] ?? null) === 152);
check('"iX" (BMW ConnectedDrive) -> über eigene, markenspezifische Idents "bmw_current_latitude"/"...longitude" gefunden (161/162), nicht die generischen "Latitude"/"Longitude"', ($byName['iX']['QuellVarLat'] ?? null) === 161 && ($byName['iX']['QuellVarLon'] ?? null) === 162);
check('"EV6" (Hyundai/Kia Bluelink) -> über Ident gefunden (171/172)', ($byName['EV6']['QuellVarLat'] ?? null) === 171 && ($byName['EV6']['QuellVarLon'] ?? null) === 172);

$allQuellVarLat = array_column($rows, 'QuellVarLat');
$allQuellVarLon = array_column($rows, 'QuellVarLon');
check('Zielposition (103/104) taucht in KEINER Zeile als Live-Standort-Variable auf (Navigationsziel != aktuelle Position)', !in_array(103, $allQuellVarLat, true) && !in_array(104, $allQuellVarLon, true));
check('das geofencyeigene Latitude/Longitude ohne "Current" (123/124) taucht in KEINER Zeile auf', !in_array(123, $allQuellVarLat, true) && !in_array(124, $allQuellVarLon, true));

echo "\n== Gegenprobe: erneute Suche nach 'Übernehmen' findet keine neuen Treffer mehr ==\n";
$hub->SetProp('Standorte', $valuesJson);
$msg2 = $hub->DiscoverMobileStandorte();
check('meldet "keine neuen" statt erneut 7 Treffer (kein Duplikat)', str_contains($msg2, 'Keine neuen'));
[, , $valuesJson2] = $hub->lastValuesUpdate('Standorte');
check('Zeilenzahl bleibt bei 7 (kein Duplikat entstanden)', count(json_decode($valuesJson2, true)) === 7);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
