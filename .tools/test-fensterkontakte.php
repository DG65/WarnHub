<?php

/**
 * Prüfstand für die Fenster-/Tür-Überwachung (contactOpenRawValue()/
 * isContactOpen()/decodeFensterkontakte()/DiscoverFensterkontakte()/die
 * neue Prüfung in processWarnings()) -- Dietmars Wunsch 07.09.2026, nach
 * Installation eigener Öffnungskontakte. Deckt insbesondere den Live-Fund
 * ab, dass Dietmars NEUE (Matter-basierte) Kontakte über die seit Symcon
 * 9.0 neue Variablendarstellung (VariablePresentation) laufen, KEIN
 * klassisches Profil haben, und dort true/false GENAU UMGEKEHRT zum
 * klassischen Systemprofil "~Window" bedeuten (true=offen dort,
 * true=geschlossen bei Dietmars echtem Küche-Fenster-Kontakt) -- die
 * Erkennung darf sich deshalb NIE auf eine feste Konvention verlassen.
 * Kein Netzzugriff nötig.
 *
 *   php .tools/test-fensterkontakte.php    # 0 = alle Prüfungen bestanden
 */

$GLOBALS['whub_test_variableValues'] = [];
$GLOBALS['whub_test_variableProfiles'] = [];
$GLOBALS['whub_test_variableCustomProfiles'] = [];
$GLOBALS['whub_test_variablePresentations'] = [];
$GLOBALS['whub_test_variableExists'] = [];
$GLOBALS['whub_test_names'] = [];
$GLOBALS['whub_test_pushCalls'] = [];

function IPS_VariableExists(int $id): bool
{
    return $GLOBALS['whub_test_variableExists'][$id] ?? false;
}
function GetValue(int $id)
{
    return $GLOBALS['whub_test_variableValues'][$id] ?? false;
}
function IPS_GetVariable(int $id)
{
    if (!($GLOBALS['whub_test_variableExists'][$id] ?? false)) {
        return false;
    }
    return [
        'VariableProfile' => $GLOBALS['whub_test_variableProfiles'][$id] ?? '',
        'VariableCustomProfile' => $GLOBALS['whub_test_variableCustomProfiles'][$id] ?? '',
        'VariablePresentation' => $GLOBALS['whub_test_variablePresentations'][$id] ?? [],
    ];
}
function IPS_GetVariableList(): array
{
    return array_keys(array_filter($GLOBALS['whub_test_variableExists']));
}
function IPS_GetName(int $id): string
{
    return $GLOBALS['whub_test_names'][$id] ?? ('#' . $id);
}
function IPS_LogMessage(string $sender, string $message): void
{
}
function WFC_PushNotification(int $id, string $title, string $text, string $sound, int $senderId): bool
{
    $GLOBALS['whub_test_pushCalls'][] = ['webfront', $id, $title, $text, $sound];
    return true;
}
function VISU_PostNotificationEx(int $id, string $title, string $text, string $icon, string $sound, int $targetId): bool
{
    $GLOBALS['whub_test_pushCalls'][] = ['kachel', $id, $title, $text, $sound];
    return true;
}
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
}

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

function presentationWithOptions(array $options): array
{
    return ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}', 'OPTIONS' => json_encode($options)];
}

$hub = new WarnHub();
$hub->Create();

echo "== contactOpenRawValue(): klassische Systemprofile ==\n";
$GLOBALS['whub_test_variableExists'][101] = true;
$GLOBALS['whub_test_variableProfiles'][101] = '~Window';
check('~Window -> true bedeutet offen', callPrivate($hub, 'contactOpenRawValue', [101]) === true);

$GLOBALS['whub_test_variableExists'][102] = true;
$GLOBALS['whub_test_variableProfiles'][102] = '~Window.Reversed';
check('~Window.Reversed -> false bedeutet offen (umgekehrt)', callPrivate($hub, 'contactOpenRawValue', [102]) === false);

