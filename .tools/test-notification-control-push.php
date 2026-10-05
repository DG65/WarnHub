<?php

/**
 * Prüfstand für den neuen Push-Kanal "Notification Control" (01.10.2026,
 * Praxis-Fund tomfes, Symcon-Forum): deckt Geräte ab, die NUR über die
 * IPSView-App bei Notification Control registriert sind und bei KEINER
 * WebFront-/Kachel-Visualisierung-Instanz als Konfigurator eingetragen --
 * WFC_PushNotification/VISU_PostNotificationEx erreichen so ein Gerät
 * strukturell nie, unabhängig davon, welche WebFront-Zeile aktiviert ist.
 *
 * NC_GetDevices()/NC_PushNotification() live gegen Dietmars echte Symcon-
 * Instanz geprüft (01.10.2026, Notification-Control-Instanz #34698). Die
 * Geräteliste unten ist wörtlich dieser echte Fund (Namen/IDs
 * anonymisiert), keine erfundene Fixture. WICHTIGER Live-Fund dabei: der
 * zweite Parameter von NC_PushNotification() ist die KONFIGURATOR-
 * Instanz-ID aus der 'Visualizations'-Map (nicht die geräteeigene 'ID') --
 * ein echter Testpush mit der Geräte-ID lieferte `false`, derselbe Aufruf
 * mit einer Visualisierungs-ID lieferte eine echte Benachrichtigungs-ID.
 * Rückgabe ist deshalb KEIN Bool, Erfolg wird als `!== false` geprüft.
 *
 *   php .tools/test-notification-control-push.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncDevices'] = [];
$GLOBALS['whub_test_ncPushResults'] = []; // [$ncInstanceID][$visualizationID] => Rückgabewert, Standard eine Fake-Benachrichtigungs-ID

function IPS_VariableExists(int $id): bool
{
    return false;
}
function IPS_LogMessage(string $sender, string $message): void
{
}
function WFC_PushNotification(int $id, string $title, string $text, string $sound, int $senderId): bool
{
    $GLOBALS['whub_test_pushCalls'][] = ['webfront', $id, $title, $text, $sound];
    return true;
}
/**
 * Wörtliche Live-Struktur (anonymisiert), siehe Dateikopf: ID/Name/Modified/
 * Visualizations (Konfigurator-Instanz-ID -> aktiv/inaktiv). Bewusst OHNE
 * Rückgabetyp -- NC_GetDevices ist offiziell undokumentiert, module.php
 * verlässt sich deshalb nicht auf eine garantierte Array-Rückgabe (siehe
 * is_array()-Prüfung), der Prüfstand muss also auch eine Nicht-Array-Antwort
 * simulieren können (z. B. false bei ungültiger Instanz-ID).
 */
function NC_GetDevices(int $InstanceID)
{
    return array_key_exists($InstanceID, $GLOBALS['whub_test_ncDevices']) ? $GLOBALS['whub_test_ncDevices'][$InstanceID] : [];
}
/** Live-Fund 01.10.2026: zweiter Parameter ist die VISUALISIERUNGS-/Konfigurator-ID, Rückgabe eine Benachrichtigungs-ID (int) bei Erfolg, `false` bei Fehlschlag -- KEIN Bool. */
function NC_PushNotification(int $InstanceID, int $VisualizationID, string $Title, string $Text, string $Sound)
{
    $GLOBALS['whub_test_pushCalls'][] = ['notification', $InstanceID, $VisualizationID, $Title, $Text, $Sound];
    if (array_key_exists($InstanceID, $GLOBALS['whub_test_ncPushResults']) && array_key_exists($VisualizationID, $GLOBALS['whub_test_ncPushResults'][$InstanceID])) {
        return $GLOBALS['whub_test_ncPushResults'][$InstanceID][$VisualizationID];
    }
    static $nextNotificationId = 5901;
    return $nextNotificationId++;
}
function IPS_GetInstanceListByModuleID(string $guid): array
{
    return [];
}
function IPS_GetModuleList(): array
{
    return [];
}
$GLOBALS['whub_test_instanceList'] = [];   // [instanceID => ['ModuleID' => guid]]
$GLOBALS['whub_test_modules'] = [];        // [guid => ['ModuleName' => ..., 'Prefix' => ...]]
function IPS_GetInstanceList(): array
{
    return array_keys($GLOBALS['whub_test_instanceList']);
}
function IPS_GetInstance(int $id)
{
    return isset($GLOBALS['whub_test_instanceList'][$id]) ? ['ModuleInfo' => ['ModuleID' => $GLOBALS['whub_test_instanceList'][$id]]] : false;
}
function IPS_GetModule(string $guid)
{
    return $GLOBALS['whub_test_modules'][$guid] ?? false;
}
function IPS_GetName(int $id): string
{
    return 'Instanz ' . $id;
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
    public function LogMessage($Message, $Type)
    {
    }
    public function UpdateFormField($n, $k, $v)
    {
    }
    public function Create()
    {
    }
}

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

