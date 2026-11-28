<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/KnxAuto.php';
require_once __DIR__ . '/../libs/TileFont.php';
require_once __DIR__ . '/../libs/ListPart.php';

/**
 * Beschattung: Rollläden und Jalousien als Liste.
 * Auf/Ab, Stopp, Position und (bei Jalousien) Lamellenwinkel, jeweils mit Status.
 * Position nach KNX-Konvention: 0 % = offen (oben), 100 % = geschlossen (unten).
 */
class Beschattung extends IPSModule
{
    use KnxAuto;
    use TileFont;
    use ListPart;

    protected function listRoles(): array
    {
        return [
            'updown'   => ['GA_UpDown', '', 'Auf/Ab', true, 1, 8],                 // DPT 1.008 (0 = auf, 1 = ab)
            'stop'     => ['GA_Stop', '', 'Stopp', true, 1, 7],                    // DPT 1.007 (Stopp / Schritt)
            'pos'      => ['GA_Pos', 'PosID', 'Position', true, 5, 1],             // DPT 5.001
            'posStat'  => ['GA_PosStat', 'PosStatID', 'Position Status', false, 5, 1],
            'slat'     => ['GA_Slat', '', 'Lamelle', true, 5, 1],                  // DPT 5.001
            'slatStat' => ['GA_SlatStat', '', 'Lamelle Status', false, 5, 1]
        ];
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Items', '[]');
        $this->RegisterPropertyInteger('KnxGateway', 0);
        $this->RegisterAttributeString('KnxHash', '');
        $this->RegisterTimer('Clock', 0, 'IPS_RequestAction($_IPS["TARGET"], "check", 0);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->listApply();
        $this->push();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        } elseif ($Message == VM_UPDATE) {
            $this->push();
        }
    }