$GLOBALS['whub_test_variableExists'][103] = true;
$GLOBALS['whub_test_variableProfiles'][103] = '~Door';
check('~Door -> true bedeutet offen', callPrivate($hub, 'contactOpenRawValue', [103]) === true);

$GLOBALS['whub_test_variableExists'][104] = true;
$GLOBALS['whub_test_variableProfiles'][104] = '~Door.Reversed';
check('~Door.Reversed -> false bedeutet offen', callPrivate($hub, 'contactOpenRawValue', [104]) === false);

echo "\n== contactOpenRawValue(): neue Variablendarstellung (Live-Fund Dietmars Küche-Fenster-Kontakt, 07.09.2026) ==\n";
// Echte Live-Daten: {"Caption":"Geschlossen",...,"Value":true},{"Caption":"Geöffnet",...,"Value":false}
// -- GENAU UMGEKEHRT zum klassischen ~Window-Profil oben (dort true=offen).
$GLOBALS['whub_test_variableExists'][201] = true;
$GLOBALS['whub_test_variablePresentations'][201] = presentationWithOptions([
    ['Caption' => 'Geschlossen', 'Color' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => true],
    ['Caption' => 'Geöffnet', 'Color' => -1, 'IconActive' => false, 'IconValue' => '', 'Value' => false],
]);
check('neue Vorlage (Dietmars echte Küche-Fenster-Daten): false bedeutet offen', callPrivate($hub, 'contactOpenRawValue', [201]) === false);

$GLOBALS['whub_test_variableExists'][202] = true;
$GLOBALS['whub_test_variablePresentations'][202] = presentationWithOptions([
    ['Caption' => 'Offen', 'Value' => true],
    ['Caption' => 'Zu', 'Value' => false],
]);
check('neue Vorlage mit "Offen"/"Zu" statt "Geöffnet"/"Geschlossen": true bedeutet offen', callPrivate($hub, 'contactOpenRawValue', [202]) === true);

echo "\n== contactOpenRawValue(): kein erkennbarer Fenster-/Tür-Kontakt ==\n";
$GLOBALS['whub_test_variableExists'][301] = true; // weder Profil noch Vorlage
check('ohne Profil/Vorlage -> null (nicht bestimmbar, nicht geraten)', callPrivate($hub, 'contactOpenRawValue', [301]) === null);

$GLOBALS['whub_test_variableExists'][302] = true;
$GLOBALS['whub_test_variableProfiles'][302] = '~Temperature';
check('unpassendes Profil (~Temperature) -> null', callPrivate($hub, 'contactOpenRawValue', [302]) === null);

check('nicht existierende Variable -> null', callPrivate($hub, 'contactOpenRawValue', [999999]) === null);

echo "\n== isContactOpen(): kombiniert Rohwert-Bedeutung mit dem aktuellen Wert ==\n";
$GLOBALS['whub_test_variableValues'][101] = true; // ~Window, true=offen
check('~Window, aktueller Wert true -> offen', callPrivate($hub, 'isContactOpen', [101]) === true);
$GLOBALS['whub_test_variableValues'][101] = false;
check('~Window, aktueller Wert false -> geschlossen', callPrivate($hub, 'isContactOpen', [101]) === false);

$GLOBALS['whub_test_variableValues'][201] = true; // neue Vorlage, false=offen -> true=geschlossen
check('neue Vorlage, aktueller Wert true -> geschlossen (Dietmars echter Fall)', callPrivate($hub, 'isContactOpen', [201]) === false);
$GLOBALS['whub_test_variableValues'][201] = false;
check('neue Vorlage, aktueller Wert false -> offen', callPrivate($hub, 'isContactOpen', [201]) === true);

check('Variable existiert nicht -> null', callPrivate($hub, 'isContactOpen', [88888]) === null);
check('VariableID 0 -> null', callPrivate($hub, 'isContactOpen', [0]) === null);