function neuerHub(array $webFronts): WarnHub
{
    $hub = new WarnHub();
    $hub->Create();
    $hub->SetProp('WebFronts', json_encode($webFronts));
    return $hub;
}

$row = ['InstanceID' => 34698, 'Name' => 'Notifications', 'Typ' => 'notification', 'Aktiv' => true, 'Zieladresse' => ''];

echo "== Happy Path: wörtlicher Live-Fund (anonymisiert) -- gemischte Visualizations je Gerät ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncPushResults'] = [];
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 1, 'Name' => 'Dietmars iPhone', 'Modified' => 1743859231, 'Visualizations' => [58070 => true]],
    ['ID' => 2, 'Name' => 'S.Gureth-iPhone', 'Modified' => 1664304611, 'Visualizations' => [58070 => false]], // kein aktiver Konfigurator -- darf NICHT angestoßen werden
    ['ID' => 5, 'Name' => 'iPhone', 'Modified' => 1789382068, 'Visualizations' => [54409 => true, 42569 => true, 16508 => true, 43734 => false, 54810 => true]],
];
$sent = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('genau EINE Zeile zählt als gesendet (1 Notification-Control-Ziel, nicht 1 je Gerät/Konfigurator)', $sent === 1);
check('Gerät #1: sein EINER aktiver Konfigurator (58070) wurde als Visualisierungs-ID angestoßen', count(array_filter($GLOBALS['whub_test_pushCalls'], fn ($c) => $c[0] === 'notification' && $c[2] === 58070)) === 1);
check('Gerät #2 (sein einziger Konfigurator ist inaktiv) wurde NICHT angestoßen', count(array_filter($GLOBALS['whub_test_pushCalls'], fn ($c) => $c[0] === 'notification' && $c[2] === 58070)) === 1); // nur von Gerät #1, nicht doppelt
check('Gerät #5 (vier aktive Konfiguratoren) bekommt genau EINEN Push, nicht vier (Rückmeldung baslerleckerli 05.10.2026)', count(array_filter($GLOBALS['whub_test_pushCalls'], fn ($c) => in_array($c[2], [54409, 42569, 16508, 54810], true))) === 1);
check('der inaktive Konfigurator (43734) wurde NICHT angestoßen', count(array_filter($GLOBALS['whub_test_pushCalls'], fn ($c) => $c[2] === 43734)) === 0);
check('insgesamt 2 Push-Versuche (Gerät #1 über 58070, Gerät #5 über einen seiner Konfiguratoren)', count($GLOBALS['whub_test_pushCalls']) === 2);
check('NC_PushNotification bekommt die Notification-Control-Instanz-ID als ersten Parameter', $GLOBALS['whub_test_pushCalls'][0][1] === 34698);
check('Titel/Text werden wie bei WFC_PushNotification auf 32/256 Byte gekürzt übergeben', mb_strlen($GLOBALS['whub_test_pushCalls'][0][3]) <= 32 && mb_strlen($GLOBALS['whub_test_pushCalls'][0][4]) <= 256);
check('Sound wird durchgereicht', $GLOBALS['whub_test_pushCalls'][0][5] === 'alarm');

