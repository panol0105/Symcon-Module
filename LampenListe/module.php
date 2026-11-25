<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/TileFont.php';

require_once __DIR__ . '/../libs/KnxAuto.php';

class LampenListe extends IPSModule
{
    use KnxAuto;
    use TileFont;

    // Funktion => [Variablen-Spalte, Name, KNX-DPT-Modul, Dimension]
    private const FUNCS = [
        'Switch' => ['SwitchID', 'Schalten', 1, 1],        // DPT 1.001
        'Dim'    => ['DimID', 'Helligkeit', 5, 1],          // DPT 5.001
        'Temp'   => ['TempID', 'Farbtemperatur', 7, 600],   // DPT 7.600
        'Color'  => ['ColorID', 'Farbe', 232, 600]          // DPT 232.600
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Lamps', '[]');
        $this->RegisterPropertyInteger('KnxGateway', 0);
        $this->RegisterAttributeString('KnxHash', '');
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

        foreach ($this->getLamps() as $l) {
            foreach (array_keys(self::FUNCS) as $f) {
                foreach ([$this->ctrl($l, $f), $this->stat($l, $f)] as $id) {
                    if ($id > 0) {
                        $this->RegisterMessage($id, VM_UPDATE);
                    }
                }
            }
        }
        $this->UpdateVisualizationValue($this->buildState());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message == VM_UPDATE) {
            $this->UpdateVisualizationValue($this->buildState());
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        array_unshift($form['elements'], [
            'type' => 'ExpansionPanel', 'caption' => 'KNX-Gruppenadressen',
            'items' => array_merge($this->knxGatewayElements(), [
                ['type' => 'Label', 'caption' => 'Datentypen: Schalten 1.001, Helligkeit 5.001, Farbtemperatur 7.600, Farbe 232.600 (RGB). Zu jeder Funktion kann eine eigene Status-Adresse eingetragen werden.']
            ])
        ]);
        return json_encode($form);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $this->tileFontCss() . $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
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
                if ($this->ctrl($l, 'Switch') > 0) {
                    $this->send($this->ctrl($l, 'Switch'), (bool)$Value);
                } elseif ($this->ctrl($l, 'Dim') > 0) {
                    [$min, $max] = $this->range($this->ctrl($l, 'Dim'), 0, 100);
                    $this->setVar($this->ctrl($l, 'Dim'), $Value ? $max : $min);
                }
                break;
            case 'dim':
                $id = $this->ctrl($l, 'Dim');
                if ($id > 0) {
                    [$min, $max] = $this->range($id, 0, 100);
                    $this->setVar($id, $min + ($max - $min) * ((float)$Value / 100));
                }
                break;
            case 'temp':
                if ($this->ctrl($l, 'Temp') > 0) {
                    $this->setVar($this->ctrl($l, 'Temp'), $Value);
                }
                break;
            case 'color':
                if ($this->ctrl($l, 'Color') > 0) {
                    $this->send($this->ctrl($l, 'Color'), (int)hexdec(ltrim((string)$Value, '#')));
                }
                break;
        }
    }

    // ---------- Variablen je Lampe ----------

    private function getLamps(): array
    {
        return json_decode($this->ReadPropertyString('Lamps'), true) ?: [];
    }

    private function knxIdent(string $f, string $role, string $ga): string
    {
        return 'KNX_' . substr(md5($f . '|' . $role . '|' . trim($ga)), 0, 12);
    }

    // Steuer-Variable: ausgewählte Variable oder aus Gruppenadresse angelegt
    private function ctrl(array $l, string $f): int
    {
        $id = (int)($l[self::FUNCS[$f][0]] ?? 0);
        if ($this->valid($id)) {
            return $id;
        }
        $ga = trim((string)($l['GA_' . $f] ?? ''));
        return $ga !== '' ? $this->knxVar($this->knxIdent($f, 'c', $ga)) : 0;
    }

    // Status-Variable (nur über Gruppenadresse)
    private function stat(array $l, string $f): int
    {
        $ga = trim((string)($l['GA_' . $f . 'Stat'] ?? ''));
        return $ga !== '' ? $this->knxVar($this->knxIdent($f, 's', $ga)) : 0;
    }

    // Anzeige: Status, falls vorhanden, sonst Steuer-Variable
    private function shown(array $l, string $f): int
    {
        return $this->stat($l, $f) ?: $this->ctrl($l, $f);
    }

    private function knxWanted(): array
    {
        $wanted = [];
        foreach ($this->getLamps() as $l) {
            $name = trim((string)($l['Name'] ?? 'Lampe'));
            foreach (self::FUNCS as $f => $d) {
                foreach (['c' => '', 's' => 'Stat'] as $role => $suffix) {
                    $ga = trim((string)($l['GA_' . $f . $suffix] ?? ''));
                    if ($ga === '') {
                        continue;
                    }
                    $wanted[$this->knxIdent($f, $role, $ga)] = [
                        'ga'    => $ga,
                        'dpt'   => $d[2],
                        'dim'   => $d[3],
                        'write' => $role === 'c',
                        'name'  => $name . ' ' . $d[1] . ($role === 's' ? ' Status' : '')
                    ];
                }
            }
        }
        return $wanted;
    }

    // ---------- Hilfsfunktionen ----------

    private function valid($id): bool
    {
        return $id > 1 && IPS_VariableExists((int)$id);
    }

    private function setVar(int $id, $value): void
    {
        $t = IPS_GetVariable($id)['VariableType'];
        $this->send($id, $t == 1 ? (int)round((float)$value) : (float)$value);
    }

    private function send(int $id, $value): void
    {
        if ($this->knxCanSend($id)) {
            RequestAction($id, $value);
        }
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
            $s = $this->shown($l, 'Switch');
            $d = $this->shown($l, 'Dim');
            $t = $this->shown($l, 'Temp');
            $c = $this->shown($l, 'Color');
            if (!$s && !$d) {
                continue;
            }
            $e = ['i' => $i, 'name' => $l['Name'],
                  'type' => $c ? 'rgbw' : ($t ? 'tw' : ($d ? 'dim' : 'switch'))];
            $dim = 0;
            if ($d) {
                [$min, $max] = $this->range($d, 0, 100);
                $dim = (int)round((GetValue($d) - $min) / ($max - $min) * 100);
                $e['dim'] = $dim;
            }
            $e['on'] = $s ? (bool)GetValue($s) : $dim > 0;
            if ($t) {
                [$tmin, $tmax] = $this->range($t, 2700, 6500);
                if ($tmax - $tmin > 20000) {
                    [$tmin, $tmax] = [2700, 6500];   // DPT 7.600 hat oft 0–65535 K als Profil
                }
                $e += ['temp' => GetValue($t), 'tmin' => $tmin, 'tmax' => $tmax];
            }
            if ($c) {
                $e['color'] = sprintf('#%06X', (int)GetValue($c) & 0xFFFFFF);
            }
            $out[] = $e;
        }
        return json_encode($out);
    }
}