echo "\n== decodeFensterkontakte(): Kategorien-Checkboxen wie bei Schutzaktionen ==\n";
$hub->SetProp('Fensterkontakte', json_encode([
    ['Name' => 'Küche Fenster', 'Aktiv' => true, 'KatSturm' => true, 'KatHagel' => true, 'KatStarkregen' => true, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 3, 'StandortFilter' => '', 'VariableID' => 201],
]));
$decoded = callPrivate($hub, 'decodeFensterkontakte', []);
check('genau ein Kontakt dekodiert', count($decoded) === 1);
check('Kategorien korrekt als ["sturm","hagel","starkregen"] gelesen', $decoded[0]['Kategorien'] === ['sturm', 'hagel', 'starkregen']);
check('VariableID korrekt übernommen', $decoded[0]['VariableID'] === 201);

echo "\n== DiscoverFensterkontakte(): systemweite Suche über beide Erkennungswege ==\n";
// Läuft bewusst NACH den obigen contactOpenRawValue()-Prüfungen und nutzt
// dieselben globalen Variablen-Fixtures weiter (IPS_GetVariableList()
// scannt IMMER den gesamten Fake-Objektbaum) -- die Zählung ist deshalb
// nicht auf "genau 2" fixiert, sondern prüft gezielt per VariableID, wer
// gefunden wurde und wer (403, ohne Profil/Vorlage) NICHT gefunden wurde.
$hub2 = new WarnHub();
$hub2->Create();
// 401: klassisches ~Window-Profil -- Treffer
$GLOBALS['whub_test_variableExists'][401] = true;
$GLOBALS['whub_test_variableProfiles'][401] = '~Window';
$GLOBALS['whub_test_names'][401] = 'WC Fenster';
// 402: neue Vorlage -- Treffer
$GLOBALS['whub_test_variableExists'][402] = true;
$GLOBALS['whub_test_variablePresentations'][402] = presentationWithOptions([
    ['Caption' => 'Geschlossen', 'Value' => true], ['Caption' => 'Geöffnet', 'Value' => false],
]);
$GLOBALS['whub_test_names'][402] = 'Esszimmer Terrassentür';
// 403: unrelated Boolean ohne Profil/Vorlage -- KEIN Treffer
$GLOBALS['whub_test_variableExists'][403] = true;
$GLOBALS['whub_test_names'][403] = 'Irgendein Schalter';
$msg = $hub2->DiscoverFensterkontakte();
check('meldet neue Treffer', str_starts_with($msg, '✅'));
[$field, , $valuesJson] = $hub2->lastValuesUpdate('Fensterkontakte');
check('schreibt in das Feld "Fensterkontakte"', $field === 'Fensterkontakte');
$rows = json_decode($valuesJson, true);
$byVar = array_column($rows, null, 'VariableID');
check('401 (~Window) gefunden, Name übernommen', ($byVar[401]['Name'] ?? null) === 'WC Fenster');
check('402 (neue Vorlage) gefunden, Name übernommen', ($byVar[402]['Name'] ?? null) === 'Esszimmer Terrassentür');
check('403 (unbeteiligter Schalter ohne Profil/Vorlage) wird NICHT aufgenommen', !isset($byVar[403]));
check('Treffer werden mit Sturm/Hagel/Starkregen vorausgefüllt (analog Schutzaktionstyp "fenster")', ($byVar[401]['KatSturm'] ?? null) === true && ($byVar[401]['KatHagel'] ?? null) === true && ($byVar[401]['KatStarkregen'] ?? null) === true);
check('Treffer sind standardmäßig aktiv', ($byVar[401]['Aktiv'] ?? null) === true);

// Simuliert den Klick auf "Übernehmen" zwischen den beiden Suchen -- ohne
// das bleibt die Property unverändert (Discovery schreibt bewusst nur in
// die offene Formularmaske, siehe DiscoverFensterkontakte()-Kommentar),
// eine zweite Suche würde sonst dieselben "neuen" Treffer wiederfinden.
$hub2->SetProp('Fensterkontakte', $valuesJson);
$msg2 = $hub2->DiscoverFensterkontakte();
check('erneute Suche findet keine neuen Treffer mehr', str_contains($msg2, 'Keine neuen'));