echo "\n== Kein Gerät mit aktivem Konfigurator ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 1, 'Name' => 'Altes Gerät', 'Modified' => 1, 'Visualizations' => [58070 => false]],
];
$sent = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('kein Konfigurator aktiv -> Zeile zählt NICHT als gesendet', $sent === 0);
check('NC_PushNotification wird gar nicht erst aufgerufen', count($GLOBALS['whub_test_pushCalls']) === 0);

echo "\n== Gerät ohne jede Visualizations-Zuordnung (leeres Array) ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 9, 'Name' => 'Nie verbunden', 'Modified' => 0, 'Visualizations' => []],
];
$sent = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('leere Visualizations -> kein Push, kein Fehler', $sent === 0 && count($GLOBALS['whub_test_pushCalls']) === 0);

echo "\n== Rückgabewert ist eine Benachrichtigungs-ID (int), kein Bool -- `false` ist der einzige Fehlschlag ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncPushResults'][34698] = [100 => 0]; // 0 ist KEIN false -- gilt als Erfolg (echte Benachrichtigungs-ID, nur zufällig 0)
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 1, 'Name' => 'Grenzfall', 'Modified' => 0, 'Visualizations' => [100 => true]],
];
$sent = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('Rückgabewert 0 (nicht false) zählt als Erfolg -- Prüfung ist strikt !== false, nicht truthy', $sent === 1);

echo "\n== Ein Konfigurator schlägt fehl, ein anderer nicht -- Zeile zählt trotzdem als erreicht ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncPushResults'] = [34698 => [100 => false, 200 => 5999]];
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 1, 'Name' => 'Ein Gerät, zwei Konfiguratoren', 'Modified' => 0, 'Visualizations' => [100 => true, 200 => true]],
];
$sent = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('mindestens ein Konfigurator erreicht -> Zeile zählt als gesendet', $sent === 1);
check('BEIDE Konfiguratoren wurden versucht (kein Abbruch nach dem ersten Fehlschlag)', count($GLOBALS['whub_test_pushCalls']) === 2);

echo "\n== NC_GetDevices liefert kein Array (unerwartete Antwort, z. B. false bei ungültiger Instanz) ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncDevices'][34698] = false;
$sentFalse = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('Nicht-Array-Antwort -> kein Fehler, Zeile zählt nicht als gesendet', $sentFalse === 0 && count($GLOBALS['whub_test_pushCalls']) === 0);

echo "\n== Mehrere Geräte teilen einen Konfigurator -- so wenige Konfiguratoren wie nötig (Rückmeldung baslerleckerli) ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncPushResults'] = [];
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 5, 'Name' => 'iPhone', 'Modified' => 1, 'Visualizations' => [54409 => true, 42569 => true]],
    ['ID' => 11, 'Name' => 'iPad', 'Modified' => 1, 'Visualizations' => [54409 => true]],
    ['ID' => 12, 'Name' => 'iPad 2', 'Modified' => 1, 'Visualizations' => [54409 => true, 43734 => false]],
];
$sent = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
check('Konfigurator 54409 (bei DREI Geräten aktiv) wird nur EINMAL angestoßen, nicht dreimal', count(array_filter($GLOBALS['whub_test_pushCalls'], fn ($c) => $c[2] === 54409)) === 1);
check('genau EIN Aufruf (54409 erreicht alle drei Geräte, 42569 wäre überflüssig), kein Aufruf für den inaktiven 43734', count($GLOBALS['whub_test_pushCalls']) === 1 && $sent === 1);

