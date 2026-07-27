<?php

// ---------------------------------------------------------------------------
// InverterHub — generisches Wechselrichter-Modul mit austauschbaren
// Hersteller-Treibern. Erster Treiber: GoodWe (portiert aus GoodweET).
// Alles in einer Datei: IPS lädt alle .php-Dateien im Modulordner, zwei
// Dateien mit derselben Klasse führen zu "Cannot redeclare class".
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// IHUB_ModbusTcpClient — gemeinsame Modbus-TCP-Grundfunktionen für alle Treiber
// ---------------------------------------------------------------------------

class IHUB_ModbusTcpClient
{
    public $host;
    public $port;
    public $unitId;

    // Float32-Wortreihenfolge: false = ABCD (big-endian, SunSpec-Konvention),
    // true = CDAB (Wort-Swap, „Standard Modbus little-endian"). Kostal-Geräte
    // sind ab Werk auf CDAB gestellt und liefern sonst Datenmüll.
    public $floatWordSwap = false;

    // Batch-Modus: eine offene Verbindung für viele Reads. Manche Geräte
    // (z. B. Sungrow WiNet-S) erlauben nur EINE Modbus-Verbindung und lehnen
    // schnelle Reconnects ab - dann fielen spätere Reads eines Zyklus aus.
    private $batchSock = null;

    // Öffnet eine wiederverwendbare Verbindung. Schlägt sie fehl, bleibt der
    // Per-Read-Modus aktiv (kein Fehler).
    public function beginBatch()
    {
        $this->endBatch();
        $s = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($s !== false) {
            stream_set_timeout($s, 3);
            $this->batchSock = $s;
        }
    }

    public function endBatch()
    {
        if ($this->batchSock !== null) {
            @fclose($this->batchSock);
            $this->batchSock = null;
        }
    }

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
    }

    public function setFloatWordSwap(bool $swap)
    {
        $this->floatWordSwap = $swap;
    }

    public function readHolding($startReg, $count)
    {
        $sock = $this->batchSock ?: @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return null;
        }
        if ($this->batchSock === null) {
            stream_set_timeout($sock, 3);
        }

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x03, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                if (ord($response[7]) & 0x80) {
                    break; // Modbus-Exception (9-Byte-Antwort) - nicht auf mehr warten
                }
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        if ($this->batchSock === null) {
            fclose($sock);
        }

        if (strlen($response) < 9) {
            return null;
        }

        $fc = ord($response[7]);
        if ($fc & 0x80 || $fc !== 0x03) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    // Read Input Registers (FC 0x04) — Sungrow, Solis, Growatt, Solax trennen
    // Mess-/Input-Register (0x04) von Holding-/Steuerregistern (0x03).
    public function readInput($startReg, $count)
    {
        $sock = $this->batchSock ?: @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return null;
        }
        if ($this->batchSock === null) {
            stream_set_timeout($sock, 3);
        }

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x04, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                if (ord($response[7]) & 0x80) {
                    break; // Modbus-Exception (9-Byte-Antwort) - nicht auf mehr warten
                }
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        if ($this->batchSock === null) {
            fclose($sock);
        }

        if (strlen($response) < 9) {
            return null;
        }

        $fc = ord($response[7]);
        if ($fc & 0x80 || $fc !== 0x04) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    public function writeSingle($reg, $value)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x06, $reg, $value & 0xFFFF);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        return ($resp !== false && strlen($resp) >= 8 && ord($resp[7]) === 0x06);
    }

    public function writeMultiple($startReg, $values)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, 3);

        $count     = count($values);
        $byteCount = $count * 2;
        $dataPart  = '';
        foreach ($values as $v) {
            $dataPart .= pack('n', $v & 0xFFFF);
        }
        $tid  = mt_rand(1, 65535);
        $pdu  = pack('CnnC', 0x10, $startReg, $count, $byteCount) . $dataPart;
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        return ($resp !== false && strlen($resp) >= 8 && ord($resp[7]) === 0x10);
    }

    public function u16($regs, $offset)
    {
        return isset($regs[$offset]) ? ($regs[$offset] & 0xFFFF) : 0;
    }

    public function s16($regs, $offset)
    {
        $v = $this->u16($regs, $offset);
        return $v > 32767 ? $v - 65536 : $v;
    }

    public function u32($regs, $offset)
    {
        return (($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1));
    }

    public function s32($regs, $offset)
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    public function readStr($regs, $offset, int $regCount)
    {
        $s = '';
        for ($i = 0; $i < $regCount; $i++) {
            $r  = $this->u16($regs, $offset + $i);
            $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
        }
        return rtrim($s, "\x00 ");
    }

    // IEEE-754 Float32 über 2 Register. Standard Big-Endian (SunSpec, ABCD);
    // bei $floatWordSwap = true wird die Wortreihenfolge getauscht (CDAB), wie
    // sie z. B. Kostal im „Standard Modbus (little-endian)"-Modus liefert.
    public function readFloat32($regs, $offset)
    {
        $hi  = $this->u16($regs, $offset);
        $lo  = $this->u16($regs, $offset + 1);
        if ($this->floatWordSwap) {
            $tmp = $hi; $hi = $lo; $lo = $tmp;
        }
        $raw = pack('nn', $hi, $lo);
        $val = unpack('G', $raw);
        return (float)($val[1] ?? 0.0);
    }
}

// ---------------------------------------------------------------------------
// IHUB_InverterDriverInterface — Vertrag, den jeder Hersteller-Treiber erfüllt
// ---------------------------------------------------------------------------

interface IHUB_InverterDriverInterface
{
    /**
     * Immer aktive Basisvariablen.
     * [ident, caption, type(F/I/B/S), profile, archive, group, reg]
     */
    public function getBaseVars();

    /**
     * Optionale Variablengruppen, je Property-Name (Checkbox in der Instanz).
     * ['GroupXYZ' => ['caption' => '...', 'vars' => [...]]]
     */
    public function getOptionalGroups();

    /** Zusätzliche boolesche Properties, die dieser Treiber braucht (Default-Werte). */
    public function getExtraBooleanProperties();

    /** Custom-Profile, die dieser Treiber anlegt: [name => [type, suffix, min, max, step, digits]] */
    public function getProfiles();

    /** Enum-Profile (Assoziationen): [name => [wert => [label, farbe]]] */
    public function getEnumProfiles();

    /** Liest die schnellen (Leistungs-)Werte. Rückgabe: Verbindung erfolgreich? */
    public function readFast($mb, $hub);

    /** Liest die langsamen Werte (Zählerstände, Fehler). */
    public function readSlow($mb, $hub);

    /** Liest einmalig Geräteinformationen (Seriennummer, Modell, Firmware). */
    public function readDeviceInfo($mb, $hub);

    /** Verarbeitet einen Schreibzugriff (RequestAction) auf ein Steuer-Ident. */
    public function writeControl($mb, $hub, string $ident, $value);
}

// ---------------------------------------------------------------------------
// IHUB_GoodweDriver — Treiber für GoodWe GW-ET/EH/BT/BH-Hybridwechselrichter
// Portiert 1:1 aus GoodweET/module.php (inkl. dokumentierter Firmware-Quirks)
// ---------------------------------------------------------------------------

class IHUB_GoodweDriver implements IHUB_InverterDriverInterface
{
    const REG_WORK_MODE         = 47000;
    const REG_EMS_ENABLE        = 47505;
    const REG_FEED_POWER_ENABLE = 47509;
    const REG_FEED_POWER_LIMIT  = 47510;
    const REG_EMS_POWER_MODE    = 47511;
    const REG_EMS_POWER_SET     = 47512;
    const REG_SOC_MIN           = 45356;
    const REG_INTERNET_MODE     = 47017;
    const REG_RESTART           = 45220;

    const EMS_POWER_MAX = 34500;

    const WORK_MODES = [
        0 => 'Selbstverbrauch', 1 => 'Inselbetrieb', 2 => 'Backup',
        3 => 'Wirtschaftlich',  4 => 'Peak-Shaving',  5 => 'Erw. Selbstverbrauch',
    ];

    const EMS_MODES = [
        0 => 'Gestoppt', 1 => 'Automatik', 2 => 'Laden - Solar', 3 => 'Entladen + Solar',
        4 => 'AC - Import', 5 => 'AC - Export', 6 => 'Energiesparen', 7 => 'Inselbetrieb',
        8 => 'Batterie - Bereitschaft', 9 => 'Stromeinkauf', 10 => 'Stromverkauf',
        11 => 'Batterie - Laden', 12 => 'Batterie - Entladen',
    ];

    const BAT_MODES = [0 => 'No Battery', 1 => 'Standby', 2 => 'entlädt', 3 => 'lädt'];

    const GRID_MODES = [
        0 => 'Warten', 1 => 'Einspeisung', 2 => 'Einspeisung: Limit', 3 => 'Einspeisung: Entsätt.',
        4 => 'Einspeisung: PV-Limit', 5 => 'Einspeisung: Reaktiv', 6 => 'Einspeisung: Blindl.',
        7 => 'Einspeisung: Absch.', 8 => 'Einspeisung: PV-Opt.', 9 => 'Einspeisung: ECO',
        10 => 'Fehler: HW-Schutz', 11 => 'Fehler', 17 => 'Bypass', 18 => 'Inselbetrieb',
    ];

