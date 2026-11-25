<?php
declare(strict_types=1);

class LampenListe extends IPSModule
{
    private const KEYS = ['SwitchID', 'DimID', 'TempID', 'ColorID'];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Lamps', '[]');
        $this->SetVisualizationType(1); // Kachel mit eigenem HTML
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        foreach ($this->GetMessageList() as $id => $msgs) {
            foreach ($msgs as $m) {
                $this->UnregisterMessage($id, $m);
            }
        }
        foreach ($this->getLamps() as $l) {
            foreach (self::KEYS as $k) {
                if ($this->valid($l[$k] ?? 0)) {
                    $this->RegisterMessage($l[$k], VM_UPDATE);
                }
            }
        }
        $this->UpdateVisualizationValue($this->buildState());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            $this->UpdateVisualizationValue($this->buildState());
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    // Ident-Format: "typ_index", z. B. "dim_2"
    public function RequestAction($Ident, $Value)
    {
        [$type, $idx] = explode('_', $Ident);
        $lamps = $this->getLamps();
        if (!isset($lamps[(int)$idx])) {
            return;
        }
        $l = $lamps[(int)$idx];
        switch ($type) {
            case 'switch':
                if ($this->valid($l['SwitchID'])) {
                    RequestAction($l['SwitchID'], (bool)$Value);
                } elseif ($this->valid($l['DimID'])) {
                    [$min, $max] = $this->range($l['DimID'], 0, 100);
                    RequestAction($l['DimID'], $Value ? $max : $min);
                }
                break;
            case 'dim':
                [$min, $max] = $this->range($l['DimID'], 0, 100);
                $raw = $min + ($max - $min) * ((float)$Value / 100);
                $this->setVar($l['DimID'], $raw);
                break;
            case 'temp':
                $this->setVar($l['TempID'], $Value);
                break;
            case 'color':
                RequestAction($l['ColorID'], (int)hexdec(ltrim((string)$Value, '#')));
                break;
        }
    }

    private function getLamps(): array
    {
        return json_decode($this->ReadPropertyString('Lamps'), true) ?: [];
    }

    private function valid($id): bool
    {
        return $id > 1 && IPS_VariableExists((int)$id);
    }

    private function setVar(int $id, $value): void
    {
        $t = IPS_GetVariable($id)['VariableType'];
        RequestAction($id, $t == 1 ? (int)round($value) : (float)$value);
    }

    private function range(int $id, float $dMin, float $dMax): array
    {
        $v = IPS_GetVariable($id);
        $p = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
        if ($p !== '' && IPS_VariableProfileExists($p)) {
            $pr = IPS_GetVariableProfile($p);
            if ($pr['MaxValue'] > $pr['MinValue']) {
                return [$pr['MinValue'], $pr['MaxValue']];
            }
        }
        return [$dMin, $dMax];
    }

    private function buildState(): string
    {
        $out = [];
        foreach ($this->getLamps() as $i => $l) {
            $s = $this->valid($l['SwitchID'] ?? 0);
            $d = $this->valid($l['DimID'] ?? 0);
            $t = $this->valid($l['TempID'] ?? 0);
            $c = $this->valid($l['ColorID'] ?? 0);
            if (!$s && !$d) {
                continue;
            }
            $e = ['i' => $i, 'name' => $l['Name'],
                  'type' => $c ? 'rgbw' : ($t ? 'tw' : ($d ? 'dim' : 'switch'))];
            $dim = 0;
            if ($d) {
                [$min, $max] = $this->range($l['DimID'], 0, 100);
                $dim = round((GetValue($l['DimID']) - $min) / ($max - $min) * 100);
                $e['dim'] = $dim;
            }
            $e['on'] = $s ? (bool)GetValue($l['SwitchID']) : $dim > 0;
            if ($t) {
                [$tmin, $tmax] = $this->range($l['TempID'], 2700, 6500);
                $e += ['temp' => GetValue($l['TempID']), 'tmin' => $tmin, 'tmax' => $tmax];
            }
            if ($c) {
                $e['color'] = sprintf('#%06X', GetValue($l['ColorID']));
            }
            $out[] = $e;
        }
        return json_encode($out);
    }
}
