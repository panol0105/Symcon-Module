<?php
declare(strict_types=1);

class KlimaKachel extends IPSModule
{
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
        $this->RegisterPropertyString('Title', 'Klima');
        $this->RegisterPropertyString('ModeCoding', '20105');
        $this->RegisterPropertyFloat('MinTemp', 16);
        $this->RegisterPropertyFloat('MaxTemp', 30);
        $this->RegisterPropertyFloat('Step', 1);
        foreach (self::VARS as $v) {
            $this->RegisterPropertyInteger($v, 0);
        }
        $this->RegisterPropertyBoolean('ShowLock', true);
        $this->RegisterPropertyString('Diagnostics', '[]');
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
        $ids = array_map(fn ($p) => $this->ReadPropertyInteger($p), self::VARS);
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
        if ($Message != VM_UPDATE) {
            return;
        }
        if ($SenderID == $this->ReadPropertyInteger('ExtSensorID')) {
            $this->forwardTemp(false);
        }
        $this->push();
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    // ---------- Schnittstelle für das Modul KlimaRaum ----------

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
            $id = $this->ReadPropertyInteger($p);
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
            $id = $this->ReadPropertyInteger($p);
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
                if (isset($map[$Value])) {
                    $this->write('ModeCtrlID', 'ModeStatID', $map[$Value]);
                }
                break;
            case 'fan':
                $this->write('FanCtrlID', 'FanStatID', max(0, min(3, (int)$Value)));
                break;
            case 'vane':
                $this->write('VaneCtrlID', 'VaneStatID', max(1, min(4, (int)$Value)));
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
                $this->write('LockID', 'LockStatID', (bool)$Value);
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

    // ---------- Hilfsfunktionen ----------

    private function valid(int $id): bool
    {
        return $id > 1 && IPS_VariableExists($id);
    }

    private function has(string $a, string $b = ''): bool
    {
        return $this->valid($this->ReadPropertyInteger($a))
            || ($b !== '' && $this->valid($this->ReadPropertyInteger($b)));
    }

    // Liest zuerst $a, sonst $b
    private function read(string $a, string $b = '')
    {
        foreach ([$a, $b] as $p) {
            if ($p !== '' && $this->valid($this->ReadPropertyInteger($p))) {
                return GetValue($this->ReadPropertyInteger($p));
            }
        }
        return null;
    }

    // Schreibt auf $ctrl, sonst auf $stat
    private function write(string $ctrl, string $stat, $value): void
    {
        $id = $this->ReadPropertyInteger($ctrl);
        if (!$this->valid($id) && $stat !== '') {
            $id = $this->ReadPropertyInteger($stat);
        }
        if (!$this->valid($id)) {
            return;
        }
        switch (IPS_GetVariable($id)['VariableType']) {
            case 0: $value = (bool)$value; break;
            case 1: $value = (int)round((float)$value); break;
            case 2: $value = (float)$value; break;
            default: $value = (string)$value;
        }
        RequestAction($id, $value);
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
        $src = $this->ReadPropertyInteger('ExtSensorID');
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
        $fan = $this->read('FanStatID', 'FanCtrlID');
        $vane = $this->read('VaneStatID', 'VaneCtrlID');
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
            'lock'    => $this->has('LockID', 'LockStatID') ? (bool)$this->read('LockStatID', 'LockID') : null,
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
