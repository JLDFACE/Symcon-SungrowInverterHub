<?php

// ---------------------------------------------------------------------------
// InverterHubDiscovery — Configurator-Modul: durchsucht einen IP-Bereich nach
// Wechselrichtern auf Modbus-TCP-Port 502, erkennt den Hersteller anhand
// weniger charakteristischer Register/Unit-IDs und legt auf Klick eine
// InverterHub-Instanz mit vorausgefüllten Werten an.
// Eigenständige, kompakte Modbus-Hilfsfunktionen (kein Zugriff auf die
// Klassen aus dem InverterHub-Modulordner — Module sind bewusst getrennt).
// ---------------------------------------------------------------------------

class InverterHubDiscovery extends IPSModule
{
    private const INVERTERHUB_GUID = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    // MeterHub-Zählermodul: Ist es installiert, bietet die Suche gefundene
    // Energiezähler gleich als MeterHub-Instanz zum Anlegen an (kombinierter
    // Scan: Wechselrichter + Zähler in einem Durchgang).
    private const METERHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const METER_LABELS = [
        'siemens_pac2200' => 'Siemens PAC2200',
        'janitza_umg604'  => 'Janitza UMG (klassische Map)',
        'janitza_umg800'  => 'Janitza UMG 800',
    ];

    // Kandidaten je Hersteller: Unit-IDs, die typischerweise/dokumentiert
    // Standard sind (kleine Liste statt vollem 1-247-Bereich).
    private const VENDOR_UNIT_IDS = [
        'goodwe'    => [247, 1],
        'sungrow'   => [1, 247, 246],
        'solis'     => [1],
        'growatt'   => [1],
        'solax'     => [1],
        'sma'       => [3, 1, 126],
        'fronius'   => [1, 100],
        'solaredge' => [1],
        'deye'      => [1, 2],
        'solplanet' => [3, 1],
        'kostal'    => [71, 1],
        'victron'   => [100],
        'huawei'    => [1, 0, 16],
    ];