echo "\n== Komplette echte Geräteliste von Dietmars System (01.10.2026, 12 Geräte, anonymisiert) -- jedes Gerät genau einmal ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncPushResults'] = [];
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 1, 'Name' => 'a', 'Modified' => 1, 'Visualizations' => [58070 => true]],
    ['ID' => 2, 'Name' => 'b', 'Modified' => 1, 'Visualizations' => [58070 => false]],
    ['ID' => 3, 'Name' => 'c', 'Modified' => 1, 'Visualizations' => [16493 => true]],
    ['ID' => 4, 'Name' => 'd', 'Modified' => 1, 'Visualizations' => [41422 => true]],
    ['ID' => 5, 'Name' => 'e', 'Modified' => 1, 'Visualizations' => [54409 => true, 42569 => true, 16508 => true, 43734 => false, 54810 => true]],
    ['ID' => 6, 'Name' => 'f', 'Modified' => 1, 'Visualizations' => [43734 => true]],
    ['ID' => 7, 'Name' => 'g', 'Modified' => 1, 'Visualizations' => [42569 => false]],
    ['ID' => 8, 'Name' => 'h', 'Modified' => 1, 'Visualizations' => [14338 => true]],
    ['ID' => 9, 'Name' => 'i', 'Modified' => 1, 'Visualizations' => [14338 => true]],
    ['ID' => 10, 'Name' => 'j', 'Modified' => 1, 'Visualizations' => [42569 => false]],
    ['ID' => 11, 'Name' => 'k', 'Modified' => 1, 'Visualizations' => [54409 => true]],
    ['ID' => 12, 'Name' => 'l', 'Modified' => 1, 'Visualizations' => [54409 => true]],
];
callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
$ids = array_map(fn ($c) => $c[2], $GLOBALS['whub_test_pushCalls']);
sort($ids);
check('Konfigurator 54409 deckt Gerät 5, 11 und 12 ab, 14338 die Geräte 8 und 9 -> 6 Aufrufe statt 12 bzw. 8', $ids === [14338, 16493, 41422, 43734, 54409, 58070]);
$abgedeckt = [];
foreach ($GLOBALS['whub_test_ncDevices'][34698] as $g) {
    foreach ($g['Visualizations'] as $vid => $aktiv) {
        if ($aktiv === true && in_array($vid, $ids, true)) {
            $abgedeckt[$g['ID']] = ($abgedeckt[$g['ID']] ?? 0) + 1;
        }
    }
}
check('jedes der 9 Geräte mit aktivem Konfigurator wird erreicht', count($abgedeckt) === 9);
check('jedes dieser Geräte wird GENAU EINMAL erreicht (keine Kopien)', count(array_filter($abgedeckt, fn ($n) => $n !== 1)) === 0);

echo "\n== Ausweichen: der gewählte Konfigurator schlägt fehl, ein anderer erreicht das Gerät trotzdem ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$GLOBALS['whub_test_ncPushResults'] = [34698 => [100 => false]];
$GLOBALS['whub_test_ncDevices'][34698] = [
    ['ID' => 1, 'Name' => 'x', 'Modified' => 1, 'Visualizations' => [100 => true, 200 => true]],
];
$sent = callPrivate(neuerHub([$row]), 'pushToAllWebfronts', ['Titel', 'Text', 'alarm']);
$ids = array_map(fn ($c) => $c[2], $GLOBALS['whub_test_pushCalls']);
check('100 (kleinste ID) schlägt fehl -> 200 springt ein, Gerät wird erreicht', $ids === [100, 200] && $sent === 1);
$GLOBALS['whub_test_ncPushResults'] = [];

echo "\n== Funktionen auf dem System nicht verfügbar (kein Notification-Control-Modul installiert) ==\n";
check('pushToAllWebfronts() prüft function_exists für NC_GetDevices/NC_PushNotification im Quellcode', str_contains(file_get_contents(__DIR__ . '/../WarnHub/module.php'), "function_exists('NC_GetDevices') || !function_exists('NC_PushNotification')"));
check('Erfolgsprüfung ist !== false, nicht === true (Rückgabe ist eine Benachrichtigungs-ID, kein Bool)', str_contains(file_get_contents(__DIR__ . '/../WarnHub/module.php'), '$result !== false'));

