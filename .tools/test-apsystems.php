<?php
/**
 * test-apsystems.php — Testgerüst für IHUB_ApsystemsDriver gegen ein echtes
 * EZ1-Gerät.
 *
 * Anlass: Der APsystems-Treiber ist der einzige, der NICHT Modbus spricht,
 * sondern eine HTTP-/JSON-API. `php -l` und die beiden anderen Prüfskripte
 * sehen davon nichts — ob der Lesepfad wirklich Werte liefert, zeigt nur ein
 * Lauf gegen ein Gerät. Analog zu MeterHubs `.tools/test-virtual.php`.
 *
 * Der Klassenquelltext wird aus InverterHub/module.php HERAUSGESCHNITTEN und
 * evaluiert — es wird also der echte Treiber getestet, keine Kopie, die beim
 * nächsten Umbau still auseinanderläuft.
 *
 * Geprüft wird:
 *   1. dass der Treiber KEINEN Modbus-Zugriff macht (FakeMb wirft sonst),
 *   2. dass jeder gesetzte Ident auch deklariert ist und umgekehrt,
 *   3. dass die Summen stimmen (pv_total = Kanal 1 + 2 usw.),
 *   4. dass ein nicht erreichbares Gerät sauber false/connected=false meldet
 *      und keine Messwerte zurücklässt.
 *
 * Aufruf:  php .tools/test-apsystems.php <host> [port]
 * Rückgabe: 0 = alles in Ordnung, 1 = Testfehler, 2 = Aufruffehler
 */

$host = $argv[1] ?? null;
$port = (int)($argv[2] ?? 8050);

$modulePath = dirname(__DIR__) . '/InverterHub/module.php';

if ($host === null) {
    fwrite(STDERR, "Aufruf: php .tools/test-apsystems.php <host> [port]\n");
    fwrite(STDERR, "Beispiel: php .tools/test-apsystems.php 192.168.178.82 8050\n");
    exit(2);
}
if (!is_file($modulePath)) {
    fwrite(STDERR, "FEHLER: {$modulePath} nicht gefunden.\n");
    exit(2);
}

// --- IP-Symcon-Konstanten, die getProfiles() benutzt -----------------------
define('VARIABLETYPE_BOOLEAN', 0);
define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_FLOAT',   2);
define('VARIABLETYPE_STRING',  3);

// --- Interface + Treiberklasse aus module.php herausschneiden --------------
$src = file_get_contents($modulePath);

if (!preg_match('/^interface IHUB_InverterDriverInterface.*?^\}/ms', $src, $m)) {
    fwrite(STDERR, "FEHLER: Interface nicht gefunden.\n");
    exit(2);
}
$interfaceSrc = $m[0];

if (!preg_match('/^class IHUB_ApsystemsDriver implements IHUB_InverterDriverInterface.*?^\}/ms', $src, $m)) {
    fwrite(STDERR, "FEHLER: IHUB_ApsystemsDriver nicht gefunden.\n");
    exit(2);
}
$driverSrc = $m[0];

eval($interfaceSrc);
eval($driverSrc);

// --- Stubs -----------------------------------------------------------------
class FakeMb
{
    public $host;
    public $port;
    public $unitId = 1;
    public function __construct($h, $p) { $this->host = $h; $this->port = $p; }
    // Jeder Modbus-Zugriff ist hier ein Testfehler: Der Treiber darf keinen machen.
    public function __call($name, $args)
    {
        throw new RuntimeException("Treiber hat Modbus-Methode {$name}() aufgerufen - das darf er nicht!");
    }
}

class FakeHub
{
    public $vars   = [];
    public $groups = [];
    public function __construct(array $groups) { $this->groups = $groups; }
    public function SetVarFloat(string $i, float $v) { $this->vars[$i] = $v; }
    public function SetVarInt(string $i, int $v)     { $this->vars[$i] = $v; }
    public function SetVarBool(string $i, bool $v)   { $this->vars[$i] = $v; }
    public function SetVarStr(string $i, string $v)  { $this->vars[$i] = $v; }
    public function GetPropBool(string $n)           { return $this->groups[$n] ?? false; }
}

// --- Testlauf --------------------------------------------------------------
$drv = new IHUB_ApsystemsDriver();
$mb  = new FakeMb($host, $port);
$hub = new FakeHub(['GroupPV' => true, 'GroupEnergy' => true, 'GroupErrors' => true, 'GroupDevice' => true]);

echo "Gerät: {$host}:{$port}\n";
echo str_repeat('=', 62), "\n";

