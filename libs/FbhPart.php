<?php
declare(strict_types=1);

/**
 * FbhPart: Fußbodenheizung über einen KNX-Raumregler.
 * Symcon setzt Sollwert und Betriebsart und zeigt Ist-Temperatur und Ventil an.
 * Genutzt von den Modulen Fussbodenheizung und KlimaFBH.
 * Benötigt die Traits KnxAuto (knxVar, knxInstance, knxCanSend).
 */
trait FbhPart
{
    // Feld => [Name, KNX-DPT-Modul, Dimension, schreibend]
    protected function fbhDefs(): array
    {
        $valve = $this->ReadPropertyString('FbhValveFormat') === 'bool' ? [1, 1] : [5, 1];
        return [
            'FbhSetCtrlID'  => ['FBH Solltemperatur', 9, 1, true],
            'FbhSetStatID'  => ['FBH Solltemperatur Status', 9, 1, false],
            'FbhModeCtrlID' => ['FBH Betriebsart', 20, 102, true],
            'FbhModeStatID' => ['FBH Betriebsart Status', 20, 102, false],
            'FbhActID'      => ['FBH Ist-Temperatur', 9, 1, false],
            'FbhValveID'    => ['FBH Stellgröße', $valve[0], $valve[1], false]
        ];
    }

    // DPT 20.102 HVAC-Betriebsart
    protected function fbhModeMap(): array
    {
        return ['comfort' => 1, 'standby' => 2, 'night' => 3, 'frost' => 4];
    }

    // Rückmeldungen, die für die Anzeige zählen
    protected function fbhFeedback(): array
    {
        return ['FbhSetStatID' => 'FBH Solltemperatur', 'FbhModeStatID' => 'FBH Betriebsart', 'FbhActID' => 'FBH Ist-Temperatur', 'FbhValveID' => 'FBH Ventil'];
    }

    protected function fbhRegister(): void
    {
        foreach (['FbhSetCtrlID', 'FbhSetStatID', 'FbhModeCtrlID', 'FbhModeStatID', 'FbhActID', 'FbhValveID'] as $k) {
            $this->RegisterPropertyInteger($k, 0);
            $this->RegisterPropertyString('GA_' . $k, '');
        }
        $this->RegisterPropertyString('FbhValveFormat', 'pct');   // pct = Prozent (DPT 5.001), bool = Ein/Aus (DPT 1.001)
        $this->RegisterPropertyFloat('FbhMin', 16);
        $this->RegisterPropertyFloat('FbhMax', 28);
        $this->RegisterPropertyFloat('FbhStep', 0.5);
        $this->RegisterAttributeString('FbhTimers', '[]');
    }

    // ---------- Variablen ----------

    protected function fbhValid(int $id): bool
    {
        return $id > 1 && IPS_VariableExists($id);
    }

    protected function fbhVid(string $key): int
    {
        $id = $this->ReadPropertyInteger($key);
        return $this->fbhValid($id) ? $id : $this->knxVar('KNX_' . $key);
    }

    protected function fbhNever(string $key): bool
    {
        $id = $this->fbhVid($key);
        return $this->fbhValid($id) && isset($this->fbhFeedback()[$key])
            && (int)(IPS_GetVariable($id)['VariableUpdated'] ?? 1) === 0;
    }

    // Wert lesen: Status, sonst Steuer-Variable; nie empfangener Status = unbekannt
    protected function fbhRead(string $a, string $b = '')
    {
        foreach ([$a, $b] as $k) {
            if ($k !== '' && $this->fbhValid($this->fbhVid($k))) {
                return $this->fbhNever($k) ? null : GetValue($this->fbhVid($k));
            }
        }
        return null;
    }

    protected function fbhHas(string $a, string $b = ''): bool
    {
        return $this->fbhValid($this->fbhVid($a)) || ($b !== '' && $this->fbhValid($this->fbhVid($b)));
    }

    protected function fbhVarIDs(): array
    {
        $ids = [];
        foreach (array_keys($this->fbhDefs()) as $k) {
            if ($this->fbhValid($this->fbhVid($k))) {
                $ids[] = $this->fbhVid($k);
            }
        }
        return $ids;
    }

    protected function fbhWanted(string $prefix): array
    {
        $wanted = [];
        foreach ($this->fbhDefs() as $key => [$name, $dpt, $dim, $write]) {
            $ga = trim($this->ReadPropertyString('GA_' . $key));
            if ($ga !== '') {
                $wanted['KNX_' . $key] = ['ga' => $ga, 'dpt' => $dpt, 'dim' => $dim, 'write' => $write, 'name' => trim($prefix . ' ' . $name)];
            }
        }
        return $wanted;
    }