    private const VENDOR_LABELS = [
        'goodwe'    => 'GoodWe',
        'sungrow'   => 'Sungrow',
        'solis'     => 'Solis',
        'growatt'   => 'Growatt',
        'solax'     => 'SolaX',
        'sma'       => 'SMA',
        'fronius'   => 'Fronius (SunSpec)',
        'solaredge' => 'SolarEdge (SunSpec)',
        'deye'      => 'Deye',
        'solplanet' => 'Solplanet / AISWEI',
        'kostal'    => 'Kostal',
        'victron'   => 'Victron GX',
        'huawei'    => 'Huawei SUN2000',
    ];

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-inverterhub-multi-wechselrichter-ein-modbus-tcp-modul-fuer-goodwe-sma-fronius-sungrow-solis-growatt-solax/144121';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    // „Was ist neu"-Banner (siehe newsBanner()/AckNews()).
    private const NEWS_VERSION = '0.45';
    private const NEWS_ITEMS = [
        'Victron und Huawei werden jetzt mit erkannt.',
        'Freie Namensvorlage für neue Instanzen mit Platzhaltern ({hersteller}, {ip}, {unitid}, {nr}).',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('SeenNews', '');

        $prefix = $this->guessLocalSubnetPrefix();
        $this->RegisterPropertyString('RangeStart', $prefix !== '' ? $prefix . '.1'   : '');
        $this->RegisterPropertyString('RangeEnd',   $prefix !== '' ? $prefix . '.254' : '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyString('NameTemplate', '');
        // IPs, die vom Scan ausgenommen werden (z. B. RTU/TCP-Konverter oder
        // andere Modbus-Geräte, die als Wechselrichter durchgehen würden).
        $this->RegisterPropertyString('IgnoreIPs', '');
        $this->RegisterAttributeString('ResultsJSON', '[]');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
    }

    // Ermittelt heuristisch die ersten drei Oktette des lokalen Subnetzes
    // (z.B. "192.168.1"), um Start-/End-IP sinnvoll vorzubelegen. Nur ein
    // Vorschlag — der Nutzer kann ihn jederzeit überschreiben.
    private function guessLocalSubnetPrefix()
    {
        $ip = @gethostbyname(gethostname());
        if ($ip === false || $ip === gethostname()) {
            return '';
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        $isPrivate = ($parts[0] === '10')
            || ($parts[0] === '192' && $parts[1] === '168')
            || ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31);
        if (!$isPrivate) {
            return '';
        }
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Versteckte Abbruch-Flagge für laufende Scans (thread-sicher über
        // GetValue/SetValue - der „Abbrechen"-Button läuft in einem eigenen
        // Thread und setzt sie, die Scan-Schleifen prüfen sie). In ApplyChanges
        // registriert, damit auch bestehende Instanzen sie nach dem Update haben.
        $this->RegisterVariableBoolean('ScanAbort', 'Scan-Abbruch', '', 100);
        IPS_SetHidden($this->GetIDForIdent('ScanAbort'), true);
    }

    // true, wenn während eines laufenden Scans „Abbrechen" geklickt wurde.
    private function scanAborted(): bool
    {
        return @$this->GetValue('ScanAbort') === true;
    }

    public function AbortScan()
    {
        if (@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->SetValue('ScanAbort', true);
        }
        @$this->UpdateFormField('ScanProgress', 'caption', 'Abbruch angefordert – bitte kurz warten …');
        @$this->UpdateFormField('ScanProgress', 'indeterminate', true);
    }

    public function GetConfigurationForm()
    {
        $results = json_decode($this->ReadAttributeString('ResultsJSON'), true);
        if (!is_array($results)) {
            $results = [];
        }

        $existing = $this->findExistingInstances();
        $template = trim($this->ReadPropertyString('NameTemplate'));

        // Laufende Nummer je Hersteller (1, 2, 3 ...) für den Namens-Default
        // und für den {nr}-Platzhalter der freien Vorlage.
        $vendorCounter = [];

        $values = [];
        foreach ($results as $r) {
            $key = $r['ip'] . '|' . $r['unitId'];
            $vendorCounter[$r['vendor']] = ($vendorCounter[$r['vendor']] ?? 0) + 1;
            $nr = $vendorCounter[$r['vendor']];

            if ($template !== '') {
                $instanceName = str_replace(
                    ['{hersteller}', '{ip}', '{unitid}', '{nr}'],
                    [$r['label'], $r['ip'], $r['unitId'], $nr],
                    $template
                );
            } else {
                $instanceName = $r['label'] . ' ' . $nr;
            }

            // Zähler → MeterHub-Instanz, Wechselrichter → InverterHub-Instanz.
            if (($r['kind'] ?? 'inverter') === 'meter') {
                $create = [
                    'moduleID'      => self::METERHUB_GUID,
                    'name'          => $instanceName,
                    'configuration' => [
                        'Host'   => $r['ip'],
                        'Port'   => $this->ReadPropertyInteger('Port'),
                        'UnitId' => $r['unitId'],
                        'Meter'  => $r['meter'],
                    ],
                ];
            } else {
                $create = [
                    'moduleID'      => self::INVERTERHUB_GUID,
                    'name'          => $instanceName,
                    'configuration' => [
                        'Host'         => $r['ip'],
                        'Port'         => $this->ReadPropertyInteger('Port'),
                        'UnitId'       => $r['unitId'],
                        'Manufacturer' => $r['vendor'],
                    ],
                ];
            }

            $values[] = [
                'name'         => $r['label'] . ' @ ' . $r['ip'] . ' (Unit ' . $r['unitId'] . ')',
                'manufacturer' => $r['label'],
                'ip'           => $r['ip'],
                'unitId'       => $r['unitId'],
                'instanceID'   => $existing[$key] ?? 0,
                'create'       => $create,
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items' => [
                        $this->VersionLabel(),
                        ['type' => 'Label', 'caption' => 'Durchsucht einen IP-Bereich im lokalen Netz nach Wechselrichtern auf Modbus-TCP-Port 502 und erkennt den Hersteller anhand weniger typischer Register/Unit-IDs pro Hersteller.'],
                        ['type' => 'Label', 'caption' => 'Einfach „Ganzes Subnetz durchsuchen" klicken — das lokale Subnetz wird automatisch erkannt und abgesucht. Gefundene Geräte erscheinen unten in der Liste — Klick auf „Erstellen" legt eine InverterHub-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.'],
                        ['type' => 'Label', 'caption' => 'Die Suche prüft nur wenige dokumentierte Standard-Unit-IDs je Hersteller, keinen vollen 1-247-Bereich — bei exotisch konfigurierter Unit-ID bitte die InverterHub-Instanz manuell anlegen.'],
                        ['type' => 'Label', 'caption' => 'Wird ein bekannter Wechselrichter nicht gefunden: unter „Erweitert: manueller IP-Bereich" einen SCHMALEN Bereich (bis 64 Adressen, z. B. .30–.45) um dessen IP absuchen. Kleine Bereiche nutzen eine langsamere, aber zuverlässigere Port-Prüfung — der schnelle Subnetz-Scan kann unter Windows oder bei langsam antwortenden Geräten (z. B. Sungrow WiNet-S) offene Ports übersehen.'],
                        ['type' => 'Label', 'caption' => 'Kombinierte Suche: Ist zusätzlich das Modul „MeterHub" installiert, findet die Suche auch Energiezähler (Janitza, Siemens PAC) und legt sie per „Erstellen" gleich als MeterHub-Instanz an — Wechselrichter und Zähler in einem Durchgang.'],
                        ['type' => 'Label', 'caption' => 'Hinweis: „Filter"/„Aktualisieren" oberhalb und „Erstellen"/„Alle erstellen" unterhalb der Tabelle sind fester Bestandteil der IP-Symcon-Konfigurator-Ansicht selbst — ihre Position lässt sich modulseitig nicht verändern.'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔎  Suchbereich',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Das gesamte lokale Subnetz auf Modbus-TCP-Wechselrichter durchsuchen — keine IP-Eingabe nötig. Das Subnetz wird automatisch erkannt.'],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'Button', 'name' => 'BtnScanSubnet', 'caption' => '🔎  Ganzes Subnetz durchsuchen', 'onClick' => 'IHUBD_DiscoverSubnet($id);'],
                                ['type' => 'Button', 'name' => 'BtnAbort',      'caption' => '✖  Suche abbrechen', 'onClick' => 'IHUBD_AbortScan($id);', 'visible' => false],
                            ],
                        ],
                        [
                            'type'          => 'ProgressBar',
                            'name'          => 'ScanProgress',
                            'caption'       => 'Bereit.',
                            'minimum'       => 0,
                            'maximum'       => 100,
                            'current'       => 0,
                            'indeterminate' => false,
                            'visible'       => false,
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Modbus-TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'ValidationTextBox', 'name' => 'NameTemplate', 'caption' => 'Name-Vorlage (leer = Hersteller + lfd. Nr.)'],
                        ['type' => 'Label', 'caption' => 'Platzhalter für die Vorlage: {hersteller} {ip} {unitid} {nr} — z.B. "{hersteller} Dach ({ip})"'],
                        ['type' => 'ValidationTextBox', 'name' => 'IgnoreIPs', 'caption' => 'IPs ignorieren (Komma-getrennt)'],
                        ['type' => 'Label', 'caption' => 'Diese Adressen werden bei der Suche komplett übersprungen — z.B. RTU/TCP-Konverter oder andere Modbus-Geräte, die sonst fälschlich als Wechselrichter erscheinen würden.'],
                        [
                            'type'    => 'ExpansionPanel',
                            'caption' => '🛠️  Erweitert: manueller IP-Bereich',
                            'expanded' => false,
                            'items' => [
                                ['type' => 'Label', 'caption' => 'Wird ein Wechselrichter beim Subnetz-Scan nicht gefunden (z. B. eine langsam antwortende Sungrow WiNet-S), hier einen SCHMALEN Bereich (bis 64 Adressen, z. B. .30–.45) um seine IP eintragen und „Bereich durchsuchen" klicken. Kleine Bereiche nutzen eine langsamere, aber zuverlässigere Port-Prüfung.'],
                                ['type' => 'ValidationTextBox', 'name' => 'RangeStart', 'caption' => 'Start-IP', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                                ['type' => 'ValidationTextBox', 'name' => 'RangeEnd',   'caption' => 'End-IP',   'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                                ['type' => 'Button', 'name' => 'BtnScan', 'caption' => '🔎  Bereich durchsuchen', 'onClick' => 'IHUBD_Discover($id);'],
                            ],
                        ],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🛠️  Erstellen',
                    'expanded' => true,
                    'items' => [
                        [
                            'type'     => 'Configurator',
                            'name'     => 'DiscoveryList',
                            'caption'  => 'Gefundene Wechselrichter',
                            'rowCount' => 6,
                            'delete'   => false,
                            'sort'     => ['column' => 'ip', 'direction' => 'ascending'],
                            'columns'  => [
                                ['caption' => 'Hersteller', 'name' => 'manufacturer', 'width' => '200px'],
                                ['caption' => 'IP-Adresse', 'name' => 'ip',           'width' => '150px'],
                                ['caption' => 'Unit ID',    'name' => 'unitId',       'width' => '100px'],
                            ],
                            'values' => $values,
                        ],
                    ],
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Bereit.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte den IP-Bereich für die Suche eintragen.'],
            ],
        ];

        // Einmaliger Beta-Hinweis mit Link zum Symcon-Forum-Thread, bis er
        // per Button ausgeblendet wird (Attribut, kein Übernehmen nötig).
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 InverterHubDiscovery ist Beta — Rückmeldungen und Testberichte sind im Symcon-Forum-Thread willkommen:'],
                    ['type' => 'Label', 'link' => true, 'caption' => self::FORUM_THREAD_URL],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'IHUBD_DismissReviewHint($id);'],
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

    // Versionszeile im Doku-Panel (Verbund-Konvention, EMS 24.07.2026): das
    // „Was ist neu"-Banner ist dismissible, die Version braucht daher eine
    // dauerhafte Stelle.
    private function VersionLabel(): array
    {
        $lib = @IPS_GetLibrary('{7EFE4BD7-DC14-460E-B0ED-88071197D35B}');
        $txt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ InverterHubDiscovery Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ InverterHubDiscovery';
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
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'IHUBD_AckNews($id);'];
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
    // Discovery
    // -----------------------------------------------------------------------

    // Aktualisiert die Fortschrittsanzeige im GEÖFFNETEN Formular, während
    // Discover() noch läuft (UpdateFormField pusht sofort über die
    // WebSocket-Verbindung zur Konsole, unabhängig vom RPC-Rückgabewert).
    private function ShowProgress($caption, $current, $indeterminate = false)
    {
        // UpdateFormField meldet ein PHP-Warning ("Instanz #<id> existiert
        // nicht"), wenn das Konfigurationsformular zwischenzeitlich
        // geschlossen wurde, während Discover() noch läuft — der Scan selbst
        // läuft unabhängig vom offenen Formular weiter. Da IPS das als
        // E_WARNING statt als Exception ausgibt, hilft try/catch nicht;
        // stattdessen hier bewusst mit @ unterdrücken.
        @$this->UpdateFormField('ScanProgress', 'visible', true);
        @$this->UpdateFormField('ScanProgress', 'caption', $caption);
        @$this->UpdateFormField('ScanProgress', 'indeterminate', $indeterminate);
        @$this->UpdateFormField('ScanProgress', 'current', $current);
    }

    // Ein-Klick-Suche über das gesamte lokale /24-Subnetz (.1–.254). Das Subnetz
    // wird automatisch aus der eigenen IP der SymBox erkannt - keine
    // Bereichseingabe nötig. Nutzt den schnellen (asynchronen) Portscan; findet
    // eine sehr träge WiNet-S ausnahmsweise nicht, hilft der manuelle
    // Schmalbereich unter „Erweitert".
    public function DiscoverSubnet()
    {
        $prefix = $this->guessLocalSubnetPrefix();
        if ($prefix === '') {
            $this->ShowProgress('Lokales Subnetz nicht erkannt – bitte den manuellen Bereich unter „Erweitert" verwenden.', 100);
            $this->SetStatus(104);
            return;
        }
        $ips = [];
        for ($i = 1; $i <= 254; $i++) {
            $ips[] = $prefix . '.' . $i;
        }
        $this->runScan($ips);
    }

    // Durchsucht den manuell eingetragenen Start-/End-Bereich (Erweitert-Panel).
    public function Discover()
    {
        $start = $this->ReadPropertyString('RangeStart');
        $end   = $this->ReadPropertyString('RangeEnd');

        if ($start === '' || $end === '') {
            $this->SetStatus(104);
            return;
        }

        $this->runScan($this->expandRange($start, $end));
    }

    // Gemeinsamer Scan-Kern beider Sucharten (Subnetz-Ein-Klick und manueller
    // Bereich). Erwartet die bereits expandierte IP-Liste.
    private function runScan(array $ips)
    {
        $port = $this->ReadPropertyInteger('Port');

        // Abbruch-Flagge zu Beginn zurücksetzen.
        if (@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->SetValue('ScanAbort', false);
        }
        // Alte Suchergebnisse sofort leeren, damit sie nicht mit den neuen
        // verwechselt werden (bisher wurde die Liste erst am Scan-Ende neu
        // geschrieben - bei einem ergebnislosen/abgebrochenen Scan blieben die
        // alten Treffer sichtbar).
        $this->WriteAttributeString('ResultsJSON', '[]');
        @$this->UpdateFormField('DiscoveryList', 'values', []);
        // Start-Buttons aus, Abbrechen-Button ein (am Scan-Ende stellt ReloadForm
        // die Ausgangslage wieder her).
        @$this->UpdateFormField('BtnScanSubnet', 'visible', false);
        @$this->UpdateFormField('BtnScan', 'visible', false);
        @$this->UpdateFormField('BtnAbort', 'visible', true);

        if (count($ips) > 1024) {
            // Sicherheitslimit gegen versehentlich riesige Bereiche
            $ips = array_slice($ips, 0, 1024);
        }

        $this->ShowProgress('Durchsuche ' . count($ips) . ' IP-Adressen auf Port ' . $port . ' …', 0);

        // Ausgeschlossene IPs (z. B. bekannte RTU/TCP-Konverter) vor dem Scan
        // entfernen - sie erscheinen damit weder in der Ergebnisliste noch
        // kosten sie Probe-Zeit.
        $ignore = $this->ParseIgnoreIPs();
        if (count($ignore) > 0) {
            $ips = array_values(array_diff($ips, $ignore));
        }

        // 3 s statt 2 s: RTU/TCP-Konverter und Wechselrichter hinter Gateways
        // brauchen für den TCP-Handshake teils spürbar länger.
        $openIps = $this->scanPortOpen($ips, $port, 3.0);

        $results  = [];
        $total    = count($openIps);
        $i        = 0;
        $aborted  = $this->scanAborted();
        foreach ($openIps as $ip) {
            if ($this->scanAborted()) { $aborted = true; break; }
            $i++;
            $this->ShowProgress("Prüfe Hersteller: $ip ($i von $total offenen Ports) …", (int)round(($i / max(1, $total)) * 100));
            $found = $this->identifyVendor($ip, $port);
            if ($found !== null) {
                $results[] = $found;
            }
        }

        if ($aborted) {
            $this->ShowProgress('Scan abgebrochen – ' . count($results) . ' Wechselrichter bis dahin gefunden.', 100);
        } else {
            $this->ShowProgress('Fertig: ' . count($results) . ' Wechselrichter gefunden (von ' . $total . ' offenen Ports).', 100);
        }

        $this->WriteAttributeString('ResultsJSON', json_encode($results));
        $this->SetStatus(102);
        $this->ReloadForm();
    }

    // Gleicht Suchergebnisse gegen bereits existierende InverterHub- UND
    // MeterHub-Instanzen ab (Host+UnitId), damit bereits angelegte Geräte in der
    // Ergebnisliste als solche erkannt werden (InstanzID statt "Kein(e)").
    private function findExistingInstances()
    {
        $map = [];
        foreach ([self::INVERTERHUB_GUID, self::METERHUB_GUID] as $guid) {
            foreach (IPS_GetInstanceListByModuleID($guid) as $iid) {
                $host   = @IPS_GetProperty($iid, 'Host');
                $unitId = @IPS_GetProperty($iid, 'UnitId');
                if ($host !== false && $host !== null && $host !== '') {
                    $map[$host . '|' . $unitId] = $iid;
                }
            }
        }
        return $map;
    }

    // Ausschlussliste einlesen: Komma-, Semikolon-, Leerzeichen- oder
    // zeilengetrennte IPs, ungültige Einträge werden ignoriert.
    private function ParseIgnoreIPs()
    {
        $raw = (string)$this->ReadPropertyString('IgnoreIPs');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ip2long($part) !== false) {
                $out[] = long2ip(ip2long($part));   // normalisieren
            }
        }
        return array_unique($out);
    }

    private function expandRange($startIp, $endIp)
    {
        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }
        $ips = [];
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    // Nicht-blockierender Parallel-Scan: testet alle IPs gleichzeitig, ob
    // Port 502 offen ist, statt sie nacheinander mit vollem Timeout abzuklopfen.
    private function scanPortOpen($ips, $port, $timeoutSec)
    {
        // Schmale Suchbereiche: zuverlässiger, blockierender Portcheck.
        // Der asynchrone Scan (unten) ist schnell für ganze Subnetze, übersieht
        // aber unter Windows und bei langsam annehmenden Geräten (z. B. Sungrow
        // WiNet-S) offene Ports. Bei kleinen Bereichen lohnt der langsamere,
        // aber verlässliche fsockopen-Weg - so wird ein gezielt gesuchter WR
        // sicher gefunden.
        if (count($ips) <= 64) {
            $open  = [];
            $total = count($ips);
            $i     = 0;
            foreach ($ips as $ip) {
                if ($this->scanAborted()) { break; }
                $i++;
                $this->ShowProgress("Portscan (genau) … $i von $total", (int)round(($i / max(1, $total)) * 90));
                $s = @fsockopen($ip, $port, $errno, $errstr, min(0.8, $timeoutSec));
                if ($s !== false) {
                    $open[] = $ip;
                    fclose($s);
                }
            }
            return $open;
        }

        $pending = [];
        foreach ($ips as $ip) {
            $s = @stream_socket_client(
                "tcp://$ip:$port",
                $errno,
                $errstr,
                0.01,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($s !== false) {
                stream_set_blocking($s, false);
                $pending[$ip] = $s;
            }
        }

        $open      = [];
        $totalOpen = count($pending);
        $startTime = microtime(true);
        $deadline  = $startTime + $timeoutSec;
        $lastUi    = 0.0;
        while (count($pending) > 0 && microtime(true) < $deadline) {
            if ($this->scanAborted()) {
                break;
            }
            $write  = array_values($pending);
            $read   = [];
            $except = [];
            $n = @stream_select($read, $write, $except, 0, 200000);
            if ($n === false) {
                break;
            }
            foreach ($pending as $ip => $sock) {
                if (in_array($sock, $write, true)) {
                    // Nach Abschluss des async Connect zeigt eine gültige
                    // Peer-Adresse Erfolg an, false bedeutet Verbindungsfehler.
                    $peer = @stream_socket_get_name($sock, true);
                    if ($peer !== false) {
                        $open[] = $ip;
                    }
                    fclose($sock);
                    unset($pending[$ip]);
                }
            }
            // Fortschritt anhand verbrauchtem Zeitbudget (grobe Schätzung,
            // da einzelne IPs unterschiedlich schnell antworten/timeouten).
            //
            // WICHTIG: gedrosselt (max. alle 300 ms) und die dafür verbrauchte
            // Zeit wird dem Scan-Budget gutgeschrieben. Ungedrosselt liefen
            // die UpdateFormField-RPCs in JEDER Schleifenrunde und fraßen das
            // Zeitfenster auf - langsamer verbindende Geräte (z. B. ein
            // Wechselrichter hinter einem RTU/TCP-Konverter) fielen dadurch
            // aus dem Scan, obwohl ihr Port offen war (real gemeldet, ab
            // 0.6.3 aufgetreten).
            $now = microtime(true);
            if ($now - $lastUi >= 0.3) {
                $lastUi  = $now;
                $elapsed = $now - $startTime;
                $pct     = (int)round(min(95, ($elapsed / $timeoutSec) * 90));
                $this->ShowProgress(
                    "Portscan läuft … " . count($open) . " offen, " . count($pending) . " von $totalOpen noch offen",
                    $pct
                );
                $deadline += microtime(true) - $now;   // UI-Zeit nicht anrechnen
            }
        }
        foreach ($pending as $sock) {
            @fclose($sock);
        }
        return $open;
    }

    private function identifyVendor($ip, $port)
    {
        // Alle Probes dieser IP über EINE Verbindung (schont Single-Connection-
        // Geräte wie den Sungrow WiNet-S, die sonst nach wenigen Reconnects
        // ablehnen und dann gar nicht erkannt werden).
        $this->beginProbe($ip, $port, 3.0);
        try {
            // Energiezähler zuerst: Sie erfüllen sonst lockere Hersteller-
            // Kriterien zufällig (real: UMG604 als Deye/Solplanet, PAC2200 als
            // SolaX). Ist MeterHub installiert, wird der Zähler als MeterHub-
            // Instanz angeboten; sonst nur übersprungen (kein Wechselrichter).
            $meter = $this->identifyMeter($ip, $port);
            if ($meter !== null) {
                if ($this->meterHubInstalled()) {
                    return [
                        'kind'   => 'meter',
                        'ip'     => $ip,
                        'unitId' => $meter['unitId'],
                        'vendor' => $meter['meter'],
                        'label'  => self::METER_LABELS[$meter['meter']] ?? $meter['meter'],
                        'meter'  => $meter['meter'],
                    ];
                }
                return null;
            }
            foreach (self::VENDOR_UNIT_IDS as $vendor => $unitIds) {
                foreach ($unitIds as $unitId) {
                    if ($this->probeVendor($vendor, $ip, $port, $unitId)) {
                        return [
                            'kind'   => 'inverter',
                            'ip'     => $ip,
                            'unitId' => $unitId,
                            'vendor' => $vendor,
                            'label'  => self::VENDOR_LABELS[$vendor],
                        ];
                    }
                }
            }
            return null;
        } finally {
            $this->endProbe();
        }
    }

    private function meterHubInstalled()
    {
        return function_exists('IPS_ModuleExists') ? IPS_ModuleExists(self::METERHUB_GUID) : false;
    }

    // Erkennt die von MeterHub unterstützten Energiezähler (klassische Janitza-
    // 19000er-Karte, Janitza UMG 800, Siemens PAC2200) anhand plausibler Float-
    // Frequenz + Spannung. Rückgabe ['meter','unitId'] oder null.
    private function identifyMeter($ip, $port)
    {
        // Siemens PAC2200: Frequenz @55, Spannung L1-N @1.
        $f = $this->readFloatHolding($ip, $port, 1, 55);
        if ($f !== null && $f >= 45.0 && $f <= 65.0) {
            $u = $this->readFloatHolding($ip, $port, 1, 1);
            if ($u !== null && $u >= 30.0 && $u <= 500.0) {
                return ['meter' => 'siemens_pac2200', 'unitId' => 1];
            }
        }
        // Janitza klassisch (UMG 604 u. a.): Frequenz @19050, Spannung @19000.
        if ($this->isJanitzaMeter($ip, $port, 1)) {
            return ['meter' => 'janitza_umg604', 'unitId' => 1];
        }
        // Janitza UMG 800: Frequenz @19054; @19050 trägt hier KEINE Frequenz.
        $f = $this->readFloatHolding($ip, $port, 1, 19054);
        if ($f !== null && $f >= 45.0 && $f <= 65.0) {
            $f50 = $this->readFloatHolding($ip, $port, 1, 19050);
            $u   = $this->readFloatHolding($ip, $port, 1, 19000);
            if (($f50 === null || $f50 < 45.0 || $f50 > 65.0) && $u !== null && $u >= 30.0 && $u <= 500.0) {
                return ['meter' => 'janitza_umg800', 'unitId' => 1];
            }
        }
        return null;
    }

    // Janitza-Messgerät (klassische 19000er-Karte): Float32 Frequenz @19050
    // (45-65 Hz) UND Spannung @19000 (30-500 V). Beides zusammen ist ein
    // eindeutiges Zähler-Merkmal, das kein Wechselrichter dort trägt.
    private function isJanitzaMeter($ip, $port, $unitId)
    {
        $f = $this->readFloatHolding($ip, $port, $unitId, 19050);
        if ($f === null || $f < 45.0 || $f > 65.0) {
            return false;
        }
        $u = $this->readFloatHolding($ip, $port, $unitId, 19000);
        return ($u !== null && $u >= 30.0 && $u <= 500.0);
    }

    private function readFloatHolding($ip, $port, $unitId, $reg)
    {
        $r = $this->readHolding($ip, $port, $unitId, $reg, 2, 1.0);
        if ($r === null || count($r) < 2) {
            return null;
        }
        $raw = pack('nn', $r[0] & 0xFFFF, $r[1] & 0xFFFF); // Big-Endian (ABCD)
        $f = unpack('G', $raw)[1] ?? null;
        return ($f !== null && is_finite($f)) ? (float)$f : null;
    }

    // Ein einzelnes "Register > 0"-Kriterium ist zu schwach — Zähler,
    // RTU/TCP-Konverter und andere Modbus-Geräte erfüllen das leicht zufällig
    // (real gemeldet: ein Janitza PAC2200 wurde als SolaX erkannt, ein
    // RTU/TCP-Konverter als GoodWe). Wo der Hersteller ein Seriennummer-/
    // Modell-Textregister dokumentiert, wird das als zweites, deutlich
    // härteres Kriterium verlangt: das Register muss zu einem plausiblen
    // ASCII-Text dekodieren, kein Zufallswert.
    private function probeVendor($vendor, $ip, $port, $unitId)
    {
        switch ($vendor) {
            case 'goodwe':
                // DSP 35001: Nennleistung, sollte > 0 sein
                $r = $this->readHolding($ip, $port, $unitId, 35001, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // DSP 35003: Seriennummer (8 Register, ASCII)
                $sn = $this->readHolding($ip, $port, $unitId, 35003, 8, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'sungrow':
                // Input 5000: Gerätetyp-Code, sollte > 0 sein
                $r = $this->readInput($ip, $port, $unitId, 5000, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // Input 4990-4999: Seriennummer (10 Register, UTF-8/ASCII)
                $sn = $this->readInput($ip, $port, $unitId, 4990, 10, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'solis':
                // Input 33000: Modell-Nr., sollte > 0 sein
                $r = $this->readInput($ip, $port, $unitId, 33000, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // Input 33004-33019: Seriennummer (16 Register, ASCII)
                $sn = $this->readInput($ip, $port, $unitId, 33004, 16, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'growatt':
                // Input 0: Inverter-Status, plausibel 0/1/3
                $r = $this->readInput($ip, $port, $unitId, 0, 1, 1.0);
                if ($r === null || !in_array($r[0], [0, 1, 3], true)) {
                    return false;
                }
                // Input 93: Wechselrichter-Temperatur (0.1°C), plausibel -40..90°C
                $t = $this->readInput($ip, $port, $unitId, 93, 1, 1.0);
                if ($t === null) {
                    return false;
                }
                $temp = $t[0] > 32767 ? $t[0] - 65536 : $t[0];
                if ($temp <= -400 || $temp >= 900) {
                    return false;
                }
                // Status 0/1/3 + Temperatur in Plausibelbereich reicht nicht als
                // Alleinstellungsmerkmal — reale Fehlmeldung: go-e-Wallboxen (die
                // ebenfalls auf Unit-ID 1 antworten) erfüllten beide Kriterien
                // zufällig. Zusätzlich Holding 23-27: Seriennummer (5 Register,
                // ASCII) verlangen — ein Wallbox-Register an dieser Adresse
                // dekodiert nicht zu plausiblem Text.
                $sn = $this->readHolding($ip, $port, $unitId, 23, 5, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'solax':
                // Holding 0x0015: InverterType, sollte > 0 sein
                $r = $this->readHolding($ip, $port, $unitId, 0x0015, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                // Holding 0x00AA-0x00AE: Modul-Seriennummer (5 Register, ASCII)
                $sn = $this->readHolding($ip, $port, $unitId, 0x00AA, 5, 1.0);
                return $this->looksLikeAsciiText($sn, 3);

            case 'sma':
                // Holding 30200 (Reg 30201): Operation.Health, plausible Enum-Werte
                $r = $this->readHolding($ip, $port, $unitId, 30200, 2, 1.0);
                if ($r === null) {
                    return false;
                }
                $val = ($r[0] << 16) | $r[1];
                return in_array($val, [35, 303, 307, 455], true);

            case 'fronius':
                // SunSpec-Marker "SunS" allein reicht nicht — SolarEdge nutzt
                // denselben Marker. Zusätzlich der Herstellername im Common
                // Block (Model 1, Feld MN ab Offset 2 hinter Marker+Header).
                return $this->probeSunSpecManufacturer($ip, $port, $unitId, 'fronius');

            case 'solaredge':
                return $this->probeSunSpecManufacturer($ip, $port, $unitId, 'solaredge');

            case 'deye':
                // Holding 0: Inverter-Typ, sollte > 0 sein. Als HARTES zweites
                // Kriterium die Deye/Sunsynk-Seriennummer (Holding 3-7, 5 Register,
                // ASCII) verlangen - "H0>0 + H500 lesbar" allein matchte zu leicht
                // fremde Geräte (real: ein Sungrow SG125CX-P2, dessen 3-7 = 0 sind).
                $r = $this->readHolding($ip, $port, $unitId, 0, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                $sn = $this->readHolding($ip, $port, $unitId, 3, 5, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'solplanet':
                // Input 1600: PV-Gesamtleistung (U32), muss lesbar sein;
                // Input 1026: Netzcode, plausibel ein kleiner Enum-Wert.
                $r = $this->readInput($ip, $port, $unitId, 1600, 2, 1.0);
                if ($r === null) {
                    return false;
                }
                $g = $this->readInput($ip, $port, $unitId, 1026, 1, 1.0);
                return ($g !== null && $g[0] < 100);

            case 'kostal':
                // Holding 100: PV-Gesamtleistung (Float32), muss lesbar sein;
                // Holding 768: Produktname, muss zu ASCII-Text dekodieren.
                $r = $this->readHolding($ip, $port, $unitId, 100, 2, 1.0);
                if ($r === null) {
                    return false;
                }
                $name = $this->readHolding($ip, $port, $unitId, 768, 16, 1.0);
                return $this->looksLikeAsciiText($name, 4);

            case 'victron':
                // Victron GX: Systemdienst liegt fest auf Unit-ID 100. Serial
                // (Reg 800, String[6]) muss zu ASCII-Text dekodieren - andere
                // Geräte antworten auf Unit 100 in aller Regel gar nicht.
                $sn = $this->readHolding($ip, $port, $unitId, 800, 6, 1.0);
                return $this->looksLikeAsciiText($sn, 4);

            case 'huawei':
                // Huawei SUN2000: Model-ID (Reg 30070) muss > 0 sein, und der
                // Modellname (Reg 30000, String) muss zu ASCII dekodieren
                // (z. B. „SUN2000-4.6KTL-L1").
                $r = $this->readHolding($ip, $port, $unitId, 30070, 1, 1.0);
                if ($r === null || $r[0] <= 0) {
                    return false;
                }
                $name = $this->readHolding($ip, $port, $unitId, 30000, 10, 1.0);
                return $this->looksLikeAsciiText($name, 5);
        }
        return false;
    }

    // Dekodiert Registerpaare als Big-Endian-ASCII und prüft, ob mindestens
    // $minPrintable druckbare, nicht-Leerzeichen-Zeichen enthalten sind —
    // filtert Zufallswerte fremder Modbus-Geräte zuverlässig heraus.
    private function looksLikeAsciiText($regs, $minPrintable)
    {
        if ($regs === null) {
            return false;
        }
        $printable = 0;
        foreach ($regs as $r) {
            foreach ([($r >> 8) & 0xFF, $r & 0xFF] as $byte) {
                if ($byte >= 0x21 && $byte <= 0x7E) {
                    $printable++;
                } elseif ($byte !== 0x00 && $byte !== 0x20) {
                    // Nicht-druckbares, nicht-Null/Leerzeichen-Byte spricht
                    // stark gegen echten Text -> sofort verwerfen.
                    return false;
                }
            }
        }
        return $printable >= $minPrintable;
    }

    // Prüft den SunSpec-Marker "SunS" an Basisregister 40000 und liest
    // anschließend den Herstellernamen aus dem Common Block (Model 1,
    // Feld MN direkt ab Modelldatenbeginn 40004) — unterscheidet Fronius
    // von SolarEdge, die beide denselben "SunS"-Marker verwenden.
    private function probeSunSpecManufacturer($ip, $port, $unitId, $wantVendor)
    {
        $marker = $this->readHolding($ip, $port, $unitId, 40000, 2, 1.0);
        if ($marker === null || $marker[0] !== 0x5375 || $marker[1] !== 0x6e53) {
            return false;
        }
        $mn = $this->readHolding($ip, $port, $unitId, 40004, 16, 1.0);
        if ($mn === null) {
            return false;
        }
        $text = strtolower($this->decodeAsciiText($mn));
        return (strpos($text, $wantVendor) !== false);
    }

    private function decodeAsciiText($regs)
    {
        $s = '';
        foreach ($regs as $r) {
            $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
        }
        return trim($s, "\x00 ");
    }

    // -----------------------------------------------------------------------
    // Minimale Modbus-TCP-Hilfsfunktionen (nur für die kurzen Scan-Proben)
    // -----------------------------------------------------------------------

    private function readHolding($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x03, $startReg, $count, $timeout);
    }

    private function readInput($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x04, $startReg, $count, $timeout);
    }

    // Ein Read + kurze Pause danach. Single-Connection-Geräte wie der Sungrow
    // WiNet-S erlauben nur EINE Modbus-Verbindung und lehnen schnell
    // aufeinanderfolgende Verbindungen ab. Statt mit Retrys mehr Verbindungen
    // zu erzeugen (was es verschlimmert), lassen wir zwischen den Verbindungen
    // etwas Zeit, damit das Gerät die nächste wieder annimmt.
    // Batch-Modus: eine Verbindung für alle Probes einer IP (Sungrow WiNet-S
    // erlaubt nur eine Modbus-Verbindung und lehnt schnelle Reconnects ab).
    private $probeSock = null;

    private function beginProbe($host, $port, $timeout)
    {
        $this->endProbe();
        $s = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($s !== false) {
            stream_set_timeout($s, $timeout);
            $this->probeSock = $s;
        }
    }

    private function endProbe()
    {
        if ($this->probeSock !== null) {
            @fclose($this->probeSock);
            $this->probeSock = null;
        }
    }

    private function modbusRead($host, $port, $unitId, $fc, $startReg, $count, $timeout)
    {
        $r = $this->modbusReadOnce($host, $port, $unitId, $fc, $startReg, $count, $timeout);
        if ($this->probeSock === null) {
            usleep(120000); // Nur im Per-Read-Modus: 120 ms Luft vor Reconnect.
        }
        return $r;
    }

    private function modbusReadOnce($host, $port, $unitId, $fc, $startReg, $count, $timeout)
    {
        $sock = $this->probeSock ?: @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return null;
        }
        if ($this->probeSock === null) {
            stream_set_timeout($sock, $timeout);
        }

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', $fc, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unitId);

        @fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                if (ord($response[7]) & 0x80) {
                    break; // Modbus-Exception (9-Byte-Antwort)
                }
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        if ($this->probeSock === null) {
            fclose($sock);
        }

        if (strlen($response) < 9) {
            return null;
        }
        $rfc = ord($response[7]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);
        $regs      = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }
}