    public function getBaseVars(){
        return [
            ['soc',           'SOC',                'I', '~Battery.100',      true,  'batcommon', 'BMS Ø'],
            ['work_mode',     'Betriebsmodus',       'I', 'GWH.WorkMode',      true,  'device',    'RW 47000'],
            ['grid_mode',     'Netzmodus',           'I', 'GWH.GridMode',      false, 'grid',      'DSP 35136'],
            ['island',        'Netzgetrennt (Insel)', 'B', '~Alert',           true,  'grid',      'calc'],
            ['pv_total',      'PV Gesamtleistung',   'F', 'GWH.Watt',          true,  'pv',        'DSP 35301'],
            ['ac_power',      'AC Wirkleistung',     'F', 'GWH.Watt',          true,  'device',    'DSP 35139'],
            ['bat_total_pwr', 'Bat. Gesamtleistg.',  'F', 'GWH.Watt',          true,  'batcommon', 'Σ Bat1+Bat2'],
            ['meter_total',   'Netz Leistung',       'F', 'GWH.Watt',          true,  'grid',      'SM 36025'],
            ['bat_charge_max_w',    'Bat. max. Ladeleistung',   'F', 'GWH.Watt', false, 'batcommon', 'BMS calc'],
            ['bat_discharge_max_w', 'Bat. max. Entladeleistung','F', 'GWH.Watt', false, 'batcommon', 'BMS calc'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed',   false, 'errors',    ''],
            ['riso',          'Isolationswiderstand','F', 'GWH.KOhm',          true,  'device',    'DSP 35365'],
        ];
    }

    public function getOptionalGroups(){
        return [
            'EnableTracker1' => ['caption' => 'MPPT-Tracker 1 (PV1+PV2)', 'vars' => [
                ['pv1_voltage', 'PV1 Spannung (Tracker1 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35103'],
                ['pv1_current', 'PV1 Strom (Tracker1 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35104'],
                ['pv2_voltage', 'PV2 Spannung (Tracker1 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35107'],
                ['pv2_current', 'PV2 Strom (Tracker1 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35108'],
                ['mppt1_power',   'MPPT1 Leistung', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35337'],
                ['mppt1_current', 'MPPT1 Strom',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35345'],
            ]],
            'EnableTracker2' => ['caption' => 'MPPT-Tracker 2 (PV3+PV4)', 'vars' => [
                ['pv3_voltage', 'PV3 Spannung (Tracker2 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35111'],
                ['pv3_current', 'PV3 Strom (Tracker2 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35112'],
                ['pv4_voltage', 'PV4 Spannung (Tracker2 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35115'],
                ['pv4_current', 'PV4 Strom (Tracker2 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35116'],
                ['mppt2_power',   'MPPT2 Leistung', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35338'],
                ['mppt2_current', 'MPPT2 Strom',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35346'],
            ]],
            'EnableTracker3' => ['caption' => 'MPPT-Tracker 3 (PV5+PV6)', 'vars' => [
                ['pv5_voltage', 'PV5 Spannung (Tracker3 A)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35304'],
                ['pv5_current', 'PV5 Strom (Tracker3 A)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35305'],
                ['pv6_voltage', 'PV6 Spannung (Tracker3 B)', 'F', 'GWH.Volt',   false, 'pv', 'DSP 35306'],
                ['pv6_current', 'PV6 Strom (Tracker3 B)',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35307'],
                ['mppt3_power',   'MPPT3 Leistung', 'F', 'GWH.Watt',   true,  'pv', 'DSP 35339'],
                ['mppt3_current', 'MPPT3 Strom',    'F', 'GWH.Ampere', false, 'pv', 'DSP 35347'],
            ]],
            'GroupGrid' => ['caption' => 'Netz L1/L2/L3 (Spannung, Strom, Frequenz, Leistung)', 'vars' => [
                ['grid_l1_volt', 'Netz L1 Spannung', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35121'],
                ['grid_l1_curr', 'Netz L1 Strom',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35122'],
                ['grid_l1_freq', 'Netz L1 Frequenz', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35123'],
                ['grid_l1_pwr',  'Netz L1 Leistung', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35124'],
                ['grid_l2_volt', 'Netz L2 Spannung', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35126'],
                ['grid_l2_curr', 'Netz L2 Strom',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35127'],
                ['grid_l2_freq', 'Netz L2 Frequenz', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35128'],
                ['grid_l2_pwr',  'Netz L2 Leistung', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35129'],
                ['grid_l3_volt', 'Netz L3 Spannung', 'F', 'GWH.Volt',   false, 'grid', 'DSP 35131'],
                ['grid_l3_curr', 'Netz L3 Strom',    'F', 'GWH.Ampere', false, 'grid', 'DSP 35132'],
                ['grid_l3_freq', 'Netz L3 Frequenz', 'F', 'GWH.Hertz',  false, 'grid', 'DSP 35133'],
                ['grid_l3_pwr',  'Netz L3 Leistung', 'F', 'GWH.Watt',   true,  'grid', 'DSP 35134'],
                ['inv_total',    'Inverter Gesamt',   'F', 'GWH.Watt',   true,  'grid', 'DSP 35137'],
                ['grid_freq',    'Netzfrequenz',      'F', 'GWH.Hertz',  false, 'grid', 'SM 36014'],
            ]],
            'GroupBat1' => ['caption' => 'Batterie 1 (Spannung, Strom, Leistung, Modus, SOC)', 'vars' => [
                ['bat1_volt', 'Bat.1 Spannung', 'F', 'GWH.Volt',   false, 'bat1', 'DSP 35180'],
                ['bat1_curr', 'Bat.1 Strom',    'F', 'GWH.Ampere', true,  'bat1', 'DSP 35181'],
                ['bat1_pwr',  'Bat.1 Leistung', 'F', 'GWH.Watt',   true,  'bat1', 'DSP 35182'],
                ['bat1_mode', 'Bat.1 Modus',    'I', 'GWH.BatMode', true,  'bat1', 'DSP 35184'],
                ['bat1_soc',  'Bat.1 SOC',      'I', '~Battery.100', true, 'bat1', 'BMS 47908'],
                ['bat1_soh',  'Bat.1 SOH',      'I', '~Intensity.100', true, 'bat1', 'BMS 47909'],
                ['bat1_temp', 'Bat.1 Temperatur', 'F', '~Temperature', true, 'bat1', 'BMS 47910'],
                ['bat1_bms_volt', 'Bat.1 Spannung (BMS)', 'F', 'GWH.Volt',   false, 'bat1', 'BMS 47906'],
                ['bat1_bms_curr', 'Bat.1 Strom (BMS)',    'F', 'GWH.Ampere', true,  'bat1', 'BMS 47907'],
                ['bat1_chg_max_a','Bat.1 max. Ladestrom',   'F', 'GWH.Ampere', false, 'bat1', 'BMS 47903'],
                ['bat1_dis_max_a','Bat.1 max. Entladestrom', 'F', 'GWH.Ampere', false, 'bat1', 'BMS 47905'],
                ['bat1_bms_warn', 'Bat.1 BMS Warnung',       'I', '', true,  'bat1', 'BMS 47911'],
                ['bat1_bms_alarm','Bat.1 BMS Alarm',         'I', '', true,  'bat1', 'BMS 47913'],
                // BMS-Zelldiagnostik (37003er-Block). Zellspannungsspreizung
                // (vmax−vmin) ist ein Frühindikator für Batteriealterung.
                ['bat1_pack_temp','Bat.1 Paket-Temperatur',  'F', '~Temperature',  false, 'bat1', 'BMS 37003 (÷10)'],
                ['bat1_cell_vmax','Bat.1 Zellspg. max',      'I', 'GWH.MilliVolt', false, 'bat1', 'BMS 37022'],
                ['bat1_cell_vmin','Bat.1 Zellspg. min',      'I', 'GWH.MilliVolt', false, 'bat1', 'BMS 37023'],
                ['bat1_cell_tmax','Bat.1 Zelltemp. max',     'F', '~Temperature',  false, 'bat1', 'BMS 37020 (÷10)'],
                ['bat1_cell_tmin','Bat.1 Zelltemp. min',     'F', '~Temperature',  false, 'bat1', 'BMS 37021 (÷10)'],
                ['bat1_idx_vmax', 'Bat.1 Zelle Nr. Spg. max','I', 'GWH.CellIdx',   false, 'bat1', 'BMS 37018'],
                ['bat1_idx_vmin', 'Bat.1 Zelle Nr. Spg. min','I', 'GWH.CellIdx',   false, 'bat1', 'BMS 37019'],
                ['bat1_idx_tmax', 'Bat.1 Zelle Nr. Temp. max','I', 'GWH.CellIdx',  false, 'bat1', 'BMS 37016'],
                ['bat1_idx_tmin', 'Bat.1 Zelle Nr. Temp. min','I', 'GWH.CellIdx',  false, 'bat1', 'BMS 37017'],
            ]],
            'GroupBat2' => ['caption' => 'Batterie 2 (Spannung, Strom, Leistung, Modus)', 'vars' => [
                ['bat2_volt', 'Bat.2 Spannung', 'F', 'GWH.Volt',   false, 'bat2', 'DSP 35262'],
                ['bat2_curr', 'Bat.2 Strom',    'F', 'GWH.Ampere', true,  'bat2', 'DSP 35263'],
                ['bat2_pwr',  'Bat.2 Leistung', 'F', 'GWH.Watt',   true,  'bat2', 'DSP 35264'],
                ['bat2_mode', 'Bat.2 Modus',    'I', 'GWH.BatMode', true,  'bat2', 'DSP 35266'],
                ['bat2_soc',  'Bat.2 SOC',      'I', '~Battery.100', true, 'bat2', 'BMS 47926'],
                ['bat2_soh',  'Bat.2 SOH',      'I', '~Intensity.100', true, 'bat2', 'BMS 47927'],
                ['bat2_temp', 'Bat.2 Temperatur', 'F', '~Temperature', true, 'bat2', 'BMS 47928'],
                ['bat2_bms_volt', 'Bat.2 Spannung (BMS)', 'F', 'GWH.Volt',   false, 'bat2', 'BMS 47924'],
                ['bat2_bms_curr', 'Bat.2 Strom (BMS)',    'F', 'GWH.Ampere', true,  'bat2', 'BMS 47925'],
                // BMS-Zelldiagnostik Batterie 2 (39001er-Block), analog Bat.1.
                ['bat2_pack_temp','Bat.2 Paket-Temperatur',  'F', '~Temperature',  false, 'bat2', 'BMS 39001 (÷10)'],
                ['bat2_cell_vmax','Bat.2 Zellspg. max',      'I', 'GWH.MilliVolt', false, 'bat2', 'BMS 39020'],
                ['bat2_cell_vmin','Bat.2 Zellspg. min',      'I', 'GWH.MilliVolt', false, 'bat2', 'BMS 39021'],
                ['bat2_cell_tmax','Bat.2 Zelltemp. max',     'F', '~Temperature',  false, 'bat2', 'BMS 39018 (÷10)'],
                ['bat2_cell_tmin','Bat.2 Zelltemp. min',     'F', '~Temperature',  false, 'bat2', 'BMS 39019 (÷10)'],
                ['bat2_idx_vmax', 'Bat.2 Zelle Nr. Spg. max','I', 'GWH.CellIdx',   false, 'bat2', 'BMS 39016'],
                ['bat2_idx_vmin', 'Bat.2 Zelle Nr. Spg. min','I', 'GWH.CellIdx',   false, 'bat2', 'BMS 39017'],
                ['bat2_idx_tmax', 'Bat.2 Zelle Nr. Temp. max','I', 'GWH.CellIdx',  false, 'bat2', 'BMS 39014'],
                ['bat2_idx_tmin', 'Bat.2 Zelle Nr. Temp. min','I', 'GWH.CellIdx',  false, 'bat2', 'BMS 39015'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (kWh Heute/Gesamt: PV, Netz, Last, Batterie)', 'vars' => [
                ['e_pv_day',       'PV Heute',           'F', '~Electricity', true, 'energy', 'DSP 35193'],
                ['e_pv_total',     'PV Gesamt',           'F', '~Electricity', true, 'energy', 'DSP 35191'],
                ['e_sell_day',     'Einspeisung Heute',   'F', '~Electricity', true, 'energy', 'DSP 35199'],
                ['e_buy_day',      'Bezug Heute',         'F', '~Electricity', true, 'energy', 'DSP 35202'],
                ['e_load_day',     'Last Heute',          'F', '~Electricity', true, 'energy', 'DSP 35205'],
                ['e_load_total',   'Last Gesamt',         'F', '~Electricity', true, 'energy', 'DSP 35203'],
                ['e_charge_day',   'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'DSP 35208'],
                ['e_charge_total', 'Bat. Laden Gesamt',   'F', '~Electricity', true, 'energy', 'DSP 35206'],
                ['e_disch_day',    'Bat. Entl. Heute',    'F', '~Electricity', true, 'energy', 'DSP 35211'],
                ['e_disch_total',  'Bat. Entl. Gesamt',   'F', '~Electricity', true, 'energy', 'DSP 35209'],
                ['work_hours',     'Betriebsstunden',     'F', '', false, 'energy', 'DSP 35197'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung, Spannung, Strom je Phase)', 'vars' => [
                ['mt_l1_volt', 'Netz L1 Spannung', 'F', 'GWH.Volt',   false, 'meter', 'SM 36052'],
                ['mt_l2_volt', 'Netz L2 Spannung', 'F', 'GWH.Volt',   false, 'meter', 'SM 36053'],
                ['mt_l3_volt', 'Netz L3 Spannung', 'F', 'GWH.Volt',   false, 'meter', 'SM 36054'],
                ['mt_l1_curr', 'Netz L1 Strom',    'F', 'GWH.Ampere', false, 'meter', 'SM 36055'],
                ['mt_l2_curr', 'Netz L2 Strom',    'F', 'GWH.Ampere', false, 'meter', 'SM 36056'],
                ['mt_l3_curr', 'Netz L3 Strom',    'F', 'GWH.Ampere', false, 'meter', 'SM 36057'],
                ['mt_l1_pwr',  'Netz L1 Leistung', 'F', 'GWH.Watt',   true,  'meter', 'SM 36019'],
                ['mt_l2_pwr',  'Netz L2 Leistung', 'F', 'GWH.Watt',   true,  'meter', 'SM 36021'],
                ['mt_l3_pwr',  'Netz L3 Leistung', 'F', 'GWH.Watt',   true,  'meter', 'SM 36023'],
            ]],
            'GroupTemp' => ['caption' => 'Temperaturen (Luft, Modul, Kühlkörper)', 'vars' => [
                ['temp_air',      'Lufttemperatur',  'F', '~Temperature', false, 'device', 'DSP 35174'],
                ['temp_module',   'Modultemperatur', 'F', '~Temperature', true,  'device', 'DSP 35175'],
                ['temp_heatsink', 'Kuehlkoerper',    'F', '~Temperature', true,  'device', 'DSP 35176'],
            ]],
            'GroupBackup' => ['caption' => 'Backup / Inselbetrieb (Leistung, Spannung je Phase)', 'vars' => [
                ['backup_total', 'Backup Leistung',  'F', 'GWH.Watt', true,  'backup', 'DSP 35169'],
                ['backup_active','Backup aktiv',     'B', '~Switch',   false, 'backup', 'RW 45252'],
                ['backup_l1_volt','Backup Spannung L1', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35145'],
                ['backup_l1_curr','Backup Strom L1',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35146'],
                ['backup_l1_freq','Backup Frequenz L1', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35147'],
                ['backup_l1_pwr', 'Backup Leistung L1',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35149'],
                ['backup_l2_volt','Backup Spannung L2', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35151'],
                ['backup_l2_curr','Backup Strom L2',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35152'],
                ['backup_l2_freq','Backup Frequenz L2', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35153'],
                ['backup_l2_pwr', 'Backup Leistung L2',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35155'],
                ['backup_l3_volt','Backup Spannung L3', 'F', 'GWH.Volt',   false, 'backup', 'DSP 35157'],
                ['backup_l3_curr','Backup Strom L3',    'F', 'GWH.Ampere', false, 'backup', 'DSP 35158'],
                ['backup_l3_freq','Backup Frequenz L3', 'F', 'GWH.Hertz',  false, 'backup', 'DSP 35159'],
                ['backup_l3_pwr', 'Backup Leistung L3',  'F', 'GWH.Watt',  true,  'backup', 'DSP 35161'],
            ]],
            'GroupErrors' => ['caption' => 'Fehler- und Warncodes', 'vars' => [
                ['warn_code',  'Warncode',      'I', '', true, 'errors', 'DSP 32000'],
                ['err_msg',    'Fehlercode',    'I', '', true, 'errors', 'DSP 32002'],
                ['err_detail', 'Fehler Detail', 'S', '', true, 'errors', ''],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation (Seriennummer, Modell, Firmware)', 'vars' => [
                ['dev_sn',      'Seriennummer', 'S', '', false, 'device', 'DSP 35003'],
                ['dev_model',   'Modell',       'S', '', false, 'device', 'DSP 35011'],
                ['dev_rated_w', 'Nennleistung', 'I', '', false, 'device', 'DSP 35001'],
                ['dev_fw_arm',  'Firmware ARM', 'I', '', false, 'device', 'DSP 35019'],
                ['dev_fw_dsp',  'Firmware DSP', 'I', '', false, 'device', 'DSP 35016'],
            ]],
            'GroupControl' => ['caption' => 'EMS-Steuerung (Betriebsmodus, Einspeisegrenze, SOC-Limits)', 'vars' => [
                ['ctl_work_mode',     'Steuermodus',          'I', 'GWH.WorkMode', false, 'control', 'RW 47000'],
                ['ctl_ems_enable',    'EMS-Steuerung aktiv',  'B', '~Switch',      false, 'control', 'RW 47505'],
                ['ctl_ems_mode',      'EMS Leistungsmodus',   'I', 'GWH.EMSMode',  false, 'control', 'RW 47511'],
                ['ctl_ems_power',     'EMS Leistung (W)',     'I', 'GWH.WattEMS',  false, 'control', 'RW 47512'],
                // ACHTUNG, Semantik von 47509 ist umgekehrt zur frueheren
                // Beschriftung: Das Register heisst bei GoodWe „Feed_Power_Enable"
                // und schaltet nicht die EINSPEISUNG, sondern deren BEGRENZUNG.
                // „Aus" = keine Begrenzung = unbegrenzt einspeisen.
                // „Ein" = Begrenzung aktiv, und zwar auf den Wert aus 47510.
                // Die alte Beschriftung „Einspeisung Ja/Nein" verleitete dazu,
                // den Schalter einzuschalten, um Einspeisung zu ERLAUBEN - bei
                // einer hinterlegten Grenze von 0 W ist das Ergebnis
                // Nulleinspeisung, also das Gegenteil der Absicht.
                // Belegt an einer realen Anlage: Schalter „Aus", Grenze 0 W,
                // und trotzdem 42,7 kWh Einspeisung am selben Tag.
                // Idents bleiben unveraendert (Idents sind API).
                ['ctl_export_enable', 'Einspeisebegrenzung aktiv', 'B', '~Switch',      false, 'control', 'RW 47509 (Feed_Power_Enable: EIN = Begrenzung aus 47510 gilt)'],
                ['ctl_export_limit',  'Einspeisegrenze (W)',       'I', 'GWH.WattEMS',  false, 'control', 'RW 47510 (wirkt nur bei aktiver Begrenzung)'],
                ['ctl_soc_min',       'SOC Min. Entladung',   'I', 'GWH.Percent',  false, 'control', 'RW 45356'],
                ['ctl_internet',      'Cloud-Verbindung',     'B', '~Switch',      false, 'control', 'RW 47017'],
                ['ctl_restart',       'WR Neustart',          'B', '~Switch',      false, 'control', 'WO 45220'],
            ]],
        ];
    }

    public function getExtraBooleanProperties(){
        return [
            'EnableTracker1' => true,
            'EnableTracker2' => true,
            'EnableTracker3' => true,
        ];
    }

    public function getProfiles(){
        return [
            'GWH.Watt'      => [VARIABLETYPE_FLOAT,   ' W',  -40000.0, 40000.0, 1.0,  0],
            'GWH.Volt'      => [VARIABLETYPE_FLOAT,   ' V',       0.0,  1000.0, 0.1,  1],
            'GWH.Ampere'    => [VARIABLETYPE_FLOAT,   ' A',    -200.0,   200.0, 0.1,  1],
            'GWH.Hertz'     => [VARIABLETYPE_FLOAT,   ' Hz',     45.0,    65.0, 0.01, 2],
            'GWH.Percent'   => [VARIABLETYPE_INTEGER, ' %',          0,     100, 1,    0],
            'GWH.WattEMS'   => [VARIABLETYPE_INTEGER, ' W',          0,   34500, 1,    0],
            'GWH.KOhm'      => [VARIABLETYPE_FLOAT,   ' kΩ',       0.0,  5000.0, 0.1,  1],
            'GWH.MilliVolt' => [VARIABLETYPE_INTEGER, ' mV',         0,    5000, 1,    0],
            'GWH.CellIdx'   => [VARIABLETYPE_INTEGER, '',            0,     512, 1,    0],
        ];
    }

    public function getEnumProfiles(){
        $wmColors = [0xF5A623, 0x7A8A99, 0x2BB3C0, 0x27D07F, 0xE74C3C, 0xF39C12];
        $workMode = [];
        foreach (self::WORK_MODES as $k => $label) {
            $workMode[$k] = [$label, $wmColors[$k] ?? 0x7A8A99];
        }
        $emsMode = [];
        foreach (self::EMS_MODES as $k => $label) {
            $emsMode[$k] = [$label, 0x7A8A99];
        }
        $batMode = [];
        foreach (self::BAT_MODES as $k => $label) {
            $batMode[$k] = [$label, 0x7A8A99];
        }
        $gridMode = [];
        foreach (self::GRID_MODES as $k => $label) {
            $gridMode[$k] = [$label, 0x7A8A99];
        }
        return [
            'GWH.WorkMode' => $workMode,
            'GWH.EMSMode'  => $emsMode,
            'GWH.BatMode'  => $batMode,
            'GWH.GridMode' => $gridMode,
        ];
    }

    public function readFast($mb, $hub){
        $inv     = $mb->readHolding(35103, 42);
        $bat1blk = $mb->readHolding(35174, 18);
        $bat2blk = $mb->readHolding(35262, 7);
        $pvext   = $mb->readHolding(35301, 41);
        $meter   = $mb->readHolding(36019, 39);
        // WBMS Bat1+Bat2 in einem Block ab 47900 (count 34). Ab 47900 lesen,
        // sonst liefern 47908/47909 (SOC/SOH) 0xFFFF!
        $bms     = $mb->readHolding(47900, 34);

        $ok = ($inv !== null && $bat1blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }
        // Ein fremdes Geraet (z. B. ein SMA-Wechselrichter, bei dem versehentlich
        // GoodWe eingestellt wurde) antwortet auf diese Adressen mit lauter
        // 0xFFFF. Ohne diese Pruefung entstuenden daraus 65535 %, 6553,5 V und
        // Seriennummern aus lauter „ÿ".
        if ($hub->BlockLooksUnset($inv)) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        $pvTotal = ($pvext !== null) ? (float)$mb->u32($pvext, 0) : 0.0;
        $hub->SetVarFloat('pv_total', $pvTotal);

        $risoBlk = $mb->readHolding(35365, 1);
        if ($risoBlk !== null) {
            $hub->SetVarFloat('riso', $mb->u16($risoBlk, 0) / 10.0);
        }

        $wm = $mb->readHolding(47000, 1);
        if ($wm !== null) {
            $hub->SetVarInt('work_mode', $mb->u16($wm, 0));
        }

        $bat2Active = $hub->GetPropBool('GroupBat2') && ($bat2blk !== null);
        $soc1 = ($bms !== null) ? (float)$mb->u16($bms, 8)  : 0.0;
        $soc2 = ($bms !== null) ? (float)$mb->u16($bms, 26) : 0.0;
        $hub->SetVarInt('soc', (int)round($bat2Active ? (($soc1 + $soc2) / 2.0) : $soc1));

        if ($hub->GetPropBool('EnableTracker1')) {
            $hub->SetVarFloat('pv1_voltage', $mb->u16($inv, 0) / 10.0);
            $hub->SetVarFloat('pv1_current', $mb->u16($inv, 1) / 10.0);
            $hub->SetVarFloat('pv2_voltage', $mb->u16($inv, 4) / 10.0);
            $hub->SetVarFloat('pv2_current', $mb->u16($inv, 5) / 10.0);
        }
        if ($hub->GetPropBool('EnableTracker2')) {
            $hub->SetVarFloat('pv3_voltage', $mb->u16($inv, 8)  / 10.0);
            $hub->SetVarFloat('pv3_current', $mb->u16($inv, 9)  / 10.0);
            $hub->SetVarFloat('pv4_voltage', $mb->u16($inv, 12) / 10.0);
            $hub->SetVarFloat('pv4_current', $mb->u16($inv, 13) / 10.0);
        }
        if ($hub->GetPropBool('EnableTracker3') && $pvext !== null) {
            $hub->SetVarFloat('pv5_voltage', $mb->u16($pvext, 3) / 10.0);
            $hub->SetVarFloat('pv5_current', $mb->u16($pvext, 4) / 10.0);
            $hub->SetVarFloat('pv6_voltage', $mb->u16($pvext, 6) / 10.0);
            $hub->SetVarFloat('pv6_current', $mb->u16($pvext, 7) / 10.0);
        }

        // Firmware-Quirk (live verifiziert): Requests ab Register 35333+
        // liefern für 35338/35339 nur 0xFFFF. Ab 35332 oder früher stabil.
        $mpptBlk = $mb->readHolding(35332, 16);
        if ($mpptBlk !== null) {
            if ($hub->GetPropBool('EnableTracker1')) {
                $hub->SetVarFloat('mppt1_power',   (float)$mb->u16($mpptBlk, 5));
                $hub->SetVarFloat('mppt1_current', (float)$mb->u16($mpptBlk, 13));
            }
            if ($hub->GetPropBool('EnableTracker2')) {
                $hub->SetVarFloat('mppt2_power',   (float)$mb->u16($mpptBlk, 6));
                $hub->SetVarFloat('mppt2_current', (float)$mb->u16($mpptBlk, 14));
            }
            if ($hub->GetPropBool('EnableTracker3')) {
                $hub->SetVarFloat('mppt3_power',   (float)$mb->u16($mpptBlk, 7));
                $hub->SetVarFloat('mppt3_current', (float)$mb->u16($mpptBlk, 15));
            }
        }

        $gridMode = $mb->u16($inv, 33);
        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_l1_volt', $mb->u16($inv, 18) / 10.0);
            $hub->SetVarFloat('grid_l1_curr', $mb->u16($inv, 19) / 10.0);
            $hub->SetVarFloat('grid_l1_freq', $mb->u16($inv, 20) / 100.0);
            $hub->SetVarFloat('grid_l1_pwr',  (float)$mb->s32($inv, 21));
            $hub->SetVarFloat('grid_l2_volt', $mb->u16($inv, 23) / 10.0);
            $hub->SetVarFloat('grid_l2_curr', $mb->u16($inv, 24) / 10.0);
            $hub->SetVarFloat('grid_l2_freq', $mb->u16($inv, 25) / 100.0);
            $hub->SetVarFloat('grid_l2_pwr',  (float)$mb->s32($inv, 26));
            $hub->SetVarFloat('grid_l3_volt', $mb->u16($inv, 28) / 10.0);
            $hub->SetVarFloat('grid_l3_curr', $mb->u16($inv, 29) / 10.0);
            $hub->SetVarFloat('grid_l3_freq', $mb->u16($inv, 30) / 100.0);
            $hub->SetVarFloat('grid_l3_pwr',  (float)$mb->s32($inv, 31));
            $hub->SetVarFloat('inv_total',    (float)$mb->s32($inv, 34));
        }
        $hub->SetVarInt('grid_mode',  $gridMode);
        $hub->SetVarFloat('ac_power', (float)$mb->s32($inv, 36));
        $hub->SetVarBool('island', ($gridMode === 17 || $gridMode === 18));

        if ($meter !== null) {
            $hub->SetVarFloat('meter_total', (float)$mb->s32($meter, 6));
        }

        if ($hub->GetPropBool('GroupBat1')) {
            $hub->SetVarFloat('bat1_volt', $mb->u16($bat1blk, 6)  / 10.0);
            $hub->SetVarFloat('bat1_curr', $mb->s16($bat1blk, 7)  / 10.0);
            $hub->SetVarFloat('bat1_pwr',  (float)$mb->s32($bat1blk, 8));
            $hub->SetVarInt('bat1_mode',   $mb->u16($bat1blk, 10));
            $hub->SetVarInt('bat1_soc',  (int)round($soc1));
            if ($bms !== null) {
                $hub->SetVarInt('bat1_soh',  $mb->u16($bms, 9));
                $hub->SetVarFloat('bat1_temp', $mb->s16($bms, 10) / 10.0);
                $hub->SetVarFloat('bat1_bms_volt', $mb->u16($bms, 6) / 10.0);
                $hub->SetVarFloat('bat1_bms_curr', $mb->s16($bms, 7) / 10.0);
                $hub->SetVarFloat('bat1_chg_max_a', $mb->u16($bms, 3) / 10.0);
                $hub->SetVarFloat('bat1_dis_max_a', $mb->u16($bms, 5) / 10.0);
                $hub->SetVarInt('bat1_bms_warn',  $mb->u32($bms, 11));
                $hub->SetVarInt('bat1_bms_alarm', $mb->u32($bms, 13));
            }
            // BMS-Zelldiagnostik: eigener Block 37003 (count 21). Getrennt vom
            // BMS-Block gelesen; Offsets an der Anlage verifiziert (aus dem
            // GoodweET-Vorgängermodul übernommen).
            $cells1 = $mb->readHolding(37003, 21);
            if ($cells1 !== null) {
                $hub->SetVarFloat('bat1_pack_temp', $mb->s16($cells1, 0) / 10.0);  // 37003
                $hub->SetVarInt('bat1_idx_tmax',    $mb->u16($cells1, 13));         // 37016
                $hub->SetVarInt('bat1_idx_tmin',    $mb->u16($cells1, 14));         // 37017
                $hub->SetVarInt('bat1_idx_vmax',    $mb->u16($cells1, 15));         // 37018
                $hub->SetVarInt('bat1_idx_vmin',    $mb->u16($cells1, 16));         // 37019
                $hub->SetVarFloat('bat1_cell_tmax', $mb->s16($cells1, 17) / 10.0);  // 37020
                $hub->SetVarFloat('bat1_cell_tmin', $mb->s16($cells1, 18) / 10.0);  // 37021
                $hub->SetVarInt('bat1_cell_vmax',   $mb->u16($cells1, 19));         // 37022
                $hub->SetVarInt('bat1_cell_vmin',   $mb->u16($cells1, 20));         // 37023
            }
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp_air',      $mb->s16($bat1blk, 0) / 10.0);
            $hub->SetVarFloat('temp_module',   $mb->s16($bat1blk, 1) / 10.0);
            $hub->SetVarFloat('temp_heatsink', $mb->s16($bat1blk, 2) / 10.0);
        }

        if ($bat2Active) {
            $hub->SetVarFloat('bat2_volt', $mb->u16($bat2blk, 0)  / 10.0);
            $hub->SetVarFloat('bat2_curr', $mb->s16($bat2blk, 1)  / 10.0);
            $hub->SetVarFloat('bat2_pwr',  (float)$mb->s32($bat2blk, 2));
            $hub->SetVarInt('bat2_mode',   $mb->u16($bat2blk, 4));
            $hub->SetVarInt('bat2_soc',  (int)round($soc2));
            if ($bms !== null) {
                $hub->SetVarInt('bat2_soh',  $mb->u16($bms, 27));
                $hub->SetVarFloat('bat2_temp', $mb->s16($bms, 28) / 10.0);
                $hub->SetVarFloat('bat2_bms_volt', $mb->u16($bms, 24) / 10.0);
                $hub->SetVarFloat('bat2_bms_curr', $mb->s16($bms, 25) / 10.0);
            }
            // BMS-Zelldiagnostik Batterie 2: Block 39001 (count 21), analog Bat.1.
            $cells2 = $mb->readHolding(39001, 21);
            if ($cells2 !== null) {
                $hub->SetVarFloat('bat2_pack_temp', $mb->s16($cells2, 0) / 10.0);  // 39001
                $hub->SetVarInt('bat2_idx_tmax',    $mb->u16($cells2, 13));         // 39014
                $hub->SetVarInt('bat2_idx_tmin',    $mb->u16($cells2, 14));         // 39015
                $hub->SetVarInt('bat2_idx_vmax',    $mb->u16($cells2, 15));         // 39016
                $hub->SetVarInt('bat2_idx_vmin',    $mb->u16($cells2, 16));         // 39017
                $hub->SetVarFloat('bat2_cell_tmax', $mb->s16($cells2, 17) / 10.0);  // 39018
                $hub->SetVarFloat('bat2_cell_tmin', $mb->s16($cells2, 18) / 10.0);  // 39019
                $hub->SetVarInt('bat2_cell_vmax',   $mb->u16($cells2, 19));         // 39020
                $hub->SetVarInt('bat2_cell_vmin',   $mb->u16($cells2, 20));         // 39021
            }
        }

        $b1p = $mb->s32($bat1blk, 8);
        $b2p = ($bat2blk !== null) ? $mb->s32($bat2blk, 2) : 0;
        $hub->SetVarFloat('bat_total_pwr', (float)($b1p + $b2p));

        if ($bms !== null) {
            $v1      = $mb->u16($bms, 6) / 10.0;
            $chgMaxW = ($mb->u16($bms, 3) / 10.0) * $v1;
            $disMaxW = ($mb->u16($bms, 5) / 10.0) * $v1;
            if ($bat2Active) {
                $v2 = $mb->u16($bms, 24) / 10.0;
                $chgMaxW += ($mb->u16($bms, 21) / 10.0) * $v2;
                $disMaxW += ($mb->u16($bms, 23) / 10.0) * $v2;
            }
            $hub->SetVarFloat('bat_charge_max_w',    $chgMaxW);
            $hub->SetVarFloat('bat_discharge_max_w', $disMaxW);
        }

        if ($hub->GetPropBool('GroupMeter') && $meter !== null) {
            $hub->SetVarFloat('mt_l1_pwr',  (float)$mb->s32($meter, 0));
            $hub->SetVarFloat('mt_l2_pwr',  (float)$mb->s32($meter, 2));
            $hub->SetVarFloat('mt_l3_pwr',  (float)$mb->s32($meter, 4));
            $hub->SetVarFloat('mt_l1_volt', $mb->u16($meter, 33) / 10.0);
            $hub->SetVarFloat('mt_l2_volt', $mb->u16($meter, 34) / 10.0);
            $hub->SetVarFloat('mt_l3_volt', $mb->u16($meter, 35) / 10.0);
            $hub->SetVarFloat('mt_l1_curr', $mb->u16($meter, 36) / 10.0);
            $hub->SetVarFloat('mt_l2_curr', $mb->u16($meter, 37) / 10.0);
            $hub->SetVarFloat('mt_l3_curr', $mb->u16($meter, 38) / 10.0);
            $freqBlk = $mb->readHolding(36014, 1);
            if ($freqBlk !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freqBlk, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBackup')) {
            $bkPhase = $mb->readHolding(35145, 26);
            if ($bkPhase !== null) {
                $hub->SetVarFloat('backup_l1_volt', $mb->u16($bkPhase, 0)  / 10.0);
                $hub->SetVarFloat('backup_l1_curr', $mb->u16($bkPhase, 1)  / 10.0);
                $hub->SetVarFloat('backup_l1_freq', $mb->u16($bkPhase, 2)  / 100.0);
                $hub->SetVarFloat('backup_l1_pwr',  (float)$mb->s32($bkPhase, 4));
                $hub->SetVarFloat('backup_l2_volt', $mb->u16($bkPhase, 6)  / 10.0);
                $hub->SetVarFloat('backup_l2_curr', $mb->u16($bkPhase, 7)  / 10.0);
                $hub->SetVarFloat('backup_l2_freq', $mb->u16($bkPhase, 8)  / 100.0);
                $hub->SetVarFloat('backup_l2_pwr',  (float)$mb->s32($bkPhase, 10));
                $hub->SetVarFloat('backup_l3_volt', $mb->u16($bkPhase, 12) / 10.0);
                $hub->SetVarFloat('backup_l3_curr', $mb->u16($bkPhase, 13) / 10.0);
                $hub->SetVarFloat('backup_l3_freq', $mb->u16($bkPhase, 14) / 100.0);
                $hub->SetVarFloat('backup_l3_pwr',  (float)$mb->s32($bkPhase, 16));
                $hub->SetVarFloat('backup_total',   (float)$mb->s32($bkPhase, 24));
            }
            $bkSt = $mb->readHolding(45252, 1);
            if ($bkSt !== null) {
                $hub->SetVarBool('backup_active', $mb->u16($bkSt, 0) > 0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub){
        if ($hub->GetPropBool('GroupEnergy')) {
            $e = $mb->readHolding(35191, 22);
            if ($e !== null) {
                $hub->SetVarFloat('e_pv_total',     $mb->u32($e, 0)  / 10.0);
                $hub->SetVarFloat('e_pv_day',       $mb->u32($e, 2)  / 10.0);
                $hub->SetVarFloat('work_hours',     (float)$mb->u32($e, 6));
                $hub->SetVarFloat('e_sell_day',     $mb->u16($e, 8)  / 10.0);
                $hub->SetVarFloat('e_buy_day',      $mb->u16($e, 11) / 10.0);
                $hub->SetVarFloat('e_load_total',   $mb->u32($e, 12) / 10.0);
                $hub->SetVarFloat('e_load_day',     $mb->u16($e, 14) / 10.0);
                $hub->SetVarFloat('e_charge_total', $mb->u32($e, 15) / 10.0);
                $hub->SetVarFloat('e_charge_day',   $mb->u16($e, 17) / 10.0);
                $hub->SetVarFloat('e_disch_total',  $mb->u32($e, 18) / 10.0);
                $hub->SetVarFloat('e_disch_day',    $mb->u16($e, 20) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupErrors')) {
            $err = $mb->readHolding(32000, 17);
            if ($err !== null) {
                $hub->SetVarInt('warn_code', $mb->u16($err, 0));
                $hub->SetVarInt('err_msg',   $mb->u16($err, 2));
                $utility = $mb->u16($err, 0);
                $detail  = [];
                if ($utility & 0x01) { $detail[] = 'Netz-Ueberspannung'; }
                if ($utility & 0x02) { $detail[] = 'Netz-Unterspannung'; }
                if ($utility & 0x04) { $detail[] = 'Netz-Ueberfrequenz'; }
                if ($utility & 0x08) { $detail[] = 'Netz-Unterfrequenz'; }
                if ($utility & 0x10) { $detail[] = 'Netz-Ueberstrom'; }
                $sys = $mb->u16($err, 2);
                if ($sys & 0x01) { $detail[] = 'Systemfehler 1'; }
                $hub->SetVarStr('err_detail', empty($detail) ? 'OK' : implode(', ', $detail));
            }
        }
    }

    public function readDeviceInfo($mb, $hub){
        $dev = $mb->readHolding(35001, 27);
        if ($dev === null) {
            return;
        }
        $hub->SetVarInt('dev_rated_w', $mb->u16($dev, 0));
        $hub->SetVarStr('dev_sn',      $mb->readStr($dev, 2, 8));
        $hub->SetVarStr('dev_model',   $mb->readStr($dev, 10, 5));
        $hub->SetVarInt('dev_fw_dsp',  $mb->u16($dev, 15));
        $hub->SetVarInt('dev_fw_arm',  $mb->u16($dev, 18));
    }

    public function writeControl($mb, $hub, string $ident, $value){
        switch ($ident) {
            case 'ctl_work_mode':
                $val = (int)$value;
                if ($val < 0 || $val > 5) { return; }
                if ($mb->writeSingle(self::REG_WORK_MODE, $val)) {
                    $hub->SetVarInt('ctl_work_mode', $val);
                    $hub->SetVarInt('work_mode', $val);
                }
                break;

            case 'ctl_ems_enable':
                $val = (bool)$value ? 2 : 0;
                if ($mb->writeSingle(self::REG_EMS_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_ems_enable', (bool)$value);
                }
                break;

            case 'ctl_ems_mode':
                $val = (int)$value;
                if ($val < 0 || $val > 12) { return; }
                if ($mb->writeSingle(self::REG_EMS_POWER_MODE, $val)) {
                    $hub->SetVarInt('ctl_ems_mode', $val);
                }
                break;

            case 'ctl_ems_power':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($mb->writeSingle(self::REG_EMS_POWER_SET, $val)) {
                    $hub->SetVarInt('ctl_ems_power', $val);
                }
                break;

            case 'ctl_export_enable':
                $val = (bool)$value ? 1 : 0;
                // Einschalten heisst: Die Begrenzung aus 47510 gilt ab jetzt.
                // Steht dort 0 W, ist das Ergebnis NULLEINSPEISUNG. Das kann
                // gewollt sein (Direktvermarktung, Netzbetreiberauflage), ist
                // aber meist ein Missverstaendnis - deshalb ein deutlicher
                // Hinweis ins Meldungslog, statt es stillschweigend zu tun.
                if ($val === 1) {
                    $lim = $mb->readHolding(self::REG_FEED_POWER_LIMIT, 1);
                    if ($lim !== null && $mb->u16($lim, 0) === 0) {
                        $hub->WarnUser(
                            'Einspeisebegrenzung wurde eingeschaltet, die hinterlegte Grenze '
                            . 'beträgt aber 0 W. Der Wechselrichter speist damit NICHTS mehr ins '
                            . 'Netz ein. Falls das nicht beabsichtigt ist: Grenze auf den '
                            . 'gewünschten Wert setzen oder die Begrenzung wieder ausschalten.'
                        );
                    }
                }
                if ($mb->writeSingle(self::REG_FEED_POWER_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_export_enable', (bool)$value);
                }
                break;

            case 'ctl_export_limit':
                $val = max(0, min(self::EMS_POWER_MAX, (int)$value));
                if ($mb->writeSingle(self::REG_FEED_POWER_LIMIT, $val)) {
                    $hub->SetVarInt('ctl_export_limit', $val);
                }
                break;

            case 'ctl_soc_min':
                $val = max(0, min(100, (int)$value));
                if ($mb->writeSingle(self::REG_SOC_MIN, $val)) {
                    $hub->SetVarInt('ctl_soc_min', $val);
                }
                break;

            case 'ctl_internet':
                $val = (bool)$value ? 0 : 1;
                if ($mb->writeSingle(self::REG_INTERNET_MODE, $val)) {
                    $hub->SetVarBool('ctl_internet', (bool)$value);
                }
                break;

            case 'ctl_restart':
                if ((bool)$value) {
                    $mb->writeSingle(self::REG_RESTART, 1);
                    IPS_Sleep(500);
                    $hub->SetVarBool('ctl_restart', false);
                }
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// IHUB_SungrowDriver — Sungrow SH-Hybrid-Serie (SH5.0RT...SH10RT, SH5.0RS...SH10RS)
// Eigene, weit verstreute Einzelregister. Mess-/Statuswerte: Read Input
// Register (FC 0x04). Steuerung: Holding Register (FC 0x03/0x06).
// Registeradressen werden direkt verwendet (kein Offset -1 dokumentiert).
// ---------------------------------------------------------------------------

class IHUB_SungrowDriver implements IHUB_InverterDriverInterface
{
    const REG_START_STOP = 13000; // Holding: 0xCF=Start, 0xCE=Stop

    // System-State (Doku 13000). Nicht gelistete Werte zeigt IP-Symcon roh an
    // (manche Firmware/Modelle liefern zusätzliche, undokumentierte Codes).
    const RUN_STATES = [
        0x2    => 'Stop',
        0x8    => 'Standby',
        0x10   => 'Initiales Standby',
        0x20   => 'Startet',
        0x40   => 'Läuft',
        0x100  => 'Störung',
        0x400  => 'Wartungsmodus',
        0x800  => 'Erzwungener Modus',
        0x1000 => 'Inselbetrieb',
        0x2501 => 'Neustart',
        0x4000 => 'Externer EMS-Modus',
    ];

    // Leistungsfluss-Text aus den running-state-Bits (Doku 13001).
    private function flowText(int $bits): string
    {
        $parts = [];
        if ($bits & 0x01) { $parts[] = 'PV-Erzeugung'; }
        if ($bits & 0x02) { $parts[] = 'Batterie lädt'; }
        if ($bits & 0x04) { $parts[] = 'Batterie entlädt'; }
        if ($bits & 0x08) { $parts[] = 'Last aktiv'; }
        if ($bits & 0x10) { $parts[] = 'Einspeisung'; }
        if ($bits & 0x20) { $parts[] = 'Netzbezug'; }
        return $parts ? implode(' · ', $parts) : 'Bereit';
    }

    public function getBaseVars()
    {
        return [
            ['connected',   'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['running_state','Betriebsstatus',   'I', 'SGW.RunState',     true,  'device', 'RO 13000'],
            ['power_flow_status', 'Leistungsfluss-Status', 'I', '',       true,  'device', 'RO 13001'],
            ['status_text',  'Leistungsfluss',   'S', '',                 true,  'device', 'dekodiert aus 13001'],
            ['has_fault',    'Störung',          'B', '~Alert',           true,  'errors', 'aus 13000 (System-State 0x100)'],
            ['pv_total',    'PV Gesamtleistung', 'F', 'SGW.Watt',         true,  'pv',     'RO 5017-5018'],
            ['ac_power',    'AC Wirkleistung',   'F', 'SGW.Watt',         true,  'device', 'RO 13034-13035'],
            ['meter_total', 'Netz Leistung',     'F', 'SGW.Watt',         true,  'grid',   'RO 13010-13011 (+ Einspeisung / − Bezug)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (MPPT-Spannung/Strom/Leistung; String-Modelle SG-CX bis MPPT 12)', 'vars' => [
                ['mppt1_volt',  'MPPT1 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5011'],
                ['mppt1_curr',  'MPPT1 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5012'],
                ['mppt1_power', 'MPPT1 Leistung', 'F', 'SGW.Watt',   true,  'pv', 'berechnet U×I'],
                ['mppt2_volt',  'MPPT2 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5013'],
                ['mppt2_curr',  'MPPT2 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5014'],
                ['mppt2_power', 'MPPT2 Leistung', 'F', 'SGW.Watt',   true,  'pv', 'berechnet U×I'],
                ['mppt3_volt',  'MPPT3 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5015'],
                ['mppt3_curr',  'MPPT3 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5016'],
                ['mppt3_power', 'MPPT3 Leistung', 'F', 'SGW.Watt',   true,  'pv', 'berechnet U×I'],
                ['mppt4_volt', 'MPPT4 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5115'],
                ['mppt4_curr', 'MPPT4 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5116'],
                ['mppt5_volt', 'MPPT5 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5117'],
                ['mppt5_curr', 'MPPT5 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5118'],
                ['mppt6_volt', 'MPPT6 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5119'],
                ['mppt6_curr', 'MPPT6 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5120'],
                ['mppt7_volt', 'MPPT7 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5121'],
                ['mppt7_curr', 'MPPT7 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5122'],
                ['mppt8_volt', 'MPPT8 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5123'],
                ['mppt8_curr', 'MPPT8 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5124'],
                ['mppt9_volt', 'MPPT9 Spannung', 'F', 'SGW.Volt',   false, 'pv', 'RO 5130'],
                ['mppt9_curr', 'MPPT9 Strom',    'F', 'SGW.Ampere', false, 'pv', 'RO 5131'],
                ['mppt10_volt','MPPT10 Spannung','F', 'SGW.Volt',   false, 'pv', 'RO 5132'],
                ['mppt10_curr','MPPT10 Strom',   'F', 'SGW.Ampere', false, 'pv', 'RO 5133'],
                ['mppt11_volt','MPPT11 Spannung','F', 'SGW.Volt',   false, 'pv', 'RO 5134'],
                ['mppt11_curr','MPPT11 Strom',   'F', 'SGW.Ampere', false, 'pv', 'RO 5135'],
                ['mppt12_volt','MPPT12 Spannung','F', 'SGW.Volt',   false, 'pv', 'RO 5136'],
                ['mppt12_curr','MPPT12 Strom',   'F', 'SGW.Ampere', false, 'pv', 'RO 5137'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Blindleistung, Power Factor, Frequenz)', 'vars' => [
                ['grid_v1',      'Netz Spannung 1', 'F', 'SGW.Volt',   false, 'grid', 'RO 5019'],
                ['grid_v2',      'Netz Spannung 2', 'F', 'SGW.Volt',   false, 'grid', 'RO 5020'],
                ['grid_v3',      'Netz Spannung 3', 'F', 'SGW.Volt',   false, 'grid', 'RO 5021'],
                ['grid_c1',      'AC-Strom L1 (WR)', 'F', 'SGW.Ampere', false, 'grid', 'RO 13031 (WR-Ausgang, 0 ohne Erzeugung)'],
                ['grid_c2',      'AC-Strom L2 (WR)', 'F', 'SGW.Ampere', false, 'grid', 'RO 13032 (WR-Ausgang, 0 ohne Erzeugung)'],
                ['grid_c3',      'AC-Strom L3 (WR)', 'F', 'SGW.Ampere', false, 'grid', 'RO 13033 (WR-Ausgang, 0 ohne Erzeugung)'],
                ['grid_reactive','Blindleistung',    'F', 'SGW.WattReactive', false, 'grid', 'RO 5033-5034'],
                ['power_factor', 'Power Factor',     'F', 'SGW.PowerFactor',  false, 'grid', 'RO 5035'],
                ['grid_freq',    'Netzfrequenz',     'F', 'SGW.Hertz',        false, 'grid', 'RO 5036'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Leistung, Spannung, Strom, SOC, SOH, Temperatur, Lade-/Entladeenergie)', 'vars' => [
                ['bat_power','Bat. Leistung',    'F', 'SGW.Watt',     true,  'bat', 'RO 13022 (Vorzeichen aus 13001)'],
                ['bat_volt', 'Bat. Spannung',    'F', 'SGW.Volt',     false, 'bat', 'RO 13020'],
                ['bat_curr', 'Bat. Strom',       'F', 'SGW.Ampere',   false, 'bat', 'RO 13021'],
                ['bat_soc',  'Bat. SOC',         'I', '~Battery.100', true,  'bat', 'RO 13023'],
                ['bat_soh',  'Bat. SOH',         'I', '~Intensity.100', true, 'bat', 'RO 13024'],
                ['bat_temp', 'Bat. Temperatur',  'F', '~Temperature', true,  'bat', 'RO 13025'],
                ['e_bat_charge_day',    'Bat. Ladung heute',    'F', '~Electricity', true, 'bat', 'RO 13012'],
                ['e_bat_discharge_day', 'Bat. Entladung heute', 'F', '~Electricity', true, 'bat', 'RO 13026'],
            ]],
            'GroupRiso' => ['caption' => 'Isolationswiderstand (nicht auf allen Modellen/WiNet-S verfügbar)', 'vars' => [
                ['riso', 'Isolationswiderstand', 'F', 'SGW.KOhm', true, 'pv', 'RO 5071 (kΩ)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (PV, Netzbezug, Einspeisung, Last, Eigenverbrauch)', 'vars' => [
                ['e_pv_day',          'PV Ertrag heute',      'F', '~Electricity', true, 'energy', 'RO 13002'],
                ['e_pv_total',        'PV Ertrag gesamt',     'F', '~Electricity', true, 'energy', 'RO 13003-13004'],
                ['e_export_pv_day',   'Einspeisung heute',    'F', '~Electricity', true, 'energy', 'RO 13005'],
                ['e_export_pv_total', 'Einspeisung gesamt',   'F', '~Electricity', true, 'energy', 'RO 13006-13007'],
                ['e_import_day',      'Netzbezug heute',      'F', '~Electricity', true, 'energy', 'RO 13036'],
                ['e_import_total',    'Netzbezug gesamt',     'F', '~Electricity', true, 'energy', 'RO 13037-13038'],
                ['load_power',        'Lastleistung',         'F', 'SGW.Watt',     true, 'energy', 'RO 13008-13009'],
                ['self_cons_today',   'Eigenverbrauch heute', 'I', '~Intensity.100', true, 'energy', 'RO 13029'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation (Typ, Nennleistung, Seriennummer, Innentemperatur)', 'vars' => [
                ['dev_type',    'Gerätetyp-Code', 'I', '', false, 'device', 'RO 5000'],
                ['dev_rated_w', 'Nennleistung',    'I', '', false, 'device', 'RO 5001'],
                ['dev_sn',      'Seriennummer',    'S', '', false, 'device', 'RO 4990-4999'],
                ['inv_temp',    'Innentemperatur', 'F', '~Temperature', true, 'device', 'RO 5008'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Start/Stop)', 'vars' => [
                ['ctl_run', 'Wechselrichter Ein/Aus', 'B', '~Switch', false, 'control', 'RW 13000'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SGW.Watt'          => [VARIABLETYPE_FLOAT,   ' W',   -40000.0, 40000.0, 1.0,  0],
            'SGW.Volt'          => [VARIABLETYPE_FLOAT,   ' V',        0.0,  1000.0, 0.1,  1],
            'SGW.Ampere'        => [VARIABLETYPE_FLOAT,   ' A',     -200.0,   200.0, 0.1,  1],
            'SGW.Hertz'         => [VARIABLETYPE_FLOAT,   ' Hz',      45.0,    65.0, 0.01, 2],
            'SGW.WattReactive'  => [VARIABLETYPE_FLOAT,   ' var', -40000.0, 40000.0, 1.0,  0],
            'SGW.PowerFactor'   => [VARIABLETYPE_FLOAT,   '',        -1.0,     1.0, 0.001, 3],
            'SGW.KOhm'          => [VARIABLETYPE_FLOAT,   ' kΩ',       0.0, 65535.0, 1.0,  0],
        ];
    }

    public function getEnumProfiles()
    {
        $runState = [];
        foreach (self::RUN_STATES as $k => $label) {
            $runState[$k] = [$label, 0x7A8A99];
        }
        return ['SGW.RunState' => $runState];
    }

    // Erkennt die Adressierungs-Konvention des Geräts. Sungrow-Firmware/-Dongles
    // sind uneinheitlich: die meisten liefern jedes Register unter der
    // PDU-Adresse = Doku-Nummer − 1 (Standard-Modbus), einige unter PDU =
    // Doku-Nummer direkt. Ein falscher Offset verschiebt ALLE Werte um genau ein
    // Register — die berüchtigten Sungrow-„off-by-one"-Fehlwerte. Wir bestimmen
    // ihn zur Laufzeit anhand der Phasenspannungen (Doku 5019/5020/5021), die bei
    // netzgekoppelten Geräten immer anliegen (auch nachts).
    //   Rückgabe:  -1 = PDU ist Doku-Nummer − 1 (Standard),  0 = PDU ist Doku-Nummer.
    private function detectOffset($mb): int
    {
        $b = $mb->readInput(5018, 4); // deckt PDU 5018..5021 (beide Hypothesen)
        if ($b === null) {
            return -1; // Standard-Konvention als sichere Vorgabe
        }
        $plaus = function ($v) { return ($v >= 1800 && $v <= 2800) ? 1 : 0; }; // 180–280 V
        // Offset -1: Phase A/B/C (Doku 5019/5020/5021) liegen auf PDU 5018/5019/5020 = b[0..2]
        $scoreM1 = $plaus($mb->u16($b, 0)) + $plaus($mb->u16($b, 1)) + $plaus($mb->u16($b, 2));
        // Offset  0: dieselben liegen auf PDU 5019/5020/5021 = b[1..3]
        $score0  = $plaus($mb->u16($b, 1)) + $plaus($mb->u16($b, 2)) + $plaus($mb->u16($b, 3));
        return ($score0 > $scoreM1) ? 0 : -1; // Gleichstand -> Standard (-1)
    }

    // 32-Bit-Wert aus zwei Registern, niederwertiges Wort ZUERST — Sungrows
    // Konvention für ALLE 32-Bit-Größen (5000er- wie 13000er-Block). Der
    // eingebaute $mb->u32() ist Big-Endian und liefert hier vertauschte Werte.
    private function u32le($regs, int $i): int
    {
        return ($regs[$i] ?? 0) | (($regs[$i + 1] ?? 0) << 16);
    }

    private function s32le($regs, int $i): int
    {
        $v = $this->u32le($regs, $i);
        return ($v >= 0x80000000) ? $v - 0x100000000 : $v;
    }

    public function readFast($mb, $hub)
    {
        // Alle Reads eines Zyklus über EINE Verbindung (Batch): der Sungrow
        // WiNet-S erlaubt nur eine Modbus-Verbindung und lehnt schnelle
        // Reconnects ab - sonst fielen spätere Reads (z. B. der MPPT-Block) aus.
        $mb->beginBatch();
        try {
            return $this->readFastInner($mb, $hub);
        } finally {
            $mb->endBatch();
        }
    }

    private function readFastInner($mb, $hub)
    {
        $off = $this->detectOffset($mb);

        // String-Wechselrichter (SG-CX/„P2") haben den 13000er-Hybrid-Block NICHT
        // (Modbus-Exception) und legen ihre Daten ausschließlich im 5000er-Block
        // ab. Dafür ein eigener, an einem SG125CX-P2 verifizierter Lesepfad.
        $probe = $mb->readInput(13000 + $off, 2); // Doku 13000 system_state, 13001 running_state
        if ($probe === null) {
            return $this->readFastString($mb, $hub);
        }

        // --- 5000er-Block (Echtzeit-Messwerte), Register live an SH10RT verifiziert ---
        $temp  = $mb->readInput(5008 + $off, 1);   // Doku 5008 Innentemperatur (0,1 °C)
        $mppt  = $mb->readInput(5011 + $off, 8);   // Doku 5011..5016 MPPT1-3 U/I, 5017/18 DC-Gesamtleistung (U32 LE)
        $volts = $mb->readInput(5019 + $off, 3);   // Doku 5019..5021 Phasenspannungen
        $rpf   = $mb->readInput(5033 + $off, 4);   // Doku 5033 Blindleistung (S32), 5035 PF, 5036 Frequenz

        // --- 13000er-Block (SH-Hybrid-spezifisch) ---
        $active = $mb->readInput(13034 + $off, 2); // Doku 13034 Gesamt-Wirkleistung (S32 LE)
        $grid   = $mb->readInput(13010 + $off, 2); // Doku 13010 Netzleistung export_power (S32 LE; + Einspeisung / − Bezug)
        $pcur   = $mb->readInput(13031 + $off, 3); // Doku 13031..13033 Phasenströme
        $batBlk = $mb->readInput(13020 + $off, 6); // Doku 13020..13025 Batterie

        $ok = ($volts !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        // Doku 13000 Betriebszustand, 13001 Leistungsfluss-/Running-Bits.
        $sysState = $mb->u16($probe, 0);
        $runBits  = $mb->u16($probe, 1);
        $hub->SetVarInt('running_state', $sysState);
        $hub->SetVarInt('power_flow_status', $runBits);
        $hub->SetVarStr('status_text', $this->flowText($runBits));
        $hub->SetVarBool('has_fault', ($sysState & 0x100) !== 0); // System-State-Bit „Störung"

        if ($mppt !== null) {
            $hub->SetVarFloat('pv_total', (float)$this->u32le($mppt, 6)); // Doku 5017/5018
        }
        if ($temp !== null) {
            $hub->SetVarFloat('inv_temp', $mb->s16($temp, 0) / 10.0);
        }
        if ($active !== null) {
            $hub->SetVarFloat('ac_power', (float)$this->s32le($active, 0));
        }
        if ($grid !== null) {
            $hub->SetVarFloat('meter_total', (float)$this->s32le($grid, 0));
        }

        if ($hub->GetPropBool('GroupPV') && $mppt !== null) {
            $v1 = $mb->u16($mppt, 0) / 10.0; $c1 = $mb->u16($mppt, 1) / 10.0;
            $v2 = $mb->u16($mppt, 2) / 10.0; $c2 = $mb->u16($mppt, 3) / 10.0;
            $v3 = $mb->u16($mppt, 4) / 10.0; $c3 = $mb->u16($mppt, 5) / 10.0;
            $hub->SetVarFloat('mppt1_volt', $v1);
            $hub->SetVarFloat('mppt1_curr', $c1);
            $hub->SetVarFloat('mppt1_power', round($v1 * $c1)); // Leistung je String = U×I
            $hub->SetVarFloat('mppt2_volt', $v2);
            $hub->SetVarFloat('mppt2_curr', $c2);
            $hub->SetVarFloat('mppt2_power', round($v2 * $c2));
            $hub->SetVarFloat('mppt3_volt', $v3);
            $hub->SetVarFloat('mppt3_curr', $c3);
            $hub->SetVarFloat('mppt3_power', round($v3 * $c3));
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_v1', $mb->u16($volts, 0) / 10.0);
            $hub->SetVarFloat('grid_v2', $mb->u16($volts, 1) / 10.0);
            $hub->SetVarFloat('grid_v3', $mb->u16($volts, 2) / 10.0);
            if ($pcur !== null) {
                $hub->SetVarFloat('grid_c1', $mb->u16($pcur, 0) / 10.0);
                $hub->SetVarFloat('grid_c2', $mb->u16($pcur, 1) / 10.0);
                $hub->SetVarFloat('grid_c3', $mb->u16($pcur, 2) / 10.0);
            }
            if ($rpf !== null) {
                $hub->SetVarFloat('grid_reactive', (float)$this->s32le($rpf, 0)); // Doku 5033/5034
                $hub->SetVarFloat('power_factor',  $mb->s16($rpf, 2) / 1000.0);   // Doku 5035
                $hub->SetVarFloat('grid_freq',     $mb->u16($rpf, 3) / 10.0);     // Doku 5036 (0,1 Hz)
            }
        }

        if ($hub->GetPropBool('GroupBat') && $batBlk !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($batBlk, 0) / 10.0);
            $hub->SetVarFloat('bat_curr', $mb->u16($batBlk, 1) / 10.0);
            // Batterieleistung: Betrag aus Doku 13022, Vorzeichen aus den
            // running-state-Bits (Bit 2 = Entladen → negativ).
            $sign = ($mb->u16($probe, 1) & 0x04) ? -1 : 1;
            $hub->SetVarFloat('bat_power', (float)($sign * $mb->u16($batBlk, 2)));
            $hub->SetVarInt('bat_soc', (int)round($mb->u16($batBlk, 3) / 10.0));
            $hub->SetVarInt('bat_soh', (int)round($mb->u16($batBlk, 4) / 10.0));
            $hub->SetVarFloat('bat_temp', $mb->s16($batBlk, 5) / 10.0);
        }

        // Isolationswiderstand nur, wenn aktiviert — viele WiNet-S liefern das
        // Register gar nicht (Modbus-Exception), dann bliebe die Variable leer.
        if ($hub->GetPropBool('GroupRiso')) {
            $riso = $mb->readInput(5071 + $off, 1); // Doku 5071 (kΩ)
            if ($riso !== null) {
                $hub->SetVarFloat('riso', (float)$mb->u16($riso, 0));
            }
        }

        return true;
    }

    // String-Wechselrichter (SG-CX/„P2"): alle Werte aus dem 5000er-Block.
    // Protokoll-Adresse = Sungrow-Doku − 1; 32-Bit „sw" (niederwertiges Wort
    // zuerst). Adressen live an einem SG125CX-P2 verifiziert.
    private function readFastString($mb, $hub)
    {
        $b = $mb->readInput(5000, 40); // 5000..5039
        $hub->SetVarBool('connected', $b !== null);
        if ($b === null) {
            return false;
        }
        // 32-Bit, niederwertiges Wort zuerst.
        $u32 = function ($i) use ($b) { return ($b[$i] ?? 0) + (($b[$i + 1] ?? 0) << 16); };
        $s32 = function ($i) use ($b) { $v = ($b[$i] ?? 0) + (($b[$i + 1] ?? 0) << 16); return ($v >= 0x80000000) ? $v - 0x100000000 : $v; };
        $s16 = function ($i) use ($b) { $v = $b[$i] ?? 0; return ($v >= 0x8000) ? $v - 0x10000 : $v; };

        $hub->SetVarFloat('pv_total', (float)$u32(16));   // 5016-5017 DC-Gesamtleistung (W)
        $hub->SetVarFloat('ac_power', (float)$u32(30));   // 5030-5031 Wirkleistung (W)

        // Isolationsimpedanz gegen Masse (kΩ) - String-Modelle bei 5070.
        if ($hub->GetPropBool('GroupRiso')) {
            $riso = $mb->readInput(5070, 1);
            if ($riso !== null) {
                $hub->SetVarFloat('riso', (float)$mb->u16($riso, 0));
            }
        }

        if ($hub->GetPropBool('GroupPV')) {
            // MPPT 1-3 im 5000er-Block (5010-5015).
            $hub->SetVarFloat('mppt1_volt', ($b[10] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt1_curr', ($b[11] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt2_volt', ($b[12] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt2_curr', ($b[13] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt3_volt', ($b[14] ?? 0) / 10.0);
            $hub->SetVarFloat('mppt3_curr', ($b[15] ?? 0) / 10.0);
            // MPPT 4-12 im erweiterten Block. AB 5100 lesen (dokumentierter
            // Blockanfang) - ein Read direkt ab 5114 wird vom WR abgelehnt.
            // V-Register: MPPT4 5114 … relative Offsets zu 5100 (Lücke 5124-5128).
            $ext = $mb->readInput(5100, 50); // 5100..5149
            if ($ext !== null) {
                $map = [4 => 14, 5 => 16, 6 => 18, 7 => 20, 8 => 22, 9 => 29, 10 => 31, 11 => 33, 12 => 35];
                foreach ($map as $n => $vi) {
                    $hub->SetVarFloat("mppt{$n}_volt", ($ext[$vi] ?? 0) / 10.0);
                    $hub->SetVarFloat("mppt{$n}_curr", ($ext[$vi + 1] ?? 0) / 10.0);
                }
            }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_v1', ($b[18] ?? 0) / 10.0);  // 5018-5020 Phasen U
            $hub->SetVarFloat('grid_v2', ($b[19] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_v3', ($b[20] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_c1', ($b[21] ?? 0) / 10.0);  // 5021-5023 Phasen I
            $hub->SetVarFloat('grid_c2', ($b[22] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_c3', ($b[23] ?? 0) / 10.0);
            $hub->SetVarFloat('grid_reactive', (float)$s32(32));  // 5032-5033 Blindleistung
            $hub->SetVarFloat('power_factor',  $s16(34) / 1000.0);// 5034 Power Factor
            $hub->SetVarFloat('grid_freq',     ($b[35] ?? 0) / 10.0); // 5035 Frequenz (×0,1)
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            $hub->SetVarFloat('e_pv_day',   ($b[2] ?? 0) / 10.0); // 5002 Tagesertrag (×0,1 kWh)
            $hub->SetVarFloat('e_pv_total', (float)$u32(3));       // 5003-5004 Gesamtertrag (kWh)
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        $mb->beginBatch();
        try {
            $off = $this->detectOffset($mb);
            if ($mb->readInput(13000 + $off, 1) === null) {
                return; // String-WR: Energie kommt aus readFastString (5000er-Block)
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $e = $mb->readInput(13002 + $off, 8); // Doku 13002..13009
                if ($e !== null) {
                    $hub->SetVarFloat('e_pv_day',          $mb->u16($e, 0) / 10.0);     // 13002 (0,1 kWh)
                    $hub->SetVarFloat('e_pv_total',        $this->u32le($e, 1) / 10.0); // 13003/04 (U32 LE)
                    $hub->SetVarFloat('e_export_pv_day',   $mb->u16($e, 3) / 10.0);     // 13005
                    $hub->SetVarFloat('e_export_pv_total', $this->u32le($e, 4) / 10.0); // 13006/07 (U32 LE)
                    $hub->SetVarFloat('load_power',        (float)$this->s32le($e, 6)); // 13008 (S32 LE, Hauslast)
                }
                $imp = $mb->readInput(13036 + $off, 3); // Doku 13036 Netzbezug heute, 13037 gesamt (U32 LE)
                if ($imp !== null) {
                    $hub->SetVarFloat('e_import_day',   $mb->u16($imp, 0) / 10.0);
                    $hub->SetVarFloat('e_import_total', $this->u32le($imp, 1) / 10.0);
                }
                $sc = $mb->readInput(13029 + $off, 1); // Doku 13029 Eigenverbrauch heute (0,1 %)
                if ($sc !== null) {
                    $hub->SetVarInt('self_cons_today', (int)round($mb->u16($sc, 0) / 10.0));
                }
            }
            if ($hub->GetPropBool('GroupBat')) {
                $bc = $mb->readInput(13012 + $off, 1); // Doku 13012 Batterie-Ladung aus PV heute
                if ($bc !== null) {
                    $hub->SetVarFloat('e_bat_charge_day', $mb->u16($bc, 0) / 10.0);
                }
                $bd = $mb->readInput(13026 + $off, 1); // Doku 13026 Batterie-Entladung heute
                if ($bd !== null) {
                    $hub->SetVarFloat('e_bat_discharge_day', $mb->u16($bd, 0) / 10.0);
                }
            }
        } finally {
            $mb->endBatch();
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $mb->beginBatch();
        try {
            $this->readDeviceInfoInner($mb, $hub);
        } finally {
            $mb->endBatch();
        }
    }

    private function readDeviceInfoInner($mb, $hub)
    {
        // Adressierung über die Auto-Erkennung vereinheitlicht: Gerätetyp Doku
        // 5000, Nennleistung Doku 5001 (0,1 kW), Seriennummer Doku 4990..4999.
        $off = $this->detectOffset($mb);
        $dev = $mb->readInput(5000 + $off, 2); // Doku 5000 Typ-Code, 5001 Nennleistung
        if ($dev !== null) {
            $hub->SetVarInt('dev_type',    $mb->u16($dev, 0));
            $hub->SetVarInt('dev_rated_w', (int)round($mb->u16($dev, 1) * 100)); // 0,1 kW -> W
        }
        $sn = $mb->readInput(4990 + $off, 10); // Doku 4990..4999 Seriennummer (ASCII)
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 10));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        if ($ident === 'ctl_run') {
            $off = $this->detectOffset($mb);
            $val = (bool)$value ? 0xCF : 0xCE;
            if ($mb->writeSingle(IHUB_SungrowDriver::REG_START_STOP + $off, $val)) {
                $hub->SetVarBool('ctl_run', (bool)$value);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// IHUB_SolisDriver — Solis Hybrid-Wechselrichter (33000er-Registerblock).
// Registeradressen direkt verwendet. Mess-/Statuswerte: Read Input Register
// (FC 0x04). Reine String-Wechselrichter (3000er-Block) werden derzeit
// nicht unterstützt.
// ---------------------------------------------------------------------------

class IHUB_SolisDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected',   'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',    'PV Gesamtleistung', 'F', 'SLS.Watt', true,  'pv',       'RO 33057-33058'],
            ['ac_power',    'AC Wirkleistung',   'F', 'SLS.Watt', true,  'device',   'RO 33079-33080'],
            ['meter_total', 'Netz Leistung',      'F', 'SLS.Watt', true,  'grid',    'RO 33151-33152'],
            ['bat_power',   'Bat. Leistung',     'F', 'SLS.Watt', true,  'bat',      'RO 33149-33150'],
            ['bat_soc',     'Bat. SOC',          'I', '~Battery.100', true, 'bat',   'RO 33139'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (bis zu 4 Strings)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33049'],
                ['pv1_curr', 'PV1 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33050'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33051'],
                ['pv2_curr', 'PV2 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33052'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33053'],
                ['pv3_curr', 'PV3 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33054'],
                ['pv4_volt', 'PV4 Spannung', 'F', 'SLS.Volt',   false, 'pv', 'RO 33055'],
                ['pv4_curr', 'PV4 Strom',    'F', 'SLS.Ampere', false, 'pv', 'RO 33056'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Blind-/Scheinleistung je Phase)', 'vars' => [
                ['grid_l1_volt', 'Netz L1 Spannung', 'F', 'SLS.Volt',   false, 'grid', 'RO 33073'],
                ['grid_l2_volt', 'Netz L2 Spannung', 'F', 'SLS.Volt',   false, 'grid', 'RO 33074'],
                ['grid_l3_volt', 'Netz L3 Spannung', 'F', 'SLS.Volt',   false, 'grid', 'RO 33075'],
                ['grid_l1_curr', 'Netz L1 Strom',    'F', 'SLS.Ampere', false, 'grid', 'RO 33076'],
                ['grid_l2_curr', 'Netz L2 Strom',    'F', 'SLS.Ampere', false, 'grid', 'RO 33077'],
                ['grid_l3_curr', 'Netz L3 Strom',    'F', 'SLS.Ampere', false, 'grid', 'RO 33078'],
                ['grid_freq',    'Netzfrequenz',      'F', 'SLS.Hertz', false, 'grid', 'RO 33094'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOH)', 'vars' => [
                ['bat_volt', 'Bat. Spannung', 'F', 'SLS.Volt',   false, 'bat', 'RO 33133'],
                ['bat_curr', 'Bat. Strom',    'F', 'SLS.Ampere', false, 'bat', 'RO 33134'],
                ['bat_soh',  'Bat. SOH',      'I', '~Intensity.100', true, 'bat', 'RO 33140'],
                ['household_load', 'Hausverbrauch', 'F', 'SLS.Watt', true, 'bat', 'RO 33147'],
                ['backup_load',    'Backup-Last',   'F', 'SLS.Watt', true, 'bat', 'RO 33148'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Spannung, Strom je Phase)', 'vars' => [
                ['mt_l1_volt', 'Meter L1 Spannung', 'F', 'SLS.Volt',   false, 'meter', 'RO 33251'],
                ['mt_l2_volt', 'Meter L2 Spannung', 'F', 'SLS.Volt',   false, 'meter', 'RO 33253'],
                ['mt_l3_volt', 'Meter L3 Spannung', 'F', 'SLS.Volt',   false, 'meter', 'RO 33255'],
                ['mt_total_pwr', 'Meter Gesamtleistung', 'F', 'SLS.Watt', true, 'meter', 'RO 33263-33264'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_inv', 'Wechselrichter-Temperatur', 'F', '~Temperature', false, 'device', 'RO 33093'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (PV, Batterie, Netz, Verbrauch)', 'vars' => [
                ['e_pv_day',       'PV Heute',            'F', '~Electricity', true, 'energy', 'RO 33035'],
                ['e_pv_total',     'PV Gesamt',           'F', '~Electricity', true, 'energy', 'RO 33029-33030'],
                ['e_charge_day',   'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'RO 33163'],
                ['e_charge_total', 'Bat. Laden Gesamt',   'F', '~Electricity', true, 'energy', 'RO 33161-33162'],
                ['e_disch_day',    'Bat. Entl. Heute',    'F', '~Electricity', true, 'energy', 'RO 33167'],
                ['e_disch_total',  'Bat. Entl. Gesamt',   'F', '~Electricity', true, 'energy', 'RO 33165-33166'],
                ['e_buy_day',      'Bezug Heute',         'F', '~Electricity', true, 'energy', 'RO 33171'],
                ['e_buy_total',    'Bezug Gesamt',        'F', '~Electricity', true, 'energy', 'RO 33169-33170'],
                ['e_sell_day',     'Einspeisung Heute',   'F', '~Electricity', true, 'energy', 'RO 33175'],
                ['e_sell_total',   'Einspeisung Gesamt',  'F', '~Electricity', true, 'energy', 'RO 33173-33174'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell-Nr.', 'I', '', false, 'device', 'RO 33000'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'RO 33004-33019'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLS.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLS.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLS.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'SLS.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $pv     = $mb->readInput(33049, 12);   // 33049-33060 (PV1-4 V/I + Total DC-Bereich)
        $ac     = $mb->readInput(33079, 6);    // 33079-33084 Wirk/Blind/Scheinleistung
        $bat    = $mb->readInput(33132, 20);   // 33132-33151 Batterie + Household/Backup Load
        $meter  = $mb->readInput(33151, 3);    // 33151-33152 (+33153 Reserve) Grid Port Power

        $ok = ($pv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', (float)$mb->u32($pv, 8));  // 33057-33058
        if ($ac !== null) {
            $hub->SetVarFloat('ac_power', (float)$mb->s32($ac, 0));
        }
        if ($bat !== null) {
            $hub->SetVarInt('bat_soc', $mb->u16($bat, 7));                // 33139
            $hub->SetVarFloat('bat_power', (float)$mb->s32($bat, 17));    // 33149-33150
        }
        if ($meter !== null) {
            $hub->SetVarFloat('meter_total', (float)$mb->s32($meter, 0));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt', $mb->u16($pv, 0) / 10.0);
            $hub->SetVarFloat('pv1_curr', $mb->u16($pv, 1) / 10.0);
            $hub->SetVarFloat('pv2_volt', $mb->u16($pv, 2) / 10.0);
            $hub->SetVarFloat('pv2_curr', $mb->u16($pv, 3) / 10.0);
            $hub->SetVarFloat('pv3_volt', $mb->u16($pv, 4) / 10.0);
            $hub->SetVarFloat('pv3_curr', $mb->u16($pv, 5) / 10.0);
            $hub->SetVarFloat('pv4_volt', $mb->u16($pv, 6) / 10.0);
            $hub->SetVarFloat('pv4_curr', $mb->u16($pv, 7) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $grid = $mb->readInput(33073, 24); // 33073-33096
            if ($grid !== null) {
                $hub->SetVarFloat('grid_l1_volt', $mb->u16($grid, 0) / 10.0);
                $hub->SetVarFloat('grid_l2_volt', $mb->u16($grid, 1) / 10.0);
                $hub->SetVarFloat('grid_l3_volt', $mb->u16($grid, 2) / 10.0);
                $hub->SetVarFloat('grid_l1_curr', $mb->u16($grid, 3) / 10.0);
                $hub->SetVarFloat('grid_l2_curr', $mb->u16($grid, 4) / 10.0);
                $hub->SetVarFloat('grid_l3_curr', $mb->u16($grid, 5) / 10.0);
                $hub->SetVarFloat('grid_freq', $mb->u16($grid, 21) / 100.0);
                if ($hub->GetPropBool('GroupTemp')) {
                    $hub->SetVarFloat('temp_inv', $mb->s16($grid, 20) / 10.0);
                }
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 1) / 10.0);       // 33133
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 2) / 10.0);       // 33134
            $hub->SetVarInt('bat_soh',    $mb->u16($bat, 8));              // 33140
            $hub->SetVarFloat('household_load', (float)$mb->u16($bat, 15)); // 33147
            $hub->SetVarFloat('backup_load',    (float)$mb->u16($bat, 16)); // 33148
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $mtV = $mb->readInput(33251, 14); // 33251-33264
            if ($mtV !== null) {
                $hub->SetVarFloat('mt_l1_volt', $mb->u16($mtV, 0) / 10.0);
                $hub->SetVarFloat('mt_l2_volt', $mb->u16($mtV, 2) / 10.0);
                $hub->SetVarFloat('mt_l3_volt', $mb->u16($mtV, 4) / 10.0);
                $hub->SetVarFloat('mt_total_pwr', (float)$mb->s32($mtV, 12));
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if ($hub->GetPropBool('GroupEnergy')) {
            $e1 = $mb->readInput(33029, 12);  // 33029-33040
            if ($e1 !== null) {
                $hub->SetVarFloat('e_pv_total', $mb->u32($e1, 0) / 10.0);
                $hub->SetVarFloat('e_pv_day',   $mb->u16($e1, 6) / 10.0);
            }
            $e2 = $mb->readInput(33161, 20);  // 33161-33180
            if ($e2 !== null) {
                $hub->SetVarFloat('e_charge_total', $mb->u32($e2, 0)  / 10.0);
                $hub->SetVarFloat('e_charge_day',   $mb->u16($e2, 2)  / 10.0);
                $hub->SetVarFloat('e_disch_total',  $mb->u32($e2, 4)  / 10.0);
                $hub->SetVarFloat('e_disch_day',    $mb->u16($e2, 6)  / 10.0);
                $hub->SetVarFloat('e_buy_total',    $mb->u32($e2, 8)  / 10.0);
                $hub->SetVarFloat('e_buy_day',      $mb->u16($e2, 10) / 10.0);
                $hub->SetVarFloat('e_sell_total',   $mb->u32($e2, 12) / 10.0);
                $hub->SetVarFloat('e_sell_day',     $mb->u16($e2, 14) / 10.0);
            }
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $model = $mb->readInput(33000, 1);
        if ($model !== null) {
            $hub->SetVarInt('dev_model', $mb->u16($model, 0));
        }
        $sn = $mb->readInput(33004, 16);
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 16));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_GrowattDriver — Growatt Wechselrichter (TL-X/TL3-X/MOD/MIX/SPH/WIT-Serie).
// Durchnummerierte Input-Register (FC 0x04), 32-Bit-Werte als H/L-Paar.
// Modellfamilien unterscheiden sich in Registerbereichen (siehe Growatt-
// Protokoll Kapitel 1.2) — abgedeckt ist der gemeinsame Basisbereich
// (Register-Index 0-107), der bei praktisch allen Modellfamilien gilt.
// ---------------------------------------------------------------------------

class IHUB_GrowattDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [0 => 'Warte', 1 => 'Normal', 3 => 'Fehler'];

    public function getBaseVars()
    {
        return [
            ['connected',  'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',     'Betriebsstatus',    'I', 'GRW.Status',       true,  'device', 'Input 0'],
            ['pv_total',   'PV Gesamtleistung', 'F', 'GRW.Watt',         true,  'pv',     'Input 1-2'],
            ['ac_power',   'AC Wirkleistung',   'F', 'GRW.Watt',         true,  'device', 'Input 35-36'],
            ['grid_freq',  'Netzfrequenz',      'F', 'GRW.Hertz',        false, 'grid',   'Input 37'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupRiso' => ['caption' => 'Isolationswiderstand (modellabhängig — nur bei manchen Modellen belegt)', 'vars' => [
                ['riso', 'Isolationswiderstand', 'F', 'GRW.KOhm', true, 'pv', 'Input 200 (kΩ, modellabhängig)'],
            ]],
            'GroupPV' => ['caption' => 'PV-Details (String 1-3, Spannung/Strom/Leistung)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'GRW.Volt',   false, 'pv', 'Input 3'],
                ['pv1_curr', 'PV1 Strom',    'F', 'GRW.Ampere', false, 'pv', 'Input 4'],
                ['pv1_power','PV1 Leistung', 'F', 'GRW.Watt',   true,  'pv', 'Input 5-6'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'GRW.Volt',   false, 'pv', 'Input 7'],
                ['pv2_curr', 'PV2 Strom',    'F', 'GRW.Ampere', false, 'pv', 'Input 8'],
                ['pv2_power','PV2 Leistung', 'F', 'GRW.Watt',   true,  'pv', 'Input 9-10'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'GRW.Volt',   false, 'pv', 'Input 11'],
                ['pv3_curr', 'PV3 Strom',    'F', 'GRW.Ampere', false, 'pv', 'Input 12'],
                ['pv3_power','PV3 Leistung', 'F', 'GRW.Watt',   true,  'pv', 'Input 13-14'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Leistung je Phase)', 'vars' => [
                ['vac1', 'Netz L1 Spannung', 'F', 'GRW.Volt',   false, 'grid', 'Input 38'],
                ['iac1', 'Netz L1 Strom',    'F', 'GRW.Ampere', false, 'grid', 'Input 39'],
                ['pac1', 'Netz L1 Leistung', 'F', 'GRW.Watt',   true,  'grid', 'Input 40-41'],
                ['vac2', 'Netz L2 Spannung', 'F', 'GRW.Volt',   false, 'grid', 'Input 42'],
                ['iac2', 'Netz L2 Strom',    'F', 'GRW.Ampere', false, 'grid', 'Input 43'],
                ['pac2', 'Netz L2 Leistung', 'F', 'GRW.Watt',   true,  'grid', 'Input 44-45'],
                ['vac3', 'Netz L3 Spannung', 'F', 'GRW.Volt',   false, 'grid', 'Input 46'],
                ['iac3', 'Netz L3 Strom',    'F', 'GRW.Ampere', false, 'grid', 'Input 47'],
                ['pac3', 'Netz L3 Leistung', 'F', 'GRW.Watt',   true,  'grid', 'Input 48-49'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Gesamt)', 'vars' => [
                ['e_day',   'Ertrag Heute',  'F', '~Electricity', true, 'energy', 'Input 53-54'],
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'Input 55-56'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp1', 'Wechselrichter-Temperatur', 'F', '~Temperature', false, 'device', 'Input 93'],
            ]],
            'GroupErrors' => ['caption' => 'Fehlercodes', 'vars' => [
                ['fault_main', 'Fehler-Hauptcode', 'I', '', true, 'errors', 'Input 105'],
                ['fault_sub',  'Fehler-Subcode',   'I', '', true, 'errors', 'Input 107'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'GRW.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'GRW.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'GRW.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'GRW.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'GRW.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ',      0.0, 65535.0, 1.0,  0],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['GRW.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        $blk = $mb->readInput(0, 108); // Index 0-107

        $ok = ($blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($blk, 0));
        $hub->SetVarFloat('pv_total', $mb->u32($blk, 1) / 10.0);
        $hub->SetVarFloat('ac_power', $mb->u32($blk, 35) / 10.0);
        $hub->SetVarFloat('grid_freq', $mb->u16($blk, 37) / 100.0);

        // Isolationswiderstand liegt außerhalb des 0-107-Blocks (Input 200) und
        // ist nur bei manchen Growatt-Modellen belegt — daher optional/separat.
        if ($hub->GetPropBool('GroupRiso')) {
            $r = $mb->readInput(200, 1);
            if ($r !== null) {
                $hub->SetVarFloat('riso', (float)$mb->u16($r, 0));
            }
        }

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt',  $mb->u16($blk, 3) / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($blk, 4) / 10.0);
            $hub->SetVarFloat('pv1_power', $mb->u32($blk, 5) / 10.0);
            $hub->SetVarFloat('pv2_volt',  $mb->u16($blk, 7) / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($blk, 8) / 10.0);
            $hub->SetVarFloat('pv2_power', $mb->u32($blk, 9) / 10.0);
            $hub->SetVarFloat('pv3_volt',  $mb->u16($blk, 11) / 10.0);
            $hub->SetVarFloat('pv3_curr',  $mb->u16($blk, 12) / 10.0);
            $hub->SetVarFloat('pv3_power', $mb->u32($blk, 13) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('vac1', $mb->u16($blk, 38) / 10.0);
            $hub->SetVarFloat('iac1', $mb->u16($blk, 39) / 10.0);
            $hub->SetVarFloat('pac1', $mb->u32($blk, 40) / 10.0);
            $hub->SetVarFloat('vac2', $mb->u16($blk, 42) / 10.0);
            $hub->SetVarFloat('iac2', $mb->u16($blk, 43) / 10.0);
            $hub->SetVarFloat('pac2', $mb->u32($blk, 44) / 10.0);
            $hub->SetVarFloat('vac3', $mb->u16($blk, 46) / 10.0);
            $hub->SetVarFloat('iac3', $mb->u16($blk, 47) / 10.0);
            $hub->SetVarFloat('pac3', $mb->u32($blk, 48) / 10.0);
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            $hub->SetVarFloat('e_day',   $mb->u32($blk, 53) / 10.0);
            $hub->SetVarFloat('e_total', $mb->u32($blk, 55) / 10.0);
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp1', $mb->s16($blk, 93) / 10.0);
        }

        if ($hub->GetPropBool('GroupErrors')) {
            $hub->SetVarInt('fault_main', $mb->u16($blk, 105));
            $hub->SetVarInt('fault_sub',  $mb->u16($blk, 107));
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Alle Werte werden bereits in readFast() in einem Block gelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_SolaxDriver — SolaX Hybrid X1/X3-Serie. WICHTIG: Der Wechselrichter selbst
// spricht nur Modbus RTU — Modbus TCP läuft ausschließlich über ein
// zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als RTU/TCP-Gateway.
// Registeradressen direkt verwendet, Read Input Register (FC 0x04).
// ---------------------------------------------------------------------------

class IHUB_SolaxDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected',   'Verbindung',         'B', '~Alert.Reversed', false, 'errors', ''],
            ['grid_status', 'Netzstatus',         'I', 'SLX.GridStatus',  false, 'grid',   'RO 0x001A'],
            ['pv_total',    'PV Gesamtleistung',  'F', 'SLX.Watt',        true,  'pv',     'RO 0x0032-0x0033'],
            ['ongrid_total','On-Grid Gesamtleistung', 'F', 'SLX.Watt',    true,  'device', 'RO 0x0034-0x0035'],
            ['bat_power',   'Bat. Leistung',      'F', 'SLX.Watt',        true,  'bat',    'RO 0x0038-0x0039'],
            ['bat_soc',     'Bat. SOC',           'I', '~Battery.100',    true,  'bat',    'RO 0x003C'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (bis zu 6 Strings)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0003'],
                ['pv1_curr', 'PV1 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0005'],
                ['pv1_power','PV1 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x000A'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0004'],
                ['pv2_curr', 'PV2 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0006'],
                ['pv2_power','PV2 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x000B'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0122'],
                ['pv3_curr', 'PV3 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x0123'],
                ['pv3_power','PV3 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x0124'],
                ['pv4_volt', 'PV4 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0028'],
                ['pv5_volt', 'PV5 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x0029'],
                ['pv6_volt', 'PV6 Spannung', 'F', 'SLX.Volt',   false, 'pv', 'RO 0x002A'],
                ['pv4_curr', 'PV4 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002B'],
                ['pv5_curr', 'PV5 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002C'],
                ['pv6_curr', 'PV6 Strom',    'F', 'SLX.Ampere', false, 'pv', 'RO 0x002D'],
                ['pv4_power','PV4 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x002E'],
                ['pv5_power','PV5 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x002F'],
                ['pv6_power','PV6 Leistung', 'F', 'SLX.Watt',   true,  'pv', 'RO 0x0030'],
            ]],
            'GroupGridX1' => ['caption' => 'Netz einphasig (X1-Modelle)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'SLX.Volt',   false, 'grid', 'RO 0x0000'],
                ['grid_curr', 'Netz Strom',     'F', 'SLX.Ampere', false, 'grid', 'RO 0x0001'],
                ['grid_pwr',  'Netz Leistung',  'F', 'SLX.Watt',   true,  'grid', 'RO 0x0002'],
                ['grid_freq', 'Netzfrequenz',   'F', 'SLX.Hertz',  false, 'grid', 'RO 0x0007'],
            ]],
            'GroupGridX3' => ['caption' => 'Netz dreiphasig (X3-Modelle)', 'vars' => [
                ['grid_r_volt', 'Netz R Spannung', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x006A'],
                ['grid_r_curr', 'Netz R Strom',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x006B'],
                ['grid_r_pwr',  'Netz R Leistung', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x006C'],
                ['grid_s_volt', 'Netz S Spannung', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x006E'],
                ['grid_s_curr', 'Netz S Strom',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x006F'],
                ['grid_s_pwr',  'Netz S Leistung', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x0070'],
                ['grid_t_volt', 'Netz T Spannung', 'F', 'SLX.Volt',   false, 'grid', 'RO 0x0072'],
                ['grid_t_curr', 'Netz T Strom',    'F', 'SLX.Ampere', false, 'grid', 'RO 0x0073'],
                ['grid_t_pwr',  'Netz T Leistung', 'F', 'SLX.Watt',   true,  'grid', 'RO 0x0074'],
            ]],
            'GroupBat' => ['caption' => 'Batterie-Systemwerte (Gesamt)', 'vars' => [
                ['bat_capacity', 'Bat. installierte Kapazität', 'F', '~Electricity', false, 'bat', 'RO 0x003A-0x003B'],
                ['bat_soh',      'Bat. SOH', 'I', '~Intensity.100', true, 'bat', 'RO 0x003D'],
            ]],
            'GroupMeter' => ['caption' => 'Meter/CT (Einspeiseleistung)', 'vars' => [
                ['feedin_total', 'Einspeiseleistung Gesamt', 'F', 'SLX.Watt', true, 'meter', 'RO 0x0046-0x0047'],
                ['feedin_r',     'Einspeiseleistung R',      'F', 'SLX.Watt', true, 'meter', 'RO 0x0082-0x0083'],
                ['feedin_s',     'Einspeiseleistung S',      'F', 'SLX.Watt', true, 'meter', 'RO 0x0084-0x0085'],
            ]],
            'GroupOffgrid' => ['caption' => 'Off-Grid / EPS (Notstrom)', 'vars' => [
                ['offgrid_total', 'Off-Grid Gesamtleistung', 'F', 'SLX.Watt', true, 'backup', 'RO 0x0036-0x0037'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_type', 'Wechselrichter-Typ', 'I', '', false, 'device', 'RO 0x0015'],
                ['dev_sn',   'Modul-Seriennummer', 'S', '', false, 'device', 'RO 0x00AA-0x00AE'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLX.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLX.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLX.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'SLX.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [
            'SLX.GridStatus' => [
                0 => ['On-Grid', 0x27D07F],
                1 => ['Off-Grid', 0xE74C3C],
            ],
        ];
    }

    public function readFast($mb, $hub)
    {
        $pv1  = $mb->readInput(0x0000, 12);      // 0x0000-0x000B (Grid X1 + PV1/2 V/I/P)
        $sys  = $mb->readInput(0x001A, 36);       // 0x001A-0x003D (Status, PV total, Bat total)
        $ext3 = $mb->readInput(0x0028, 9);        // 0x0028-0x0030 (PV4-6)
        $pv3  = $mb->readInput(0x0122, 3);        // 0x0122-0x0124 (PV3)

        $ok = ($sys !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('grid_status', $mb->u16($sys, 0));                  // 0x001A
        $hub->SetVarFloat('pv_total',     (float)$mb->u32($sys, 24));       // 0x0032-33
        $hub->SetVarFloat('ongrid_total', (float)$mb->s32($sys, 26));       // 0x0034-35
        $hub->SetVarFloat('bat_power',    (float)$mb->s32($sys, 30));       // 0x0038-39
        $hub->SetVarInt('bat_soc', $mb->u16($sys, 34));                     // 0x003C

        if ($hub->GetPropBool('GroupPV') && $pv1 !== null) {
            $hub->SetVarFloat('pv1_volt',  $mb->u16($pv1, 3)  / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($pv1, 5)  / 10.0);
            $hub->SetVarFloat('pv1_power', (float)$mb->u16($pv1, 10));
            $hub->SetVarFloat('pv2_volt',  $mb->u16($pv1, 4)  / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($pv1, 6)  / 10.0);
            $hub->SetVarFloat('pv2_power', (float)$mb->u16($pv1, 11));
            if ($pv3 !== null) {
                $hub->SetVarFloat('pv3_volt',  $mb->u16($pv3, 0) / 10.0);
                $hub->SetVarFloat('pv3_curr',  $mb->u16($pv3, 1) / 10.0);
                $hub->SetVarFloat('pv3_power', (float)$mb->u16($pv3, 2));
            }
            if ($ext3 !== null) {
                $hub->SetVarFloat('pv4_volt',  $mb->u16($ext3, 0) / 10.0);
                $hub->SetVarFloat('pv5_volt',  $mb->u16($ext3, 1) / 10.0);
                $hub->SetVarFloat('pv6_volt',  $mb->u16($ext3, 2) / 10.0);
                $hub->SetVarFloat('pv4_curr',  $mb->u16($ext3, 3) / 10.0);
                $hub->SetVarFloat('pv5_curr',  $mb->u16($ext3, 4) / 10.0);
                $hub->SetVarFloat('pv6_curr',  $mb->u16($ext3, 5) / 10.0);
                $hub->SetVarFloat('pv4_power', (float)$mb->u16($ext3, 6));
                $hub->SetVarFloat('pv5_power', (float)$mb->u16($ext3, 7));
                $hub->SetVarFloat('pv6_power', (float)$mb->u16($ext3, 8));
            }
        }

        if ($hub->GetPropBool('GroupGridX1') && $pv1 !== null) {
            $hub->SetVarFloat('grid_volt', $mb->u16($pv1, 0) / 10.0);
            $hub->SetVarFloat('grid_curr', $mb->s16($pv1, 1) / 10.0);
            $hub->SetVarFloat('grid_pwr',  (float)$mb->s16($pv1, 2));
            $hub->SetVarFloat('grid_freq', $mb->u16($pv1, 7) / 100.0);
        }

        if ($hub->GetPropBool('GroupGridX3')) {
            $g3 = $mb->readInput(0x006A, 12); // 0x006A-0x0075
            if ($g3 !== null) {
                $hub->SetVarFloat('grid_r_volt', $mb->u16($g3, 0) / 10.0);
                $hub->SetVarFloat('grid_r_curr', $mb->s16($g3, 1) / 10.0);
                $hub->SetVarFloat('grid_r_pwr',  (float)$mb->s16($g3, 2));
                $hub->SetVarFloat('grid_s_volt', $mb->u16($g3, 4) / 10.0);
                $hub->SetVarFloat('grid_s_curr', $mb->s16($g3, 5) / 10.0);
                $hub->SetVarFloat('grid_s_pwr',  (float)$mb->s16($g3, 6));
                $hub->SetVarFloat('grid_t_volt', $mb->u16($g3, 8) / 10.0);
                $hub->SetVarFloat('grid_t_curr', $mb->s16($g3, 9) / 10.0);
                $hub->SetVarFloat('grid_t_pwr',  (float)$mb->s16($g3, 10));
            }
        }

        if ($hub->GetPropBool('GroupBat')) {
            $hub->SetVarFloat('bat_capacity', $mb->u32($sys, 32) / 1000.0); // 0x003A-3B, Wh -> kWh
            $hub->SetVarInt('bat_soh', $mb->u16($sys, 35));                 // 0x003D
        }

        if ($hub->GetPropBool('GroupOffgrid')) {
            $hub->SetVarFloat('offgrid_total', (float)$mb->s32($sys, 28));  // 0x0036-37
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $mb->readInput(0x0046, 64); // 0x0046-0x0085
            if ($meter !== null) {
                $hub->SetVarFloat('feedin_total', (float)$mb->s32($meter, 0));
                $hub->SetVarFloat('feedin_r', (float)$mb->s32($meter, 0x3C)); // 0x0082-83
                $hub->SetVarFloat('feedin_s', (float)$mb->s32($meter, 0x3E)); // 0x0084-85
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Keine zusätzlichen langsamen Werte in der ersten Ausbaustufe.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $type = $mb->readHolding(0x0015, 1);
        if ($type !== null) {
            $hub->SetVarInt('dev_type', $mb->u16($type, 0));
        }
        $sn = $mb->readHolding(0x00AA, 5);
        if ($sn !== null) {
            $hub->SetVarStr('dev_sn', $mb->readStr($sn, 0, 5));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_SmaDriver — SMA-Wechselrichter über SunSpec (wie von OpenEMS für SMA
// Sunny Tripower verwendet — SMA-eigene native Register wurden zugunsten
// von SunSpec verworfen, siehe Erkenntnis vom 2026-07-16). Laufzeit-
// Discovery ab Basisregister 40000, keine festen Adressen — analog zu
// IHUB_FroniusDriver, Feldoffsets gegen OpenEMS-SunSpec-Referenz verifiziert.
// ---------------------------------------------------------------------------

class IHUB_SmaDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        1 => 'Aus', 2 => 'Auto-Shutdown', 3 => 'Startet', 4 => 'Normal (MPPT)',
        5 => 'Leistungsreduktion', 6 => 'Schaltet ab', 7 => 'Fehler', 8 => 'Standby',
    ];

    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002;
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null;
            }
            if ($modelId === $wantedModelId) {
                return [$addr + 2, $len];
            }
            $addr += 2 + $len;
        }
        return null;
    }

    // ---- SunSpec Int+SF ----------------------------------------------------
    // Bewusst als eigene Methoden DIESER Klasse (nicht aus IHUB_FroniusDriver
    // mitbenutzt): Die Treiber sind getrennte Klassen, ein klassenübergreifender
    // $this->-Aufruf wäre ein Fatal Error zur Laufzeit.

    // sunssf-Skalierungsfaktor auswerten (int16, 0x8000 = nicht implementiert).
    private function sfVal($raw)
    {
        $v = ($raw > 32767) ? $raw - 65536 : $raw;
        return ($v === -32768) ? 0 : $v;
    }

    // Ganzzahlregister mit zugehörigem SF-Register zu einem echten Messwert
    // verrechnen. Liefert null, wenn das Feld den Sentinel „nicht implementiert"
    // trägt - u16: 0xFFFF, s16: 0x8000. Bei s16 ist 0xFFFF der gültige Wert -1
    // und darf gerade NICHT als Sentinel gelten.
    private function scaled($mb, $blk, $off, $sfOff, bool $signed = false)
    {
        $raw = $mb->u16($blk, $off);
        if ($signed ? ($raw === 0x8000) : ($raw === 0xFFFF)) {
            return null;
        }
        $val = $signed ? $mb->s16($blk, $off) : $raw;
        return $val * pow(10, $this->sfVal($mb->u16($blk, $sfOff)));
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'SMA.Status',      true,  'device', 'SunSpec St'],
            ['ac_power',  'AC Wirkleistung',   'F', 'SMA.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'SMA.Watt',        true,  'pv',     'RO 30773+30961 (SMA-Profil, DC MPP A+B), Fallback SunSpec DCW'],
            ['riso',      'Isolationswiderstand','F', 'SMA.KOhm',      true,  'pv',     'RO 30225 (SMA-Profil, Ohm ÷1000)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'SMA.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Netz Strom',     'F', 'SMA.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Netzfrequenz',   'F', 'SMA.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Gesamtertrag)', 'vars' => [
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_cab', 'Gehäusetemperatur', 'F', '~Temperature', false, 'device', 'SunSpec TmpCab (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung)', 'vars' => [
                ['meter_total', 'Netz Leistung (Meter)', 'F', 'SMA.Watt', true, 'meter', 'SunSpec Meter Model 201/203'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Hybrid-/Storage-Geräte)', 'vars' => [
                ['bat_soc',  'Batterie SOC',        'I', '~Battery.100',  true,  'bat', 'RO 30845 (SMA-Profil, %)'],
                ['bat_pwr',  'Batterie Leistung',   'F', 'SMA.Watt',      true,  'bat', 'RO 31393-31395 (Laden minus Entladen, + = laedt)'],
                ['bat_volt', 'Batterie Spannung',   'F', 'SMA.Volt',      false, 'bat', 'RO 30851 (SMA-Profil, V ÷100)'],
                ['bat_temp', 'Batterie Temperatur', 'F', '~Temperature',  false, 'bat', 'RO 30849 (SMA-Profil, °C ÷10)'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'SunSpec Common Block'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SMA.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SMA.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SMA.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'SMA.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'SMA.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ',      0.0, 65535.0, 1.0,  0],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['SMA.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        // Float-Inverter-Model bevorzugt (111/112/113), sonst Int+SF (101/102/103)
        // SMA-Besonderheit: Die SunSpec-Kette liegt NICHT auf der Unit-ID, die in
        // der SMA-Oberflaeche eingestellt ist, sondern auf dieser Zahl PLUS 123.
        // So dokumentiert es SMA selbst; OpenEMS setzt seine Vorgabe deshalb auf
        // 126 (= 3 + 123). Wer in der Oberflaeche 4 eingestellt hat, braucht 127.
        //
        // Weil das kaum jemand weiss, probiert der Treiber es selbst: Findet sich
        // unter der eingetragenen Unit-ID keine SunSpec-Kette, wird einmalig mit
        // +123 erneut gesucht. Das SMA-EIGENE Registerprofil (30000er, z. B. Riso)
        // liegt dagegen auf der unveraenderten Unit-ID.
        $native = $mb->unitId;
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }
        if ($inv === null && $native <= 124) {
            $mb->unitId = $native + 123;
            $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
            $isFloat = ($inv !== null);
            if ($inv === null) {
                $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
            }
            if ($inv === null) {
                $mb->unitId = $native;
            }
        }

        $ok = ($inv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        [$base, $len] = $inv;
        $blk = $mb->readHolding($base, min($len, 60));
        if ($blk === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        if ($hub->BlockLooksUnset($blk)) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // ---- SMA-Eigenprofil (30000er-Register) --------------------------
        // Es liegt NICHT auf der SunSpec-Kennung, sondern auf der Geraete-
        // Unit-ID. Welche das ist, haengt davon ab, was der Nutzer eingetragen
        // hat: die Geraete-ID (z. B. 3, dann SunSpec via +123-Retry) ODER
        // direkt die SunSpec-Kennung (z. B. 126, der OpenEMS-Standard) - dann
        // ist die Geraete-ID die eingetragene MINUS 123. Beim Tester fror so
        // der Isolationswiderstand ein und die Batterie blieb stumm, obwohl
        // sein Altmodul dieselben Register (auf Unit 3) problemlos las.
        // Deshalb wird die Geraete-ID sondiert: Kandidaten der Reihe nach
        // gegen Reg 30775 (AC-Wirkleistung, liefert auch nachts einen Wert
        // statt NaN) geprueft, der erste Treffer gewinnt.
        //
        // WICHTIG, an der Anlage belegt (Tester-Registerliste 22.07.2026): Das
        // SMA-Eigenprofil (30000er) wird als INPUT-Register (FC 0x04) gelesen,
        // NICHT als Holding (0x03). Deshalb kamen 30773/30961 (DC), 30845 ff.
        // (Batterie) und 31393/31395 (Batterieleistung) mit readHolding als NaN
        // zurueck und liessen das Geraet batterielos erscheinen. Die SunSpec-
        // Kette weiter oben bleibt Holding - das sind zwei verschiedene Welten.
        $sunspecUnit = $mb->unitId;
        $cands = [$native];
        if ($native > 123) {
            $cands[] = $native - 123;
        }
        if (!in_array(3, $cands, true)) {
            $cands[] = 3;   // SMA-Werksvorgabe
        }
        $devUnit = $native;
        foreach ($cands as $c) {
            $mb->unitId = $c;
            $probe = $mb->readInput(30775, 2);
            if ($probe !== null && $mb->u32($probe, 0) !== 0xFFFFFFFF) {
                $devUnit = $c;
                break;
            }
        }
        $mb->unitId = $devUnit;
        $risoBlk = $mb->readInput(30225, 2);
        if ($risoBlk !== null) {
            $r = $mb->u32($risoBlk, 0);
            if ($r !== 0xFFFFFFFF) {
                $hub->SetVarFloat('riso', $r / 1000.0);
            }
        }
        // PV-Gesamtleistung ebenfalls aus dem SMA-Eigenprofil: SMA belegt das
        // SunSpec-Feld DCW nicht (Sentinel 0x8000), die Variable bliebe leer
        // bzw. auf ihrem letzten Stand. Stattdessen die DC-Leistungen der MPP-
        // Eingaenge summieren: Reg 30773 (MPP A) und 30961 (MPP B), jeweils
        // int32 in W. Sentinel 0x80000000 = "nicht verfuegbar" (z. B. kein
        // zweiter Eingang oder nachts) zaehlt nicht mit.
        // SMA meldet "nicht verfuegbar" je nach Geraet als 0x80000000 (signed
        // NaN) ODER 0xFFFFFFFF (unsigned NaN, als s32 = -1). Der STP Smart
        // Energy z. B. belegt 30773/30961 gar nicht und lieferte so -1 je
        // Register - die Kachel zeigte -2 W bei laufender Produktion.
        $dcSum = null;
        foreach ([30773, 30961] as $reg) {
            $dcBlk = $mb->readInput($reg, 2);
            if ($dcBlk === null) {
                continue;
            }
            $w = $mb->s32($dcBlk, 0);
            if ($w === -2147483648 || $w === -1) {
                continue;   // Sentinel: Register nicht belegt oder nachts
            }
            $dcSum = ($dcSum ?? 0.0) + $w;
        }
        if ($dcSum !== null) {
            $hub->SetVarFloat('pv_total', $dcSum);
        }

        // Batterie (Hybrid-/Storage-Geraete) aus dem SMA-Eigenprofil. Alle
        // Register u32, NaN = 0xFFFFFFFF ("keine Batterie" bzw. gerade kein
        // Wert). Quellen: SOC 30845 (%), Temperatur 30849 (÷10 °C), Spannung
        // 30851 (÷100 V), Ladeleistung 31393 (W), Entladeleistung 31395 (W) -
        // lt. SMA Modbus-TI (EDMx) und CodeKing-Registerkarte. Vorzeichen:
        // + = laedt, - = entlaedt.
        // Batterie IMMER lesen, auch bei abgewählter Gruppe: Ob ueberhaupt eine
        // Batterie da ist, entscheidet unten darueber, ob die PV-Leistung aus
        // der AC-Leistung abgeleitet werden darf. Geschrieben werden die
        // Variablen weiterhin nur bei eingeschalteter Gruppe.
        //
        // Erkennungsmerkmal ist der SOC (30845) und NICHT die Lade-/Entlade-
        // leistung: 30845/30849/30851 stehen im GERAETE-Registerprofil, die
        // Leistungsregister 31393/31395 dagegen nur im Profil des SMA DATA
        // MANAGER. Auf dem Wechselrichter selbst antworten sie mit NaN - und
        // genau daran scheiterte die erste Fassung: Das Geraet galt als
        // batterielos, und die Entladeleistung erschien wieder als Solarertrag.
        $batNet     = null;    // + = laedt, - = entlaedt; null = unbekannt
        $hasBattery = false;
        $bs = $mb->readInput(30845, 8);   // 30845 SOC, 30849 Temp, 30851 Volt
        $soc = ($bs !== null) ? $mb->u32($bs, 0) : 0xFFFFFFFF;
        if ($soc !== 0xFFFFFFFF && $soc <= 100) {
            $hasBattery = true;
        }
        $bp = $mb->readInput(31393, 4);   // Laden (W), Entladen (W) - s. o.
        if ($bp !== null) {
            $chg = $mb->u32($bp, 0);
            $dis = $mb->u32($bp, 2);
            if ($chg !== 0xFFFFFFFF && $dis !== 0xFFFFFFFF) {
                $hasBattery = true;
                $batNet     = (float)$chg - (float)$dis;
            }
        }
        if ($hub->GetPropBool('GroupBat')) {
            if ($batNet !== null) {
                $hub->SetVarFloat('bat_pwr', $batNet);
            }
            if ($bs !== null) {
                if ($soc !== 0xFFFFFFFF) {
                    $hub->SetVarInt('bat_soc', $soc);
                }
                $t = $mb->u32($bs, 4);
                if ($t !== 0xFFFFFFFF) {
                    $hub->SetVarFloat('bat_temp', $t / 10.0);
                }
                $u = $mb->u32($bs, 6);
                if ($u !== 0xFFFFFFFF) {
                    $hub->SetVarFloat('bat_volt', $u / 100.0);
                }
            }
        }
        $mb->unitId = $sunspecUnit; // zurueck auf die SunSpec-Kennung

        // Offsets siehe IHUB_FroniusDriver (identische SunSpec-Modelle 101/103/111/113),
        // gegen OpenEMS-SunSpec-Referenz verifiziert. Zusätzlich hier genutzt:
        // DCW (aggregierte DC-Leistung) Float @36, Int+SF @29; TmpCab Float @38, Int+SF @31.
        if ($isFloat) {
            $acW = $mb->readFloat32($blk, 20);
            $st  = $mb->u16($blk, 46);
            $hub->SetVarFloat('ac_power', $acW);
            $hub->SetVarInt('status', $st);
            if ($dcSum === null) {
                $dcw = $mb->readFloat32($blk, 36);
                if (is_finite($dcw)) {
                    $hub->SetVarFloat('pv_total', $dcw);
                    $dcSum = $dcw;
                }
            }
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->readFloat32($blk, 38));
            }
        } else {
            // Int+SF-Variante (Model 101/103): Jedes Feld ist ein Ganzzahl-
            // Register MIT einem eigenen Skalierungsfaktor-Register (sunssf).
            // Ohne dessen Auswertung liegen die Werte um Zehnerpotenzen daneben.
            // SMA bietet ausschliesslich diese Modelle an (keine Float-Modelle
            // 111/113), deshalb traf das bis 0.65.3 jeden SMA-Nutzer:
            // Netzspannung 2390 statt 239,0 V (V_SF = -1), Netzstrom 57 statt
            // 0,57 A (A_SF = -2). Gegenprobe: 0,57 A * 239 V = 136 W, und die
            // AC-Wirkleistung meldete zeitgleich 137 W.
            //
            // Offsets ab Modellstart (Model 101/103, identische Feldreihenfolge):
            //   0 A(+SF@4) | 8 PhVphA(+SF@11) | 12 W(+SF@13) | 14 Hz(+SF@15)
            //   22 WH acc32(+SF@24) | 29 DCW(+SF@30) | 31 TmpCab(+SF@35) | 36 St
            $acW = $this->scaled($mb, $blk, 12, 13, true);
            if ($acW !== null) {
                $hub->SetVarFloat('ac_power', $acW);
            }
            $st = $mb->u16($blk, 36);
            $hub->SetVarInt('status', $st);
            if ($dcSum === null) {
                $v = $this->scaled($mb, $blk, 29, 30, true);
                if ($v !== null) {
                    $hub->SetVarFloat('pv_total', $v);
                    $dcSum = $v;
                }
            }
            if ($hub->GetPropBool('GroupGrid')) {
                $v = $this->scaled($mb, $blk, 0, 4);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_curr', $v);
                }
                $v = $this->scaled($mb, $blk, 8, 11);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_volt', $v);
                }
                $v = $this->scaled($mb, $blk, 14, 15);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_freq', $v);
                }
            }
            // Gesamtertrag fehlte in diesem Zweig komplett - die Variable blieb
            // bei SMA dauerhaft auf 0,00 kWh / "Nie aktualisiert".
            if ($hub->GetPropBool('GroupEnergy')) {
                $wh = $mb->u32($blk, 22);
                if ($wh !== 0xFFFFFFFF) {
                    $hub->SetVarFloat('e_total', $wh * pow(10, $this->sfVal($mb->u16($blk, 24))) / 1000.0);
                }
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $v = $this->scaled($mb, $blk, 31, 35, true);
                if ($v !== null) {
                    $hub->SetVarFloat('temp_cab', $v);
                }
            }
        }

        // Letzte Rueckfallebene fuer die PV-Gesamtleistung, wenn weder das
        // SMA-Eigenprofil (30773/30961) noch SunSpec-DCW einen DC-Wert liefert.
        //
        // ACHTUNG, teuer gelernt (Tester-Report 22.07.2026, 21:50 Uhr): Auf
        // einem HYBRIDGERAET sagt die AC-Leistung NICHTS darueber aus, woher
        // die Energie kommt. Nachts speiste die Batterie 1850 W ins Haus, der
        // Wechselrichter meldete weiter Status "Normal (MPPT)" - und die Kachel
        // zeigte diese 1850 W als Solarerzeugung an. Der Status taugt also NICHT
        // als Tageslicht-Ersatz.
        //
        // Deshalb wird nur noch abgeleitet, wenn die Herkunft geklaert ist:
        //   - Geraet OHNE Batterie: AC ~ PV ist zulaessig (nur Wandlerverluste).
        //   - Geraet MIT Batterie: nur wenn die Batterieleistung wirklich
        //     gelesen wurde, dann PV ~ AC + Ladeleistung.
        //   - Batterie vorhanden, aber Leistung unbekannt: 0 W. Lieber gar kein
        //     Wert als eine erfundene Erzeugung.
        if ($dcSum === null) {
            $pv = 0.0;
            if ($st === 4 && $acW !== null) {
                if (!$hasBattery) {
                    $pv = max(0.0, (float)$acW);
                } elseif ($batNet !== null) {
                    $pv = max(0.0, (float)$acW + $batNet);
                }
            }
            $hub->SetVarFloat('pv_total', $pv);
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $this->findModel($mb, 201) ?: $this->findModel($mb, 203) ?: $this->findModel($mb, 211) ?: $this->findModel($mb, 213);
            if ($meter !== null) {
                [$mtbase, $mtlen] = $meter;
                // Model 201/203: W@16 mit W_SF@20 - der Block muss bis 20 reichen.
                $mtblk = $mb->readHolding($mtbase, min($mtlen, 24));
                if ($mtblk !== null) {
                    $v = $this->scaled($mb, $mtblk, 16, 20, true);
                    if ($v !== null) {
                        $hub->SetVarFloat('meter_total', $v);
                    }
                }
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $common = $this->findModel($mb, 1);
        if ($common === null) {
            return;
        }
        [$base, $len] = $common;
        $blk = $mb->readHolding($base, min($len, 66));
        if ($blk === null) {
            return;
        }
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_FroniusDriver — Fronius SunSpec-Modbus (Datamanager). Reine SunSpec-
// Implementierung mit DYNAMISCHEN Registeradressen: Modelle werden zur
// Laufzeit ab Basisregister 40000 durchlaufen (Model-ID + Länge), keine
// festen Adressen. Adressen werden pro Zyklus neu ermittelt und im Attribut
// gecacht, da sie laut Fronius-Doku über Firmware-Versionen hinweg nicht
// garantiert stabil sind.
// ---------------------------------------------------------------------------

class IHUB_FroniusDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        1 => 'Aus', 2 => 'Auto-Shutdown', 3 => 'Startet', 4 => 'Normal (MPPT)',
        5 => 'Leistungsreduktion', 6 => 'Schaltet ab', 7 => 'Fehler', 8 => 'Standby',
    ];

    // Sucht ab Basisregister 40000 (SunSpec "SunS"-Marker + Common Block) nach
    // dem gewünschten Model und gibt [Startadresse Nutzdaten, Länge] zurück.
    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002; // hinter dem 2-Register-Common-Marker "SunS"
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null; // End Block erreicht
            }
            if ($modelId === $wantedModelId) {
                return [$addr + 2, $len];
            }
            $addr += 2 + $len;
        }
        return null;
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'FRO.Status',      true,  'device', 'SunSpec St'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'FRO.Watt',        true,  'pv',     'SunSpec DCW (Model 160)'],
            ['ac_power',  'AC Wirkleistung',   'F', 'FRO.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (MPPT laut Multiple MPPT Extension Model 160)', 'vars' => [
                ['mppt1_volt',  'MPPT1 Spannung', 'F', 'FRO.Volt',   false, 'pv', 'SunSpec Model 160 DCV'],
                ['mppt1_curr',  'MPPT1 Strom',    'F', 'FRO.Ampere', false, 'pv', 'SunSpec Model 160 DCA'],
                ['mppt1_power','MPPT1 Leistung',  'F', 'FRO.Watt',   true,  'pv', 'SunSpec Model 160 DCW'],
                ['mppt2_volt',  'MPPT2 Spannung', 'F', 'FRO.Volt',   false, 'pv', 'SunSpec Model 160 DCV'],
                ['mppt2_curr',  'MPPT2 Strom',    'F', 'FRO.Ampere', false, 'pv', 'SunSpec Model 160 DCA'],
                ['mppt2_power','MPPT2 Leistung',  'F', 'FRO.Watt',   true,  'pv', 'SunSpec Model 160 DCW'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'FRO.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Netz Strom',     'F', 'FRO.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Netzfrequenz',   'F', 'FRO.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Gesamtertrag)', 'vars' => [
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung)', 'vars' => [
                ['meter_total', 'Netz Leistung (Meter)', 'F', 'FRO.Watt', true, 'meter', 'SunSpec Meter Model 20x/21x (Unit-ID 200)'],
            ]],
            'GroupMeterEnergy' => ['caption' => 'Smart Meter Energie (Bezug / Einspeisung gesamt)', 'vars' => [
                ['meter_imp', 'Bezug Gesamt (aus dem Netz)',   'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhImp (EnergyReal_WAC_Sum_Consumed)'],
                ['meter_exp', 'Einspeisung Gesamt (ins Netz)', 'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhExp (EnergyReal_WAC_Sum_Produced)'],
            ]],
            'GroupMeterPhases' => ['caption' => 'Smart Meter je Phase (Spannung/Strom/Leistung)', 'vars' => [
                ['meter_l1_volt', 'Meter L1 Spannung', 'F', 'FRO.Volt',   false, 'meter', 'SunSpec Meter PhVphA'],
                ['meter_l1_curr', 'Meter L1 Strom',    'F', 'FRO.Ampere', false, 'meter', 'SunSpec Meter AphA'],
                ['meter_l1_pwr',  'Meter L1 Leistung', 'F', 'FRO.Watt',   true,  'meter', 'SunSpec Meter WphA'],
                ['meter_l2_volt', 'Meter L2 Spannung', 'F', 'FRO.Volt',   false, 'meter', 'SunSpec Meter PhVphB'],
                ['meter_l2_curr', 'Meter L2 Strom',    'F', 'FRO.Ampere', false, 'meter', 'SunSpec Meter AphB'],
                ['meter_l2_pwr',  'Meter L2 Leistung', 'F', 'FRO.Watt',   true,  'meter', 'SunSpec Meter WphB'],
                ['meter_l3_volt', 'Meter L3 Spannung', 'F', 'FRO.Volt',   false, 'meter', 'SunSpec Meter PhVphC'],
                ['meter_l3_curr', 'Meter L3 Strom',    'F', 'FRO.Ampere', false, 'meter', 'SunSpec Meter AphC'],
                ['meter_l3_pwr',  'Meter L3 Leistung', 'F', 'FRO.Watt',   true,  'meter', 'SunSpec Meter WphC'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (GEN24-Hybrid: SOC, Leistung, Spannung)', 'vars' => [
                ['bat_soc',   'Bat. SOC', 'F', 'FRO.Soc', true, 'bat', 'SunSpec Model 124 ChaState'],
                ['bat_power', 'Bat. Leistung (Entladen + / Laden -)', 'F', 'FRO.Watt', true, 'bat', 'SunSpec Model 160 Module 3+4'],
                ['bat_volt',  'Bat. Spannung', 'F', 'FRO.Volt', false, 'bat', 'SunSpec Model 160 Speicher-Modul DCV'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['riso',      'Isolationswiderstand', 'F', 'FRO.KOhm', true, 'device', 'SunSpec Ris (Model 122)'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    // sunssf-Skalierungsfaktor auswerten (int16, 0x8000 = nicht implementiert).
    private function sfVal($raw)
    {
        $v = ($raw > 32767) ? $raw - 65536 : $raw;
        return ($v === -32768) ? 0 : $v;
    }

    // Ganzzahlregister mit zugehoerigem SF-REGISTER (Offset, nicht Faktor)
    // verrechnen. null bei Sentinel „nicht implementiert" - u16: 0xFFFF,
    // s16: 0x8000. Bewusst anders benannt als scaledU16(), das einen bereits
    // ausgewerteten Faktor erwartet.
    private function scaledSf($mb, $blk, $off, $sfOff, bool $signed = false)
    {
        $raw = $mb->u16($blk, $off);
        if ($signed ? ($raw === 0x8000) : ($raw === 0xFFFF)) {
            return null;
        }
        $val = $signed ? $mb->s16($blk, $off) : $raw;
        return $val * pow(10, $this->sfVal($mb->u16($blk, $sfOff)));
    }

    // uint16-Registerwert mit Skalierungsfaktor; 0xFFFF = nicht implementiert.
    private function scaledU16($mb, $blk, $off, $sf)
    {
        $raw = $mb->u16($blk, $off);
        if ($raw === 0xFFFF) {
            return null;
        }
        return $raw * pow(10, $sf);
    }

    public function getProfiles()
    {
        return [
            'FRO.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'FRO.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'FRO.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'FRO.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ', 0.0, 100000.0, 1.0, 0],
            'FRO.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'FRO.Soc'    => [VARIABLETYPE_FLOAT, ' %',        0.0,   100.0, 0.1,  1],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        return ['FRO.Status' => $status];
    }

    public function readFast($mb, $hub)
    {
        // Float-Inverter-Model bevorzugt (111/112/113), sonst Int+SF (101/102/103)
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }

        $ok = ($inv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        [$base, $len] = $inv;
        $blk = $mb->readHolding($base, min($len, 60));
        if ($blk === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        if ($hub->BlockLooksUnset($blk)) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // Offsets ab Modellstart gemäß offizieller SunSpec-Modelldefinition
        // (Model 113/103 "Inverter Three Phase" — Feldreihenfolge, gegen die
        // OpenEMS-SunSpec-Implementierung verifiziert 2026-07-16):
        // Float (Model 113, je Feld 2 Register): 0 A, 14 PhVphA(Netzspannung),
        // 22 Hz(Frequenz), 20 W(Wirkleistung), 30 WH(Gesamtertrag), 46 St(Status)
        // Int+SF (Model 103, je Feld 1 Register + eigenes SF-Register):
        // 0 A, 8 PhVphA, 14 Hz(+SF@15), 12 W(+SF@13), 36 St
        if ($isFloat) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($blk, 20));
            $hub->SetVarInt('status', $mb->u16($blk, 46));
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
        } else {
            // Int+SF-Variante (Model 101/103): Ganzzahlregister mit eigenem
            // Skalierungsfaktor-Register. Bis 0.65.3 wurde hier der Rohwert
            // uebernommen - bei Fronius faellt das kaum auf, weil der
            // Datamanager die Float-Modelle 111/113 anbietet und der Code die
            // bevorzugt. Der Zweig greift nur bei aelteren Geraeten, war dort
            // aber genauso falsch wie bei SMA.
            // Offsets: 0 A(+SF@4) | 8 PhVphA(+SF@11) | 12 W(+SF@13)
            //          14 Hz(+SF@15) | 22 WH acc32(+SF@24) | 36 St
            $v = $this->scaledSf($mb, $blk, 12, 13, true);
            if ($v !== null) {
                $hub->SetVarFloat('ac_power', $v);
            }
            $hub->SetVarInt('status', $mb->u16($blk, 36));
            if ($hub->GetPropBool('GroupGrid')) {
                $v = $this->scaledSf($mb, $blk, 0, 4);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_curr', $v);
                }
                $v = $this->scaledSf($mb, $blk, 8, 11);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_volt', $v);
                }
                $v = $this->scaledSf($mb, $blk, 14, 15);
                if ($v !== null) {
                    $hub->SetVarFloat('grid_freq', $v);
                }
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $wh = $mb->u32($blk, 22);
                if ($wh !== 0xFFFFFFFF) {
                    $hub->SetVarFloat('e_total', $wh * pow(10, $this->sfVal($mb->u16($blk, 24))) / 1000.0);
                }
            }
        }

        if ($hub->GetPropBool('GroupPV') || $hub->GetPropBool('GroupBat')) {
            $mppt = $this->findModel($mb, 160);
            if ($mppt !== null) {
                [$mbase, $mlen] = $mppt;
                $readLen = min($mlen, 88);   // fester Kopf (8) + bis zu 4 Module à 20
                $mblk = $mb->readHolding($mbase, $readLen);
                if ($mblk !== null) {
                    // Multiple MPPT Extension (Model 160), offizielles Layout:
                    // fester Kopf: DCA_SF(0) DCV_SF(1) DCW_SF(2) DCWH_SF(3)
                    // Evt(4-5) N(6) TmsPer(7); je Modul (20 Register ab 8+n*20):
                    // ID(+0) IDStr(+1..+8, Text!) DCA(+9) DCV(+10) DCW(+11) ...
                    // Bugfix: die alten Offsets (10/12 bzw. 30/32) lasen mitten
                    // im IDStr-Textfeld - daher Fantasiewerte wie "2056,4 V".
                    $dcaSf = $this->sfVal($mb->u16($mblk, 0));
                    $dcvSf = $this->sfVal($mb->u16($mblk, 1));
                    $dcwSf = $this->sfVal($mb->u16($mblk, 2));
                    $modVal = function ($n, $inner, $sf) use ($mb, $mblk, $readLen) {
                        $base = 8 + $n * 20;
                        if ($base + 20 > $readLen) {
                            return null;   // Modul nicht vorhanden/gelesen
                        }
                        return $this->scaledU16($mb, $mblk, $base + $inner, $sf);
                    };

                    if ($hub->GetPropBool('GroupPV')) {
                        $p1 = $modVal(0, 11, $dcwSf);
                        $p2 = $modVal(1, 11, $dcwSf);
                        $hub->SetVarFloat('mppt1_volt',  (float)($modVal(0, 10, $dcvSf) ?? 0));
                        $hub->SetVarFloat('mppt1_curr',  (float)($modVal(0, 9, $dcaSf) ?? 0));
                        $hub->SetVarFloat('mppt1_power', (float)($p1 ?? 0));
                        $hub->SetVarFloat('mppt2_volt',  (float)($modVal(1, 10, $dcvSf) ?? 0));
                        $hub->SetVarFloat('mppt2_curr',  (float)($modVal(1, 9, $dcaSf) ?? 0));
                        $hub->SetVarFloat('mppt2_power', (float)($p2 ?? 0));
                        $hub->SetVarFloat('pv_total', (float)(($p1 ?? 0) + ($p2 ?? 0)));
                    }

                    // GEN24-Hybrid: Module 3 und 4 sind die Speicherkanäle
                    // (Laden bzw. Entladen). Konvention im Modul: positiv =
                    // Entladung (Leistung wird ans System abgegeben).
                    if ($hub->GetPropBool('GroupBat')) {
                        $charge    = $modVal(2, 11, $dcwSf);
                        $discharge = $modVal(3, 11, $dcwSf);
                        if ($charge !== null || $discharge !== null) {
                            $hub->SetVarFloat('bat_power', (float)(($discharge ?? 0) - ($charge ?? 0)));
                        }
                        // Batteriespannung aus dem DCV der Speichermodule (2/3);
                        // ist am aktiven Kanal belegt, daher den ersten gültigen
                        // Wert nehmen.
                        $bVolt = $modVal(2, 10, $dcvSf);
                        if ($bVolt === null || $bVolt <= 0) {
                            $bVolt = $modVal(3, 10, $dcvSf);
                        }
                        if ($bVolt !== null && $bVolt > 0) {
                            $hub->SetVarFloat('bat_volt', (float)$bVolt);
                        }
                    }
                }
            }
        }

        // Batterie-SOC aus dem Basic-Storage-Model 124: ChaState (Offset 6,
        // uint16) mit ChaState_SF (Offset 20, sunssf).
        if ($hub->GetPropBool('GroupBat')) {
            $stor = $this->findModel($mb, 124);
            if ($stor !== null) {
                [$sbase, $slen] = $stor;
                $sblk = $mb->readHolding($sbase, min($slen, 24));
                if ($sblk !== null && min($slen, 24) >= 21) {
                    $soc = $this->scaledU16($mb, $sblk, 6, $this->sfVal($mb->u16($sblk, 20)));
                    if ($soc !== null) {
                        // Fronius liefert den SOC als Float (ChaState mit
                        // Skalierungsfaktor) - eine Nachkommastelle zeigen.
                        $hub->SetVarFloat('bat_soc', round((float)$soc, 1));
                    }
                }
            }
        }

        // Isolationswiderstand: Solange der Wechselrichter NICHT einspeist
        // (Status 4 = Normal/MPPT, 5 = Leistungsreduktion), wird er in jedem
        // schnellen Zyklus gelesen. Der aussagekraeftige Wert entsteht beim
        // Selbsttest VOR dem Zuschalten - im langsamen Zyklus wuerde man ihn
        // verpassen. Im Betrieb genuegt die Auffrischung aus readSlow().
        if (!in_array($invStatus ?? 0, [4, 5], true)) {
            $this->readRiso($mb, $hub);
        }

        if ($hub->GetPropBool('GroupMeter')) {
            // Fronius meldet den Smart Meter NICHT in der SunSpec-Kette des
            // Wechselrichters, sondern unter einer eigenen Unit-ID (die
            // "Zähleradresse"). Deren Vorgabe ist 200, je nach Konfiguration
            // aber z. B. 240 - daher konfigurierbar. Erst dort suchen, zur
            // Sicherheit als Rückfall auch in der eigenen Kette.
            $meterVal = $this->readMeterTotal(new IHUB_ModbusTcpClient($mb->host, $mb->port, $hub->GetMeterUnitId()));
            if ($meterVal === null) {
                $meterVal = $this->readMeterTotal($mb);
            }
            if ($meterVal !== null) {
                // SunSpec-Meter: positiv = Bezug. Modul-Konvention: positiv =
                // Einspeisung - daher Vorzeichen drehen.
                $hub->SetVarFloat('meter_total', -$meterVal);
            }
        }

        if ($hub->GetPropBool('GroupMeterPhases')) {
            $mc = new IHUB_ModbusTcpClient($mb->host, $mb->port, $hub->GetMeterUnitId());
            if (!$this->readMeterPhases($mc, $hub)) {
                $this->readMeterPhases($mb, $hub);
            }
        }

        if ($hub->GetPropBool('GroupMeterEnergy')) {
            $mc = new IHUB_ModbusTcpClient($mb->host, $mb->port, $hub->GetMeterUnitId());
            $me = $this->readMeterEnergy($mc);
            if ($me === null) {
                $me = $this->readMeterEnergy($mb);
            }
            if ($me !== null) {
                // Werte in kWh; die optionale Wh-Ausgabe rechnet SetVarFloat um.
                $hub->SetVarFloat('meter_imp', $me['imp']); // Consumed / Bezug
                $hub->SetVarFloat('meter_exp', $me['exp']); // Produced / Einspeisung
            }
        }

        return true;
    }

    // Liest die kumulierten Energie-Zählerstände eines SunSpec-Meters
    // (Bezug/Einspeisung gesamt) - entspricht EnergyReal_WAC_Sum_Consumed/
    // Produced der Fronius-API. Rückgabe ['imp'=>kWh, 'exp'=>kWh] oder null.
    private function readMeterEnergy($client)
    {
        // Int-Modelle 20x: TotWhExp @36, TotWhImp @44, TotWh_SF @52 (uint32, Wh).
        $meter = $this->findModel($client, 201) ?: $this->findModel($client, 202) ?: $this->findModel($client, 203);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 54));
            if ($blk === null || min($len, 54) < 53) {
                return null;
            }
            $sf = pow(10, $this->sfVal($client->u16($blk, 52)));
            return [
                'imp' => $client->u32($blk, 44) * $sf / 1000.0,
                'exp' => $client->u32($blk, 36) * $sf / 1000.0,
            ];
        }
        // Float-Modelle 21x (je Messwert 2 Register): TotWhExp @58, TotWhImp @66.
        // WICHTIG: 58/66 sind die GESAMT-Zähler; 60/68 wären TotWhExpPhA/ImpPhA
        // (Per-Phase), die viele Meter nicht füllen -> lieferten fälschlich 0 Wh.
        $meter = $this->findModel($client, 211) ?: $this->findModel($client, 212) ?: $this->findModel($client, 213);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 70));
            if ($blk === null || min($len, 70) < 68) {
                return null;
            }
            return [
                'imp' => $client->readFloat32($blk, 66) / 1000.0,
                'exp' => $client->readFloat32($blk, 58) / 1000.0,
            ];
        }
        return null;
    }

    // Liest die Phasenwerte (U/I/P je Phase) eines SunSpec-Meters. Strom und
    // Leistung je Phase werden im Roh-Vorzeichen des Zählers geführt (P und I
    // damit zueinander konsistent, P = U·I); nur der Gesamtwert wird gedreht.
    // Rückgabe true, wenn ein Meter-Model gefunden wurde.
    private function readMeterPhases($client, $hub)
    {
        // Int-Modelle 20x: Messwerte int16/uint16 mit Skalierungsfaktor.
        $meter = $this->findModel($client, 201) ?: $this->findModel($client, 202) ?: $this->findModel($client, 203);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 24));
            if ($blk === null || min($len, 24) < 21) {
                return false;
            }
            $aSf = pow(10, $this->sfVal($client->u16($blk, 4)));   // A_SF
            $vSf = pow(10, $this->sfVal($client->u16($blk, 13)));  // V_SF
            $wSf = pow(10, $this->sfVal($client->u16($blk, 20)));  // W_SF
            // Offsets: AphA/B/C = 1/2/3, PhVphA/B/C = 6/7/8, WphA/B/C = 17/18/19.
            $ph = [['l1', 1, 6, 17], ['l2', 2, 7, 18], ['l3', 3, 8, 19]];
            foreach ($ph as [$p, $ai, $vi, $wi]) {
                if ($client->u16($blk, $vi) !== 0xFFFF) {
                    $hub->SetVarFloat('meter_' . $p . '_volt', $client->u16($blk, $vi) * $vSf);
                }
                if ($client->s16($blk, $ai) !== -32768) {
                    $hub->SetVarFloat('meter_' . $p . '_curr', $client->s16($blk, $ai) * $aSf);
                }
                if ($client->s16($blk, $wi) !== -32768) {
                    // Phasen-Leistung NICHT drehen: Der Zähler liefert sie mit
                    // demselben Vorzeichen wie den Phasenstrom (P = U·I). Der
                    // Gesamtwert (meter_total) wird separat gedreht - Phasenwert
                    // und Strom bleiben dagegen konsistent zueinander.
                    $hub->SetVarFloat('meter_' . $p . '_pwr', $client->s16($blk, $wi) * $wSf);
                }
            }
            return true;
        }

        // Float-Modelle 21x: Messwerte als IEEE-754-Float32 (je 2 Register).
        $meter = $this->findModel($client, 211) ?: $this->findModel($client, 212) ?: $this->findModel($client, 213);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 34));
            if ($blk === null || min($len, 34) < 34) {
                return false;
            }
            // Offsets (Float32): AphA/B/C = 2/4/6, PhVphA/B/C = 10/12/14,
            // WphA/B/C = 28/30/32.
            $ph = [['l1', 2, 10, 28], ['l2', 4, 12, 30], ['l3', 6, 14, 32]];
            foreach ($ph as [$p, $ai, $vi, $wi]) {
                $hub->SetVarFloat('meter_' . $p . '_volt', $client->readFloat32($blk, $vi));
                $hub->SetVarFloat('meter_' . $p . '_curr', $client->readFloat32($blk, $ai));
                $hub->SetVarFloat('meter_' . $p . '_pwr', $client->readFloat32($blk, $wi));
            }
            return true;
        }

        return false;
    }

    // Liest die Gesamtwirkleistung eines SunSpec-Meters (Int-Modelle 20x mit
    // Skalierungsfaktor, Float-Modelle 21x als IEEE-754) über den übergebenen
    // Client. Rückgabe null, wenn kein Meter-Model gefunden wird.
    private function readMeterTotal($client)
    {
        $meter = $this->findModel($client, 201) ?: $this->findModel($client, 202) ?: $this->findModel($client, 203);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 24));
            if ($blk === null || min($len, 24) < 21) {
                return null;
            }
            // Model 20x: W bei Offset 16 (int16), W_SF bei Offset 20.
            $raw = $client->s16($blk, 16);
            if ($raw === -32768) {
                return null;
            }
            return $raw * pow(10, $this->sfVal($client->u16($blk, 20)));
        }

        $meter = $this->findModel($client, 211) ?: $this->findModel($client, 212) ?: $this->findModel($client, 213);
        if ($meter !== null) {
            [$base, $len] = $meter;
            $blk = $client->readHolding($base, min($len, 30));
            if ($blk === null || min($len, 30) < 28) {
                return null;
            }
            // Float-Model 21x: W ist der 14. Messwert -> Offset 26 (je 2 Reg.).
            return $client->readFloat32($blk, 26);
        }

        return null;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
        // Isolationswiderstand hier als Auffrischung waehrend des Betriebs;
        // steht der Wechselrichter, holt ihn readFast() in jedem Zyklus.
        $this->readRiso($mb, $hub);
    }

    /**
     * Isolationswiderstand aus SunSpec-Modell 122 ("Measurements Status").
     *
     * WANN das gelesen wird, ist fachlich entscheidend: Der Wechselrichter misst
     * die Isolation beim Selbsttest VOR dem Zuschalten. Genau dieser Wert zeigt,
     * ob eine Strecke Feuchtigkeit zieht ("absaufen"). Steht das Geraet erst im
     * Betrieb, ist der Wert weniger aussagekraeftig bzw. aendert sich kaum.
     *
     * Deshalb: Im Stillstand und beim Anlauf (Status != 4/5) wird er in JEDEM
     * schnellen Zyklus gelesen, damit die Messung des Selbsttests nicht
     * verpasst wird. Speist das Geraet ein, genuegt der langsame Zyklus.
     *
     * Das Modell fuehrt Ris und Ris_SF als die BEIDEN LETZTEN Register des 44
     * Register langen Blocks. Bewusst ueber die gemeldete Modelllaenge
     * adressiert statt ueber eine feste Adresse - die SunSpec-Kette liegt je
     * nach Geraet an unterschiedlicher Stelle.
     *
     * Nicht jedes Geraet fuehrt Modell 122; fehlt es, entfaellt der Wert
     * stillschweigend. Ueber die Fronius Solar API ist der Wert NICHT verfuegbar
     * (CommonInverterData kennt ihn nicht) - Modbus ist der einzige Weg.
     */
    private function readRiso($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupDevice')) {
            return;
        }
        $m122 = $this->findModel($mb, 122);
        if ($m122 === null) {
            return;
        }
        [$base, $len] = $m122;
        if ($len < 44) {
            return; // unerwartete Laenge - lieber nichts liefern als etwas Falsches
        }
        $blk = $mb->readHolding($base, 44);
        if ($blk === null) {
            return;
        }
        $ris   = $mb->u16($blk, 42);   // vorletztes Register
        $risSf = $this->sfVal($mb->u16($blk, 43));
        if ($ris === 0xFFFF || $risSf === null) {
            return; // SunSpec-Kennung fuer "nicht implementiert"
        }
        // Ris ist in Ohm * 10^Ris_SF; die anderen Treiber fuehren riso in kOhm.
        $hub->SetVarFloat('riso', ($ris * pow(10, $risSf)) / 1000.0);
    }

    public function readDeviceInfo($mb, $hub)
    {
        $common = $this->findModel($mb, 1);
        if ($common === null) {
            return;
        }
        [$base, $len] = $common;
        $blk = $mb->readHolding($base, min($len, 66));
        if ($blk === null) {
            return;
        }
        // Common Block: Mn(16 Zeichen ab Offset 0), Md(16 ab Offset 16),
        // Vr(8 ab Offset 32... Herstellerabhängig), SN(16 ab Offset 40)
        // SunSpec Common Block (Model 1): MN(0-15) MD(16-31) OPT(32-39)
        // VR(40-47) SN(48-63) — verifiziert gegen OpenEMS-SunSpec-Definition.
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_SolarEdgeDriver — SolarEdge-Wechselrichter über SunSpec. Registeradressen
// sind bei SolarEdge in der Praxis stabil (Common Block ab 40000), es wird
// trotzdem dieselbe Laufzeit-Discovery wie bei Fronius/SMA verwendet — das
// funktioniert für sowohl feste als auch dynamische Layouts gleichermaßen.
// Registerauswahl/-offsets 2026-07-16 gegen eine community-getestete
// SolarEdge-Modbus-Vorlage aus dem IP-Symcon-Forum verifiziert.
// ---------------------------------------------------------------------------

class IHUB_SolarEdgeDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        1 => 'Aus', 2 => 'Auto-Shutdown', 3 => 'Startet', 4 => 'Normal (MPPT)',
        5 => 'Leistungsreduktion', 6 => 'Schaltet ab', 7 => 'Fehler', 8 => 'Standby',
    ];

    private function findModel($mb, $wantedModelId)
    {
        $addr = 40002;
        for ($i = 0; $i < 20; $i++) {
            $hdr = $mb->readHolding($addr, 2);
            if ($hdr === null) {
                return null;
            }
            $modelId = $mb->u16($hdr, 0);
            $len     = $mb->u16($hdr, 1);
            if ($modelId === 0xFFFF) {
                return null;
            }
            if ($modelId === $wantedModelId) {
                return [$addr + 2, $len];
            }
            $addr += 2 + $len;
        }
        return null;
    }

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'SLE.Status',      true,  'device', 'SunSpec St'],
            ['ac_power',  'AC Wirkleistung',   'F', 'SLE.Watt',        true,  'device', 'SunSpec W (Model 101/103)'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'SLE.Watt',        true,  'pv',     'SunSpec DCW (Model 101/103)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'SLE.Volt',   false, 'grid', 'SunSpec PhVphA (Model 101/103)'],
                ['grid_curr', 'Netz Strom',     'F', 'SLE.Ampere', false, 'grid', 'SunSpec A (Model 101/103)'],
                ['grid_freq', 'Netzfrequenz',   'F', 'SLE.Hertz',  false, 'grid', 'SunSpec Hz (Model 101/103)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Gesamtertrag)', 'vars' => [
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'SunSpec WH (Model 101/103)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_cab', 'Kühlkörpertemperatur', 'F', '~Temperature', false, 'device', 'SunSpec TmpSnk (Model 101/103)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung)', 'vars' => [
                ['meter_total', 'Netz Leistung (Meter)', 'F', 'SLE.Watt', true, 'meter', 'SunSpec Meter Model 201/203'],
            ]],
            'GroupMeterEnergy' => ['caption' => 'Zähler-Energie (Bezug / Einspeisung)', 'vars' => [
                ['meter_imp', 'Bezug Gesamt',       'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhImp (Offset 44)'],
                ['meter_exp', 'Einspeisung Gesamt', 'F', '~Electricity', true, 'meter', 'SunSpec Meter TotWhExp (Offset 36)'],
            ]],
            'GroupBattery' => ['caption' => 'Batterie (StorEdge)', 'vars' => [
                ['bat_soc',    'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'SE 0xE184 (Float32, LE)'],
                ['bat_power',  'Bat. Leistung',   'F', 'SLE.Watt',     true,  'bat', 'SE 0xE174 (Float32, LE)'],
                ['bat_volt',   'Bat. Spannung',   'F', 'SLE.Volt',     false, 'bat', 'SE 0xE170 (Float32, LE)'],
                ['bat_curr',   'Bat. Strom',      'F', 'SLE.Ampere',   false, 'bat', 'SE 0xE172 (Float32, LE)'],
                ['bat_temp',   'Bat. Temperatur', 'F', '~Temperature', false, 'bat', 'SE 0xE16C (Float32, LE)'],
                ['bat_soh',    'Bat. SOH',        'I', '~Battery.100', true,  'bat', 'SE 0xF582 (Float32, LE)'],
                ['bat_status', 'Bat. Zustand',    'I', 'SLE.BatState', true,  'bat', 'SE 0xE186'],
            ]],
            'GroupPvReal' => ['caption' => 'PV-Erzeugung berechnet (StorEdge; benötigt Batteriegruppe)', 'vars' => [
                ['pv_real', 'PV-Erzeugung (berechnet)', 'F', 'SLE.Watt', true, 'pv', 'PV-Gesamt + Batterieleistung, ≥ 0'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell', 'S', '', false, 'device', 'SunSpec Common Block'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'SunSpec Common Block'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SLE.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'SLE.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'SLE.Ampere' => [VARIABLETYPE_FLOAT, ' A',       0.0,   200.0, 0.1,  1],
            'SLE.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        // SolarEdge StorEdge Speicherstatus (Reg 0xE186).
        $batState = [
            1 => ['Aus',        0x7A8A99],
            3 => ['Laden',      0x3BA55D],
            4 => ['Entladen',   0xE08A2B],
            6 => ['Ruhemodus',  0x7A8A99],
        ];
        return ['SLE.Status' => $status, 'SLE.BatState' => $batState];
    }

    public function readFast($mb, $hub)
    {
        $inv = $this->findModel($mb, 111) ?: $this->findModel($mb, 112) ?: $this->findModel($mb, 113);
        $isFloat = ($inv !== null);
        if ($inv === null) {
            $inv = $this->findModel($mb, 101) ?: $this->findModel($mb, 102) ?: $this->findModel($mb, 103);
        }

        $ok = ($inv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        [$base, $len] = $inv;
        $blk = $mb->readHolding($base, min($len, 60));
        if ($blk === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }

        // Für die berechnete PV-Erzeugung (StorEdge-Eigenart, s. u.) merken wir
        // uns PV-Gesamtleistung und Batterieleistung.
        $pvTotalVal = 0.0;
        $batPowerVal = 0.0;

        // Offsets siehe IHUB_FroniusDriver/IHUB_SmaDriver (identische SunSpec-Modelle),
        // zusätzlich gegen eine reale SolarEdge-Registertabelle verifiziert.
        if ($isFloat) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($blk, 20));
            $hub->SetVarInt('status', $mb->u16($blk, 46));
            $pvTotalVal = $mb->readFloat32($blk, 36);
            $hub->SetVarFloat('pv_total', $pvTotalVal);
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->readFloat32($blk, 0));
                $hub->SetVarFloat('grid_volt', $mb->readFloat32($blk, 14));
                $hub->SetVarFloat('grid_freq', $mb->readFloat32($blk, 22));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->readFloat32($blk, 30) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->readFloat32($blk, 40));
            }
        } else {
            // SunSpec-Integer-Modell 103: Jeder Messwert ist ein int16/uint16
            // mit separatem Skalierungsfaktor-Register (sunssf, 10^SF). SolarEdge
            // liefert z. B. Spannung/Strom mit SF -1 - ohne Anwenden erscheinen
            // sie um Faktor 10 zu groß (225 V -> 2250 V, 28,9 A -> 289 A).
            $hub->SetVarFloat('ac_power', $mb->s16($blk, 12) * $this->sf($mb, $blk, 13));
            $hub->SetVarInt('status', $mb->u16($blk, 36));
            $pvTotalVal = $mb->s16($blk, 29) * $this->sf($mb, $blk, 30);
            $hub->SetVarFloat('pv_total', $pvTotalVal);
            if ($hub->GetPropBool('GroupGrid')) {
                $hub->SetVarFloat('grid_curr', $mb->u16($blk, 0) * $this->sf($mb, $blk, 4));
                $hub->SetVarFloat('grid_volt', $mb->u16($blk, 8) * $this->sf($mb, $blk, 11));
                $hub->SetVarFloat('grid_freq', $mb->u16($blk, 14) * $this->sf($mb, $blk, 15));
            }
            if ($hub->GetPropBool('GroupEnergy')) {
                $hub->SetVarFloat('e_total', $mb->u32($blk, 22) * $this->sf($mb, $blk, 24) / 1000.0);
            }
            if ($hub->GetPropBool('GroupTemp')) {
                $hub->SetVarFloat('temp_cab', $mb->s16($blk, 32) * $this->sf($mb, $blk, 35));
            }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $meter = $this->findModel($mb, 201) ?: $this->findModel($mb, 203) ?: $this->findModel($mb, 211) ?: $this->findModel($mb, 213);
            if ($meter !== null) {
                [$mtbase, $mtlen] = $meter;
                $mtblk = $mb->readHolding($mtbase, min($mtlen, 54));
                if ($mtblk !== null) {
                    // Zähler-Modell 20x: W (gesamt) bei Offset 16, W_SF bei 20.
                    $hub->SetVarFloat('meter_total', $mb->s16($mtblk, 16) * $this->sf($mb, $mtblk, 20));
                    // Energie-Zähler: TotWhExp bei Offset 36, TotWhImp bei 44,
                    // gemeinsamer TotWh_SF bei 52 (uint32, in Wh -> kWh /1000).
                    if ($hub->GetPropBool('GroupMeterEnergy')) {
                        $whsf = $this->sf($mb, $mtblk, 52);
                        $hub->SetVarFloat('meter_exp', $mb->u32($mtblk, 36) * $whsf / 1000.0);
                        $hub->SetVarFloat('meter_imp', $mb->u32($mtblk, 44) * $whsf / 1000.0);
                    }
                }
            }
        }

        if ($hub->GetPropBool('GroupBattery')) {
            // SolarEdge StorEdge: Batterie-1-Block ab 0xE100 (57600). Die
            // Float32-Momentanwerte liegen im Bereich 0xE16C..0xE184 und sind -
            // anders als der SunSpec-Inverter-Block (ABCD) - little-endian
            // (CDAB, Wort-Swap). Daher hier gezielt umschalten.
            $mb->setFloatWordSwap(true);
            $bat = $mb->readHolding(57708, 28); // 0xE16C .. 0xE187 (inkl. Status)
            if ($bat !== null) {
                $hub->SetVarFloat('bat_temp', $mb->readFloat32($bat, 0));   // 0xE16C
                $hub->SetVarFloat('bat_volt', $mb->readFloat32($bat, 4));   // 0xE170
                // Vorzeichen: SolarEdge meldet Batterie-Strom/-Leistung mit
                // + = Laden (0xE172/0xE174). Modul-Konvention ist + = Entladen /
                // − = Laden (damit die Kachel-Flussrichtung stimmt) -> negieren.
                $hub->SetVarFloat('bat_curr', -$mb->readFloat32($bat, 6));  // 0xE172
                $batChargeW  = $mb->readFloat32($bat, 8);                   // 0xE174 (+ = Laden)
                $batPowerVal = -$batChargeW;                               // + = Entladen
                $hub->SetVarFloat('bat_power', $batPowerVal);
                $hub->SetVarInt('bat_soc', (int)round($mb->readFloat32($bat, 24))); // 0xE184
                // Speicherstatus (0xE186, uint32): Wert 1-6 liegt im niederw.
                // Wort, das bei CDAB zuerst kommt -> u16 am Offset 26.
                $hub->SetVarInt('bat_status', $mb->u16($bat, 26));          // 0xE186

                // Berechnete PV-Erzeugung (optional). StorEdge-Eigenart: Das
                // DC-Leistungsregister spiegelt bei Batteriebetrieb nicht die
                // reine PV-Erzeugung wider. Formel des Testers (am realen Gerät
                // bewährt): PV-Erzeugung = PV-Gesamtleistung + Batterie-
                // Ladeleistung, nie negativ. Benötigt die aktive Batteriegruppe.
                if ($hub->GetPropBool('GroupPvReal')) {
                    $hub->SetVarFloat('pv_real', max(0.0, $pvTotalVal + $batChargeW));
                }
            }
            // SOH liegt in einem separaten Block (0xF582 = 62850), Float32 CDAB.
            $soh = $mb->readHolding(62850, 2);
            if ($soh !== null) {
                $hub->SetVarInt('bat_soh', (int)round($mb->readFloat32($soh, 0)));
            }
            $mb->setFloatWordSwap(false);
        }

        return true;
    }

    // 10^SF aus einem SunSpec-Skalierungsfaktor-Register. Nicht implementierte
    // SF liefern den Sentinel 0x8000 (-32768) bzw. unplausible Werte - dann 1.0.
    private function sf($mb, $blk, $off)
    {
        $e = $mb->s16($blk, $off);
        if ($e < -10 || $e > 10) {
            return 1.0;
        }
        return pow(10, $e);
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Inverter-Model in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        $common = $this->findModel($mb, 1);
        if ($common === null) {
            return;
        }
        [$base, $len] = $common;
        $blk = $mb->readHolding($base, min($len, 66));
        if ($blk === null) {
            return;
        }
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 16, 16));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 48, 16));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_DeyeDriver — Deye Hybrid-Wechselrichter (SG04LP3-Serie). Direkte
// Registeradressierung, ausschließlich Einzelregister (FC 0x03).
// Register 2026-07-16 gegen eine community-getestete Deye-Modbus-Vorlage
// aus dem IP-Symcon-Forum übernommen (getestet an einem Deye 8K-SG04LP3).
// ---------------------------------------------------------------------------

class IHUB_DeyeDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', '',                true,  'device', 'RO 500'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'DYE.Watt',        true,  'pv',     'Σ RO 672+673'],
            ['bat_power', 'Bat. Leistung',     'F', 'DYE.Watt',        true,  'bat',    'RO 590'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (String 1+2)', 'vars' => [
                ['pv1_power', 'PV1 Leistung', 'F', 'DYE.Watt',   true,  'pv', 'RO 672'],
                ['pv1_volt',  'PV1 Spannung', 'F', 'DYE.Volt',   false, 'pv', 'RO 676'],
                ['pv1_curr',  'PV1 Strom',    'F', 'DYE.Ampere', false, 'pv', 'RO 677'],
                ['pv2_power', 'PV2 Leistung', 'F', 'DYE.Watt',   true,  'pv', 'RO 673'],
                ['pv2_volt',  'PV2 Spannung', 'F', 'DYE.Volt',   false, 'pv', 'RO 678'],
                ['pv2_curr',  'PV2 Strom',    'F', 'DYE.Ampere', false, 'pv', 'RO 679'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom je Phase, Frequenz)', 'vars' => [
                ['grid_l1_volt', 'Netz L1 Spannung', 'F', 'DYE.Volt',   false, 'grid', 'RO 598'],
                ['grid_l2_volt', 'Netz L2 Spannung', 'F', 'DYE.Volt',   false, 'grid', 'RO 599'],
                ['grid_l3_volt', 'Netz L3 Spannung', 'F', 'DYE.Volt',   false, 'grid', 'RO 600'],
                ['grid_l1_curr', 'Netz L1 Strom',    'F', 'DYE.Ampere', false, 'grid', 'RO 616'],
                ['grid_l2_curr', 'Netz L2 Strom',    'F', 'DYE.Ampere', false, 'grid', 'RO 617'],
                ['grid_l3_curr', 'Netz L3 Strom',    'F', 'DYE.Ampere', false, 'grid', 'RO 618'],
                ['grid_total',   'Netzbezug Gesamt',  'F', 'DYE.Watt',   true,  'grid', 'RO 619'],
                ['grid_freq',    'Netzfrequenz',      'F', 'DYE.Hertz',  false, 'grid', 'RO 638'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOC, Temperatur)', 'vars' => [
                ['bat_volt', 'Bat. Spannung',   'F', 'DYE.Volt',     false, 'bat', 'RO 587'],
                ['bat_soc',  'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 588'],
                ['bat_curr', 'Bat. Strom',      'F', 'DYE.Ampere',   false, 'bat', 'RO 591'],
                ['bat_temp', 'Bat. Temperatur', 'F', '~Temperature', true,  'bat', 'RO 586'],
            ]],
            'GroupLoad' => ['caption' => 'Hausverbrauch (Last)', 'vars' => [
                ['load_l1',    'Last L1',      'F', 'DYE.Watt', true, 'device', 'RO 650'],
                ['load_l2',    'Last L2',      'F', 'DYE.Watt', true, 'device', 'RO 651'],
                ['load_l3',    'Last L3',      'F', 'DYE.Watt', true, 'device', 'RO 652'],
                ['load_total', 'Last Gesamt',  'F', 'DYE.Watt', true, 'device', 'RO 653'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Gesamt)', 'vars' => [
                ['e_charge_day',    'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'RO 514'],
                ['e_disch_day',     'Bat. Entladen Heute', 'F', '~Electricity', true, 'energy', 'RO 515'],
                ['e_charge_total',  'Bat. Laden Gesamt',   'F', '~Electricity', true, 'energy', 'RO 516'],
                ['e_disch_total',   'Bat. Entladen Gesamt','F', '~Electricity', true, 'energy', 'RO 518'],
                ['e_buy_day',       'Netzbezug Heute',     'F', '~Electricity', true, 'energy', 'RO 520'],
                ['e_sell_day',      'Einspeisung Heute',   'F', '~Electricity', true, 'energy', 'RO 521'],
                ['e_buy_total',     'Netzbezug Gesamt',    'F', '~Electricity', true, 'energy', 'RO 522'],
                ['e_sell_total',    'Einspeisung Gesamt',  'F', '~Electricity', true, 'energy', 'RO 524'],
                ['e_load_day',      'Hausverbrauch Heute', 'F', '~Electricity', true, 'energy', 'RO 526'],
                ['e_load_total',    'Hausverbrauch Gesamt','F', '~Electricity', true, 'energy', 'RO 527'],
                ['e_pv_day',        'PV Heute',            'F', '~Electricity', true, 'energy', 'RO 529'],
                ['e_pv_total',      'PV Gesamt',           'F', '~Electricity', true, 'energy', 'RO 534'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung', 'vars' => [
                ['ctl_onoff', 'Wechselrichter Ein/Aus', 'B', '~Switch', false, 'control', 'RW 80'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'DYE.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'DYE.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'DYE.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'DYE.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $status = $mb->readHolding(500, 1);
        $pv     = $mb->readHolding(672, 8);   // 672-679: PV1/2 power,volt,curr interleaved
        $bat    = $mb->readHolding(586, 6);   // 586-591: temp,volt,soc,-,power,current
        $grid   = $mb->readHolding(598, 3);   // 598-600: L1-L3 Spannung
        $gcurr  = $mb->readHolding(616, 4);   // 616-619: L1-L3 Strom + Gesamt
        $freq   = $mb->readHolding(638, 1);
        $load   = $mb->readHolding(650, 4);   // 650-653

        $ok = ($status !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($status, 0));

        $pv1w = ($pv !== null) ? $mb->s16($pv, 0) : 0;
        $pv2w = ($pv !== null) ? $mb->s16($pv, 1) : 0;
        $hub->SetVarFloat('pv_total', (float)($pv1w + $pv2w));

        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s16($bat, 4));
        }

        if ($hub->GetPropBool('GroupPV') && $pv !== null) {
            $hub->SetVarFloat('pv1_power', (float)$pv1w);
            $hub->SetVarFloat('pv1_volt',  $mb->u16($pv, 4) / 10.0);
            $hub->SetVarFloat('pv1_curr',  $mb->u16($pv, 5) / 10.0);
            $hub->SetVarFloat('pv2_power', (float)$pv2w);
            $hub->SetVarFloat('pv2_volt',  $mb->u16($pv, 6) / 10.0);
            $hub->SetVarFloat('pv2_curr',  $mb->u16($pv, 7) / 10.0);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            if ($grid !== null) {
                $hub->SetVarFloat('grid_l1_volt', $mb->u16($grid, 0) / 10.0);
                $hub->SetVarFloat('grid_l2_volt', $mb->u16($grid, 1) / 10.0);
                $hub->SetVarFloat('grid_l3_volt', $mb->u16($grid, 2) / 10.0);
            }
            if ($gcurr !== null) {
                $hub->SetVarFloat('grid_l1_curr', (float)$mb->s16($gcurr, 0));
                $hub->SetVarFloat('grid_l2_curr', (float)$mb->s16($gcurr, 1));
                $hub->SetVarFloat('grid_l3_curr', (float)$mb->s16($gcurr, 2));
                $hub->SetVarFloat('grid_total',   (float)$mb->s16($gcurr, 3));
            }
            if ($freq !== null) {
                $hub->SetVarFloat('grid_freq', $mb->u16($freq, 0) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_temp', $mb->s16($bat, 0) / 10.0);
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 1) / 100.0);
            $hub->SetVarInt('bat_soc',    $mb->u16($bat, 2));
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 5) / 100.0);
        }

        if ($hub->GetPropBool('GroupLoad') && $load !== null) {
            $hub->SetVarFloat('load_l1',    (float)$mb->s16($load, 0));
            $hub->SetVarFloat('load_l2',    (float)$mb->s16($load, 1));
            $hub->SetVarFloat('load_l3',    (float)$mb->s16($load, 2));
            $hub->SetVarFloat('load_total', (float)$mb->s16($load, 3));
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e1 = $mb->readHolding(514, 11); // 514-524 (mit Lücken)
        if ($e1 !== null) {
            $hub->SetVarFloat('e_charge_day',   $mb->u16($e1, 0) / 10.0);
            $hub->SetVarFloat('e_disch_day',    $mb->u16($e1, 1) / 10.0);
            $hub->SetVarFloat('e_charge_total', $mb->u16($e1, 2) / 10.0);
            $hub->SetVarFloat('e_disch_total',  $mb->u16($e1, 4) / 10.0);
            $hub->SetVarFloat('e_buy_day',      $mb->u16($e1, 6) / 10.0);
            $hub->SetVarFloat('e_sell_day',     $mb->u16($e1, 7) / 10.0);
            $hub->SetVarFloat('e_buy_total',    $mb->u16($e1, 8) / 10.0);
            $hub->SetVarFloat('e_sell_total',   $mb->u16($e1, 10) / 10.0);
        }
        $e2 = $mb->readHolding(526, 9); // 526-534 (mit Lücken)
        if ($e2 !== null) {
            $hub->SetVarFloat('e_load_day',   $mb->u16($e2, 0) / 10.0);
            $hub->SetVarFloat('e_load_total', $mb->u16($e2, 1) / 10.0);
            $hub->SetVarFloat('e_pv_day',     $mb->u16($e2, 3) / 10.0);
            $hub->SetVarFloat('e_pv_total',   $mb->u16($e2, 8) / 10.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        if ($ident === 'ctl_onoff') {
            $val = (bool)$value ? 1 : 0;
            if ($mb->writeSingle(80, $val)) {
                $hub->SetVarBool('ctl_onoff', (bool)$value);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// IHUB_SolplanetDriver — Solplanet/AISWEI ASW-Gen-Serie. Direkte Adressierung,
// Read Input Register (FC 0x04). Register 2026-07-16 gegen eine community-
// getestete Solplanet-Modbus-Vorlage aus dem IP-Symcon-Forum übernommen.
// ---------------------------------------------------------------------------

class IHUB_SolplanetDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV Gesamtleistung', 'F', 'SPL.Watt',        true,  'pv',     'RO 1600'],
            ['ac_power',  'AC Wirkleistung',   'F', 'SPL.Watt',        true,  'device', 'RO 1370'],
            ['bat_power', 'Bat. Leistung',     'F', 'SPL.Watt',        true,  'bat',    'RO 1618'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (String 1-3)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'SPL.Volt',   false, 'pv', 'RO 1318'],
                ['pv1_curr', 'PV1 Strom',    'F', 'SPL.Ampere', false, 'pv', 'RO 1319'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'SPL.Volt',   false, 'pv', 'RO 1320'],
                ['pv2_curr', 'PV2 Strom',    'F', 'SPL.Ampere', false, 'pv', 'RO 1321'],
                ['pv3_volt', 'PV3 Spannung', 'F', 'SPL.Volt',   false, 'pv', 'RO 1322'],
                ['pv3_curr', 'PV3 Strom',    'F', 'SPL.Ampere', false, 'pv', 'RO 1323'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOC, SOH, Temperatur)', 'vars' => [
                ['bat_volt', 'Bat. Spannung',   'F', 'SPL.Volt',       false, 'bat', 'RO 1616'],
                ['bat_curr', 'Bat. Strom',      'F', 'SPL.Ampere',     false, 'bat', 'RO 1617'],
                ['bat_soc',  'Bat. SOC',        'I', '~Battery.100',   true,  'bat', 'RO 1621'],
                ['bat_soh',  'Bat. SOH',        'I', '~Intensity.100', true,  'bat', 'RO 1622'],
                ['bat_temp', 'Bat. Temperatur', 'F', '~Temperature',   true,  'bat', 'RO 1620'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_internal', 'Innentemperatur', 'F', '~Temperature', false, 'device', 'RO 1310'],
                ['temp_env',      'Umgebungstemperatur', 'F', '~Temperature', false, 'device', 'RO 1680'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Gesamt)', 'vars' => [
                ['e_pv_day',      'PV Heute',            'F', '~Electricity', true, 'energy', 'RO 1602'],
                ['e_pv_total',    'PV Gesamt',           'F', '~Electricity', true, 'energy', 'RO 1604'],
                ['e_inv_day',     'Wechselrichter Heute','F', '~Electricity', true, 'energy', 'RO 1302'],
                ['e_inv_total',   'Wechselrichter Gesamt','F', '~Electricity', true, 'energy', 'RO 1304'],
                ['e_charge_day',  'Bat. Laden Heute',    'F', '~Electricity', true, 'energy', 'RO 1625'],
                ['e_disch_day',   'Bat. Entladen Heute', 'F', '~Electricity', true, 'energy', 'RO 1627'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'SPL.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0, 0],
            'SPL.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1, 1],
            'SPL.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1, 1],
        ];
    }

    public function getEnumProfiles()
    {
        return [];
    }

    public function readFast($mb, $hub)
    {
        $sys = $mb->readInput(1368, 4);   // 1368-1371: Apparent(2)+Active(2)
        $pv  = $mb->readInput(1600, 2);   // 1600-1601: PV total power (U32)
        $bat = $mb->readInput(1616, 7);   // 1616-1622

        $ok = ($sys !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('ac_power', (float)$mb->s32($sys, 2));
        if ($pv !== null) {
            $hub->SetVarFloat('pv_total', (float)$mb->u32($pv, 0));
        }
        if ($bat !== null) {
            $hub->SetVarFloat('bat_power', (float)$mb->s32($bat, 2));
        }

        if ($hub->GetPropBool('GroupPV')) {
            $pvd = $mb->readInput(1318, 6);
            if ($pvd !== null) {
                $hub->SetVarFloat('pv1_volt', $mb->u16($pvd, 0) / 10.0);
                $hub->SetVarFloat('pv1_curr', $mb->u16($pvd, 1) / 100.0);
                $hub->SetVarFloat('pv2_volt', $mb->u16($pvd, 2) / 10.0);
                $hub->SetVarFloat('pv2_curr', $mb->u16($pvd, 3) / 100.0);
                $hub->SetVarFloat('pv3_volt', $mb->u16($pvd, 4) / 10.0);
                $hub->SetVarFloat('pv3_curr', $mb->u16($pvd, 5) / 100.0);
            }
        }

        if ($hub->GetPropBool('GroupBat') && $bat !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 0) / 100.0);
            $hub->SetVarFloat('bat_curr', $mb->s16($bat, 1) / 10.0);
            $hub->SetVarInt('bat_soc',    $mb->u16($bat, 5));
            $hub->SetVarInt('bat_soh',    $mb->u16($bat, 6));
        }
        if ($hub->GetPropBool('GroupBat')) {
            $batTemp = $mb->readInput(1620, 1);
            if ($batTemp !== null) {
                $hub->SetVarFloat('bat_temp', $mb->s16($batTemp, 0) / 10.0);
            }
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $t1 = $mb->readInput(1310, 1);
            if ($t1 !== null) {
                $hub->SetVarFloat('temp_internal', $mb->s16($t1, 0) / 10.0);
            }
            $t2 = $mb->readInput(1680, 1);
            if ($t2 !== null) {
                $hub->SetVarFloat('temp_env', $mb->u16($t2, 0) / 10.0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e1 = $mb->readInput(1302, 4); // 1302-1305
        if ($e1 !== null) {
            $hub->SetVarFloat('e_inv_day',   $mb->u32($e1, 0) / 10.0);
            $hub->SetVarFloat('e_inv_total', $mb->u32($e1, 2) / 10.0);
        }
        $e2 = $mb->readInput(1602, 4); // 1602-1605
        if ($e2 !== null) {
            $hub->SetVarFloat('e_pv_day',   $mb->u32($e2, 0) / 10.0);
            $hub->SetVarFloat('e_pv_total', $mb->u32($e2, 2) / 10.0);
        }
        $e3 = $mb->readInput(1625, 4); // 1625-1628
        if ($e3 !== null) {
            $hub->SetVarFloat('e_charge_day', $mb->u32($e3, 0) / 10.0);
            $hub->SetVarFloat('e_disch_day',  $mb->u32($e3, 2) / 10.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Kein separates Geräteinfo-Register in der ersten Ausbaustufe gelesen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_KostalDriver — Kostal PLENTICORE plus (Generation 1). Direkte Adressierung,
// Float32-Register (FC 0x03), Wert bereits in physikalischer SI-Einheit
// (kein separates Skalierungsfaktor-Register wie bei SunSpec Int+SF).
// Register 2026-07-16 gegen eine community-getestete Kostal-Modbus-Vorlage
// aus dem IP-Symcon-Forum übernommen.
// ---------------------------------------------------------------------------

class IHUB_KostalDriver implements IHUB_InverterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV Gesamtleistung (DC)', 'F', 'KST.Watt',   true,  'pv',     'RO 100 (Float32)'],
            ['ac_power',  'AC Wirkleistung Gesamt',  'F', 'KST.Watt',  true,  'device', 'RO 172 (Float32)'],
            ['riso',      'Isolationswiderstand', 'F', 'KST.KOhm',    true,  'pv',     'RO 120 (Float32, Ohm ÷1000)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (DC-Eingang 1-3)', 'vars' => [
                ['pv1_curr',  'PV1 Strom',    'F', 'KST.Ampere', false, 'pv', 'RO 258 (Float32)'],
                ['pv1_power', 'PV1 Leistung', 'F', 'KST.Watt',   true,  'pv', 'RO 260 (Float32)'],
                ['pv1_volt',  'PV1 Spannung', 'F', 'KST.Volt',   false, 'pv', 'RO 266 (Float32)'],
                ['pv2_curr',  'PV2 Strom',    'F', 'KST.Ampere', false, 'pv', 'RO 268 (Float32)'],
                ['pv2_power', 'PV2 Leistung', 'F', 'KST.Watt',   true,  'pv', 'RO 270 (Float32)'],
                ['pv2_volt',  'PV2 Spannung', 'F', 'KST.Volt',   false, 'pv', 'RO 276 (Float32)'],
                ['pv3_curr',  'PV3 Strom',    'F', 'KST.Ampere', false, 'pv', 'RO 278 (Float32)'],
                ['pv3_power', 'PV3 Leistung', 'F', 'KST.Watt',   true,  'pv', 'RO 280 (Float32)'],
                ['pv3_volt',  'PV3 Spannung', 'F', 'KST.Volt',   false, 'pv', 'RO 286 (Float32)'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Leistung je Phase, Frequenz)', 'vars' => [
                ['grid_freq',    'Netzfrequenz',      'F', 'KST.Hertz',  false, 'grid', 'RO 152 (Float32)'],
                ['grid_l1_curr', 'Netz L1 Strom',     'F', 'KST.Ampere', false, 'grid', 'RO 154 (Float32)'],
                ['grid_l1_pwr',  'Netz L1 Leistung',  'F', 'KST.Watt',   true,  'grid', 'RO 156 (Float32)'],
                ['grid_l1_volt', 'Netz L1 Spannung',  'F', 'KST.Volt',   false, 'grid', 'RO 158 (Float32)'],
                ['grid_l2_curr', 'Netz L2 Strom',     'F', 'KST.Ampere', false, 'grid', 'RO 160 (Float32)'],
                ['grid_l2_pwr',  'Netz L2 Leistung',  'F', 'KST.Watt',   true,  'grid', 'RO 162 (Float32)'],
                ['grid_l2_volt', 'Netz L2 Spannung',  'F', 'KST.Volt',   false, 'grid', 'RO 164 (Float32)'],
                ['grid_l3_curr', 'Netz L3 Strom',     'F', 'KST.Ampere', false, 'grid', 'RO 166 (Float32)'],
                ['grid_l3_pwr',  'Netz L3 Leistung',  'F', 'KST.Watt',   true,  'grid', 'RO 168 (Float32)'],
                ['grid_l3_volt', 'Netz L3 Spannung',  'F', 'KST.Volt',   false, 'grid', 'RO 170 (Float32)'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Leistung, Spannung, Strom, SOC, Temperatur, Zustand)', 'vars' => [
                ['bat_soc',    'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 210 (Float32)'],
                ['bat_power',  'Bat. Leistung (Entladen + / Laden -)', 'F', 'KST.Watt', true, 'bat', 'RO 582 (SInt16)'],
                ['bat_status', 'Bat. Zustand',    'I', 'KST.BatState', true,  'bat', 'abgeleitet aus RO 582'],
                ['bat_temp', 'Bat. Temperatur', 'F', '~Temperature', true,  'bat', 'RO 214 (Float32)'],
                ['bat_volt', 'Bat. Spannung',   'F', 'KST.Volt',     false, 'bat', 'RO 216 (Float32)'],
                ['bat_curr', 'Bat. Strom (Laden -/Entladen +)', 'F', 'KST.Ampere', false, 'bat', 'RO 200 (Float32)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung je Phase)', 'vars' => [
                ['meter_l1_pwr',  'Meter L1 Leistung', 'F', 'KST.Watt', true, 'meter', 'RO 224 (Float32)'],
                ['meter_l2_pwr',  'Meter L2 Leistung', 'F', 'KST.Watt', true, 'meter', 'RO 234 (Float32)'],
                ['meter_l3_pwr',  'Meter L3 Leistung', 'F', 'KST.Watt', true, 'meter', 'RO 244 (Float32)'],
                ['meter_total',   'Meter Gesamtleistung', 'F', 'KST.Watt', true, 'meter', 'RO 252 (Float32)'],
            ]],
            'GroupHome' => ['caption' => 'Hausverbrauch (aufgeteilt nach Quelle)', 'vars' => [
                ['home_total',    'Hausverbrauch Gesamt',       'F', '~Electricity', true, 'device', 'RO 118 (Float32, Wh)'],
                ['home_from_pv',  'Hausverbrauch aus PV',       'F', 'KST.Watt', true, 'device', 'RO 116 (Float32)'],
                ['home_from_bat', 'Hausverbrauch aus Batterie', 'F', 'KST.Watt', true, 'device', 'RO 106 (Float32)'],
                ['home_from_grid','Hausverbrauch aus Netz',     'F', 'KST.Watt', true, 'device', 'RO 108 (Float32)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Monat/Jahr/Gesamt)', 'vars' => [
                ['e_total',   'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'RO 320 (Float32, Wh)'],
                ['e_day',     'Ertrag Heute',  'F', '~Electricity', true, 'energy', 'RO 322 (Float32, Wh)'],
                ['e_year',    'Ertrag Jahr',   'F', '~Electricity', true, 'energy', 'RO 324 (Float32, Wh)'],
                ['e_month',   'Ertrag Monat',  'F', '~Electricity', true, 'energy', 'RO 326 (Float32, Wh)'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_name', 'Produktname', 'S', '', false, 'device', 'RO 768 (String)'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'KST.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'KST.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'KST.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'KST.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
            'KST.KOhm'   => [VARIABLETYPE_FLOAT, ' kΩ',      0.0, 100000.0, 1.0, 0],
        ];
    }

    public function getEnumProfiles()
    {
        return [
            'KST.BatState' => [
                0 => ['Ruhe',     0x7A8A99],
                1 => ['Laden',    0x3BA55D],
                2 => ['Entladen', 0xE08A2B],
            ],
        ];
    }

    public function readFast($mb, $hub)
    {
        $mb->setFloatWordSwap($hub->GetKostalWordSwap());
        $pv  = $mb->readHolding(100, 2);
        $ac  = $mb->readHolding(172, 2);

        $ok = ($pv !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', $mb->readFloat32($pv, 0));
        if ($ac !== null) {
            $hub->SetVarFloat('ac_power', $mb->readFloat32($ac, 0));
        }
        // Isolationswiderstand (Reg 120, Float32 in Ohm -> kΩ). Wort-Swap greift
        // über die bereits gesetzte Byte-Reihenfolge.
        $risoBlk = $mb->readHolding(120, 2);
        if ($risoBlk !== null) {
            $hub->SetVarFloat('riso', $mb->readFloat32($risoBlk, 0) / 1000.0);
        }

        if ($hub->GetPropBool('GroupPV')) {
            $dc1 = $mb->readHolding(258, 2);
            $dc1p = $mb->readHolding(260, 2);
            $dc1v = $mb->readHolding(266, 2);
            $dc2 = $mb->readHolding(268, 2);
            $dc2p = $mb->readHolding(270, 2);
            $dc2v = $mb->readHolding(276, 2);
            $dc3 = $mb->readHolding(278, 2);
            $dc3p = $mb->readHolding(280, 2);
            $dc3v = $mb->readHolding(286, 2);
            if ($dc1 !== null)  { $hub->SetVarFloat('pv1_curr',  $mb->readFloat32($dc1, 0)); }
            if ($dc1p !== null) { $hub->SetVarFloat('pv1_power', $mb->readFloat32($dc1p, 0)); }
            if ($dc1v !== null) { $hub->SetVarFloat('pv1_volt',  $mb->readFloat32($dc1v, 0)); }
            if ($dc2 !== null)  { $hub->SetVarFloat('pv2_curr',  $mb->readFloat32($dc2, 0)); }
            if ($dc2p !== null) { $hub->SetVarFloat('pv2_power', $mb->readFloat32($dc2p, 0)); }
            if ($dc2v !== null) { $hub->SetVarFloat('pv2_volt',  $mb->readFloat32($dc2v, 0)); }
            if ($dc3 !== null)  { $hub->SetVarFloat('pv3_curr',  $mb->readFloat32($dc3, 0)); }
            if ($dc3p !== null) { $hub->SetVarFloat('pv3_power', $mb->readFloat32($dc3p, 0)); }
            if ($dc3v !== null) { $hub->SetVarFloat('pv3_volt',  $mb->readFloat32($dc3v, 0)); }
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $grid = $mb->readHolding(152, 20); // 152-171
            if ($grid !== null) {
                $hub->SetVarFloat('grid_freq',    $mb->readFloat32($grid, 0));
                $hub->SetVarFloat('grid_l1_curr', $mb->readFloat32($grid, 2));
                $hub->SetVarFloat('grid_l1_pwr',  $mb->readFloat32($grid, 4));
                $hub->SetVarFloat('grid_l1_volt', $mb->readFloat32($grid, 6));
                $hub->SetVarFloat('grid_l2_curr', $mb->readFloat32($grid, 8));
                $hub->SetVarFloat('grid_l2_pwr',  $mb->readFloat32($grid, 10));
                $hub->SetVarFloat('grid_l2_volt', $mb->readFloat32($grid, 12));
                $hub->SetVarFloat('grid_l3_curr', $mb->readFloat32($grid, 14));
                $hub->SetVarFloat('grid_l3_pwr',  $mb->readFloat32($grid, 16));
                $hub->SetVarFloat('grid_l3_volt', $mb->readFloat32($grid, 18));
            }
        }

        if ($hub->GetPropBool('GroupBat')) {
            $soc  = $mb->readHolding(210, 2);
            $temp = $mb->readHolding(214, 2);
            $volt = $mb->readHolding(216, 2);
            $curr = $mb->readHolding(200, 2);
            if ($soc !== null)  { $hub->SetVarInt('bat_soc', (int)round($mb->readFloat32($soc, 0))); }
            if ($temp !== null) { $hub->SetVarFloat('bat_temp', $mb->readFloat32($temp, 0)); }
            if ($volt !== null) { $hub->SetVarFloat('bat_volt', $mb->readFloat32($volt, 0)); }
            if ($curr !== null) { $hub->SetVarFloat('bat_curr', $mb->readFloat32($curr, 0)); }
            // Bat.-Leistung: Reg 582 ist ein einzelnes SInt16 (W) - kein Float32,
            // daher direkt lesen (Wort-Swap irrelevant). Vorzeichen wie der Strom
            // (Reg 200): + = Entladen, − = Laden. Der Zustand wird daraus abgeleitet.
            $pwr = $mb->readHolding(582, 1);
            if ($pwr !== null) {
                $p = $mb->s16($pwr, 0);
                $hub->SetVarFloat('bat_power', (float)$p);
                $hub->SetVarInt('bat_status', ($p > 10) ? 2 : (($p < -10) ? 1 : 0));
            }
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $m1 = $mb->readHolding(224, 2);
            $m2 = $mb->readHolding(234, 2);
            $m3 = $mb->readHolding(244, 2);
            $mt = $mb->readHolding(252, 2);
            if ($m1 !== null) { $hub->SetVarFloat('meter_l1_pwr', $mb->readFloat32($m1, 0)); }
            if ($m2 !== null) { $hub->SetVarFloat('meter_l2_pwr', $mb->readFloat32($m2, 0)); }
            if ($m3 !== null) { $hub->SetVarFloat('meter_l3_pwr', $mb->readFloat32($m3, 0)); }
            if ($mt !== null) { $hub->SetVarFloat('meter_total',  $mb->readFloat32($mt, 0)); }
        }

        if ($hub->GetPropBool('GroupHome')) {
            $home = $mb->readHolding(106, 14); // 106-118 (mit Lücke bei 120)
            if ($home !== null) {
                $hub->SetVarFloat('home_from_bat',  $mb->readFloat32($home, 0));
                $hub->SetVarFloat('home_from_grid', $mb->readFloat32($home, 2));
                $hub->SetVarFloat('home_from_pv',   $mb->readFloat32($home, 10));
                // Register 118 ist It. offizieller KOSTAL-Doku eine kumulierte
                // Energie in Wh (nicht W wie ursprünglich fälschlich
                // angenommen) - daher Umrechnung auf kWh wie bei allen
                // anderen ~Electricity-Datenpunkten im Modul.
                $hub->SetVarFloat('home_total',     $mb->readFloat32($home, 12) / 1000.0);
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        $mb->setFloatWordSwap($hub->GetKostalWordSwap());
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        // Register 320-327 sind It. offizieller KOSTAL-Doku Wh, nicht kWh -
        // Umrechnung fehlte bisher komplett (Bugfix: Werte erschienen um
        // Faktor 1000 zu groß, z.B. "Milliarden Watt" bei älteren Anlagen
        // mit hohem Gesamtertrag).
        $e = $mb->readHolding(320, 8); // 320-327
        if ($e !== null) {
            $hub->SetVarFloat('e_total', $mb->readFloat32($e, 0) / 1000.0);
            $hub->SetVarFloat('e_day',   $mb->readFloat32($e, 2) / 1000.0);
            $hub->SetVarFloat('e_year',  $mb->readFloat32($e, 4) / 1000.0);
            $hub->SetVarFloat('e_month', $mb->readFloat32($e, 6) / 1000.0);
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $mb->setFloatWordSwap($hub->GetKostalWordSwap());
        $name = $mb->readHolding(768, 16);
        if ($name !== null) {
            $hub->SetVarStr('dev_name', $mb->readStr($name, 0, 16));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert.
    }
}

// ---------------------------------------------------------------------------
// IHUB_HuaweiDriver — Huawei SUN2000 (L1/M1) + DTSU666-Zähler + LUNA2000-Batterie.
// Native Huawei-Registermap (FC 0x03, Big-Endian, int16/int32 mit Gain, kein
// SunSpec). Register/Gain verbatim aus der Bibliothek wlcrs/huawei-solar-lib
// (huawei_solar/registers.py) übernommen. Standard-Unit-ID des Wechselrichters
// ist 1 (je nach Konfiguration auch 0/16), Port 502.
// ---------------------------------------------------------------------------

class IHUB_HuaweiDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        0x0000 => 'Standby: Initialisierung', 0x0001 => 'Standby: Isolationswiderstand',
        0x0002 => 'Standby: Einstrahlung',    0x0003 => 'Standby: Netzerkennung',
        0x0100 => 'Startet',                  0x0200 => 'Netzbetrieb',
        0x0201 => 'Netzbetrieb: leistungsbegrenzt', 0x0202 => 'Netzbetrieb: Eigenderating',
        0x0300 => 'Abschaltung: Fehler',      0x0301 => 'Abschaltung: Befehl',
        0x0304 => 'Abschaltung: leistungsbegrenzt', 0x0306 => 'Abschaltung: DC-Schalter offen',
        0x0308 => 'Abschaltung: Eingang unterversorgt', 0xA000 => 'Standby: keine Einstrahlung',
    ];
    const BAT_STATUS = [
        0 => 'Offline', 1 => 'Standby', 2 => 'Läuft', 3 => 'Fehler', 4 => 'Ruhemodus',
    ];

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'HUA.Status',      true,  'device', 'RO 32089'],
            ['pv_total',  'PV Gesamtleistung (DC-Eingang)', 'F', 'HUA.Watt', true, 'pv',   'RO 32064 (I32)'],
            ['ac_power',  'AC Wirkleistung',   'F', 'HUA.Watt',        true,  'device', 'RO 32080 (I32)'],
            ['riso',      'Isolationswiderstand', 'F', 'HUA.MOhm',     true,  'pv',     'RO 32088 (÷1000, MΩ)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Netz (Spannung, Strom, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung',  'F', 'HUA.Volt',   false, 'grid', 'RO 32069 (÷10)'],
                ['grid_curr', 'Netz Strom',     'F', 'HUA.Ampere', false, 'grid', 'RO 32072 (I32, ÷1000)'],
                ['grid_freq', 'Netzfrequenz',   'F', 'HUA.Hertz',  false, 'grid', 'RO 32085 (÷100)'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_cab', 'Innentemperatur', 'F', '~Temperature', false, 'device', 'RO 32087 (÷10)'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Ertrag Gesamt/Heute)', 'vars' => [
                ['e_total', 'Ertrag Gesamt', 'F', '~Electricity', true, 'energy', 'RO 32106 (U32, ÷100)'],
                ['e_day',   'Ertrag Heute',  'F', '~Electricity', true, 'energy', 'RO 32114 (U32, ÷100)'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (DTSU666)', 'vars' => [
                ['meter_total', 'Netz Leistung (Meter, + Einspeisung / − Bezug)', 'F', 'HUA.Watt', true, 'meter', 'RO 37113 (I32)'],
                ['meter_volt',  'Meter Spannung L1', 'F', 'HUA.Volt',   false, 'meter', 'RO 37101 (I32, ÷10)'],
                ['meter_curr',  'Meter Strom L1',    'F', 'HUA.Ampere', false, 'meter', 'RO 37107 (I32, ÷100)'],
                ['meter_freq',  'Meter Frequenz',    'F', 'HUA.Hertz',  false, 'meter', 'RO 37118 (I16, ÷100)'],
            ]],
            'GroupBattery' => ['caption' => 'Batterie (LUNA2000)', 'vars' => [
                ['bat_soc',    'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'RO 37004 (÷10)'],
                ['bat_power',  'Bat. Leistung (+ Entladen / − Laden)', 'F', 'HUA.Watt', true, 'bat', 'RO 37001 (I32)'],
                ['bat_volt',   'Bat. Spannung',   'F', 'HUA.Volt',    false, 'bat', 'RO 37003 (÷10)'],
                ['bat_curr',   'Bat. Strom',      'F', 'HUA.Ampere',  false, 'bat', 'RO 37021 (I16, ÷10)'],
                ['bat_temp',   'Bat. Temperatur', 'F', '~Temperature', false, 'bat', 'RO 37022 (I16, ÷10)'],
                ['bat_status', 'Bat. Zustand',    'I', 'HUA.BatStatus', true, 'bat', 'RO 37000'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell', 'S', '', false, 'device', 'RO 30000 (String)'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'HUA.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -100000.0, 100000.0, 1.0,  0],
            'HUA.Volt'   => [VARIABLETYPE_FLOAT, ' V',        0.0,   1000.0, 0.1,  1],
            'HUA.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -1000.0,   1000.0, 0.1,  1],
            'HUA.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',      45.0,     65.0, 0.01, 2],
            'HUA.MOhm'   => [VARIABLETYPE_FLOAT, ' MΩ',       0.0,    100.0, 0.001, 3],
        ];
    }

    public function getEnumProfiles()
    {
        $st = [];
        foreach (self::STATUS as $k => $label) {
            $st[$k] = [$label, 0x7A8A99];
        }
        $bs = [];
        foreach (self::BAT_STATUS as $k => $label) {
            $bs[$k] = [$label, 0x7A8A99];
        }
        return ['HUA.Status' => $st, 'HUA.BatStatus' => $bs];
    }

    public function readFast($mb, $hub)
    {
        // Block 32064..32089 (26 Register): Eingangs-/Wirkleistung, Netz, Temp, Status.
        $a = $mb->readHolding(32064, 26);
        $ok = ($a !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarFloat('pv_total', (float)$mb->s32($a, 0));   // 32064 Input power (DC)
        $hub->SetVarFloat('ac_power', (float)$mb->s32($a, 16));  // 32080 Active power
        $hub->SetVarInt('status', $mb->u16($a, 25));             // 32089 Device status
        $hub->SetVarFloat('riso', $mb->u16($a, 24) / 1000.0);    // 32088 Isolationswiderstand (MΩ)

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_volt', $mb->u16($a, 5) / 10.0);    // 32069
            $hub->SetVarFloat('grid_curr', $mb->s32($a, 8) / 1000.0);  // 32072
            $hub->SetVarFloat('grid_freq', $mb->u16($a, 21) / 100.0);  // 32085
        }
        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp_cab', $mb->s16($a, 23) / 10.0);    // 32087
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $m = $mb->readHolding(37100, 19); // 37100..37118
            if ($m !== null) {
                // Huawei-Zähler: + = Einspeisung, − = Bezug (Modul-Konvention).
                $hub->SetVarFloat('meter_total', (float)$mb->s32($m, 13)); // 37113
                $hub->SetVarFloat('meter_volt',  $mb->s32($m, 1) / 10.0);  // 37101
                $hub->SetVarFloat('meter_curr',  $mb->s32($m, 7) / 100.0); // 37107
                $hub->SetVarFloat('meter_freq',  $mb->s16($m, 18) / 100.0); // 37118
            }
        }

        if ($hub->GetPropBool('GroupBattery')) {
            $b = $mb->readHolding(37000, 23); // 37000..37022
            if ($b !== null) {
                $hub->SetVarInt('bat_status', $mb->u16($b, 0));            // 37000
                // Huawei: + = Laden. Modul-Konvention + = Entladen -> negieren.
                $hub->SetVarFloat('bat_power', (float)(-$mb->s32($b, 1))); // 37001
                $hub->SetVarFloat('bat_volt', $mb->u16($b, 3) / 10.0);     // 37003
                $hub->SetVarInt('bat_soc', (int)round($mb->u16($b, 4) / 10.0)); // 37004
                $hub->SetVarFloat('bat_curr', -$mb->s16($b, 21) / 10.0);   // 37021
                $hub->SetVarFloat('bat_temp', $mb->s16($b, 22) / 10.0);    // 37022
            }
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        if (!$hub->GetPropBool('GroupEnergy')) {
            return;
        }
        $e = $mb->readHolding(32106, 10); // 32106..32115
        if ($e !== null) {
            $hub->SetVarFloat('e_total', $mb->u32($e, 0) / 100.0);  // 32106
            $hub->SetVarFloat('e_day',   $mb->u32($e, 8) / 100.0);  // 32114
        }
    }

    public function readDeviceInfo($mb, $hub)
    {
        $name = $mb->readHolding(30000, 15); // Model name (STR, 30 Zeichen)
        if ($name !== null) {
            $hub->SetVarStr('dev_model', $mb->readStr($name, 0, 15));
        }
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Keine Steuerregister in der ersten Ausbaustufe.
    }
}

// ---------------------------------------------------------------------------
// IHUB_FoxEssDriver — FoxESS H1/H3 Single Phase Hybrid Inverter. Register aus der
// offiziellen "Fox Hybrid/AC Modbus Protocol"-Dokumentation (V1.01, 2021.09.02),
// vollständig gelesen (nicht nur auszugsweise).
//
// READ-ONLY-MVP, bewusst beschränkt auf den Echtzeit-/Zähler-Block 11000-11095
// (96 zusammenhängende Register, ein Leseblock). Dafür zeigt die Doku explizite
// Beispielrahmen mit Funktionscode 0x04 (Read Registers) - hier also FC04 wie
// dokumentiert (readInput), analog IHUB_SolplanetDriver.
//
// BEWUSST NICHT eingebaut, bis ein Beta-Tester es an echter Hardware verifiziert
// hat (Lehre aus dem SMA-FC03/FC04-Fehler vom selben Tag - siehe CLAUDE.md):
// - Config-/Steuerregister ab 40000 (Work Mode, Lade-Fenster, SOC-Grenzen usw.):
//   Die Doku belegt FC04 nur für die 10000er/11000er-Bloecke; ob 40000+ ebenso
//   ueber FC04 oder wie bei anderen Herstellern ueber FC03 (Holding) gelesen
//   wird, steht nicht explizit da. Erst nach Bestaetigung am echten Geraet
//   implementieren - sonst droht dieselbe Fehldeutung wie bei SMA.
// - "Remote"-Sollwertregister (44000+, Wirk-/Blindleistungsvorgabe fuer
//   Fernsteuerung): dieselbe Risikoklasse wie der GoodWe-47509-Beschriftungs-
//   fehler - erst nach Read-Only-Verifikation und mit Bedacht einbauen.
// - CleanMem-Act (45000-45002): "Clear events/energy", FACTORY RESET, alle
//   WriteOnly - nie als leicht ausloesbare Aktion anbieten.
//
// Word-Order bei den U32-Energiezaehlern (zwei Register je Wert, z. B.
// "11069----11070") ist in der Doku nicht explizit benannt; hier wie bei allen
// anderen Treibern High-Word-zuerst angenommen (Standard-Konvention, passt zum
// vorhandenen u32()-Helfer). Von einem Tester an einem plausiblen kWh-Wert zu
// bestaetigen.
// ---------------------------------------------------------------------------

class IHUB_FoxEssDriver implements IHUB_InverterDriverInterface
{
    const STATUS = [
        0 => 'Warten', 1 => 'Prüfung', 2 => 'Netzbetrieb', 3 => 'Inselbetrieb',
        4 => 'Behebbarer Fehler', 5 => 'Nicht behebbarer Fehler',
    ];
    const BAT_STATUS = [0 => 'Leerlauf', 1 => 'Normal', 2 => 'Offline'];

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['status',    'Betriebsstatus',    'I', 'FOX.Status',      true,  'device', 'RO 11056'],
            ['pv_total',  'PV Gesamtleistung', 'F', 'FOX.Watt',        true,  'pv',     'Σ RO 11002+11005'],
            ['ac_power',  'AC Wirkleistung',   'F', 'FOX.Watt',        true,  'device', 'RO 11011 (Inv Power_P)'],
            ['bat_power', 'Bat. Leistung',     'F', 'FOX.Watt',        true,  'bat',    'RO 11008 (+ = Entladen)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPV' => ['caption' => 'PV-Details (String 1+2)', 'vars' => [
                ['pv1_volt', 'PV1 Spannung', 'F', 'FOX.Volt',   false, 'pv', 'RO 11000'],
                ['pv1_curr', 'PV1 Strom',    'F', 'FOX.Ampere', false, 'pv', 'RO 11001'],
                ['pv2_volt', 'PV2 Spannung', 'F', 'FOX.Volt',   false, 'pv', 'RO 11003'],
                ['pv2_curr', 'PV2 Strom',    'F', 'FOX.Ampere', false, 'pv', 'RO 11004'],
            ]],
            'GroupGrid' => ['caption' => 'Netz (Spannung, Frequenz)', 'vars' => [
                ['grid_volt', 'Netz Spannung', 'F', 'FOX.Volt',  false, 'grid', 'RO 11009'],
                ['grid_freq', 'Netzfrequenz',  'F', 'FOX.Hertz', false, 'grid', 'RO 11014'],
            ]],
            'GroupBat' => ['caption' => 'Batterie (Spannung, Strom, SOC, Temperatur)', 'vars' => [
                ['bat_volt',    'Bat. Spannung',    'F', 'FOX.Volt',     false, 'bat', 'RO 11034 (BMS)'],
                ['bat_curr',    'Bat. Strom',       'F', 'FOX.Ampere',   false, 'bat', 'RO 11035 (BMS)'],
                ['bat_soc',     'Bat. SOC',         'I', '~Battery.100', true,  'bat', 'RO 11036'],
                ['bat_temp',    'Bat. Temperatur',  'F', '~Temperature', true,  'bat', 'RO 11038'],
                ['bat_status',  'Bat. Status',      'I', 'FOX.BatStat',  false, 'bat', 'RO 11057'],
            ]],
            'GroupEps' => ['caption' => 'Ersatzstrom (EPS/Inselbetrieb)', 'vars' => [
                ['eps_volt', 'EPS Spannung',  'F', 'FOX.Volt',  false, 'backup', 'RO 11015'],
                ['eps_pwr',  'EPS Leistung',  'F', 'FOX.Watt',  true,  'backup', 'RO 11017'],
                ['eps_freq', 'EPS Frequenz',  'F', 'FOX.Hertz', false, 'backup', 'RO 11020'],
            ]],
            'GroupMeter' => ['caption' => 'Smart Meter (Leistung)', 'vars' => [
                ['meter1_pwr', 'Meter 1 Leistung', 'F', 'FOX.Watt', true, 'meter', 'RO 11021'],
                ['meter2_pwr', 'Meter 2 Leistung', 'F', 'FOX.Watt', true, 'meter', 'RO 11022'],
            ]],
            'GroupTemp' => ['caption' => 'Temperatur', 'vars' => [
                ['temp_inv', 'Wechselrichter-Temperatur', 'F', '~Temperature', false, 'device', 'RO 11024'],
                ['temp_env', 'Umgebungstemperatur',        'F', '~Temperature', false, 'device', 'RO 11025'],
            ]],
            'GroupEnergy' => ['caption' => 'Energiezähler (Tag/Gesamt)', 'vars' => [
                ['e_pv_day',       'PV Heute',              'F', '~Electricity', true, 'energy', 'RO 11071 (÷10)'],
                ['e_pv_total',     'PV Gesamt',             'F', '~Electricity', true, 'energy', 'RO 11069+11070 (U32, ÷10)'],
                ['e_charge_day',   'Bat. Laden Heute',      'F', '~Electricity', true, 'energy', 'RO 11074 (÷10)'],
                ['e_charge_total', 'Bat. Laden Gesamt',     'F', '~Electricity', true, 'energy', 'RO 11072+11073 (U32, ÷10)'],
                ['e_disch_day',    'Bat. Entladen Heute',   'F', '~Electricity', true, 'energy', 'RO 11077 (÷10)'],
                ['e_disch_total',  'Bat. Entladen Gesamt',  'F', '~Electricity', true, 'energy', 'RO 11075+11076 (U32, ÷10)'],
                ['e_sell_day',     'Einspeisung Heute',     'F', '~Electricity', true, 'energy', 'RO 11080 (÷10)'],
                ['e_sell_total',   'Einspeisung Gesamt',    'F', '~Electricity', true, 'energy', 'RO 11078+11079 (U32, ÷10)'],
                ['e_load_day',     'Hausverbrauch Heute',   'F', '~Electricity', true, 'energy', 'RO 11092 (÷10)'],
                ['e_load_total',   'Hausverbrauch Gesamt',  'F', '~Electricity', true, 'energy', 'RO 11090+11091 (U32, ÷10)'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_model', 'Modell',       'S', '', false, 'device', 'RO 10000-10007 (STR)'],
                ['dev_sn',    'Seriennummer', 'S', '', false, 'device', 'RO 10008-10015 (STR)'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'FOX.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -40000.0, 40000.0, 1.0,  0],
            'FOX.Volt'   => [VARIABLETYPE_FLOAT, ' V',       0.0,  1000.0, 0.1,  1],
            'FOX.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -200.0,   200.0, 0.1,  1],
            'FOX.Hertz'  => [VARIABLETYPE_FLOAT, ' Hz',     45.0,    65.0, 0.01, 2],
        ];
    }

    public function getEnumProfiles()
    {
        $status = [];
        foreach (self::STATUS as $k => $label) {
            $status[$k] = [$label, 0x7A8A99];
        }
        $batStatus = [];
        foreach (self::BAT_STATUS as $k => $label) {
            $batStatus[$k] = [$label, 0x7A8A99];
        }
        return ['FOX.Status' => $status, 'FOX.BatStat' => $batStatus];
    }

    public function readFast($mb, $hub)
    {
        // Ein Block ueber den gesamten Echtzeit-/Zaehler-Bereich (96 Register,
        // 11000-11095) - laut Doku per FC04 (Read Registers) lesbar, siehe
        // IHUB_SolplanetDriver fuer dasselbe Muster mit readInput().
        $blk = $mb->readInput(11000, 96);
        $ok  = ($blk !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        $hub->SetVarInt('status', $mb->u16($blk, 56));   // 11056 Inverter state

        $pv1w = (float)$mb->s16($blk, 2);   // 11002 PV1 power
        $pv2w = (float)$mb->s16($blk, 5);   // 11005 PV2 power
        $hub->SetVarFloat('pv_total', $pv1w + $pv2w);
        $hub->SetVarFloat('ac_power', (float)$mb->s16($blk, 11));   // 11011 Inv Power_P
        $hub->SetVarFloat('bat_power', (float)$mb->s16($blk, 8));   // 11008 Battery power

        if ($hub->GetPropBool('GroupPV')) {
            $hub->SetVarFloat('pv1_volt', $mb->s16($blk, 0) / 10.0);   // 11000
            $hub->SetVarFloat('pv1_curr', $mb->s16($blk, 1) / 10.0);   // 11001
            $hub->SetVarFloat('pv2_volt', $mb->s16($blk, 3) / 10.0);   // 11003
            $hub->SetVarFloat('pv2_curr', $mb->s16($blk, 4) / 10.0);   // 11004
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('grid_volt', $mb->u16($blk, 9) / 10.0);   // 11009 (U16 lt. Doku)
            $hub->SetVarFloat('grid_freq', $mb->u16($blk, 14) / 100.0); // 11014
        }

        if ($hub->GetPropBool('GroupBat')) {
            $hub->SetVarFloat('bat_volt', $mb->s16($blk, 34) / 10.0);  // 11034 BMS
            $hub->SetVarFloat('bat_curr', $mb->s16($blk, 35) / 10.0);  // 11035 BMS
            $hub->SetVarInt('bat_soc',    $mb->u16($blk, 36));         // 11036
            $hub->SetVarFloat('bat_temp', $mb->s16($blk, 38) / 10.0);  // 11038
            $hub->SetVarInt('bat_status', $mb->u16($blk, 57));         // 11057
        }

        if ($hub->GetPropBool('GroupEps')) {
            $hub->SetVarFloat('eps_volt', $mb->u16($blk, 15) / 10.0);  // 11015
            $hub->SetVarFloat('eps_pwr',  (float)$mb->s16($blk, 17));  // 11017 Epspower_P
            $hub->SetVarFloat('eps_freq', $mb->u16($blk, 20) / 100.0); // 11020
        }

        if ($hub->GetPropBool('GroupMeter')) {
            $hub->SetVarFloat('meter1_pwr', (float)$mb->s16($blk, 21));   // 11021
            $hub->SetVarFloat('meter2_pwr', (float)$mb->s16($blk, 22));   // 11022
        }

        if ($hub->GetPropBool('GroupTemp')) {
            $hub->SetVarFloat('temp_inv', $mb->s16($blk, 24) / 10.0);   // 11024
            $hub->SetVarFloat('temp_env', $mb->s16($blk, 25) / 10.0);   // 11025
        }

        if ($hub->GetPropBool('GroupEnergy')) {
            // U32-Zaehler, Word-Order High-zuerst angenommen (s.o., unbestaetigt).
            $hub->SetVarFloat('e_pv_total',     $mb->u32($blk, 69) / 10.0);   // 11069+11070
            $hub->SetVarFloat('e_pv_day',       $mb->u16($blk, 71) / 10.0);   // 11071
            $hub->SetVarFloat('e_charge_total', $mb->u32($blk, 72) / 10.0);   // 11072+11073
            $hub->SetVarFloat('e_charge_day',   $mb->u16($blk, 74) / 10.0);   // 11074
            $hub->SetVarFloat('e_disch_total',  $mb->u32($blk, 75) / 10.0);   // 11075+11076
            $hub->SetVarFloat('e_disch_day',    $mb->u16($blk, 77) / 10.0);   // 11077
            $hub->SetVarFloat('e_sell_total',   $mb->u32($blk, 78) / 10.0);   // 11078+11079
            $hub->SetVarFloat('e_sell_day',     $mb->u16($blk, 80) / 10.0);   // 11080
            $hub->SetVarFloat('e_load_total',   $mb->u32($blk, 90) / 10.0);   // 11090+11091
            $hub->SetVarFloat('e_load_day',     $mb->u16($blk, 92) / 10.0);   // 11092
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Energie wird bereits im Echtzeit-Block in readFast() mitgelesen.
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Modell (10000-10007, 16 Zeichen) und SN (10008-10015, 15 Zeichen).
        // Read-Only-MVP: nur einmalig lesen, kein Steuerregister in dieser
        // Ausbaustufe implementiert.
        $blk = $mb->readInput(10000, 16);
        if ($blk === null) {
            return;
        }
        $hub->SetVarStr('dev_model', $mb->readStr($blk, 0, 8));
        $hub->SetVarStr('dev_sn',    $mb->readStr($blk, 8, 8));
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Kein Steuerregister in der ersten Ausbaustufe implementiert - siehe
        // Kommentar am Klassenkopf (44000er-Sollwertregister erst nach
        // Read-Only-Verifikation und mit Bedacht).
    }
}

// ---------------------------------------------------------------------------
// IHUB_VictronDriver — Victron GX (Cerbo/Venus OS) über Modbus TCP. Anders als bei
// Einzel-Wechselrichtern ist die Unit-ID hier ein Geräte-Selektor: Der Dienst
// com.victronenergy.system liegt IMMER auf Unit-ID 100 und aggregiert die
// Anlagenwerte (PV, Netz, Batterie, Verbrauch). Wir sprechen daher fest Unit
// 100 an, unabhängig von der im Formular gesetzten Unit-ID. Register verbatim
// aus Victrons offizieller attributes.csv (dbus_modbustcp), Big-Endian.
// ---------------------------------------------------------------------------

class IHUB_VictronDriver implements IHUB_InverterDriverInterface
{
    const UNIT_SYSTEM = 100;

    const BAT_STATE = [
        0 => 'Ruhe', 1 => 'Laden', 2 => 'Entladen',
    ];
    const GRID_SOURCE = [
        0 => 'Nicht verfügbar', 1 => 'Netz', 2 => 'Generator',
        3 => 'Landstrom', 240 => 'Nicht verbunden',
    ];

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['pv_total',  'PV Gesamtleistung', 'F', 'VIC.Watt',        true,  'pv',     'DC-PV 850 + AC-PV 808..816'],
            ['ac_power',  'AC Verbrauch (Haus)', 'F', 'VIC.Watt',      true,  'device', 'Σ AC Consumption 817..819'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupGrid' => ['caption' => 'Netz (Leistung, Quelle)', 'vars' => [
                ['meter_total', 'Netz Leistung (+ Einspeisung / − Bezug)', 'F', 'VIC.Watt', true, 'grid', 'Σ Grid 820..822 (int16)'],
                ['grid_source', 'Aktive Netz-Quelle', 'I', 'VIC.GridSrc', true, 'grid', 'Reg 826'],
            ]],
            'GroupBattery' => ['caption' => 'Batterie', 'vars' => [
                ['bat_soc',   'Bat. SOC',        'I', '~Battery.100', true,  'bat', 'Reg 843'],
                ['bat_power', 'Bat. Leistung (+ Entladen / − Laden)', 'F', 'VIC.Watt', true, 'bat', 'Reg 842 (int16)'],
                ['bat_volt',  'Bat. Spannung',   'F', 'VIC.Volt',    false, 'bat', 'Reg 840 (÷10)'],
                ['bat_curr',  'Bat. Strom',      'F', 'VIC.Ampere',  false, 'bat', 'Reg 841 (int16, ÷10)'],
                ['bat_state', 'Bat. Zustand',    'I', 'VIC.BatState', true,  'bat', 'Reg 844'],
            ]],
            'GroupPvDetail' => ['caption' => 'PV-Details (DC / AC-gekoppelt)', 'vars' => [
                ['pv_dc',  'PV DC-gekoppelt (MPPT)',  'F', 'VIC.Watt', true, 'pv', 'Reg 850'],
                ['pv_ac',  'PV AC-gekoppelt (Σ)',     'F', 'VIC.Watt', true, 'pv', 'Σ 808..816'],
            ]],
            // Zählerstände. WICHTIG: Der Systemdienst (Unit 100) führt KEINE
            // Energiezähler, nur Momentanleistungen. Netzbezug/-einspeisung
            // liegen auf dem eigenen Dienst com.victronenergy.grid mit eigener
            // Unit-ID (unten einzutragen); der Solarertrag kommt von den
            // Solarladereglern und nutzt deren MPPT-Unit-IDs mit.
            'GroupEnergy' => ['caption' => 'Energiezähler (Netzbezug/-einspeisung, Solarertrag) — Unit-ID des Netzzählers unten eintragen', 'vars' => [
                ['meter_imp',  'Netzbezug gesamt',   'F', '~Electricity', true, 'energy', 'Σ Reg 2622/2624/2626 (u32, ÷100)'],
                ['meter_exp',  'Einspeisung gesamt', 'F', '~Electricity', true, 'energy', 'Σ Reg 2628/2630/2632 (u32, ÷100)'],
                ['e_pv_total', 'Solarertrag gesamt', 'F', '~Electricity', true, 'energy', 'Σ Laderegler Reg 790 (÷10)'],
                ['e_pv_day',   'Solarertrag heute',  'F', '~Electricity', true, 'energy', 'Σ Laderegler Reg 784 (÷10)'],
                // Einzelwerte je Solarladeregler. Auf Anregung von Beta-Tester
                // loerdy: Sie sind für sich interessant und machen zugleich einen
                // Zählerüberlauf sichtbar, statt ihn in der Summe zu verstecken.
                ['mppt1_e_day',   'MPPT 1 Ertrag heute',  'F', '~Electricity', true, 'energy', 'Laderegler 1, Reg 784 (÷10)'],
                ['mppt1_e_total', 'MPPT 1 Ertrag gesamt', 'F', '~Electricity', true, 'energy', 'Laderegler 1, Reg 790 (÷10)'],
                ['mppt2_e_day',   'MPPT 2 Ertrag heute',  'F', '~Electricity', true, 'energy', 'Laderegler 2, Reg 784 (÷10)'],
                ['mppt2_e_total', 'MPPT 2 Ertrag gesamt', 'F', '~Electricity', true, 'energy', 'Laderegler 2, Reg 790 (÷10)'],
                ['mppt3_e_day',   'MPPT 3 Ertrag heute',  'F', '~Electricity', true, 'energy', 'Laderegler 3, Reg 784 (÷10)'],
                ['mppt3_e_total', 'MPPT 3 Ertrag gesamt', 'F', '~Electricity', true, 'energy', 'Laderegler 3, Reg 790 (÷10)'],
                ['mppt4_e_day',   'MPPT 4 Ertrag heute',  'F', '~Electricity', true, 'energy', 'Laderegler 4, Reg 784 (÷10)'],
                ['mppt4_e_total', 'MPPT 4 Ertrag gesamt', 'F', '~Electricity', true, 'energy', 'Laderegler 4, Reg 790 (÷10)'],
            ]],
            'GroupMppt' => ['caption' => 'PV je Solarladeregler / MPPT (Unit-IDs unten eintragen)', 'vars' => [
                ['mppt1_power', 'MPPT 1 Leistung', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt1_volt',  'MPPT 1 Spannung', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt1_state', 'MPPT 1 Zustand',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
                ['mppt2_power', 'MPPT 2 Leistung', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt2_volt',  'MPPT 2 Spannung', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt2_state', 'MPPT 2 Zustand',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
                ['mppt3_power', 'MPPT 3 Leistung', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt3_volt',  'MPPT 3 Spannung', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt3_state', 'MPPT 3 Zustand',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
                ['mppt4_power', 'MPPT 4 Leistung', 'F', 'VIC.Watt', true,  'pv', 'Solarladeregler Reg 789 (÷10)'],
                ['mppt4_volt',  'MPPT 4 Spannung', 'F', 'VIC.Volt', false, 'pv', 'Reg 776 (÷100)'],
                ['mppt4_state', 'MPPT 4 Zustand',  'I', 'VIC.ChgState', true, 'pv', 'Reg 775'],
            ]],
        ];
    }

    public function getExtraBooleanProperties()
    {
        return [];
    }

    public function getProfiles()
    {
        return [
            'VIC.Watt'   => [VARIABLETYPE_FLOAT, ' W',  -100000.0, 100000.0, 1.0, 0],
            'VIC.Volt'   => [VARIABLETYPE_FLOAT, ' V',        0.0,   1000.0, 0.1, 1],
            'VIC.Ampere' => [VARIABLETYPE_FLOAT, ' A',    -1000.0,   1000.0, 0.1, 1],
        ];
    }

    public function getEnumProfiles()
    {
        $bat = [];
        foreach (self::BAT_STATE as $k => $label) {
            $bat[$k] = [$label, 0x7A8A99];
        }
        $src = [];
        foreach (self::GRID_SOURCE as $k => $label) {
            $src[$k] = [$label, 0x7A8A99];
        }
        $chg = [
            0 => ['Aus', 0x7A8A99], 2 => ['Fehler', 0xC0392B], 3 => ['Bulk', 0x3BA55D],
            4 => ['Absorption', 0x3BA55D], 5 => ['Float', 0x3BA55D], 6 => ['Storage', 0x7A8A99],
            7 => ['Ausgleich', 0xE08A2B], 11 => ['Hub-1', 0x7A8A99], 252 => ['Hub-1', 0x7A8A99],
        ];
        return ['VIC.BatState' => $bat, 'VIC.GridSrc' => $src, 'VIC.ChgState' => $chg];
    }

    public function readFast($mb, $hub)
    {
        // Victron: Systemdienst ist immer Unit-ID 100 (Geräte-Selektor).
        $mb->unitId = self::UNIT_SYSTEM;

        // AC-Block 808..826 (19 Register) in einem Zug.
        $ac = $mb->readHolding(808, 19);
        $ok = ($ac !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }

        // AC-gekoppelte PV (Output/Grid/Genset L1..L3), alle uint16 W.
        $pvAc = 0.0;
        for ($o = 0; $o <= 8; $o++) {
            $pvAc += $mb->u16($ac, $o);
        }
        // Hausverbrauch = Σ AC Consumption L1..L3 (Offsets 9..11).
        $cons = $mb->u16($ac, 9) + $mb->u16($ac, 10) + $mb->u16($ac, 11);
        // Netzleistung = Σ Grid L1..L3 (Offsets 12..14, int16). Victron: + =
        // Bezug. Modul-Konvention Meter: + = Einspeisung -> negieren.
        $grid = $mb->s16($ac, 12) + $mb->s16($ac, 13) + $mb->s16($ac, 14);

        $hub->SetVarFloat('ac_power', (float)$cons);

        // DC-gekoppelte PV (Reg 850) und den Batterieblock (840..844) GETRENNT
        // lesen: Zwischen 844 und 850 liegen reservierte Register (845..849),
        // und manche GX-Firmwares quittieren einen Block, der reservierte oder
        // nicht belegte Register überspannt, mit einer Modbus-Exception - dann
        // scheiterte bisher der gesamte Batterie-/PV-Block (real gemeldet:
        // nur Netz kam an, PV/Batterie blieben leer).
        $pvDcBlk = $mb->readHolding(850, 1);
        $pvDc = ($pvDcBlk !== null) ? (float)$mb->u16($pvDcBlk, 0) : 0.0;
        $hub->SetVarFloat('pv_total', $pvAc + $pvDc);

        // Batterieblock 840..844 (V, A, W, SOC, State) - ohne reservierte Register.
        $bat = $mb->readHolding(840, 5);

        if ($hub->GetPropBool('GroupPvDetail')) {
            $hub->SetVarFloat('pv_dc', $pvDc);
            $hub->SetVarFloat('pv_ac', $pvAc);
        }

        if ($hub->GetPropBool('GroupGrid')) {
            $hub->SetVarFloat('meter_total', (float)(-$grid));
            $hub->SetVarInt('grid_source', $mb->s16($ac, 18)); // Reg 826
        }

        if ($hub->GetPropBool('GroupBattery') && $bat !== null) {
            $hub->SetVarFloat('bat_volt', $mb->u16($bat, 0) / 10.0);  // 840
            // Strom wie Leistung auf Modul-Konvention (+ = Entladen) negieren.
            $hub->SetVarFloat('bat_curr', -$mb->s16($bat, 1) / 10.0); // 841
            // Reg 842: Victron + = Laden. Modul-Konvention + = Entladen -> negieren.
            $hub->SetVarFloat('bat_power', (float)(-$mb->s16($bat, 2))); // 842
            $hub->SetVarInt('bat_soc', $mb->u16($bat, 3));            // 843
            $hub->SetVarInt('bat_state', $mb->u16($bat, 4));         // 844
        }

        // Einzelne Solarladeregler (MPPT): jeder ist ein eigenes Modbus-Gerät
        // mit eigener Unit-ID (im GX unter Modbus-Diensten ablesbar). Je Regler
        // Leistung (789 ÷10), Spannung (776 ÷100) und Zustand (775).
        if ($hub->GetPropBool('GroupMppt')) {
            $ids = $hub->GetVictronMpptUnitIds();
            for ($i = 0; $i < 4; $i++) {
                $n = $i + 1;
                if (!isset($ids[$i]) || $ids[$i] <= 0) {
                    continue;
                }
                $mb->unitId = $ids[$i];
                $p = $mb->readHolding(789, 1);
                $v = $mb->readHolding(776, 1);
                $s = $mb->readHolding(775, 1);
                if ($p !== null) { $hub->SetVarFloat('mppt' . $n . '_power', $mb->u16($p, 0) / 10.0); }
                if ($v !== null) { $hub->SetVarFloat('mppt' . $n . '_volt',  $mb->u16($v, 0) / 100.0); }
                if ($s !== null) { $hub->SetVarInt('mppt' . $n . '_state',   $mb->u16($s, 0)); }
            }
            $mb->unitId = self::UNIT_SYSTEM;
        }

        return true;
    }

    public function readSlow($mb, $hub)
    {
        // Zählerstände. Der Systemdienst (Unit 100) führt KEINE Energiezähler -
        // nur Momentanleistungen. Die Zähler liegen auf eigenen Diensten:
        //   com.victronenergy.grid          -> eigene Unit-ID (Netzzähler)
        //   com.victronenergy.solarcharger  -> die MPPT-Unit-IDs
        // Deshalb wird hier die Unit-ID gewechselt und am Ende zurückgesetzt.
        if (!$hub->GroupEnabled('GroupEnergy')) {
            return;
        }
        $restore = $mb->unitId;

        // --- Netzbezug / Einspeisung -------------------------------------
        // Bewusst die 32-Bit-Register 2622..2632 statt der 16-Bit-Variante
        // 2603..2608: Letztere laufen bei ÷100 schon nach 655,35 kWh über und
        // sind als Lebenszähler unbrauchbar.
        $gridUnit = $hub->GetVictronGridUnitId();
        if ($gridUnit > 0) {
            $mb->unitId = $gridUnit;
            $imp = 0.0; $exp = 0.0; $ok = false;
            foreach ([2622, 2624, 2626] as $reg) {
                $r = $mb->readHolding($reg, 2);
                if ($r !== null) { $imp += $mb->u32($r, 0) / 100.0; $ok = true; }
            }
            foreach ([2628, 2630, 2632] as $reg) {
                $r = $mb->readHolding($reg, 2);
                if ($r !== null) { $exp += $mb->u32($r, 0) / 100.0; $ok = true; }
            }
            if ($ok) {
                $hub->SetVarFloat('meter_imp', $imp);
                $hub->SetVarFloat('meter_exp', $exp);
            }
        }

        // --- Solarertrag je Laderegler ------------------------------------
        // ACHTUNG: Beide Register sind nur 16 Bit; für die Solarladeregler gibt
        // es KEINE 32-Bit-Variante und kein /Yield/System. Yield/User (790)
        // läuft daher bei ÷10 nach 6553,5 kWh über und zählt wieder bei null an.
        // Bei einem Beta-Tester bestätigt: zwei Regler mit 10616,55 und 9628,06
        // kWh lieferten 4063,0 + 3074,5 = 7137,5 statt 20244,6 kWh.
        //
        // Optional korrigiert das Modul das (siehe YieldCorrected()): Die Zahl
        // der bisherigen Überläufe wird einmalig aus einem vom Nutzer
        // eingetragenen Ist-Wert geeicht und danach laufend fortgezählt.
        $ids = $hub->GetVictronMpptUnitIds();
        if (count($ids) > 0) {
            $total = 0.0; $day = 0.0; $any = false;
            foreach ($ids as $i => $id) {
                $mb->unitId = $id;
                // Gesamtertrag bevorzugt aus dem 32-BIT-Register 3728 lesen.
                // Von Beta-Tester loerdy am Geraet nachgewiesen: Es liefert den
                // echten Lebensdauer-Ertrag (bei ihm 10617 kWh), waehrend das
                // 16-Bit-Register 790 bei /10 nach 6553,5 kWh ueberlaeuft und
                // denselben Zaehler nur verstuemmelt zeigt (40631 -> 4063,1).
                // Manche Geraete/Firmwares bieten 3728 nur ueber FC04 an, daher
                // erst Holding, dann Input. Erst wenn beides fehlt, greift der
                // Rueckfall auf 790 samt Ueberlaufkorrektur.
                $t32 = $mb->readHolding(3728, 2);
                if ($t32 === null) { $t32 = $mb->readInput(3728, 2); }
                $t = ($t32 === null) ? $mb->readHolding(790, 1) : null;
                $d = $mb->readHolding(784, 1);
                $n = $i + 1;
                if ($t32 !== null) {
                    // ACHTUNG, anderer Massstab als Reg 790: 3728 zaehlt in
                    // GANZEN kWh (loerdys Geraet: Rohwert 10617 = 10617 kWh),
                    // waehrend 790 in 0,1 kWh zaehlt. Hier also NICHT teilen.
                    $kwh = (float) $mb->u32($t32, 0);
                    $total += $kwh; $any = true;
                    $hub->SetVarFloat('mppt' . $n . '_e_total', $kwh);
                } elseif ($t !== null) {
                    $kwh = $hub->YieldCorrected($id, $i, (int) $t[0]);
                    $total += $kwh; $any = true;
                    $hub->SetVarFloat('mppt' . $n . '_e_total', $kwh);
                }
                if ($d !== null) {
                    $dayKwh = $d[0] / 10.0;
                    $day += $dayKwh; $any = true;
                    $hub->SetVarFloat('mppt' . $n . '_e_day', $dayKwh);
                }
            }
            if ($any) {
                $hub->SetVarFloat('e_pv_total', $total);
                $hub->SetVarFloat('e_pv_day',   $day);
            }
            $hub->FlushYieldState();
        }

        $mb->unitId = $restore;
    }

    public function readDeviceInfo($mb, $hub)
    {
        // Seriennummer/Modell optional - Systemdienst hat keinen Klarnamen.
    }

    public function writeControl($mb, $hub, $ident, $value)
    {
        // Keine Steuerregister in der ersten Ausbaustufe.
    }
}

// ---------------------------------------------------------------------------
// InverterHub — Hauptmodul, lädt den Treiber laut Manufacturer-Property
// ---------------------------------------------------------------------------

class InverterHub extends IPSModule
{
    private const DRIVERS = [
        'goodwe'    => 'IHUB_GoodweDriver',
        'sungrow'   => 'IHUB_SungrowDriver',
        'solis'     => 'IHUB_SolisDriver',
        'growatt'   => 'IHUB_GrowattDriver',
        'solax'     => 'IHUB_SolaxDriver',
        'sma'       => 'IHUB_SmaDriver',
        'fronius'   => 'IHUB_FroniusDriver',
        'solaredge' => 'IHUB_SolarEdgeDriver',
        'deye'      => 'IHUB_DeyeDriver',
        'solplanet' => 'IHUB_SolplanetDriver',
        'kostal'    => 'IHUB_KostalDriver',
        'victron'   => 'IHUB_VictronDriver',
        'huawei'    => 'IHUB_HuaweiDriver',
        'foxess'    => 'IHUB_FoxEssDriver',
    ];

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-inverterhub-multi-wechselrichter-ein-modbus-tcp-modul-fuer-goodwe-sma-fronius-sungrow-solis-growatt-solax/144121';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    // „Was ist neu"-Banner (siehe newsBanner()/AckNews()). Vergleich laeuft
    // gegen den STRING NEWS_VERSION - jede Erhoehung zeigt den Banner erneut,
    // bis der Nutzer ihn (wieder) bestaetigt. Pro-Version-Dismiss ist damit
    // bereits erfuellt (Verbund-Konvention, EMS 24.07.2026); diese Konstante
    // bitte bei jeder nutzerrelevanten Aenderungsrunde mit hochziehen - sie
    // stand seit ihrer Einfuehrung bei 0.45 unveraendert, waehrend das Modul
    // laengst bei 0.72 war.
    private const NEWS_VERSION = '0.72';
    private const NEWS_ITEMS = [
        'Neuer Wechselrichter: FoxESS H1/H3 (Read-Only-Vorabversion, Beta).',
        'SMA: mehrere Korrekturen an Skalierung, Batterie-/PV-Erkennung und Registerzugriff — Werte sind jetzt deutlich genauer.',
        'Victron: Hauslast-Berechnung korrigiert (war zu hoch, wenn gleichzeitig Netzbezug bestand).',
        'GoodWe: Schalter zur Einspeisebegrenzung korrekt beschriftet, plus Warnung bei einer Begrenzung auf 0 W.',
        'Energiezähler: falscher Archiv-Aggregationstyp behoben — bestehende Variablen werden automatisch und ohne Datenverlust korrigiert.',
        'Stromflusskachel: Einheit wählt sich automatisch (W/kW/MW), eingesteckte Wallbox wird nicht mehr fälschlich ausgegraut.',
        'Monitoring: neuer Reiter „Strompreis" mit Netzbezug — bei installiertem Preismodul.',
    ];

    private $driver = null;
    // true, wenn der „Energie in Wh"-Schalter seit dem letzten ApplyChanges
    // umgelegt wurde → dann (und nur dann) Energie-Profile neu setzen.
    private $reprofileEnergy = false;

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('SeenNews', '');
        // Merkt den zuletzt angewandten Zustand des „Energie in Wh"-Schalters,
        // um Energie-Profile nur bei echter Umstellung neu zu setzen.
        $this->RegisterAttributeBoolean('LastEnergyUnitWh', false);
        // Zustand der Ueberlaufkorrektur: {unitId: {w: Ueberlaeufe, r: letzter Rohwert}}
        $this->RegisterAttributeString('VictronYieldState', '{}');

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Manufacturer', 'goodwe');
        $this->RegisterPropertyBoolean('MeterInvert', false);
        $this->RegisterPropertyBoolean('BatInvert', false);
        // Steuerhoheit dieser Instanz (Verbund-Vertrag IHUB_GetFunctions,
        // EMS-Prioritaetshierarchie Situation A/B in CLAUDE.md). 'ems' = Normalfall,
        // das EMS darf hierher schreiben. 'external' = ein anderer Akteur besitzt
        // den Schreibkanal (z. B. Sunny Home Manager, Tibber->Herstellercloud) -
        // das EMS soll NICHT hierher schreiben. 'none' = niemand soll steuern.
        // Nur relevant, wenn der Treiber ueberhaupt Steuerregister hat
        // (GroupControl); bei reinen Lesetreibern wirkungslos, aber schadet nicht.
        $this->RegisterPropertyString('ControlAuthority', 'ems');
        // Anzahl tatsächlich vorhandener MPPT-Eingänge / Solarladeregler.
        // 0 = alle anlegen, die der Treiber kennt (bisheriges Verhalten und
        // Vorgabe, damit bestehende Instanzen unverändert bleiben).
        $this->RegisterPropertyInteger('MpptCount', 0);
        // Energie-Ausgabe in Wh statt kWh (Basiseinheit, konsistent zu W;
        // die neue IPS-Darstellung skaliert dann selbst auf Wh/kWh/MWh).
        $this->RegisterPropertyBoolean('EnergyUnitWh', false);
        // Messwerte automatisch archivieren (Standard an). Ausschalten NUR, wenn
        // diese Instanz Ziel einer Migration aus einem Altmodul (z. B. GoodweET)
        // werden soll: MigrationsHub haengt die Alt-Historie per
        // AC_ChangeVariableID um, und das gelingt nur an einer Variable OHNE
        // eigene Archivhistorie. Wuerde InverterHub sofort mitloggen, waere das
        // Ziel binnen Sekunden "nicht mehr jungfraeulich" und die Migration
        // wuerde blockiert. Nach der Migration schaltet MigrationsHub das Logging
        // am Ziel selbst ein - oder man setzt diesen Schalter dann wieder auf an.
        $this->RegisterPropertyBoolean('AutoArchive', true);
        // Modbus-Unit-ID des Smart Meters (SunSpec-Hersteller wie Fronius/SMA/
        // SolarEdge: der Zähler ist ein eigenes Modbus-Gerät, Adresse ab 200,
        // je nach Konfiguration z. B. auch 240).
        $this->RegisterPropertyInteger('MeterUnitId', 200);
        // Float32-Wortreihenfolge bei Kostal. 0 = CDAB (little-endian „Standard
        // Modbus", Werkseinstellung Plenticore), 1 = ABCD (big-endian/SunSpec).
        // Falsche Wahl liefert unbrauchbare Werte (riesige/negative Zahlen).
        $this->RegisterPropertyInteger('KostalByteOrder', 0);
        // Victron: Unit-IDs der einzelnen Solarladeregler (MPPT), kommagetrennt.
        $this->RegisterPropertyString('VictronMpptUnitIds', '');
        // Unit-ID des Victron-Netzzaehlers (com.victronenergy.grid).
        // 0 = nicht konfiguriert; Netz-Zaehlerstaende entfallen dann.
        $this->RegisterPropertyInteger('VictronGridUnitId', 0);
        // Ueberlaufkorrektur des Solarertrags (Reg 790 ist nur 16 Bit).
        $this->RegisterPropertyBoolean('VictronYieldFix', false);
        // Ist-Werte je Laderegler in kWh, Reihenfolge wie die MPPT-Unit-IDs.
        $this->RegisterPropertyString('VictronYieldCalib', '');
        $this->RegisterPropertyInteger('HouseLoadMeterID', 0);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 247);
        $this->RegisterPropertyInteger('IntervalFast', 5);
        $this->RegisterPropertyInteger('IntervalSlow', 300);
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);

        // Treiber-spezifische Properties werden hier für ALLE Treiber (nicht nur
        // den aktuell gewählten) registriert. Grund: Create() legt die Properties
        // einmalig zum Erstellungszeitpunkt an, aber der tatsächlich gewünschte
        // Manufacturer-Wert (z.B. vom Discovery-Modul mitgegeben) wird oft erst
        // NACH Create() über die Konfiguration gesetzt. Würden nur die Properties
        // des zu diesem Zeitpunkt aktiven (Default-)Treibers registriert, fehlten
        // bei jedem anderen Hersteller sämtliche Datenpunkt-Gruppen-Checkboxen
        // ("Eigenschaft GroupPV nicht gefunden" etc.) — ungenutzte Properties
        // anderer Treiber bleiben einfach unbenutzt, das ist unschädlich.
        $allProps = [];
        foreach (self::DRIVERS as $driverClass) {
            $drv = new $driverClass();
            foreach ($drv->getExtraBooleanProperties() as $name => $default) {
                if (!array_key_exists($name, $allProps)) {
                    $allProps[$name] = $default;
                }
            }
            foreach ($drv->getOptionalGroups() as $propName => $group) {
                if (!array_key_exists($propName, $allProps)) {
                    $allProps[$propName] = true;
                }
            }
        }
        foreach ($allProps as $name => $default) {
            $this->RegisterPropertyBoolean($name, $default);
        }

        $this->RegisterTimer('FastTimer', 0, 'IHUB_ReadFast($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlowTimer', 0, 'IHUB_ReadSlow($_IPS[\'TARGET\']);');
        // IPS_SetVariableCustomAction() schlägt fehl, wenn die Instanz noch
        // innerhalb derselben Erstellungs-/Konfigurations-Transaktion nicht
        // als gültiges Ziel sichtbar ist (z.B. wenn ein Configurator-Modul
        // Instanz+Konfiguration in einem einzigen synchronen Ablauf anlegt —
        // da hilft auch "erst beim zweiten ApplyChanges" nichts, weil beide
        // Aufrufe noch zur selben Transaktion gehören). Custom Actions
        // werden deshalb einmalig per Kurz-Timer NACH der Transaktion gesetzt.
        $this->RegisterTimer('EnableActionsTimer', 0, 'IHUB_EnableActions($_IPS[\'TARGET\']);');

        $this->RegisterAttributeBoolean('DeviceInfoRead', false);
        $this->RegisterAttributeBoolean('ImplausibleLogged', false);
    }

    // Ein Gerät, das auf Adressen antwortet, die es gar nicht belegt, liefert
    // reihenweise 0xFFFF - den Modbus-/SunSpec-Sentinel „nicht implementiert".
    // Häufigste Ursache: In der Instanz ist der falsche Hersteller eingestellt,
    // dann werden fremde Registeradressen abgefragt und das Gerät quittiert sie
    // höflich mit lauter Einsen. Ohne diese Prüfung landen daraus Geisterwerte
    // in den Variablen (65535 %, 6553,5 V, 4294967295 W, Strings aus „ÿ").
    // Gemeldet wird einmal pro Instanzstart, damit das Log nicht vollläuft.
    // Warnung ins Meldungslog, aus einem Treiber heraus aufrufbar.
    public function WarnUser(string $message)
    {
        $this->LogMessage($message, KL_WARNING);
    }

    public function BlockLooksUnset(array $regs): bool
    {
        $n = count($regs);
        if ($n < 8) {
            return false;   // zu kurz für eine belastbare Aussage
        }
        $unset = 0;
        foreach ($regs as $r) {
            if (($r & 0xFFFF) === 0xFFFF) {
                $unset++;
            }
        }
        if ($unset * 5 < $n * 4) {
            // Unter 80 % - normaler Betrieb, ggf. gemeldeten Zustand zurücknehmen.
            if ($this->ReadAttributeBoolean('ImplausibleLogged')) {
                $this->WriteAttributeBoolean('ImplausibleLogged', false);
            }
            return false;
        }
        if (!$this->ReadAttributeBoolean('ImplausibleLogged')) {
            $this->WriteAttributeBoolean('ImplausibleLogged', true);
            $this->LogMessage(
                'Das Gerät antwortet auf den abgefragten Registerbereich ausschließlich mit '
                . '0xFFFF ("Register nicht belegt"). Es ist erreichbar, liefert aber keine '
                . 'Messwerte. Bitte in den Instanzeinstellungen den Hersteller und die '
                . 'Unit-ID prüfen - meist ist ein anderer Hersteller eingestellt, als das '
                . 'Gerät tatsächlich ist.',
                KL_WARNING
            );
        }
        return true;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        // Energie-Profile nur neu setzen, wenn der Wh-Schalter umgelegt wurde -
        // sonst würde jedes „Übernehmen" eine vom Nutzer gewählte Presentation
        // (IPS 7 „Wertanzeige") auf das Legacy-Profil zurücksetzen.
        $wh = $this->ReadPropertyBoolean('EnergyUnitWh');
        $this->reprofileEnergy = ($this->ReadAttributeBoolean('LastEnergyUnitWh') !== $wh);
        $this->RegisterVariables();
        $this->WriteAttributeBoolean('LastEnergyUnitWh', $wh);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }

        $host = $this->ReadPropertyString('Host');
        if ($host === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            return;
        }

        $this->SetTimerInterval('FastTimer', $this->ReadPropertyInteger('IntervalFast') * 1000);
        $this->SetTimerInterval('SlowTimer', $this->ReadPropertyInteger('IntervalSlow') * 1000);
        $this->SetTimerInterval('EnableActionsTimer', 200);
        $this->WriteAttributeBoolean('DeviceInfoRead', false);
        $this->SetStatus(102);
    }

    // Wird kurz nach ApplyChanges einmalig vom EnableActionsTimer aufgerufen,
    // sobald die Instanz die Erstellungstransaktion sicher verlassen hat.
    public function EnableActions()
    {
        $this->SetTimerInterval('EnableActionsTimer', 0);

        $driver = $this->GetDriver();
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                if ($v[5] === 'control') {
                    $vid = $this->FindVarByIdent($v[0]);
                    if ($vid) {
                        IPS_SetVariableCustomAction($vid, $this->InstanceID);
                    }
                }
            }
        }
    }

    public function ReadFast()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $driver = $this->GetDriver();
        if (!$this->ReadAttributeBoolean('DeviceInfoRead')) {
            $driver->readDeviceInfo($this->GetModbusClient(), $this);
            $this->WriteAttributeBoolean('DeviceInfoRead', true);
        }
        $driver->readFast($this->GetModbusClient(), $this);
    }

    public function ReadSlow()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $this->GetDriver()->readSlow($this->GetModbusClient(), $this);
    }

    public function RequestAction($Ident, $Value)
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        // Steuerhoheits-Schutz: Nur bei 'ems' schreibt dieses Modul ueberhaupt
        // an den Wechselrichter. Ist die Instanz als 'external'/'none' markiert
        // (ein anderer Akteur besitzt den Schreibkanal, z. B. Sunny Home
        // Manager), wird der Schreibzugriff verweigert - auch wenn irgendein
        // Aufrufer (Skript, fehlerhafte EMS-Logik) es versucht. Verteidigung in
        // der Tiefe: Das EMS soll controlAuthority selbst pruefen, aber ein
        // Bug dort darf hier trotzdem nicht zu einer ungewollten Schreibaktion
        // fuehren.
        if ($this->ReadPropertyString('ControlAuthority') !== 'ems') {
            $this->LogMessage(
                'Schreibzugriff auf "' . $Ident . '" verweigert: Diese Instanz ist als "'
                . $this->ReadPropertyString('ControlAuthority') . '" markiert (Steuerhoheit liegt '
                . 'nicht beim EMS). Falls das EMS hier steuern soll, "Steuerhoheit" in den '
                . 'Instanzeinstellungen auf "EMS" setzen.',
                KL_WARNING
            );
            return;
        }
        $this->GetDriver()->writeControl($this->GetModbusClient(), $this, $Ident, $Value);
    }

    // Verbund-Vertrag fuer das EMS und andere Konsumenten (analog MHUB_GetFunctions).
    // Liefert Identitaet, Steuerfaehigkeit und die wichtigsten Variablen-IDs dieser
    // InverterHub-Instanz, damit ein Konsument nicht selbst nach Idents suchen muss.
    public function GetFunctions(): array
    {
        $driver = $this->GetDriver();
        $hasControl = array_key_exists('GroupControl', $driver->getOptionalGroups());
        $find = function (string $ident): int {
            $vid = $this->FindVarByIdent($ident);
            return $vid ?: 0;
        };
        return [
            'contractVersion'  => '1.0',
            'instanceID'       => $this->InstanceID,
            'manufacturer'     => $this->ReadPropertyString('Manufacturer'),
            // Immer false bei einer PHYSISCHEN Instanz (dieses Modul). Reserviert
            // fuer InverterHubVirtual (Aggregat mehrerer Instanzen), damit ein
            // Konsument einen Aggregatwert nicht versehentlich als weiteres
            // Einzelgeraet in die Steuerung nimmt (siehe CLAUDE.md).
            'virtual'          => false,
            'measured'         => true,
            // Kann dieser Treiber ueberhaupt Steuerregister schreiben? (aktuell
            // GoodWe/Deye/Sungrow - generisch ueber die GroupControl-Gruppe
            // erkannt, damit sich das automatisch mitzieht, wenn weitere
            // Treiber Steuerregister bekommen.)
            'controllable'     => $hasControl,
            // Wer darf tatsaechlich schreiben (Nutzereinstellung, s. o.).
            // Fuer einen Konsumenten ohne 'controllable' ist dieser Wert irrelevant.
            'controlAuthority' => $this->ReadPropertyString('ControlAuthority'),
            'pvPowerID'        => $find('pv_total'),
            'acPowerID'        => $find('ac_power'),
            'batPowerID'       => $find('bat_power') ?: $find('bat_total_pwr'),
            'gridPowerID'      => $find('meter_total'),
            'socID'            => $find('bat_soc') ?: $find('soc'),
            'connectedID'      => $find('connected'),
        ];
    }

    public function GetConfigurationForm()
    {
        $driver = $this->GetDriver();

        $groupItems = [];
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            $groupItems[] = [
                'type'    => 'CheckBox',
                'name'    => $propName,
                'caption' => $group['caption'],
            ];
            // Steuerhoheit direkt bei der Steuerungs-Gruppe: nur sichtbar, wenn
            // dieser Treiber ueberhaupt Steuerregister hat (GroupControl-Gruppe
            // existiert). Reine Lesetreiber bekommen das Feld nicht - es waere
            // dort wirkungslos und nur Ballast im Formular.
            if ($propName === 'GroupControl') {
                $groupItems[] = [
                    'type'    => 'Select',
                    'name'    => 'ControlAuthority',
                    'caption' => 'Steuerhoheit dieser Instanz',
                    'options' => [
                        ['label' => 'EMS (Normalfall — das EMS darf hier schreiben)', 'value' => 'ems'],
                        ['label' => 'Extern (ein anderer Akteur schreibt, z. B. Sunny Home Manager) — EMS darf NICHT schreiben', 'value' => 'external'],
                        ['label' => 'Keine (niemand soll hier steuern)', 'value' => 'none'],
                    ],
                ];
            }
        }
        // Invers-Schalter: die Vorzeichen von Netz- und Batterieleistung hängen
        // von Einbauort/Verdrahtung bzw. der gewünschten Konvention ab und sind
        // je Anlage verschieden - der Nutzer legt die Richtung selbst fest.
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'MeterInvert',
            'caption' => 'Netz-Leistung (Meter) invertieren — falls Einspeisung/Bezug vertauscht angezeigt werden',
        ];
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'BatInvert',
            'caption' => 'Batterie-Leistung invertieren — Standard ist + Entladen / − Laden',
        ];
        // Anzahl MPPT-Eingänge: Die Treiber kennen so viele, wie die Baureihe
        // maximal haben kann. Wer weniger Strings betreibt, bekommt sonst leere
        // Variablen. Die Höchstzahl stammt aus den Idents des gewählten Treibers.
        $maxMppt = 0;
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                if (preg_match('/^mppt(\d+)_/', (string) $v[0], $m)) {
                    $maxMppt = max($maxMppt, (int) $m[1]);
                }
            }
        }
        if ($maxMppt > 1) {
            $groupItems[] = [
                'type'    => 'NumberSpinner',
                'name'    => 'MpptCount',
                'caption' => 'Anzahl MPPT-Eingänge (0 = alle ' . $maxMppt . ' anlegen)',
                'minimum' => 0,
                'maximum' => $maxMppt,
            ];
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => 'ℹ Dieser Wechselrichter unterstützt bis zu ' . $maxMppt . ' MPPT-Eingänge. '
                    . 'Trage ein, wie viele tatsächlich belegt sind — die übrigen Variablen werden dann nicht '
                    . 'angelegt bzw. beim Übernehmen wieder entfernt. 0 legt weiterhin alle an.',
            ];
        }
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'EnergyUnitWh',
            'caption' => 'Energie in Wh statt kWh ausgeben (Basiseinheit; die neue IPS-Darstellung skaliert dann selbst auf Wh/kWh/MWh)',
        ];
        $groupItems[] = [
            'type'    => 'CheckBox',
            'name'    => 'AutoArchive',
            'caption' => 'Messwerte automatisch archivieren',
        ];
        if (!$this->ReadPropertyBoolean('AutoArchive')) {
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => '⚠️ Automatische Archivierung ist aus. Neue Messwert-Variablen werden nicht ins Archiv aufgenommen. Das ist nur für eine Migration aus einem Altmodul (z. B. GoodweET) gedacht — dabei muss die Zielvariable ohne eigene Historie bleiben, damit MigrationsHub die Alt-Historie übernehmen kann. Nach der Migration wieder einschalten.',
            ];
        }

        // Meter-Adresse nur bei Fronius relevant: Dort ist der Smart Meter ein
        // eigenständiges Modbus-Gerät auf derselben IP mit eigener Unit-ID
        // (SMA/SolarEdge liefern den Zähler in der eigenen SunSpec-Kette).
        if ($this->ReadPropertyString('Manufacturer') === 'fronius') {
            $groupItems[] = [
                'type'    => 'NumberSpinner',
                'name'    => 'MeterUnitId',
                'caption' => 'Smart-Meter-Adresse (Modbus Unit-ID, Vorgabe 200; je nach Fronius-Konfiguration z. B. 240)',
                'minimum' => 1,
                'maximum' => 247,
            ];
        }

        // Byte-/Wortreihenfolge nur bei Kostal: Der Plenticore bietet im
        // Webinterface (Einstellungen → Modbus/Sunspec) die Wahl zwischen
        // „little-endian (CDAB) Standard Modbus" (Werkseinstellung) und
        // „big-endian (ABCD) Sunspec". Muss hier passend gewählt werden, sonst
        // liefert das Gerät unbrauchbare Float-Werte (riesige/negative Zahlen).
        if ($this->ReadPropertyString('Manufacturer') === 'kostal') {
            $groupItems[] = [
                'type'    => 'Select',
                'name'    => 'KostalByteOrder',
                'caption' => 'Byte-Reihenfolge (muss zur Einstellung im Plenticore passen)',
                'options' => [
                    ['caption' => 'little-endian (CDAB) — Standard Modbus (Werkseinstellung)', 'value' => 0],
                    ['caption' => 'big-endian (ABCD) — Sunspec', 'value' => 1],
                ],
            ];
        }

        // SMA: Die SunSpec-Kette liegt auf einer um 123 versetzten Unit-ID.
        if ($this->ReadPropertyString('Manufacturer') === 'sma') {
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => 'ℹ SMA-Besonderheit bei der Unit-ID: Die SunSpec-Daten liegen NICHT auf der Unit-ID, '
                    . 'die in der SMA-Oberfläche eingestellt ist, sondern auf dieser Zahl PLUS 123 — bei der '
                    . 'SMA-Vorgabe 3 also auf 126, bei 4 auf 127. Das Modul probiert den Versatz automatisch, '
                    . 'wenn unter der eingetragenen Unit-ID nichts gefunden wird. Kommt trotzdem keine Verbindung '
                    . 'zustande: In der SMA-Oberfläche unter „Externe Kommunikation" den Modbus-TCP-Server '
                    . 'aktivieren und prüfen, welche Unit-ID dort steht.',
            ];
        }

        // Victron: Unit-IDs der einzelnen Solarladeregler (MPPT). Für die
        // optionale Gruppe „PV je Solarladeregler / MPPT".
        if ($this->ReadPropertyString('Manufacturer') === 'victron') {
            $groupItems[] = [
                'type'    => 'ValidationTextBox',
                'name'    => 'VictronMpptUnitIds',
                'caption' => 'Solarladeregler-Unit-IDs (MPPT, kommagetrennt, max. 4 — im GX unter Einstellungen → Services → Modbus TCP → verfügbare Dienste ablesbar). Aktiviert die Gruppe „PV je Solarladeregler / MPPT".',
            ];
            $groupItems[] = [
                'type'    => 'NumberSpinner',
                'name'    => 'VictronGridUnitId',
                'caption' => 'Unit-ID des Netzzählers (0 = keiner) — für die Gruppe „Energiezähler"',
                'minimum' => 0,
                'maximum' => 247,
            ];
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => 'ℹ Zählerstände liegen bei Victron NICHT im Systemdienst, sondern auf eigenen Diensten: '
                    . 'Netzbezug/-einspeisung beim Netzzähler (com.victronenergy.grid, Unit-ID oben eintragen), '
                    . 'der Solarertrag bei den Solarladereglern (nutzt die MPPT-Unit-IDs mit). '
                    . 'Hinweis: Der Solarertrag-Gesamtzähler ist geräteseitig nur 16 Bit und läuft nach 6.553,5 kWh über; '
                    . 'Netzbezug und Einspeisung werden dagegen aus 32-Bit-Registern gelesen und sind davon nicht betroffen.',
            ];
            $groupItems[] = [
                'type'    => 'CheckBox',
                'name'    => 'VictronYieldFix',
                'caption' => 'Solarertrag: Zählerüberlauf korrigieren (nötig ab 6.553,5 kWh je Laderegler)',
            ];
            $groupItems[] = [
                'type'    => 'ValidationTextBox',
                'name'    => 'VictronYieldCalib',
                'caption' => 'Eichung: tatsächlicher Gesamtertrag je Laderegler in kWh, kommagetrennt in derselben Reihenfolge wie die Unit-IDs oben',
            ];
            $groupItems[] = [
                'type'    => 'Label',
                'caption' => 'ℹ Zur Eichung den Wert „Gesamt Lebensdauer" je Laderegler aus der VictronConnect-App bzw. dem GX ablesen und hier eintragen (Beispiel bei zwei Reglern: 10617,9628). Daraus ermittelt das Modul einmalig, wie oft der Zähler bereits übergelaufen ist; danach zählt es weitere Überläufe selbst mit. '
                    . 'Wird der Ertragszähler am Gerät zurückgesetzt, erkennt das Modul dies und setzt die Korrektur aus — dann bitte neu eichen. '
                    . 'Die Einzelwerte je Laderegler werden zusätzlich angelegt, damit sichtbar bleibt, was gemessen und was gerechnet ist.',
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items' => array_merge([
                        $this->VersionLabel(),
                    ], [
                        ['type' => 'Label', 'caption' => 'InverterHub liest Wechselrichter verschiedener Hersteller direkt per Modbus TCP aus. Hersteller wählen, IP-Adresse oder Hostname (und ggf. Port/Unit-ID) eintragen, Datenpunkt-Gruppen je nach Anlage aktivieren. Tipp: Trägt man statt der IP einen festen Hostnamen ein (DHCP-Reservierung/mDNS), läuft das Modul auch nach einem IP-Wechsel des Wechselrichters weiter.'],
                        ['type' => 'Label', 'caption' => 'Unterstützte Hersteller: GoodWe (GW-ET/EH/BT/BH), Sungrow (SH-Hybrid), Solis (Hybrid, 33000er-Register), Growatt (TL-X/TL3-X/MOD/MIX/SPH/WIT), SolaX, SMA (STP/STPS/SI, inkl. Netzmessung), Fronius (SunSpec, GEN24-Hybrid inkl. Batterie/Smart Meter), SolarEdge (inkl. StorEdge-Batterie), Deye (SG04LP3), Solplanet/AISWEI, Kostal (PLENTICORE plus Gen. 1), Victron GX (Cerbo/Venus OS) und Huawei SUN2000 (inkl. DTSU666-Zähler + LUNA2000-Batterie).'],
                        ['type' => 'Label', 'caption' => '⚙️ Anschluss-Besonderheiten je Hersteller:'],
                        ['type' => 'Label', 'caption' => '• Kostal: Standard-Port ist 1502 (nicht 502). Zusätzlich die Byte-Reihenfolge passend zum Wechselrichter wählen (Werkseinstellung CDAB).'],
                        ['type' => 'Label', 'caption' => '• Victron GX: Port 502; die Unit-ID ist bei Victron ein Geräte-Selektor – der Systemdienst liegt fest auf 100 und wird automatisch angesprochen (die Formular-Unit-ID wird ignoriert). Im GX unter Einstellungen → Services → Modbus TCP aktivieren.'],
                        ['type' => 'Label', 'caption' => '• Huawei SUN2000: Port 502, Unit-ID meist 1 (je nach Konfiguration auch 0/16). Modbus TCP im Wechselrichter aktivieren.'],
                        ['type' => 'Label', 'caption' => '• Fronius: Der Smart Meter ist ein eigenes Modbus-Gerät mit eigener Unit-ID – über das Feld „Smart-Meter-Adresse" einstellbar (Vorgabe 200, je nach Konfiguration z. B. 240).'],
                        ['type' => 'Label', 'caption' => '• SolaX: Der Wechselrichter selbst spricht nur Modbus RTU. Modbus TCP läuft nur über ein zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als Gateway – dessen IP-Adresse eintragen, nicht die des Wechselrichters.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Vorzeichen-Konvention (modulweit): Batterie + = Entladen / − = Laden; Netz-Meter + = Einspeisung / − = Bezug. Stimmt eine Richtung an der eigenen Anlage nicht, hilft der jeweilige Invers-Schalter unten – die InverterHubTile-Kachel bleibt dabei automatisch korrekt.'],
                        ['type' => 'Label', 'caption' => '🛡️ Isolationswiderstand (Riso): bei GoodWe, Huawei, Sungrow, SMA und Kostal verfügbar; bei Growatt optional (modellabhängig). Reine SunSpec-Geräte (Fronius/SolarEdge) liefern ihn nicht.'],
                        ['type' => 'Label', 'caption' => 'Registeradressen stehen im Beschreibungsfeld jeder Variable (Objekt-Manager, Spalte „Beschreibung").'],
                    ]),
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Kommunikation aktiv',
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Manufacturer',
                    'caption' => 'Hersteller',
                    'options' => [
                        ['label' => 'GoodWe',  'value' => 'goodwe'],
                        ['label' => 'Sungrow', 'value' => 'sungrow'],
                        ['label' => 'Solis',   'value' => 'solis'],
                        ['label' => 'Growatt', 'value' => 'growatt'],
                        ['label' => 'SolaX (nur über SolaX-Monitoring-Dongle)', 'value' => 'solax'],
                        ['label' => 'SMA',     'value' => 'sma'],
                        ['label' => 'Fronius', 'value' => 'fronius'],
                        ['label' => 'SolarEdge', 'value' => 'solaredge'],
                        ['label' => 'Deye', 'value' => 'deye'],
                        ['label' => 'Solplanet / AISWEI', 'value' => 'solplanet'],
                        ['label' => 'Kostal (PLENTICORE plus Gen. 1)', 'value' => 'kostal'],
                        ['label' => 'Victron GX (Cerbo/Venus OS, Unit-ID 100)', 'value' => 'victron'],
                        ['label' => 'Huawei SUN2000 (+ DTSU666 / LUNA2000, Unit-ID meist 1)', 'value' => 'huawei'],
                        ['label' => 'FoxESS H1/H3 (Read-Only-Vorabversion, Beta)', 'value' => 'foxess'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔌  Verbindung',
                    'expanded' => true,
                    'items' => [
                        // IP-Adresse ODER Hostname erlaubt (fsockopen löst den
                        // Namen per DNS auf) - so überlebt die Instanz einen
                        // IP-Wechsel des Wechselrichters, wenn ein fester Name
                        // (DHCP-Reservierung/mDNS, z. B. „wr-fronius.local") genutzt wird.
                        ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse oder Hostname', 'validate' => '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🏠  Externer Hauslastzähler — Eingang (optional)',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Eingang, nicht Ausgang: Hier optional eine bereits vorhandene Variable mit real GEMESSENER Hauslast auswählen (z. B. ein separater Energiezähler/Shelly am Hausanschluss). Bitte einen echten Verbrauchszähler wählen (immer positiv) — kein Netz-/Einspeisezähler, der negativ werden kann. Ist ein Zähler gewählt, zeigt die InverterHubTile-Kachel damit eine genauere Last sowie die Differenz zur PV/Netz/Batterie-Bilanz als „Wandlungsverluste" (Wechselrichter-Eigenverbrauch, Leitungsverluste). Ohne Auswahl bleibt es bei der reinen Bilanzschätzung.'],
                        ['type' => 'Label', 'caption' => 'Hinweis: Die vom Modul BERECHNETE Hauslast als eigene Variable AUSGEBEN kannst du dagegen in der Kachel-Instanz (InverterHubTile) → Panel „Datenquelle" → „Berechnete Hauslast … in eine Variable schreiben".'],
                        ['type' => 'SelectVariable', 'name' => 'HouseLoadMeterID', 'caption' => 'Externe Hauslast-Messvariable (Eingang)'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⏱️  Polling',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Schnell-Intervall (Sekunden)', 'minimum' => 5, 'maximum' => 60, 'suffix' => 's'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalSlow', 'caption' => 'Langsam-Intervall (Sekunden)', 'minimum' => 60, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📊  Datenpunkte',
                    'expanded' => true,
                    'items' => $groupItems,
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Verbindung testen / Daten sofort lesen', 'onClick' => 'IHUB_ReadFast($id);'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte IP-Adresse oder Hostname eintragen.'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Verbindung aktiv.'],
                ['code' => 201, 'icon' => 'error',     'caption' => 'Verbindungsfehler – Wechselrichter nicht erreichbar.'],
            ],
        ];

        // Einmaliger Beta-Hinweis mit Link zum Symcon-Forum-Thread, bis er
        // per Button ausgeblendet wird (Attribut, kein Übernehmen nötig).
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 InverterHub ist Beta — Rückmeldungen und Testberichte sind im Symcon-Forum-Thread willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::FORUM_THREAD_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'IHUB_DismissReviewHint($id);'],
                ],
            ];
        }

        // „Was ist neu"-Banner nach einem Update ganz oben.
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        return json_encode($form);
    }

    // Versionszeile im Doku-Panel (Verbund-Konvention, Teil 2 der einheitlichen
    // Formular-Optik, EMS 24.07.2026): Das „Was ist neu"-Banner ist dismissible
    // und verschwindet damit irgendwann - die Versionsnummer muss deshalb an
    // einer IMMER sichtbaren Stelle stehen, nicht nur im Banner.
    // Bibliotheks-GUID = "id" aus library.json (nicht die Modul-GUID).
    private function VersionLabel(): array
    {
        $lib = @IPS_GetLibrary('{7EFE4BD7-DC14-460E-B0ED-88071197D35B}');
        $txt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ InverterHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ InverterHub';
        return ['type' => 'Label', 'caption' => $txt];
    }

    // „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet leer),
    // bis der Nutzer „Verstanden" klickt. Neuinstallation sieht es einmalig.
    private function newsBanner()
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'IHUB_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissReviewHint()
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    // -----------------------------------------------------------------------
    // Treiber-Auswahl
    // -----------------------------------------------------------------------

    private function GetDriver(){
        if ($this->driver !== null) {
            return $this->driver;
        }
        $key   = $this->ReadPropertyString('Manufacturer');
        $class = self::DRIVERS[$key] ?? self::DRIVERS['goodwe'];
        $this->driver = new $class();
        return $this->driver;
    }

    // Modbus-Unit-ID des Smart Meters (für SunSpec-Hersteller, deren Zähler
    // ein eigenständiges Modbus-Gerät auf derselben IP/Port ist).
    public function GetMeterUnitId(): int
    {
        $v = (int)$this->ReadPropertyInteger('MeterUnitId');
        return ($v >= 1 && $v <= 247) ? $v : 200;
    }

    // Float32-Wortreihenfolge bei Kostal: true = CDAB (Wort-Swap), false = ABCD.
    public function GetKostalWordSwap(): bool
    {
        return (int)$this->ReadPropertyInteger('KostalByteOrder') === 0;
    }

    // Victron: Unit-IDs der Solarladeregler (MPPT) als Integer-Array (max. 4).
    /**
     * Ist eine optionale Gruppe aktiviert? Treiber nutzen das, um teure Lesungen
     * zu überspringen, deren Variablen ohnehin nicht existieren.
     */
    public function GroupEnabled(string $propName): bool
    {
        try {
            return $this->ReadPropertyBoolean($propName);
        } catch (Throwable $e) {
            return false; // Gruppe kennt der aktuelle Treiber nicht
        }
    }

    // Zustand der Überlaufkorrektur, innerhalb eines Lesezyklus gepuffert und
    // am Ende gesammelt geschrieben (FlushYieldState) - nicht je Regler einzeln.
    private $yieldState = null;
    private $yieldDirty = false;

    /**
     * Rechnet den 16-Bit-Rohwert des Solarertrags (Reg 790, ÷10) auf den
     * tatsächlichen Gesamtertrag hoch.
     *
     * Hintergrund: Victron stellt diesen Zähler über Modbus nur als UINT16
     * bereit - bei ÷10 ist bei 6553,5 kWh Schluss, danach zählt er wieder bei
     * null an. Eine 32-Bit-Variante existiert nicht.
     *
     * Verfahren:
     *  - EICHUNG: Beim ersten Lauf je Regler wird die Zahl der bisherigen
     *    Überläufe aus dem vom Nutzer eingetragenen Ist-Wert bestimmt (aus der
     *    Victron-App ablesbar). Ohne Eintrag wird 0 angenommen.
     *  - FORTZÄHLEN: Sinkt der Rohwert, war es entweder ein Überlauf oder ein
     *    Reset des Zählers durch den Nutzer. Unterschieden wird über die Höhe:
     *    Vor einem Überlauf MUSS der Zähler nahe am Maximum stehen. Nur dann
     *    wird hochgezählt; sonst gilt es als Reset und die Korrektur beginnt
     *    von vorn (der Nutzer muss dann neu eichen).
     *
     * Ist die Korrektur abgeschaltet, wird der Rohwert unverändert geliefert.
     */
    public function YieldCorrected(int $unitId, int $index, int $raw): float
    {
        if (!$this->ReadPropertyBoolean('VictronYieldFix')) {
            return $raw / 10.0;
        }
        if ($this->yieldState === null) {
            $s = json_decode($this->ReadAttributeString('VictronYieldState'), true);
            $this->yieldState = is_array($s) ? $s : [];
        }
        $key = (string) $unitId;

        if (!isset($this->yieldState[$key])) {
            // Eichung aus dem eingetragenen Ist-Wert des Geräts.
            $calib = $this->GetVictronYieldCalib();
            $true  = (float) ($calib[$index] ?? 0.0);
            $wraps = ($true > 0.0) ? intdiv((int) round($true * 10), 65536) : 0;
        } else {
            $wraps = (int) $this->yieldState[$key]['w'];
            $last  = (int) $this->yieldState[$key]['r'];
            if ($raw < $last) {
                // Überlauf nur annehmen, wenn der Zähler vorher nahe am Maximum
                // stand. Ein Nutzer-Reset kommt von einem beliebigen Wert und
                // darf NICHT als Überlauf gezählt werden - das addierte sonst
                // fälschlich 6553,6 kWh.
                if ($last >= 60000 && $raw <= 5000) {
                    $wraps++;
                } else {
                    $wraps = 0; // Reset erkannt: Korrektur ist ungültig geworden
                }
            }
        }

        $this->yieldState[$key] = ['w' => $wraps, 'r' => $raw];
        $this->yieldDirty = true;
        return ($wraps * 65536 + $raw) / 10.0;
    }

    /** Gepufferten Korrekturzustand schreiben (einmal je Lesezyklus). */
    public function FlushYieldState(): void
    {
        if ($this->yieldDirty && $this->yieldState !== null) {
            $this->WriteAttributeString('VictronYieldState', json_encode($this->yieldState));
            $this->yieldDirty = false;
        }
    }

    /** Eichwerte (tatsächlicher Gesamtertrag je Laderegler, kWh), Reihenfolge wie die Unit-IDs. */
    public function GetVictronYieldCalib(): array
    {
        $out = [];
        foreach (explode(',', $this->ReadPropertyString('VictronYieldCalib')) as $part) {
            $out[] = (float) str_replace(',', '.', trim($part));
        }
        return $out;
    }

    /**
     * Unit-ID des Victron-Netzzählers (Dienst com.victronenergy.grid).
     * 0 = nicht konfiguriert; die Netz-Zählerstände entfallen dann.
     */
    public function GetVictronGridUnitId(): int
    {
        $id = $this->ReadPropertyInteger('VictronGridUnitId');
        return ($id > 0 && $id <= 247) ? $id : 0;
    }

    public function GetVictronMpptUnitIds(): array
    {
        $out = [];
        foreach (explode(',', $this->ReadPropertyString('VictronMpptUnitIds')) as $part) {
            $id = (int)trim($part);
            if ($id > 0 && $id <= 247) {
                $out[] = $id;
            }
            if (count($out) >= 4) {
                break;
            }
        }
        return $out;
    }

    private function GetModbusClient(): IHUB_ModbusTcpClient
    {
        return new IHUB_ModbusTcpClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('UnitId')
        );
    }

    // -----------------------------------------------------------------------
    // Variablen-Registrierung (generisch, treiberunabhängig)
    // -----------------------------------------------------------------------

    private function RegisterVariables()
    {
        $driver = $this->GetDriver();

        // Menge der gültigen Idents des AKTUELL gewählten Treibers (Basis +
        // aktivierte optionale Gruppen). Alles andere unter der Instanz ist
        // ein Überbleibsel eines anderen Herstellers oder einer deaktivierten
        // Gruppe und wird entfernt - sonst blieben beim Herstellerwechsel
        // (z. B. GoodWe -> Fronius) die Ordner/Variablen des vorigen Treibers
        // stehen (real gemeldet: neu angelegte Fronius-Instanz zeigte die
        // GoodWe-Datenpunkte).
        $valid = [];
        foreach ($driver->getBaseVars() as $v) {
            $valid[$v[0]] = true;
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($this->MpptFiltered($group['vars']) as $v) {
                    $valid[$v[0]] = true;
                }
            }
        }
        $this->PruneForeignObjects($valid);

        $pos = 0;
        foreach ($driver->getBaseVars() as $v) {
            $this->RegisterVar($v, $pos++);
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($this->MpptFiltered($group['vars']) as $v) {
                    $this->RegisterVar($v, $pos++);
                }
            }
        }
    }

    /**
     * Blendet Variablen nicht vorhandener MPPT-Eingänge aus.
     *
     * Die Treiber definieren so viele MPPT-Idents (mppt1_*, mppt2_* …), wie die
     * jeweilige Baureihe maximal haben kann — beim Sungrow SG-CX etwa zwölf.
     * Wer nur zwei Strings betreibt, bekäme sonst zehn leere Variablen, die den
     * Objektbaum zumüllen und im Archiv Platz kosten.
     *
     * MpptCount = 0 bedeutet „alle anlegen" (Vorgabe, bisheriges Verhalten).
     * Idents ohne mppt-Nummer bleiben immer erhalten.
     *
     * Die Filterung greift bewusst auch in der Liste gültiger Idents, damit
     * PruneForeignObjects() überzählige Variablen einer früheren Einstellung
     * wieder abräumt, statt sie verwaist stehen zu lassen.
     */
    private function MpptFiltered(array $vars): array
    {
        $max = $this->ReadPropertyInteger('MpptCount');
        if ($max <= 0) {
            return $vars;
        }
        $out = [];
        foreach ($vars as $v) {
            if (preg_match('/^mppt(\d+)_/', (string) $v[0], $m) && (int) $m[1] > $max) {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }

    // Entfernt unter der Instanz alle Variablen mit einem Ident, das nicht in
    // $validIdents steht (Reste eines anderen Treibers / deaktivierter Gruppe),
    // und räumt danach leere Modul-Kategorien (cat_*) ab.
    private function PruneForeignObjects(array $validIdents)
    {
        $all = [];
        $collect = function ($pid) use (&$collect, &$all) {
            foreach (IPS_GetChildrenIDs($pid) as $cid) {
                $all[] = $cid;
                if (IPS_GetObject($cid)['ObjectType'] === 0) {
                    $collect($cid);
                }
            }
        };
        $collect($this->InstanceID);

        // 1) Variablen (ObjectType 2) mit ungültigem Ident löschen.
        foreach ($all as $cid) {
            if (!IPS_ObjectExists($cid)) {
                continue;
            }
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2 || $obj['ObjectIdent'] === '') {
                continue;
            }
            if (!isset($validIdents[$obj['ObjectIdent']])) {
                @IPS_DeleteVariable($cid);
            }
        }

        // 2) Leere Modul-Kategorien (cat_*) entfernen.
        foreach ($all as $cid) {
            if (!IPS_ObjectExists($cid)) {
                continue;
            }
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] === 0
                && strpos($obj['ObjectIdent'], 'cat_') === 0
                && count(IPS_GetChildrenIDs($cid)) === 0) {
                @IPS_DeleteCategory($cid);
            }
        }
    }

    // Custom Actions werden hier bewusst NICHT gesetzt — das übernimmt
    // EnableActions() per Kurz-Timer nach Abschluss der Transaktion
    // (siehe Kommentar in Create()).
    private function RegisterVar(array $def, int $pos)
    {
        [$ident, $caption, $type, $profile, $archive, $group] = $def;
        $reg = isset($def[6]) ? $def[6] : '';

        $vtype = [
            'F' => VARIABLETYPE_FLOAT,
            'I' => VARIABLETYPE_INTEGER,
            'B' => VARIABLETYPE_BOOLEAN,
            'S' => VARIABLETYPE_STRING,
        ][$type];

        $vid = $this->FindVarByIdent($ident);
        // Typ-Migration: Ändert sich der gewünschte Variablentyp (z. B. Fronius-
        // SOC von Integer auf Float), muss die bestehende Variable neu angelegt
        // werden - IPS kann den Typ einer Variable nicht nachträglich ändern.
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== $vtype) {
            @IPS_DeleteVariable($vid);
            $vid = 0;
        }
        $created = false;
        if (!$vid) {
            $vid = IPS_CreateVariable($vtype);
            IPS_SetIdent($vid, $ident);
            $created = true;
        }

        $catID = $this->EnsureCategory($group);
        IPS_SetParent($vid, $catID);
        IPS_SetPosition($vid, $pos);
        IPS_SetName($vid, $caption);

        // Energie in Wh: Statt des kWh-Standardprofils ~Electricity das Wh-
        // Profil setzen. Die Skalierung des Werts (×1000) übernimmt SetVarFloat.
        $isEnergy = ($profile === '~Electricity');
        if ($isEnergy && $this->ReadPropertyBoolean('EnergyUnitWh')) {
            $profile = 'IHB.Wh';
        }
        // Profil NUR bei Neuanlage setzen (bzw. bei einer echten Wh-Umstellung
        // für Energie-Variablen). Ab IPS 7 leert eine vom Nutzer gewählte
        // Presentation („Wertanzeige") das CustomProfile; würden wir es bei
        // jedem „Übernehmen" neu setzen, sprängen die Variablen zurück auf
        // „Legacy". Bestehende Variablen fassen wir daher nicht mehr an.
        if ($profile !== '' && ($created || ($this->reprofileEnergy && $isEnergy))) {
            if (@IPS_GetVariable($vid)['VariableCustomProfile'] !== $profile) {
                IPS_SetVariableCustomProfile($vid, $profile);
            }
        }
        if ($reg !== '') {
            IPS_SetInfo($vid, $reg);
        }
        // Auto-Archivierung nur bei NEU angelegter Variable und nur, wenn der
        // Nutzer sie nicht abgeschaltet hat (Migrations-Vorbedingung, s. o.).
        // Bereits geloggte Variablen fassen wir nie an - ein Nutzer, der die
        // Archivierung von Hand ausgeschaltet hat, soll das behalten.
        if ($archive && $created && $this->ReadPropertyBoolean('AutoArchive')) {
            $this->SetArchive($vid, $isEnergy);
        } elseif ($archive && $isEnergy && !$created) {
            // Bestandskorrektur (real gemeldeter Fehler, 24.07.2026): Vor
            // Build 182 wurden Energie-Variablen mit Aggregationstyp
            // "Standard" statt "Zaehler" archiviert. Fuer bereits vorhandene
            // Variablen HISTORIENERHALTEND nachkorrigieren - AC_SetAggregationType
            // aendert nur die Archiv-Metadaten (wie kuenftig aggregiert wird),
            // NICHT die gespeicherten Werte. Kein Loeschen/Neuanlegen noetig.
            // Laeuft bei jedem ApplyChanges automatisch mit (u. a. direkt nach
            // einem Modul-Update) - ohne Zutun des Nutzers, ohne Datenverlust.
            $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (count($archiveIDs) > 0
                && @AC_GetLoggingStatus($archiveIDs[0], $vid)
                && @AC_GetAggregationType($archiveIDs[0], $vid) !== 1) {
                AC_SetAggregationType($archiveIDs[0], $vid, 1);
            }
        }
    }

    private function UnregVarIfExists($ident)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            IPS_DeleteVariable($vid);
        }
    }

    private const CATEGORY_LABELS = [
        'pv'        => 'PV / MPPT',
        'bat'       => 'Batterie',
        'bat1'      => 'Batterie 1',
        'bat2'      => 'Batterie 2',
        'batcommon' => 'Batterie (gemeinsam)',
        'grid'      => 'Netz',
        'meter'     => 'Smart Meter',
        'backup'    => 'Backup / Insel',
        'energy'    => 'Energiezähler',
        'device'    => 'Wechselrichter / Gerät',
        'control'   => 'EMS-Steuerung',
        'errors'    => 'Fehler / Verbindung',
    ];

    private function EnsureCategory($key){
        $catIdent = 'cat_' . $key;
        $catID = $this->FindIdentRecursive($this->InstanceID, $catIdent);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, $catIdent);
            $pos = array_search($key, array_keys(self::CATEGORY_LABELS));
            IPS_SetPosition($catID, $pos !== false ? $pos : 99);
        }
        IPS_SetName($catID, self::CATEGORY_LABELS[$key] ?? $key);
        return $catID;
    }

    private function FindVarByIdent($ident){
        return $this->FindIdentRecursive($this->InstanceID, $ident);
    }

    private function FindIdentRecursive(int $parentID, $ident){
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectIdent'] === $ident) {
                return $childID;
            }
            if ($obj['ObjectType'] === 0) {
                $found = $this->FindIdentRecursive($childID, $ident);
                if ($found) {
                    return $found;
                }
            }
        }
        return 0;
    }

    // Aggregationstyp beim ERSTMALIGEN Archivieren setzen: 1 (Zähler) für
    // kumulative Energie-Idents (Profil ~Electricity/IHB.Wh - Ertrag, Bezug,
    // Einspeisung usw.), sonst 0 (Standard/Mittelwert) für Momentanwerte.
    //
    // ACHTUNG, real gemeldeter Fehler (Tester sirkentucky, 24.07.2026): Hier
    // stand zuvor UNBEDINGT AggregationType 0, auch für Energie-Idents. Für
    // einen kWh-Zähler ist das falsch - AC_GetAggregatedValues liefert dann
    // bei Zeitreihen-Auswertungen den ROHSTAND statt des Periodenverbrauchs
    // (siehe die Counter-Aggregations-Falle, an anderer Stelle bereits
    // dokumentiert). Der Nutzer hatte es von Hand auf „Zähler" umgestellt,
    // aber jede Neuanlage der Variable (z. B. nach Loeschen) setzte es wieder
    // auf Standard zurueck.
    // Nur bei ERSTANLAGE gesetzt (Aufrufer prueft $created) - eine vom Nutzer
    // manuell geaenderte Aggregation an einer bestehenden Variable wird nie
    // wieder ueberschrieben.
    private function SetArchive($vid, bool $isEnergy = false)
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            AC_SetLoggingStatus($archiveIDs[0], $vid, true);
            AC_SetAggregationType($archiveIDs[0], $vid, $isEnergy ? 1 : 0);
        }
    }

    // -----------------------------------------------------------------------
    // Variable setzen (public, damit Treiber sie via $hub->... aufrufen können)
    // -----------------------------------------------------------------------

    public function SetVarFloat(string $ident, float $value)
    {
        // Robustheit: Liefert ein Register keinen gültigen Float32 (z. B. weil
        // ein Gerät an dieser Adresse gar keinen Float ablegt oder eine andere
        // Byte-/Wort-Reihenfolge nutzt), entstehen NaN/INF. IP-Symcon lehnt die
        // beim Setzen mit einer Warnung ab ("NaN/INF Werte werden nicht
        // unterstützt") - wir fangen das zentral ab und schreiben 0.0.
        if (!is_finite($value)) {
            $value = 0.0;
        }
        // Zentraler Invers-Schalter für die Meter-Leistung: Je nach Einbauort/
        // Verdrahtung des Zählers melden Anlagen die Richtung genau umgekehrt -
        // der Nutzer entscheidet selbst, statt dass wir je Hersteller raten.
        if ($ident === 'meter_total' && $this->ReadPropertyBoolean('MeterInvert')) {
            $value = -$value;
        }
        // Analog für die Batterieleistung (Vorzeichen je nach gewünschter
        // Konvention). Modul-Standard ist + = Entladen, − = Laden.
        if (in_array($ident, ['bat_total_pwr', 'bat_power', 'bat1_pwr', 'bat2_pwr'], true)
            && $this->ReadPropertyBoolean('BatInvert')) {
            $value = -$value;
        }
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            // Energie in Wh: Die Treiber liefern kWh. Ist der Schalter aktiv und
            // handelt es sich um eine Energie-Variable, auf Wh hochrechnen. Die
            // Erkennung erfolgt am Ident (nicht am Profil), damit eine vom Nutzer
            // geänderte Presentation die Skalierung nicht aushebelt.
            if ($this->ReadPropertyBoolean('EnergyUnitWh') && $this->IsEnergyIdent($ident)) {
                $value *= 1000.0;
            }
            SetValueFloat($vid, $value);
        }
    }

    // Energie-Zähler-Variablen (kWh), die der Wh-Schalter auf Wh hochrechnet.
    // Deckt alle ~Electricity-Idents ab: e_* plus die wenigen Ausnahmen.
    private function IsEnergyIdent(string $ident): bool
    {
        return strncmp($ident, 'e_', 2) === 0
            || in_array($ident, ['meter_imp', 'meter_exp', 'home_total', 'bat_capacity'], true);
    }

    public function SetVarInt(string $ident, int $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueInteger($vid, $value);
        }
    }

    public function SetVarBool(string $ident, bool $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueBoolean($vid, $value);
        }
    }

    public function SetVarStr(string $ident, string $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueString($vid, $value);
        }
    }

    // Öffentlicher Wrapper: ReadPropertyBoolean() ist bei IPSModule protected
    // und daher von externen Treiber-Klassen (IHUB_GoodweDriver etc.) nicht direkt
    // aufrufbar.
    public function GetPropBool(string $name)
    {
        return $this->ReadPropertyBoolean($name);
    }

    // -----------------------------------------------------------------------
    // Profile (treiberdefiniert)
    // -----------------------------------------------------------------------

    private function CreateProfiles()
    {
        $driver = $this->GetDriver();

        // Energie-Profil in Wh (Basiseinheit) für die optionale Wh-Ausgabe.
        if (!IPS_VariableProfileExists('IHB.Wh')) {
            IPS_CreateVariableProfile('IHB.Wh', VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileDigits('IHB.Wh', 0);
        IPS_SetVariableProfileText('IHB.Wh', '', ' Wh');

        foreach ($driver->getProfiles() as $name => $def) {
            [$type, $suffix, $min, $max, $step, $digits] = $def;
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
            }
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }

        foreach ($driver->getEnumProfiles() as $name => $assocs) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            }
            foreach ($assocs as $value => $def) {
                [$label, $color] = $def;
                IPS_SetVariableProfileAssociation($name, $value, $label, '', $color);
            }
        }
    }
}