    protected function fbhMissing(): array
    {
        $out = [];
        foreach ($this->fbhFeedback() as $key => $name) {
            if ($this->fbhNever($key)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    protected function fbhRequestStatus(): void
    {
        foreach ($this->fbhDefs() as $key => $d) {
            if ($d[3]) {
                continue;
            }
            $iid = $this->knxInstance('KNX_' . $key);
            if ($iid > 0 && IPS_GetInstance($iid)['ConnectionID'] > 0 && function_exists('KNX_RequestStatus')) {
                @KNX_RequestStatus($iid);
            }
        }
    }

    // Temperaturen für das Diagramm: [Art, Name, Variable]
    protected function fbhTemps(): array
    {
        $list = [];
        $ist = $this->fbhVid('FbhActID');
        $soll = $this->fbhValid($this->fbhVid('FbhSetStatID')) ? $this->fbhVid('FbhSetStatID') : $this->fbhVid('FbhSetCtrlID');
        if ($this->fbhValid($ist)) {
            $list[] = ['fist', 'FBH Ist', $ist];
        }
        if ($this->fbhValid($soll)) {
            $list[] = ['fsoll', 'FBH Soll', $soll];
        }
        return $list;
    }

    // ---------- Schreiben ----------

    protected function fbhWrite(string $ctrl, string $stat, $value): bool
    {
        $id = $this->fbhVid($ctrl);
        if (!$this->fbhValid($id)) {
            $id = $this->fbhVid($stat);
        }
        if (!$this->fbhValid($id) || !$this->knxCanSend($id)) {
            return false;
        }
        $v = IPS_GetVariable($id);
        if (empty($v['VariableAction']) && empty($v['VariableCustomAction'])) {
            $this->LogMessage('„' . IPS_GetName($id) . '“ (#' . $id . ') hat keine Aktion und kann nicht geschaltet werden. Bitte die Steuer-Adresse (schreibend) eintragen.', KL_WARNING);
            return false;
        }
        switch ($v['VariableType']) {
            case 0: $value = (bool)$value; break;
            case 1: $value = (int)round((float)$value); break;
            case 2: $value = (float)$value; break;
        }
        $this->SendDebug('FBH senden', $ctrl . ' -> #' . $id . ' = ' . json_encode($value), 0);
        RequestAction($id, $value);
        return true;
    }

    // Befehle aus der Kachel; true = erledigt
    protected function fbhAction(string $ident, $value): bool
    {
        switch ($ident) {
            case 'fset':
                $v = max($this->ReadPropertyFloat('FbhMin'), min($this->ReadPropertyFloat('FbhMax'), (float)$value));
                $this->fbhWrite('FbhSetCtrlID', 'FbhSetStatID', $v);
                return true;
            case 'fmode':
                $map = $this->fbhModeMap();
                if (isset($map[$value])) {
                    $this->fbhWrite('FbhModeCtrlID', 'FbhModeStatID', $map[$value]);
                }
                return true;
            case 'ftimers':
                $t = json_decode((string)$value, true);
                if (is_array($t)) {
                    $this->WriteAttributeString('FbhTimers', json_encode(array_values($t)));
                }
                return true;
        }
        return false;
    }

    // Zeitschaltuhr: Betriebsart und/oder Sollwert zu festen Zeiten
    protected function fbhCheckTimers(array $timers): void
    {
        $key = date('Y-m-d H:i');
        if ($this->GetBuffer('FbhLastRun') === $key) {
            return;
        }
        $this->SetBuffer('FbhLastRun', $key);
        $day = (int)date('N');
        $now = date('H:i');
        foreach ($timers as $t) {
            if (empty($t['enabled']) || ($t['time'] ?? '') !== $now || !in_array($day, $t['days'] ?? [])) {
                continue;
            }
            if (($t['fmode'] ?? '') !== '') {
                $this->fbhAction('fmode', $t['fmode']);
            }
            if (!empty($t['fset'])) {
                $this->fbhAction('fset', (float)$t['fset']);
            }
            $this->SendDebug('FBH Timer', 'Ausgeführt: ' . json_encode($t), 0);
        }
    }

    // ---------- Zustand für die Kachel (Schlüssel mit "f"-Präfix) ----------

    protected function fbhState(): array
    {
        $raw = $this->fbhRead('FbhModeStatID', 'FbhModeCtrlID');
        $mode = null;
        if ($raw !== null) {
            $k = array_search((int)$raw, $this->fbhModeMap(), true);
            $mode = $k === false ? null : $k;
        }
        $valve = $this->fbhRead('FbhValveID');
        if ($valve !== null) {
            $valve = $this->ReadPropertyString('FbhValveFormat') === 'bool' ? ((bool)$valve ? 100 : 0) : (int)round((float)$valve);
        }
        $set = $this->fbhRead('FbhSetStatID', 'FbhSetCtrlID');
        $act = $this->fbhRead('FbhActID');
        return [
            'fhas'   => [
                'any'   => $this->fbhHas('FbhSetCtrlID', 'FbhSetStatID') || $this->fbhHas('FbhModeCtrlID', 'FbhModeStatID') || $this->fbhHas('FbhActID'),
                'set'   => $this->fbhHas('FbhSetCtrlID', 'FbhSetStatID'),
                'mode'  => $this->fbhHas('FbhModeCtrlID', 'FbhModeStatID'),
                'act'   => $this->fbhHas('FbhActID'),
                'valve' => $this->fbhHas('FbhValveID'),
                'bool'  => $this->ReadPropertyString('FbhValveFormat') === 'bool'
            ],
            'fset'   => $set === null ? null : round((float)$set, 1),
            'fact'   => $act === null ? null : round((float)$act, 1),
            'fmode'  => $mode,
            'fvalve' => $valve,
            'fmin'   => $this->ReadPropertyFloat('FbhMin'),
            'fmax'   => $this->ReadPropertyFloat('FbhMax'),
            'fstep'  => $this->ReadPropertyFloat('FbhStep'),
            'fnoFeedback' => $this->fbhMissing(),
            'ftimers' => json_decode($this->ReadAttributeString('FbhTimers'), true) ?: []
        ];
    }

    // ---------- Formular ----------

    protected function fbhFormPanel(bool $expanded = true): array
    {
        $labels = [
            'FbhSetCtrlID'  => 'Solltemperatur Steuern',
            'FbhSetStatID'  => 'Solltemperatur Status',
            'FbhModeCtrlID' => 'Betriebsart Steuern',
            'FbhModeStatID' => 'Betriebsart Status',
            'FbhActID'      => 'Ist-Temperatur',
            'FbhValveID'    => 'Stellgröße / Ventil'
        ];
        $items = [];
        foreach ($this->fbhDefs() as $key => [$name, $dpt, $dim, $write]) {
            $items[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectVariable', 'name' => $key, 'caption' => $labels[$key], 'width' => '420px'],
                ['type' => 'ValidationTextBox', 'name' => 'GA_' . $key, 'caption' => 'oder Gruppenadresse', 'width' => '170px',
                 'validate' => '^\s*$|^\s*\d{1,2}\s*/\s*\d{1,2}\s*/\s*\d{1,3}\s*$'],
                ['type' => 'Label', 'caption' => 'DPT ' . $dpt . '.' . str_pad((string)$dim, 3, '0', STR_PAD_LEFT) . ($write ? '' : ', nur lesen')]
            ]];
        }
        $items[] = ['type' => 'Select', 'name' => 'FbhValveFormat', 'caption' => 'Format Stellgröße', 'options' => [
            ['caption' => 'Prozent 0–100 % (DPT 5.001)', 'value' => 'pct'],
            ['caption' => 'Ein/Aus (DPT 1.001)', 'value' => 'bool']
        ]];
        $items[] = ['type' => 'RowLayout', 'items' => [
            ['type' => 'NumberSpinner', 'name' => 'FbhMin', 'caption' => 'Sollwert min', 'digits' => 1, 'suffix' => ' °C'],
            ['type' => 'NumberSpinner', 'name' => 'FbhMax', 'caption' => 'Sollwert max', 'digits' => 1, 'suffix' => ' °C'],
            ['type' => 'NumberSpinner', 'name' => 'FbhStep', 'caption' => 'Schrittweite', 'digits' => 1, 'suffix' => ' °C']
        ]];
        $items[] = ['type' => 'Label', 'caption' => 'Betriebsart nach DPT 20.102: 1 Komfort, 2 Standby, 3 Nacht, 4 Frostschutz. Geregelt wird im KNX-Raumregler.'];
        return ['type' => 'ExpansionPanel', 'caption' => 'Fußbodenheizung (KNX-Raumregler)', 'expanded' => $expanded, 'items' => $items];
    }
}