echo "\n== processWarnings(): Ende-zu-Ende -- offenes Fenster bei aktiver Sturmwarnung löst genau eine Push aus ==\n";
$standort = [
    'Name' => 'Zuhause', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 0, 'QuellVarLon' => 0,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true,
];
$webfronts = [['InstanceID' => 601, 'Name' => 'Handy', 'Typ' => 'kachel', 'Aktiv' => true]];
$machWarnung = function (string $severity = 'Severe'): array {
    return [
        'identifier' => 'test-fenster-1', 'source' => 'test', 'msgType' => 'Update', 'event' => 'Sturm',
        'headline' => 'Sturmböen', 'description' => '', 'instruction' => '', 'severity' => $severity,
        'effective' => date('c'), 'onset' => date('c'), 'expires' => null,
        'areaDesc' => '', 'rings' => [], 'circles' => [['lat' => 48.4785, 'lon' => 7.9448, 'radiusKm' => 5.0]],
    ];
};

$hub3 = new WarnHub();
$hub3->Create();
$hub3->SetProp('Standorte', json_encode([$standort]));
$hub3->SetProp('WebFronts', json_encode($webfronts));
$hub3->SetProp('PushAktiv', true);
$hub3->SetProp('Fensterkontakte', json_encode([
    ['Name' => 'Küche Fenster', 'Aktiv' => true, 'KatSturm' => true, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 2, 'StandortFilter' => '', 'VariableID' => 201],
]));
$GLOBALS['whub_test_variableValues'][201] = false; // neue Vorlage, false=offen -> Fenster ist OFFEN

$GLOBALS['whub_test_pushCalls'] = [];
$r1 = callPrivate($hub3, 'processWarnings', [[$machWarnung()]]);
check('genau eine Fenster-Warnung gemeldet', $r1['fensterWarnungen'] === 1);
check('genau ein zusätzlicher Push wurde tatsächlich zugestellt (Warnung + Fensterwarnung = 2 insgesamt)', count($GLOBALS['whub_test_pushCalls']) === 2);
$fensterCall = $GLOBALS['whub_test_pushCalls'][1];
check('Push-Titel ist "⚠️ Fenster/Tür offen"', $fensterCall[2] === '⚠️ Fenster/Tür offen');
check('Push-Text nennt den Kontakt-Namen', str_contains($fensterCall[3], 'Küche Fenster'));

echo "\n== processWarnings(): dieselbe Warnung, Fenster weiterhin offen -> KEIN erneuter Push (Dedupe) ==\n";
$GLOBALS['whub_test_pushCalls'] = [];
$r2 = callPrivate($hub3, 'processWarnings', [[$machWarnung()]]);
check('keine erneute Fenster-Warnung (bereits gesehen)', $r2['fensterWarnungen'] === 0);

echo "\n== processWarnings(): Fenster wird geschlossen und während derselben Warnung wieder geöffnet -> NEUE Push ==\n";
$GLOBALS['whub_test_variableValues'][201] = true; // neue Vorlage, true=geschlossen
$GLOBALS['whub_test_pushCalls'] = [];
$r3 = callPrivate($hub3, 'processWarnings', [[$machWarnung()]]);
check('geschlossenes Fenster löst keine Warnung aus', $r3['fensterWarnungen'] === 0);

$GLOBALS['whub_test_variableValues'][201] = false; // wieder offen
$GLOBALS['whub_test_pushCalls'] = [];
$r4 = callPrivate($hub3, 'processWarnings', [[$machWarnung()]]);
check('erneutes Öffnen während derselben Warnung löst eine NEUE Meldung aus', $r4['fensterWarnungen'] === 1);

