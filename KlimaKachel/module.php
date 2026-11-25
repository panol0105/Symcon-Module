<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/TileFont.php';

require_once __DIR__ . '/../libs/KnxAuto.php';

class KlimaKachel extends IPSModule
{
    use KnxAuto;
    use TileFont;

    // Datenpunkte, die per Gruppenadresse angelegt werden können:
    // Feld => [Name, KNX-DPT-Modul, Dimension (Unter-Typ), schreibend]
    // Die Betriebsart hängt von der gewählten Codierung ab (siehe knxDef).
    private const KNX = [
        'PowerCtrlID'   => ['Ein/Aus', 1, 1, true],
        'PowerStatID'   => ['Ein/Aus Status', 1, 1, false],
        'ModeCtrlID'    => ['Betriebsart', 0, 0, true],
        'ModeStatID'    => ['Betriebsart Status', 0, 0, false],
        'SetCtrlID'     => ['Solltemperatur', 9, 1, true],
        'SetStatID'     => ['Solltemperatur Status', 9, 1, false],
        'ActID'         => ['Ist-Temperatur', 9, 1, false],
        'KnxAmbCtrlID'  => ['Raumtemperatur an Gateway', 9, 1, true],
        'FanCtrlID'     => ['Lüfterstufe', 5, 10, true],
        'FanStatID'     => ['Lüfterstufe Status', 5, 10, false],
        'VaneCtrlID'    => ['Lamellenposition', 5, 10, true],
        'VaneStatID'    => ['Lamellenposition Status', 5, 10, false],
        'SwingCtrlID'   => ['Swing', 1, 1, true],
        'SwingStatID'   => ['Swing Status', 1, 1, false],
        'CommErrID'     => ['Kommunikationsfehler', 1, 5, false],
        'ErrCodeID'     => ['Störcode', 8, 1, false],
        'ErrResetID'    => ['Störung zurücksetzen', 1, 15, true],
        'FilterID'      => ['Filtermeldung', 1, 5, false],
        'FilterResetID' => ['Filter zurücksetzen', 1, 15, true],
        'CompID'        => ['Verdichter', 1, 1, false],
        'ThermoID'      => ['Thermo Ein/Aus', 1, 1, false],
        'LockID'        => ['Fernbedienungssperre', 1, 2, true],
        'LockStatID'    => ['Fernbedienungssperre Status', 1, 2, false]
    ];

    private const VARS = [
        'PowerCtrlID', 'PowerStatID', 'ModeCtrlID', 'ModeStatID',
        'SetCtrlID', 'SetStatID', 'ActID', 'ExtSensorID', 'KnxAmbCtrlID',
        'FanCtrlID', 'FanStatID', 'VaneCtrlID', 'VaneStatID', 'SwingCtrlID', 'SwingStatID',
        'CommErrID', 'ErrCodeID', 'ErrResetID', 'FilterID', 'FilterResetID',
        'CompID', 'ThermoID', 'LockID', 'LockStatID'
    ];

    private const MODES = [
        '20105' => ['auto' => 0, 'heat' => 1, 'cool' => 3, 'fan' => 9, 'dry' => 14],
        '5A'    => ['auto' => 0, 'heat' => 1, 'dry' => 2, 'fan' => 3, 'cool' => 4],
        '5B'    => ['cool' => 0, 'heat' => 1, 'fan' => 2, 'dry' => 3, 'auto' => 4]
    ];

