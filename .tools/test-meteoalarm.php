<?php

/**
 * Prüft die Meteoalarm-Anbindung offline, ohne Netzzugriff:
 *   1. parseMeteoalarmAtom() gegen eine kleine, von der echten Feed-Struktur
 *      abgeleitete Atom+CAP-Fixture (Live gegen germany/france/austria
 *      gegengeprüft 04.09.2026 -- KEIN Polygon/Kreis in den Einträgen, nur
 *      benannte Gebiete je EMMA_ID/NUTS3-Geocode, siehe SUITE.md/CHANGELOG).
 *   2. namesOverlap() -- der Namensabgleich-Ersatz für die fehlende Geometrie.
 *
 *   php .tools/test-meteoalarm.php    # 0 = alle Prüfungen bestanden
 */

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
    public function SetProp($n, $v)
    {
        $this->props[$n] = $v;
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

echo "== parseMeteoalarmAtom(): Struktur der echten Feeds nachgestellt (Live gegen germany/france/austria gegengeprüft, 04.09.2026) ==\n";
// Zwei gültige Einträge (dieselbe CAP-Warnung, zwei betroffene Gebiete --
// genau das reale Muster: EIN CAP-identifier, EIN Atom-Eintrag JE Gebiet)
// plus ein absichtlich unvollständiger dritter Eintrag (keine areaDesc --
// muss übersprungen werden, kein Rateversuch).
$fixture = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:cap="urn:oasis:names:tc:emergency:cap:1.2">
  <entry>
    <cap:geocode><valueName>EMMA_ID</valueName><value>DE202</value></cap:geocode>
    <cap:areaDesc>Berlin</cap:areaDesc>
    <cap:event>storm-force gusts</cap:event>
    <cap:sent>2026-09-04T15:04:00+00:00</cap:sent>
    <cap:expires>2026-09-04T17:00:00+00:00</cap:expires>
    <cap:onset>2026-09-04T15:04:00+00:00</cap:onset>
    <cap:severity>Moderate</cap:severity>
    <cap:message_type>Alert</cap:message_type>
    <cap:identifier>2.49.0.0.276.0.DWD.PVW.TEST.MUL</cap:identifier>
    <title>Orange Wind Warning issued for Germany - Berlin</title>
  </entry>
  <entry>
    <cap:geocode><valueName>EMMA_ID</valueName><value>DE140</value></cap:geocode>
    <cap:areaDesc>Kreis Oberspreewald-Lausitz</cap:areaDesc>
    <cap:event>storm-force gusts</cap:event>
    <cap:sent>2026-09-04T15:04:00+00:00</cap:sent>
    <cap:expires>2026-09-04T17:00:00+00:00</cap:expires>
    <cap:onset>2026-09-04T15:04:00+00:00</cap:onset>
    <cap:severity>Moderate</cap:severity>
    <cap:message_type>Alert</cap:message_type>
    <cap:identifier>2.49.0.0.276.0.DWD.PVW.TEST.MUL</cap:identifier>
    <title>Orange Wind Warning issued for Germany - Kreis Oberspreewald-Lausitz</title>
  </entry>
  <entry>
    <cap:event>ohne Gebiet -- muss übersprungen werden</cap:event>
    <cap:identifier>2.49.0.0.276.0.DWD.PVW.OHNE-AREADESC.MUL</cap:identifier>
    <title>Unvollständiger Eintrag</title>
  </entry>
</feed>
XML;

$hub = new WarnHub();
$hub->Create();
$parsed = callPrivate($hub, 'parseMeteoalarmAtom', [$fixture, 'germany']);
check('genau 2 gültige Einträge (der dritte ohne areaDesc wird übersprungen)', count($parsed) === 2);
check('source ist "meteoalarm"', ($parsed[0]['source'] ?? null) === 'meteoalarm');
check('identifier enthält den CAP-Identifier UND das Gebiet (zwei Einträge teilen sich sonst denselben Identifier)', $parsed[0]['identifier'] === '2.49.0.0.276.0.DWD.PVW.TEST.MUL|Berlin');
check('zweiter Eintrag trägt das jeweils eigene Gebiet im identifier', $parsed[1]['identifier'] === '2.49.0.0.276.0.DWD.PVW.TEST.MUL|Kreis Oberspreewald-Lausitz');
check('severity wird unverändert übernommen (identisches Vokabular zu SEVERITY_RANK)', $parsed[0]['severity'] === 'Moderate');
check('KEINE Geometrie -- rings und circles bleiben leer (kein Polygon/Kreis in Meteoalarm-Feeds)', $parsed[0]['rings'] === [] && $parsed[0]['circles'] === []);
check('nameMatch trägt die areaDesc für den Ersatz-Namensabgleich', $parsed[0]['nameMatch'] === ['Berlin']);
check('msgType fällt auf "Alert" zurück, falls leer', $parsed[0]['msgType'] === 'Alert');

echo "\n== parseMeteoalarmAtom(): kaputtes XML führt zu leerem Ergebnis statt Fehler ==\n";
$broken = callPrivate($hub, 'parseMeteoalarmAtom', ['<feed><entry>', 'germany']);
check('liefert eine leere Liste statt eines Fehlers/Absturzes', $broken === []);

echo "\n== parseMeteoalarmAtom(): Übungs-/Testmeldungen (cap:status != Actual) werden verworfen (Praxis-Fund ralf, Symcon-Forum, 16.09.2026: 'ACHTUNG! TEST TEST ... Heute scheint der Mond.' kam unverändert als echte Warnung durch) ==\n";
$statusFixture = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:cap="urn:oasis:names:tc:emergency:cap:1.2">
  <entry>
    <cap:areaDesc>Echt-Gebiet</cap:areaDesc>
    <cap:event>storm-force gusts</cap:event>
    <cap:severity>Moderate</cap:severity>
    <cap:message_type>Alert</cap:message_type>
    <cap:status>Actual</cap:status>
    <cap:identifier>test-status-actual</cap:identifier>
    <title>Echte Warnung</title>
  </entry>
  <entry>
    <cap:areaDesc>Kein-Status-Gebiet</cap:areaDesc>
    <cap:event>storm-force gusts</cap:event>
    <cap:severity>Moderate</cap:severity>
    <cap:message_type>Alert</cap:message_type>
    <cap:identifier>test-status-fehlt</cap:identifier>
    <title>Warnung ohne status-Feld (ältere/andere Quelle)</title>
  </entry>
  <entry>
    <cap:areaDesc>Übungs-Gebiet</cap:areaDesc>
    <cap:event>storm-force gusts</cap:event>
    <cap:severity>Extreme</cap:severity>
    <cap:message_type>Alert</cap:message_type>
    <cap:status>Exercise</cap:status>
    <cap:identifier>test-status-exercise</cap:identifier>
    <title>ACHTUNG! TEST TEST</title>
  </entry>
  <entry>
    <cap:areaDesc>Test-Gebiet</cap:areaDesc>
    <cap:event>storm-force gusts</cap:event>
    <cap:severity>Extreme</cap:severity>
    <cap:message_type>Alert</cap:message_type>
    <cap:status>Test</cap:status>
    <cap:identifier>test-status-test</cap:identifier>
    <title>Testmeldung</title>
  </entry>
</feed>
XML;
$statusParsed = callPrivate($hub, 'parseMeteoalarmAtom', [$statusFixture, 'germany']);
check('genau 2 Einträge bleiben übrig (status=Actual und ganz ohne status-Feld), Exercise UND Test werden verworfen', count($statusParsed) === 2);
check('der Eintrag mit status=Actual ist dabei', in_array('test-status-actual|Echt-Gebiet', array_column($statusParsed, 'identifier'), true));
check('der Eintrag ganz ohne status-Feld ist dabei (Rückwärtskompatibilität -- nicht jede Quelle liefert das Feld)', in_array('test-status-fehlt|Kein-Status-Gebiet', array_column($statusParsed, 'identifier'), true));
check('der Eintrag mit status=Exercise fehlt', !in_array('test-status-exercise|Übungs-Gebiet', array_column($statusParsed, 'identifier'), true));
check('der Eintrag mit status=Test fehlt', !in_array('test-status-test|Test-Gebiet', array_column($statusParsed, 'identifier'), true));

echo "\n== namesOverlap(): Namensabgleich-Ersatz für die fehlende Geometrie ==\n";
check('identischer Name matcht', callPrivate($hub, 'namesOverlap', [['Berlin'], ['Berlin']]) === true);
check('Groß-/Kleinschreibung ist egal', callPrivate($hub, 'namesOverlap', [['BERLIN'], ['berlin']]) === true);
check('Substring in beide Richtungen ("Ortenaukreis" in "Kreis Ortenaukreis")', callPrivate($hub, 'namesOverlap', [['Ortenaukreis'], ['Kreis Ortenaukreis']]) === true);
check('umgekehrte Substring-Richtung ebenfalls', callPrivate($hub, 'namesOverlap', [['Kreis Ortenaukreis'], ['Ortenaukreis']]) === true);
check('unterschiedliche Gebiete matchen NICHT (keine geratene Präzision)', callPrivate($hub, 'namesOverlap', [['Hamburg'], ['Berlin']]) === false);
check('leere Listen matchen nie', callPrivate($hub, 'namesOverlap', [[], ['Berlin']]) === false);
check('eine von mehreren Kandidaten-Regionen reicht (Standort liefert mehrere Nominatim-Ebenen)', callPrivate($hub, 'namesOverlap', [['Berlin'], ['Deutschland', 'Berlin', 'Mitte']]) === true);

echo "\n== meteoalarmSlugsForStandorte(): Länderauswahl ohne Netzzugriff (Reverse-Geocoding kommt aus dem Cache) ==\n";
// Praxis-Fund hbraun, Symcon-Forum, 23.10.2026: Meteoalarm zusätzlich zu
// NINA aktiv -> "Sturmböen" (NINA/DWD) UND "Gale-Force Gusts" (Meteoalarm-
// Deutschland-Feed, englischer Ereignisname = eigener Episoden-Schlüssel)
// zugleich in der Kachel. Deutschland übernimmt deshalb NINA bzw. DWD
// direkt, wie Österreich GeoSphere und die Schweiz Alertswiss.
function hubMitStandorten(array $laender, array $quellen, array $inaktiv = []): WarnHub
{
    $h = new WarnHub();
    $h->Create();
    $standorte = [];
    $cache = [];
    $i = 0;
    foreach ($laender as $name => $cc) {
        $lat = 47.0 + $i;
        $lon = 7.0 + $i;
        $i++;
        $standorte[] = ['Name' => $name, 'Ort' => '', 'Lat' => $lat, 'Lon' => $lon, 'RadiusKm' => 10, 'MinSeverity' => 1, 'Aktiv' => !in_array($name, $inaktiv, true), 'PushZielFilter' => ''];
        $cache[sprintf('%.1f_%.1f', $lat, $lon)] = ['ts' => time(), 'countryCode' => $cc, 'names' => [$name]];
    }
    $h->SetProp('Standorte', json_encode($standorte));
    $h->WriteAttributeString('ReverseGeoCache', json_encode($cache));
    foreach (['QuelleNina', 'QuelleDwd', 'QuelleGeosphereAt', 'QuelleAlertSwissCh'] as $p) {
        $h->SetProp($p, in_array($p, $quellen, true));
    }
    return $h;
}
$slugs = fn (WarnHub $h) => callPrivate($h, 'meteoalarmSlugsForStandorte');

check('hbrauns Fall: deutscher Standort, NINA aktiv -> Meteoalarm-Deutschland wird NICHT abgefragt', $slugs(hubMitStandorten(['Schwanewede' => 'de'], ['QuelleNina'])) === []);
check('deutscher Standort, nur DWD direkt aktiv -> ebenfalls nicht', $slugs(hubMitStandorten(['Schwanewede' => 'de'], ['QuelleDwd'])) === []);
check('deutscher Standort, NINA UND DWD aktiv -> nicht', $slugs(hubMitStandorten(['Schwanewede' => 'de'], ['QuelleNina', 'QuelleDwd'])) === []);
check('deutscher Standort OHNE NINA/DWD -> Meteoalarm bleibt die einzige deutsche Quelle und wird abgefragt', $slugs(hubMitStandorten(['Schwanewede' => 'de'], [])) === ['germany']);
check('Deutschland + Frankreich, NINA aktiv -> nur Frankreich (die Deutschland-Regel frisst kein anderes Land)', $slugs(hubMitStandorten(['Zuhause' => 'de', 'Urlaub' => 'fr'], ['QuelleNina'])) === ['france']);
check('Niederlande, NINA aktiv -> Niederlande bleibt (NINA deckt nur Deutschland ab)', $slugs(hubMitStandorten(['Camping' => 'nl'], ['QuelleNina'])) === ['netherlands']);
check('Österreich mit GeoSphere -> nicht (bestehende Regel unverändert)', $slugs(hubMitStandorten(['Wien' => 'at'], ['QuelleGeosphereAt'])) === []);
check('Österreich ohne GeoSphere -> austria (NINA/DWD spielen für Österreich keine Rolle)', $slugs(hubMitStandorten(['Wien' => 'at'], ['QuelleNina', 'QuelleDwd'])) === ['austria']);
check('Schweiz mit Alertswiss -> nicht (bestehende Regel unverändert)', $slugs(hubMitStandorten(['Basel' => 'ch'], ['QuelleAlertSwissCh'])) === []);
check('Schweiz ohne Alertswiss -> switzerland', $slugs(hubMitStandorten(['Basel' => 'ch'], ['QuelleNina'])) === ['switzerland']);
check('Land ohne Meteoalarm-Feed (us) -> nichts', $slugs(hubMitStandorten(['Reise' => 'us'], [])) === []);
check('inaktiver Standort zählt nicht', $slugs(hubMitStandorten(['Alt' => 'fr'], [], ['Alt'])) === []);
check('zwei Standorte im selben Land -> der Feed wird nur EINMAL abgefragt', $slugs(hubMitStandorten(['A' => 'fr', 'B' => 'fr'], [])) === ['france']);
check('D-A-CH komplett mit allen direkten Quellen -> Meteoalarm hat nichts mehr zu tun', $slugs(hubMitStandorten(['D' => 'de', 'A' => 'at', 'CH' => 'ch'], ['QuelleNina', 'QuelleGeosphereAt', 'QuelleAlertSwissCh'])) === []);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