echo "\n== processWarnings(): MinSeverity-Filter greift auch für Fensterkontakte ==\n";
$hub4 = new WarnHub();
$hub4->Create();
$hub4->SetProp('Standorte', json_encode([$standort]));
$hub4->SetProp('WebFronts', json_encode($webfronts));
$hub4->SetProp('PushAktiv', true);
$hub4->SetProp('Fensterkontakte', json_encode([
    ['Name' => 'HWR Fenster', 'Aktiv' => true, 'KatSturm' => true, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 4, 'StandortFilter' => '', 'VariableID' => 202],
]));
$GLOBALS['whub_test_variableValues'][202] = true; // "Offen"/"Zu"-Vorlage, true=offen
$r5 = callPrivate($hub4, 'processWarnings', [[$machWarnung('Severe')]]); // Severe (3) < MinSeverity 4 (Extreme)
check('Severity unterhalb MinSeverity -> keine Fenster-Warnung', $r5['fensterWarnungen'] === 0);

echo "\n== processWarnings(): unpassende Kategorie löst keine Fenster-Warnung aus ==\n";
$hub5 = new WarnHub();
$hub5->Create();
$hub5->SetProp('Standorte', json_encode([$standort]));
$hub5->SetProp('WebFronts', json_encode($webfronts));
$hub5->SetProp('PushAktiv', true);
$hub5->SetProp('Fensterkontakte', json_encode([
    ['Name' => 'Nur Starkregen', 'Aktiv' => true, 'KatSturm' => false, 'KatHagel' => false, 'KatStarkregen' => true, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 1, 'StandortFilter' => '', 'VariableID' => 203],
]));
$GLOBALS['whub_test_variableExists'][203] = true;
$GLOBALS['whub_test_variableProfiles'][203] = '~Window';
$GLOBALS['whub_test_variableValues'][203] = true; // offen
$r6 = callPrivate($hub5, 'processWarnings', [[$machWarnung('Severe')]]); // event=Sturm, Kontakt nur für Starkregen
check('Kontakt nur für "starkregen" konfiguriert -> Sturm-Warnung löst NICHTS aus', $r6['fensterWarnungen'] === 0);

echo "\n== processWarnings(): unfiltert (StandortFilter leer) feuert NICHT für einen mobilen Standort ==\n";
$standortMobil = [
    'Name' => 'Unterwegs', 'Ort' => '', 'Lat' => 48.4785, 'Lon' => 7.9448, 'QuellVarLat' => 501, 'QuellVarLon' => 502,
    'RadiusKm' => 15.0, 'MinSeverity' => 1, 'PushZielFilter' => '', 'Aktiv' => true,
];
$GLOBALS['whub_test_variableExists'][501] = true;
$GLOBALS['whub_test_variableExists'][502] = true;
$GLOBALS['whub_test_variableValues'][501] = 48.4785;
$GLOBALS['whub_test_variableValues'][502] = 7.9448;
$hub6 = new WarnHub();
$hub6->Create();
$hub6->SetProp('Standorte', json_encode([$standortMobil]));
$hub6->SetProp('WebFronts', json_encode($webfronts));
$hub6->SetProp('PushAktiv', true);
$hub6->SetProp('Fensterkontakte', json_encode([
    ['Name' => 'Auto-Fenster', 'Aktiv' => true, 'KatSturm' => true, 'KatHagel' => false, 'KatStarkregen' => false, 'KatGewitter' => false, 'KatSchnee' => false, 'KatHitze' => false, 'MinSeverity' => 1, 'StandortFilter' => '', 'VariableID' => 204],
]));
$GLOBALS['whub_test_variableExists'][204] = true;
$GLOBALS['whub_test_variableProfiles'][204] = '~Window';
$GLOBALS['whub_test_variableValues'][204] = true; // offen
$r7 = callPrivate($hub6, 'processWarnings', [[$machWarnung('Severe')]]);
check('leerer Standort-Filter + mobiler Standort -> keine Fenster-Warnung (Sicherheitssperre wie bei Schutzaktionen)', $r7['fensterWarnungen'] === 0);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