    public function GetConfigurationForm()
    {
        $ga = fn ($cap, $name, $w) => ['caption' => $cap, 'name' => $name, 'width' => $w, 'add' => '', 'edit' => ['type' => 'ValidationTextBox']];
        return json_encode([
            'status'   => $this->listStatusCodes(),
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'KNX-Gruppenadressen', 'expanded' => true, 'items' => $this->knxGatewayElements()],
                ['type' => 'List', 'name' => 'Items', 'caption' => 'Rollläden und Jalousien', 'add' => true, 'delete' => true, 'changeOrder' => true, 'rowCount' => 10,
                 'columns' => [
                    ['caption' => 'Name', 'name' => 'Name', 'width' => '160px', 'add' => 'Rollladen', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Art', 'name' => 'Kind', 'width' => '110px', 'add' => 'shutter', 'edit' => ['type' => 'Select', 'options' => [
                        ['caption' => 'Rollladen', 'value' => 'shutter'], ['caption' => 'Jalousie', 'value' => 'blind']]]],
                    $ga('GA Auf/Ab', 'GA_UpDown', '100px'),
                    $ga('GA Stopp', 'GA_Stop', '90px'),
                    $ga('GA Position', 'GA_Pos', '100px'),
                    $ga('GA Position Status', 'GA_PosStat', '140px'),
                    $ga('GA Lamelle', 'GA_Slat', '100px'),
                    $ga('GA Lamelle Status', 'GA_SlatStat', '130px'),
                    ['caption' => 'Laufzeit (s)', 'name' => 'RunTime', 'width' => '100px', 'add' => 60, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 5, 'maximum' => 300]],
                    ['caption' => 'oder Variable Position', 'name' => 'PosID', 'width' => '170px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Variable Position Status', 'name' => 'PosStatID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']]
                 ]],
                ['type' => 'Label', 'caption' => 'Datentypen: Auf/Ab DPT 1.008 (0 = auf, 1 = ab), Stopp DPT 1.007, Position und Lamelle DPT 5.001 (0 % = offen, 100 % = geschlossen). Lamellen-Adressen nur bei Jalousien. Ohne Auf/Ab-Adresse fahren „Auf“ und „Ab“ über die Position (0 % / 100 %).']
            ],
            'actions'  => [['type' => 'Button', 'caption' => 'Status vom Gateway abfragen', 'onClick' => 'BS_RequestStatus($id);']]
        ]);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $this->tileFontCss() . $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    // Idents: up_2, down_2, stop_2, pos_2 (0–100), slat_2 (0–100), all_up, all_down
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'check') {
            $this->listRefreshMissing();
            $this->push();
            return;
        }
        $items = $this->listItems();
        if ($Ident === 'all_up' || $Ident === 'all_down') {
            foreach (array_keys($items) as $i) {
                $this->move($i, $Ident === 'all_up' ? 'up' : 'down', null);
            }
            $this->push();
            return;
        }
        [$cmd, $idx] = array_pad(explode('_', (string)$Ident), 2, '');
        if (!isset($items[(int)$idx]) || !in_array($cmd, ['up', 'down', 'stop', 'pos', 'slat'], true)) {
            throw new Exception('Ungültiger Ident: ' . $Ident);
        }
        $i = (int)$idx;
        $it = $items[$i];
        switch ($cmd) {
            case 'stop':
                if ($this->listSend($this->listVid($it, 'stop'), true)) {
                    $this->SetBuffer('Cmd_' . $i, '');
                }
                break;
            case 'slat':
                $this->listSend($this->listVid($it, 'slat'), max(0, min(100, (float)$Value)));
                break;
            default:
                $this->move($i, $cmd, $cmd === 'pos' ? max(0, min(100, (float)$Value)) : null);
        }
        $this->push();
    }

    // Status beim Gateway abfragen (BS_RequestStatus)
    public function RequestStatus(): void
    {
        $this->listRequestStatus();
    }

    // Zustand als JSON (BS_GetState)
    public function GetState(): string
    {
        return $this->buildState();
    }

    // ---------- intern ----------

    // Fahrbefehl: up/down über Auf/Ab-Objekt (sonst über die Position), pos über die Position
    private function move(int $i, string $dir, ?float $target): void
    {
        $it = $this->listItems()[$i] ?? null;
        if ($it === null) {
            return;
        }
        $ud = $this->listVid($it, 'updown');
        $pos = $this->listVid($it, 'pos');
        $sent = false;
        if ($dir === 'pos') {
            $sent = $this->listSend($pos, $target);
        } elseif ($ud > 0) {
            $sent = $this->listSend($ud, $dir === 'down');
            $target = $dir === 'down' ? 100.0 : 0.0;
        } elseif ($pos > 0) {
            $target = $dir === 'down' ? 100.0 : 0.0;
            $sent = $this->listSend($pos, $target);
        }
        if ($sent) {
            $this->SetBuffer('Cmd_' . $i, json_encode(['dir' => $dir, 'target' => $target, 't' => time()]));
        }
    }

    private function buildState(): string
    {
        $out = [];
        foreach ($this->listItems() as $i => $it) {
            $pos = $this->listShown($it, 'posStat', 'pos');
            $slat = $this->listShown($it, 'slatStat', 'slat');
            $pos = $pos === null ? null : (int)round((float)$pos);
            $run = max(5, (int)($it['RunTime'] ?? 60));
            $cmd = json_decode($this->GetBuffer('Cmd_' . $i), true);
            $moving = null;
            $age = null;
            $target = null;
            if (is_array($cmd)) {
                $age = time() - (int)$cmd['t'];
                $target = $cmd['target'];
                $reached = $pos !== null && $target !== null && abs($pos - (float)$target) <= 2;
                if ($age < $run && !$reached) {
                    $moving = $target === null ? $cmd['dir'] : ((float)$target > ($pos ?? 0) ? 'down' : ((float)$target < ($pos ?? 100) ? 'up' : $cmd['dir']));
                }
            }
            $out[] = [
                'i'       => $i,
                'name'    => (string)($it['Name'] ?? ''),
                'kind'    => ($it['Kind'] ?? 'shutter') === 'blind' ? 'blind' : 'shutter',
                'pos'     => $pos,
                'slat'    => $slat === null ? null : (int)round((float)$slat),
                'hasPos'  => $this->listVid($it, 'posStat') > 0 || $this->listVid($it, 'pos') > 0,
                'canMove' => $this->listVid($it, 'updown') > 0 || $this->listVid($it, 'pos') > 0,
                'canStop' => $this->listVid($it, 'stop') > 0,
                'canPos'  => $this->listVid($it, 'pos') > 0,
                'canSlat' => $this->listVid($it, 'slat') > 0,
                'moving'  => $moving,
                'target'  => $target,
                'age'     => $age,
                'run'     => $run
            ];
        }
        return json_encode(['items' => $out, 'missing' => $this->listMissing()]);
    }

    private function push(): void
    {
        $this->UpdateVisualizationValue($this->buildState());
    }
}