echo "\n== Erkennung: GUID UND Modulliste liefern nichts (Praxis-Fund tomfes 02.10.2026, Instanz #25900) ==\n";
$GLOBALS['whub_test_instanceList'] = [
    100 => '{AAAAAAAA-0000-0000-0000-000000000001}', // irgendein anderes Modul
    25900 => '{FFFFFFFF-0000-0000-0000-00000000NC01}', // fremde GUID -- exakter Treffer UND Modulliste (leer) scheitern
    300 => '{AAAAAAAA-0000-0000-0000-000000000002}', // Store-Modul "Notification" -- darf NICHT mitgenommen werden
];
$GLOBALS['whub_test_modules'] = [
    '{AAAAAAAA-0000-0000-0000-000000000001}' => ['ModuleName' => 'WebFront Configurator', 'Prefix' => 'WFC'],
    '{FFFFFFFF-0000-0000-0000-00000000NC01}' => ['ModuleName' => 'Notification Control', 'Prefix' => 'NC'],
    '{AAAAAAAA-0000-0000-0000-000000000002}' => ['ModuleName' => 'Notification', 'Prefix' => 'NOTIF'],
];
$ids = callPrivate(neuerHub([]), 'findNotificationControlInstances');
check('Instanz #25900 wird über Modulname/Präfix gefunden, obwohl GUID und Modulliste leer sind', in_array(25900, $ids, true));
check('das Store-Modul "Notification" (anderer Name, anderes Präfix) wird NICHT fälschlich mitgenommen', !in_array(300, $ids, true) && count($ids) === 1);

$GLOBALS['whub_test_modules']['{FFFFFFFF-0000-0000-0000-00000000NC01}'] = ['ModuleName' => 'Benachrichtigungssteuerung', 'Prefix' => 'NC']; // lokalisierter Name, Präfix bleibt
$ids = callPrivate(neuerHub([]), 'findNotificationControlInstances');
check('auch bei lokalisiertem Modulnamen reicht das Präfix "NC"', in_array(25900, $ids, true));

$GLOBALS['whub_test_instanceList'] = [];
$GLOBALS['whub_test_modules'] = [];
check('keine Notification-Control-Instanz vorhanden -> leere Liste, kein Fehler', callPrivate(neuerHub([]), 'findNotificationControlInstances') === []);

echo "\n== Formular: neue Option im Typ-Select ==\n";
$hub = neuerHub([]);
$form = json_decode($hub->GetConfigurationForm(), true);
function findItemNC(array $elements, callable $pred): ?array
{
    foreach ($elements as $el) {
        if ($pred($el)) {
            return $el;
        }
        foreach (['items', 'columns'] as $k) {
            if (isset($el[$k]) && is_array($el[$k])) {
                $f = findItemNC($el[$k], $pred);
                if ($f !== null) {
                    return $f;
                }
            }
        }
    }
    return null;
}
$webFrontsList = findItemNC($form['elements'], fn ($el) => ($el['name'] ?? null) === 'WebFronts');
check('WebFronts-Liste vorhanden', $webFrontsList !== null);
$typColumn = null;
foreach ($webFrontsList['columns'] ?? [] as $col) {
    if (($col['name'] ?? null) === 'Typ') {
        $typColumn = $col;
    }
}
$options = $typColumn['edit']['options'] ?? [];
check('Typ-Select enthält die Option "notification"', in_array('notification', array_column($options, 'value'), true));

echo "\n" . ($failures === 0 ? "Alle $checks Prüfungen bestanden." : "$failures von $checks Prüfungen FEHLGESCHLAGEN.") . "\n";
exit($failures === 0 ? 0 : 1);