// 1) Deklarierte Idents gegen die gesetzten abgleichen
$declared = [];
foreach ($drv->getBaseVars() as $v) { $declared[$v[0]] = $v[1]; }
foreach ($drv->getOptionalGroups() as $g) {
    foreach ($g['vars'] as $v) { $declared[$v[0]] = $v[1]; }
}
echo 'Deklarierte Variablen: ', count($declared), "\n\n";

// 2) readDeviceInfo
$t = microtime(true);
$drv->readDeviceInfo($mb, $hub);
printf("readDeviceInfo   %5.2f s\n", microtime(true) - $t);

// 3) readFast
$t = microtime(true);
$ok = $drv->readFast($mb, $hub);
printf("readFast         %5.2f s   Rückgabe: %s\n", microtime(true) - $t, var_export($ok, true));

// 4) readSlow
$t = microtime(true);
$drv->readSlow($mb, $hub);
printf("readSlow         %5.2f s\n", microtime(true) - $t);

echo "\n", str_repeat('-', 62), "\n";
echo "Gesetzte Werte:\n";
foreach ($hub->vars as $ident => $val) {
    $shown = is_bool($val) ? ($val ? 'true' : 'false') : (is_float($val) ? rtrim(rtrim(sprintf('%.5f', $val), '0'), '.') : $val);
    printf("  %-14s %-24s  %s\n", $ident, $shown, $declared[$ident] ?? '*** NICHT DEKLARIERT ***');
}

echo "\n", str_repeat('-', 62), "\n";
$problems = [];

// Jeder gesetzte Ident muss deklariert sein, sonst landet der Wert nirgends.
foreach (array_keys($hub->vars) as $ident) {
    if (!isset($declared[$ident])) { $problems[] = "Ident '{$ident}' wird gesetzt, ist aber nicht deklariert."; }
}
// Bei allen aktiven Gruppen sollten alle Idents auch befüllt worden sein.
foreach ($declared as $ident => $caption) {
    if (!array_key_exists($ident, $hub->vars)) { $problems[] = "Ident '{$ident}' ({$caption}) wurde nicht befüllt."; }
}
// Plausibilität
if (($hub->vars['connected'] ?? false) !== true) { $problems[] = 'connected ist nicht true.'; }
if (isset($hub->vars['pv_total'], $hub->vars['mppt1_power'], $hub->vars['mppt2_power'])) {
    $sum = $hub->vars['mppt1_power'] + $hub->vars['mppt2_power'];
    if (abs($sum - $hub->vars['pv_total']) > 0.001) { $problems[] = 'pv_total != mppt1_power + mppt2_power'; }
}
if (isset($hub->vars['e_pv_total'], $hub->vars['e_pv_total_1'], $hub->vars['e_pv_total_2'])) {
    $sum = $hub->vars['e_pv_total_1'] + $hub->vars['e_pv_total_2'];
    if (abs($sum - $hub->vars['e_pv_total']) > 0.001) { $problems[] = 'e_pv_total != Summe der Kanäle'; }
}
if (isset($hub->vars['ac_power'], $hub->vars['pv_total']) && abs($hub->vars['ac_power'] - $hub->vars['pv_total']) > 0.001) {
    $problems[] = 'ac_power != pv_total';
}

if ($problems) {
    echo "FEHLER:\n";
    foreach ($problems as $p) { echo "  ✗ ", $p, "\n"; }
    exit(1);
}
echo "OK — alle Idents deklariert, befüllt und in sich schlüssig.\n";

// 5) Verhalten bei nicht erreichbarem Gerät
echo "\n", str_repeat('-', 62), "\n";
echo "Gegenprobe: nicht erreichbares Gerät (Port 9)\n";
$hub2 = new FakeHub(['GroupPV' => true, 'GroupEnergy' => true, 'GroupErrors' => true, 'GroupDevice' => true]);
$mb2  = new FakeMb($host, 9);
$t = microtime(true);
$ok2 = $drv->readFast($mb2, $hub2);
printf("  readFast  %5.2f s  Rückgabe: %s  connected=%s\n",
    microtime(true) - $t, var_export($ok2, true), var_export($hub2->vars['connected'] ?? null, true));
if ($ok2 !== false || ($hub2->vars['connected'] ?? null) !== false) {
    echo "  ✗ Treiber meldet keinen sauberen Verbindungsfehler.\n";
    exit(1);
}
$drv->readSlow($mb2, $hub2);
$drv->readDeviceInfo($mb2, $hub2);
if (count($hub2->vars) !== 1) {
    echo "  ✗ Bei Verbindungsfehler wurden Messwerte gesetzt: ", implode(', ', array_keys($hub2->vars)), "\n";
    exit(1);
}
echo "  OK — meldet false, setzt connected=false und keine Altwerte.\n";