    private const ERRORS = [
        1 => 'Kommunikation Fernbedienung', 2 => 'Doppelte Innengeräteadresse',
        3 => 'Signalleitung Außengerät', 4 => 'Ungültige Innengeräteadresse',
        5 => 'Kommunikationsfehler im Betrieb', 6 => 'Fühler Wärmetauscher innen',
        7 => 'Fühler Rücklufttemperatur', 8 => 'Überlast Heizbetrieb',
        9 => 'Kondensatablauf gestört', 10 => 'Zu viele Innengeräte an Fernbedienung',
        11 => 'RAS bei mehreren Fernbedienungen', 12 => 'Adressierung gemischt',
        13 => 'SAF-Störung', 14 => 'Kommunikation Master/Slave innen',
        15 => 'Zuluftfühler getrennt', 16 => 'Lüftermotor innen',
        17 => 'Lüftermotor innen defekt', 18 => 'Gassensor gestört',
        19 => 'Einstellfehler Betriebs-/Pumpenprüfung', 20 => 'Drehzahl Lüftermotor innen',
        21 => 'Gitter offen', 28 => 'Fühler Fernbedienung',
        30 => 'Innen-/Außengerät passen nicht', 31 => 'Doppelte Außengeräteadresse',
        32 => 'Phase L3 offen', 33 => 'Primärstrom Inverter', 34 => 'Phasenausfall',
        35 => 'Überlast Kühlbetrieb', 36 => 'Heißgastemperatur zu hoch',
        37 => 'Fühler Wärmetauscher außen', 38 => 'Außentemperaturfühler',
        39 => 'Heißgasfühler', 40 => 'Hochdruckstörung', 41 => 'Leistungstransistor überhitzt',
        42 => 'Stromabschaltung', 43 => 'Zu viele Innengeräte / Leistung zu hoch',
        44 => 'Verdichterbodentemperatur', 45 => 'Kommunikation Inverterplatine',
        46 => 'Gemischte Adressierung im Netz', 47 => 'Überstrom Inverter',
        48 => 'Lüftermotor außen', 49 => 'Niederdruckstörung', 50 => 'Eisspeicher',
        51 => 'Inverterstörung', 52 => 'Wassertemperaturfühler', 53 => 'Sauggasfühler',
        54 => 'Hoch-/Niederdrucksensor', 55 => 'Unterseitenfühler', 56 => 'Fühler Leistungstransistor',
        57 => 'Kältemittelmangel / Serviceventil zu', 58 => 'Verdichter außer Tritt',
        59 => 'Verdichter-Anlauffehler', 60 => 'Verdichterrotor blockiert',
        61 => 'Kommunikation Master/Slave außen', 62 => 'Adressfehler Außengerät',
        63 => 'Not-Aus', 64 => 'Kältemittelstörung', 65 => 'Systemfehler PAC-Schnittstelle',
        66 => 'Doppelte I/F-Adresse', 67 => 'V-Multi: zu viele Innengeräte',
        68 => 'Kommunikation V-Multi', 74 => 'Doppelte Netzwerkadresse',
        75 => 'Kommunikationsschaltung Netzwerk', 79 => 'Kommunikation SLQ-Platine'
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Title', '');
        $this->RegisterPropertyString('ModeCoding', '20105');
        $this->RegisterPropertyFloat('MinTemp', 16);
        $this->RegisterPropertyFloat('MaxTemp', 30);
        $this->RegisterPropertyFloat('Step', 1);
        foreach (self::VARS as $v) {
            $this->RegisterPropertyInteger($v, 0);
        }
        $this->RegisterPropertyBoolean('ShowLock', true);
        $this->RegisterPropertyBoolean('ShowAuto', false);
        $this->RegisterPropertyString('FanFormat', 'pct');    // pct = Prozent (scaling, DPT 5.001), enum = Stufe (DPT 5.010)
        $this->RegisterPropertyString('VaneFormat', 'pct');
        $this->RegisterAttributeString('LockState', '');
        $this->RegisterPropertyBoolean('ChartLogging', true);   // Ist/Soll im Archiv aufzeichnen
        $this->RegisterPropertyBoolean('ShowDry', false);
        $this->RegisterPropertyString('Diagnostics', '[]');
        foreach (array_keys(self::KNX) as $k) {
            $this->RegisterPropertyString('GA_' . $k, '');
        }
        $this->RegisterPropertyInteger('KnxGateway', 0);
        $this->RegisterAttributeString('KnxHash', '');
        $this->RegisterAttributeString('Timers', '[]');
        $this->RegisterTimer('Clock', 0, 'IPS_RequestAction($_IPS["TARGET"], "check", 0);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        foreach ($this->GetMessageList() as $id => $msgs) {
            foreach ($msgs as $m) {
                $this->UnregisterMessage($id, $m);
            }
        }
        // Beim Systemstart erst loslegen, wenn alle Module geladen sind
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // KNX-Instanzen aus Gruppenadressen anlegen / aktualisieren
        $wanted = $this->knxWanted();
        $gw = $this->ReadPropertyInteger('KnxGateway');
        $hash = $this->knxHash($wanted, $gw);
        if ($hash !== $this->ReadAttributeString('KnxHash')) {
            $errors = $this->knxSync($wanted, $gw);
            $this->WriteAttributeString('KnxHash', $errors ? '' : $hash);
            $this->SetStatus($errors ? 201 : 102);
        } else {
            $this->SetStatus(102);
        }
        // aktuelle Werte der Status-Objekte vom Bus holen
        $this->RequestStatus();
        // Aufzeichnung für das Diagramm einschalten
        if ($this->ReadPropertyBoolean('ChartLogging')) {
            $this->enableLogging();
        }

        $ids = array_map(fn ($p) => $this->vid($p), self::VARS);
        foreach ($this->diag() as $d) {
            $ids[] = (int)($d['VariableID'] ?? 0);
        }
        foreach (array_unique($ids) as $id) {
            if ($this->valid($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
        $this->SetTimerInterval('Clock', 20000);
        $this->forwardTemp(true);
        $this->push();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message != VM_UPDATE) {
            return;
        }
        if ($SenderID == $this->vid('ExtSensorID')) {
            $this->forwardTemp(false);
        }
        $this->push();
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $this->tileFontCss() . $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    // ---------- Schnittstelle für das Modul KlimaGruppe ----------

    // Aktueller Zustand als JSON (KK_GetState)
    public function GetState(): string
    {
        return $this->buildState();
    }

    // Alle zugeordneten Variablen (KK_GetVariableIDs)
    public function GetVariableIDs(): array
    {
        $ids = [];
        foreach (self::VARS as $p) {
            $id = $this->vid($p);
            if ($this->valid($id)) {
                $ids[] = $id;
            }
        }
        foreach ($this->diag() as $d) {
            $id = (int)($d['VariableID'] ?? 0);
            if ($this->valid($id)) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    // Nur die Bedien-Variablen (KK_GetControlVariableIDs), für das automatische Angleichen
    public function GetControlVariableIDs(): array
    {
        $ids = [];
        foreach (['PowerCtrlID', 'PowerStatID', 'ModeCtrlID', 'ModeStatID', 'SetCtrlID', 'SetStatID',
                  'FanCtrlID', 'FanStatID', 'VaneCtrlID', 'VaneStatID', 'SwingCtrlID', 'SwingStatID'] as $p) {
            $id = $this->vid($p);
            if ($this->valid($id)) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'power':
                $this->write('PowerCtrlID', 'PowerStatID', (bool)$Value);
                break;
            case 'setpoint':
                $v = max($this->ReadPropertyFloat('MinTemp'), min($this->ReadPropertyFloat('MaxTemp'), (float)$Value));
                $this->write('SetCtrlID', 'SetStatID', $v);
                break;
            case 'mode':
                $map = $this->modeMap();
                if (isset($map[$Value]) && in_array($Value, $this->modeList(), true)) {
                    $this->write('ModeCtrlID', 'ModeStatID', $map[$Value]);
                }
                break;
            case 'fan':
                // Stufe 0..3; bei Prozent-Objekt (DPT 5.001) 25/50/75/100 %
                $i = max(0, min(3, (int)$Value));
                $this->write('FanCtrlID', 'FanStatID', $this->pct('FanFormat') ? self::PCT_SEND[$i] : $i);
                break;
            case 'vane':
                // Position 1..4; bei Prozent-Objekt 25/50/75/100 %
                $i = max(1, min(4, (int)$Value));
                $this->write('VaneCtrlID', 'VaneStatID', $this->pct('VaneFormat') ? self::PCT_SEND[$i - 1] : $i);
                break;
            case 'swing':
                $this->write('SwingCtrlID', 'SwingStatID', (bool)$Value);
                break;
            case 'filterReset':
                $this->write('FilterResetID', '', true);
                break;
            case 'errReset':
                $this->write('ErrResetID', '', true);
                break;
            case 'lock':
                // Das Gateway meldet die Sperre nicht zurück: gesendeten Zustand merken
                if ($this->write('LockID', 'LockStatID', (bool)$Value)) {
                    $this->WriteAttributeString('LockState', $Value ? '1' : '0');
                    $this->push();
                }
                break;
            case 'chart':
                // Daten für das Diagramm an die Kachel schicken
                $this->UpdateVisualizationValue(json_encode(['chart' => json_decode($this->GetChartData((int)$Value), true)]));
                break;
            case 'timers':
                $t = json_decode((string)$Value, true);
                if (is_array($t)) {
                    $this->WriteAttributeString('Timers', json_encode(array_values($t)));
                    $this->push();
                }
                break;
            case 'check':
                $this->checkTimers();
                $this->forwardTemp(false);
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // Formular: neben jede Variablen-Auswahl ein Feld für die Gruppenadresse
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $add = function (array $items) use (&$add): array {
            $out = [];
            foreach ($items as $el) {
                if (isset($el['items'])) {
                    $el['items'] = $add($el['items']);
                }
                if (($el['type'] ?? '') === 'SelectVariable' && isset(self::KNX[$el['name']])) {
                    $def = $this->knxDef($el['name']);
                    $el['width'] = '420px';
                    $out[] = ['type' => 'RowLayout', 'items' => [
                        $el,
                        ['type' => 'ValidationTextBox', 'name' => 'GA_' . $el['name'], 'caption' => 'oder Gruppenadresse',
                         'width' => '170px', 'validate' => '^\s*$|^\s*\d{1,2}\s*/\s*\d{1,2}\s*/\s*\d{1,3}\s*$'],
                        ['type' => 'Label', 'caption' => 'DPT ' . $def[1] . '.' . str_pad((string)$def[2], 3, '0', STR_PAD_LEFT)
                            . ($def[3] ? '' : ', nur lesen')]
                    ]];
                    continue;
                }
                $out[] = $el;
            }
            return $out;
        };
        $form['elements'] = $add($form['elements']);
        array_splice($form['elements'], 1, 0, [[
            'type' => 'ExpansionPanel', 'caption' => 'KNX-Gruppenadressen', 'expanded' => true,
            'items' => $this->knxGatewayElements()
        ]]);
        return json_encode($form);
    }

    // ---------- KNX-Gruppenadressen ----------

    // Definition eines Datenpunkts inkl. Betriebsart je nach Codierung
    private function knxDef(string $key): array
    {
        $d = self::KNX[$key];
        if (in_array($key, ['FanCtrlID', 'FanStatID', 'VaneCtrlID', 'VaneStatID'], true)) {
            $fmt = $this->ReadPropertyString(strpos($key, 'Fan') === 0 ? 'FanFormat' : 'VaneFormat');
            [$d[1], $d[2]] = $fmt === 'pct' ? [5, 1] : [5, 10];
        }
        if ($key === 'ModeCtrlID' || $key === 'ModeStatID') {
            if ($this->ReadPropertyString('ModeCoding') === '20105') {
                [$d[1], $d[2]] = [20, 105];
            } else {
                [$d[1], $d[2]] = [5, 10];
            }
        }
        return $d;
    }

    private function knxWanted(): array
    {
        $title = trim($this->ReadPropertyString('Title'));
        if ($title === '') {
            $title = IPS_GetName($this->InstanceID);
        }
        $wanted = [];
        foreach (array_keys(self::KNX) as $key) {
            $ga = trim($this->ReadPropertyString('GA_' . $key));
            if ($ga === '') {
                continue;
            }
            $d = $this->knxDef($key);
            $wanted['KNX_' . $key] = [
                'ga'    => $ga,
                'dpt'   => $d[1],
                'dim'   => $d[2],
                'write' => $d[3],
                'name'  => ($title !== '' ? $title . ' ' : '') . $d[0]
            ];
        }
        return $wanted;
    }

    // Variable eines Felds: ausgewählte Variable, sonst die aus der Gruppenadresse angelegte
    private function vid(string $prop): int
    {
        $id = $this->ReadPropertyInteger($prop);
        if ($this->valid($id)) {
            return $id;
        }
        return isset(self::KNX[$prop]) ? $this->knxVar('KNX_' . $prop) : 0;
    }

    // ---------- Hilfsfunktionen ----------

    private function valid(int $id): bool
    {
        return $id > 1 && IPS_VariableExists($id);
    }

    private function has(string $a, string $b = ''): bool
    {
        return $this->valid($this->vid($a))
            || ($b !== '' && $this->valid($this->vid($b)));
    }

    // Liest zuerst $a, sonst $b
    private function read(string $a, string $b = '')
    {
        foreach ([$a, $b] as $p) {
            if ($p !== '' && $this->valid($this->vid($p))) {
                return GetValue($this->vid($p));
            }
        }
        return null;
    }

    // Variable, die gelesen bzw. beschrieben wird ($a, sonst $b)
    private function target(string $a, string $b): int
    {
        $id = $this->vid($a);
        return $this->valid($id) ? $id : $this->vid($b);
    }

    // Prozent-Objekte (scaling): Steuern 0–24 / 25–49 / 50–74 / 75–100 %,
    // Status meldet 25 / 50 / 75 / 100 %. Gesendet wird die Mitte des Bereichs.
    private const PCT_SEND = [12, 37, 62, 87];

    private function pct(string $formatProp): bool
    {
        return $this->ReadPropertyString($formatProp) === 'pct';
    }

    // Stufe 0..3 aus Status- oder Steuer-Variable
    private function level(string $stat, string $ctrl, string $formatProp): ?int
    {
        $fromStatus = $this->valid($this->vid($stat));
        $raw = $this->read($stat, $ctrl);
        if ($raw === null) {
            return null;
        }
        if (!$this->pct($formatProp)) {
            return max(0, min(3, (int)$raw - ($formatProp === 'VaneFormat' ? 1 : 0)));
        }
        $v = (float)$raw;
        $i = $fromStatus ? (int)round($v / 25) - 1 : (int)floor($v / 25);
        return max(0, min(3, $i));
    }

    // Sperre: Status-Variable, sonst der zuletzt gesendete Zustand
    private function lockState(): ?bool
    {
        if (!$this->has('LockID', 'LockStatID')) {
            return null;
        }
        if ($this->valid($this->vid('LockStatID'))) {
            return (bool)GetValue($this->vid('LockStatID'));
        }
        $a = $this->ReadAttributeString('LockState');
        return $a === '' ? (bool)$this->read('LockID') : $a === '1';
    }

    // ---------- Diagramm ----------

    private function archiveID(): int
    {
        foreach (IPS_GetModuleList() as $guid) {
            if (IPS_GetModule($guid)['ModuleName'] === 'Archive Control') {
                $list = IPS_GetInstanceListByModuleID($guid);
                return $list ? (int)$list[0] : 0;
            }
        }
        return 0;
    }

    private function enableLogging(): void
    {
        $ac = $this->archiveID();
        if ($ac === 0) {
            return;
        }
        $changed = false;
        foreach ([$this->target('ActID', 'ActID'), $this->target('SetStatID', 'SetCtrlID')] as $id) {
            if ($this->valid($id) && !AC_GetLoggingStatus($ac, $id)) {
                AC_SetLoggingStatus($ac, $id, true);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($ac);
        }
    }

    // Werte einer Variablen im Zeitraum, aufsteigend als [[Zeit, Wert], ...]
    private function series(int $ac, int $id, int $from, int $to, int $hours): array
    {
        if ($ac === 0 || !$this->valid($id) || !AC_GetLoggingStatus($ac, $id)) {
            return [];
        }
        $pts = [];
        if ($hours <= 48) {
            // Rohwerte; dazu der letzte Wert vor Beginn, damit die Linie links anfängt
            $before = AC_GetLoggedValues($ac, $id, 0, $from, 1);
            if ($before) {
                $pts[] = [$from, round((float)$before[0]['Value'], 2)];
            }
            foreach (array_reverse(AC_GetLoggedValues($ac, $id, $from, $to, 0)) as $r) {
                $pts[] = [(int)$r['TimeStamp'], round((float)$r['Value'], 2)];
            }
            if ($pts) {
                $pts[] = [$to, end($pts)[1]];   // bis jetzt fortführen
            }
        } else {
            foreach (array_reverse(AC_GetAggregatedValues($ac, $id, 0 /* stündlich */, $from, $to, 0)) as $r) {
                $pts[] = [(int)$r['TimeStamp'], round((float)$r['Avg'], 2)];
            }
        }
        // auf höchstens 600 Punkte ausdünnen
        if (count($pts) > 600) {
            $step = (int)ceil(count($pts) / 600);
            $pts = array_values(array_filter($pts, fn ($k) => $k % $step === 0, ARRAY_FILTER_USE_KEY));
        }
        return $pts;
    }

    // Verlauf für das Diagramm (KK_GetChartData), Zeitraum in Stunden
    public function GetChartData(int $hours): string
    {
        $hours = max(1, min(24 * 31, $hours));
        $to = time();
        $from = $to - $hours * 3600;
        $ac = $this->archiveID();
        return json_encode([
            'hours'   => $hours,
            'from'    => $from,
            'to'      => $to,
            'archive' => $ac > 0,
            'series'  => [
                ['name' => 'Ist', 'color' => '#2563eb', 'points' => $this->series($ac, $this->target('ActID', 'ActID'), $from, $to, $hours)],
                ['name' => 'Soll', 'color' => '#e5534b', 'dash' => true, 'step' => $hours <= 48,
                 'points' => $this->series($ac, $this->target('SetStatID', 'SetCtrlID'), $from, $to, $hours)]
            ]
        ]);
    }

    // Aktuelle Werte der Status-Objekte vom Gateway abfragen (KK_RequestStatus)
    public function RequestStatus(): void
    {
        foreach (self::KNX as $key => $d) {
            if ($d[3]) {
                continue;   // nur Status-/Meldeobjekte
            }
            $iid = $this->knxInstance('KNX_' . $key);
            if ($iid > 0 && IPS_GetInstance($iid)['ConnectionID'] > 0 && function_exists('KNX_RequestStatus')) {
                @KNX_RequestStatus($iid);
            }
        }
    }

    // Schreibt auf $ctrl, sonst auf $stat
    private function write(string $ctrl, string $stat, $value): bool
    {
        $id = $this->vid($ctrl);
        if (!$this->valid($id) && $stat !== '') {
            $id = $this->vid($stat);
        }
        if (!$this->valid($id)) {
            return false;
        }
        switch (IPS_GetVariable($id)['VariableType']) {
            case 0: $value = (bool)$value; break;
            case 1: $value = (int)round((float)$value); break;
            case 2: $value = (float)$value; break;
            default: $value = (string)$value;
        }
        if (!$this->knxCanSend($id)) {
            return false;
        }
        $v = IPS_GetVariable($id);
        if (empty($v['VariableAction']) && empty($v['VariableCustomAction'])) {
            $this->LogMessage('„' . IPS_GetName($id) . '“ (#' . $id . ') hat keine Aktion und kann nicht geschaltet werden. Bitte die Steuer-Variable bzw. -Gruppenadresse (schreibend) zuordnen.', KL_WARNING);
            return false;
        }
        $this->SendDebug('Senden', $ctrl . ' -> #' . $id . ' = ' . json_encode($value), 0);
        RequestAction($id, $value);
        return true;
    }

    // Betriebsarten, die in der Kachel angeboten werden
    private function modeList(): array
    {
        $list = ['heat', 'cool', 'fan'];
        if ($this->ReadPropertyBoolean('ShowAuto')) {
            $list[] = 'auto';
        }
        if ($this->ReadPropertyBoolean('ShowDry')) {
            $list[] = 'dry';
        }
        return $list;
    }

    private function modeMap(): array
    {
        return self::MODES[$this->ReadPropertyString('ModeCoding')] ?? self::MODES['20105'];
    }

    private function diag(): array
    {
        return json_decode($this->ReadPropertyString('Diagnostics'), true) ?: [];
    }

    private function errorText(int $c): string
    {
        if ($c == 65535) {
            return 'IH1 Gateway ↔ Anlage gestört';
        }
        if ($c == 65336) {
            return 'IH2 Busspannung kritisch';
        }
        if ($c >= 70 && $c <= 73) {
            $t = 'Kommunikation Zentralsteuerung';
        } elseif ($c >= 80 && $c <= 90) {
            $t = 'Störung Gasmotor';
        } else {
            $t = self::ERRORS[$c] ?? 'Unbekannte Störung';
        }
        return 'E' . $c . ' ' . $t;
    }

    private function forwardTemp(bool $force): void
    {
        $src = $this->vid('ExtSensorID');
        if (!$this->valid($src) || !$this->has('KnxAmbCtrlID')) {
            return;
        }
        $val = round((float)GetValue($src), 1);
        $last = $this->GetBuffer('ExtVal');
        $age = time() - (int)$this->GetBuffer('ExtSent');
        // senden bei Änderung oder spätestens alle 10 Minuten
        if ($force || $last === '' || abs($val - (float)$last) >= 0.1 || $age > 600) {
            $this->write('KnxAmbCtrlID', '', $val);
            $this->SetBuffer('ExtVal', (string)$val);
            $this->SetBuffer('ExtSent', (string)time());
        }
    }

    private function checkTimers(): void
    {
        $key = date('Y-m-d H:i');
        if ($this->GetBuffer('LastRun') === $key) {
            return;
        }
        $this->SetBuffer('LastRun', $key);
        $day = (int)date('N');
        $now = date('H:i');
        foreach (json_decode($this->ReadAttributeString('Timers'), true) ?: [] as $t) {
            if (empty($t['enabled']) || ($t['time'] ?? '') !== $now || !in_array($day, $t['days'] ?? [])) {
                continue;
            }
            if (($t['action'] ?? 'on') === 'on') {
                $this->write('PowerCtrlID', 'PowerStatID', true);
                if (($t['mode'] ?? '') !== '') {
                    $this->RequestAction('mode', $t['mode']);
                }
                if ((int)($t['fan'] ?? -1) >= 0) {
                    $this->RequestAction('fan', (int)$t['fan']);
                }
                if (!empty($t['temp'])) {
                    $this->RequestAction('setpoint', (float)$t['temp']);
                }
            } else {
                $this->write('PowerCtrlID', 'PowerStatID', false);
            }
            $this->SendDebug('Timer', 'Ausgeführt: ' . json_encode($t), 0);
        }
    }

    private function buildState(): string
    {
        $bool = function (string $p) {
            return $this->has($p) ? (bool)$this->read($p) : null;
        };
        $modeRaw = $this->read('ModeStatID', 'ModeCtrlID');
        $mode = null;
        if ($modeRaw !== null) {
            $k = array_search((int)$modeRaw, $this->modeMap(), true);
            $mode = $k === false ? null : $k;
        }
        $err = $this->has('ErrCodeID') ? (int)$this->read('ErrCodeID') : null;
        $fan = $this->level('FanStatID', 'FanCtrlID', 'FanFormat');
        $vane = $this->level('VaneStatID', 'VaneCtrlID', 'VaneFormat');
        $vane = $vane === null ? null : $vane + 1;
        $swing = $this->read('SwingStatID', 'SwingCtrlID');

        $diag = [];
        foreach ($this->diag() as $d) {
            $id = (int)($d['VariableID'] ?? 0);
            if ($this->valid($id)) {
                $diag[] = ['n' => $d['Name'], 'v' => GetValueFormatted($id)];
            }
        }

        return json_encode([
            'title'   => $this->ReadPropertyString('Title'),
            'power'   => (bool)$this->read('PowerStatID', 'PowerCtrlID'),
            'mode'    => $mode,
            'modeList' => $this->modeList(),
            'set'     => $this->read('SetStatID', 'SetCtrlID'),
            'act'     => $this->read('ActID'),
            'fan'     => $fan === null ? null : (int)$fan,
            'vane'    => $vane === null ? null : (int)$vane,
            'swing'   => $swing === null ? null : (bool)$swing,
            'comm'    => $bool('CommErrID'),
            'errCode' => $err,
            'err'     => $err !== null && $err > 0,
            'errText' => $err ? $this->errorText($err) : '',
            'filter'  => $bool('FilterID'),
            'comp'    => $bool('CompID'),
            'thermo'  => $bool('ThermoID'),
            'lock'    => $this->lockState(),
            'diag'    => $diag,
            'has'     => [
                'mode'        => $this->has('ModeCtrlID', 'ModeStatID'),
                'fan'         => $this->has('FanCtrlID', 'FanStatID'),
                'vane'        => $this->has('VaneCtrlID', 'VaneStatID'),
                'swing'       => $this->has('SwingCtrlID', 'SwingStatID'),
                'errReset'    => $this->has('ErrResetID'),
                'filterReset' => $this->has('FilterResetID'),
                'lock'        => $this->ReadPropertyBoolean('ShowLock') && $this->has('LockID', 'LockStatID')
            ],
            'min'     => $this->ReadPropertyFloat('MinTemp'),
            'max'     => $this->ReadPropertyFloat('MaxTemp'),
            'step'    => $this->ReadPropertyFloat('Step'),
            'timers'  => json_decode($this->ReadAttributeString('Timers'), true) ?: []
        ]);
    }

    private function push(): void
    {
        $this->UpdateVisualizationValue($this->buildState());
    }
}
