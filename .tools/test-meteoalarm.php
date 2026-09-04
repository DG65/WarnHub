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

echo "\n== namesOverlap(): Namensabgleich-Ersatz für die fehlende Geometrie ==\n";
check('identischer Name matcht', callPrivate($hub, 'namesOverlap', [['Berlin'], ['Berlin']]) === true);
check('Groß-/Kleinschreibung ist egal', callPrivate($hub, 'namesOverlap', [['BERLIN'], ['berlin']]) === true);
check('Substring in beide Richtungen ("Ortenaukreis" in "Kreis Ortenaukreis")', callPrivate($hub, 'namesOverlap', [['Ortenaukreis'], ['Kreis Ortenaukreis']]) === true);
check('umgekehrte Substring-Richtung ebenfalls', callPrivate($hub, 'namesOverlap', [['Kreis Ortenaukreis'], ['Ortenaukreis']]) === true);
check('unterschiedliche Gebiete matchen NICHT (keine geratene Präzision)', callPrivate($hub, 'namesOverlap', [['Hamburg'], ['Berlin']]) === false);
check('leere Listen matchen nie', callPrivate($hub, 'namesOverlap', [[], ['Berlin']]) === false);
check('eine von mehreren Kandidaten-Regionen reicht (Standort liefert mehrere Nominatim-Ebenen)', callPrivate($hub, 'namesOverlap', [['Berlin'], ['Deutschland', 'Berlin', 'Mitte']]) === true);

echo "\n" . ($failures === 0 ? "✅ Alle $checks Prüfungen bestanden.\n" : "❌ $failures von $checks Prüfungen fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
