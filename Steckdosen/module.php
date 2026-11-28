<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/KnxAuto.php';
require_once __DIR__ . '/../libs/TileFont.php';
require_once __DIR__ . '/../libs/ListPart.php';

/**
 * Steckdosen: schaltbare Steckdosen als Liste, optional mit Leistung und Energie.
 */
class Steckdosen extends IPSModule
{
    use KnxAuto;
    use TileFont;
    use ListPart;

    protected function listRoles(): array
    {
        return [
            'switch' => ['GA_Switch', 'SwitchID', 'Schalten', true, 1, 1],          // DPT 1.001
            'status' => ['GA_Status', 'StatusID', 'Status', false, 1, 1],           // DPT 1.001
            'power'  => ['GA_Power', 'PowerID', 'Wirkleistung', false, 14, 56],     // DPT 14.056 (W), optional
            'energy' => ['GA_Energy', 'EnergyID', 'Wirkarbeit', false, 13, 13]      // DPT 13.013 (kWh), optional
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
        return json_encode([
            'status'   => $this->listStatusCodes(),
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'KNX-Gruppenadressen', 'expanded' => true, 'items' => $this->knxGatewayElements()],
                ['type' => 'List', 'name' => 'Items', 'caption' => 'Steckdosen (ohne Schalt-Adresse wird nur der Zustand angezeigt)',
                 'add' => true, 'delete' => true, 'changeOrder' => true, 'rowCount' => 10, 'columns' => [
                    ['caption' => 'Name', 'name' => 'Name', 'width' => '170px', 'add' => 'Steckdose', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'GA Schalten', 'name' => 'GA_Switch', 'width' => '110px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'GA Status', 'name' => 'GA_Status', 'width' => '100px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'GA Wirkleistung (W, optional)', 'name' => 'GA_Power', 'width' => '190px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'GA Wirkarbeit (kWh, optional)', 'name' => 'GA_Energy', 'width' => '190px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Nachfragen', 'name' => 'Confirm', 'width' => '100px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'oder Variable Schalten', 'name' => 'SwitchID', 'width' => '170px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Variable Status', 'name' => 'StatusID', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Variable Wirkleistung', 'name' => 'PowerID', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Variable Wirkarbeit', 'name' => 'EnergyID', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']]
                 ]],
                ['type' => 'Label', 'caption' => 'Datentypen: Schalten/Status DPT 1.001, Wirkleistung DPT 14.056 (W) und Wirkarbeit DPT 13.013 (kWh) sind optional und erscheinen in der Kachel nur, wenn eine Adresse verknüpft ist. „Nachfragen“: Ausschalten muss in der Kachel bestätigt werden (z. B. Kühlschrank, Server).']
            ],
            'actions'  => [['type' => 'Button', 'caption' => 'Status vom Gateway abfragen', 'onClick' => 'SD_RequestStatus($id);']]
        ]);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $this->tileFontCss() . $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    // Ident: "sw_3" (Wert true/false), "all_off"
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'check') {
            $this->listRefreshMissing();
            return;
        }
        $items = $this->listItems();
        if ($Ident === 'all_off') {
            foreach ($items as $it) {
                if (empty($it['Confirm']) && $this->listVid($it, 'switch') > 0) {
                    $this->listSend($this->listVid($it, 'switch'), false);
                }
            }
            return;
        }
        [$cmd, $idx] = array_pad(explode('_', (string)$Ident), 2, '');
        if ($cmd !== 'sw' || !isset($items[(int)$idx])) {
            throw new Exception('Ungültiger Ident: ' . $Ident);
        }
        $this->listSend($this->listVid($items[(int)$idx], 'switch'), (bool)$Value);
    }

    // Status beim Gateway abfragen (SD_RequestStatus)
    public function RequestStatus(): void
    {
        $this->listRequestStatus();
    }

    // Zustand als JSON (SD_GetState)
    public function GetState(): string
    {
        return $this->buildState();
    }

    private function buildState(): string
    {
        $out = [];
        $total = null;
        foreach ($this->listItems() as $i => $it) {
            $on = $this->listShown($it, 'status', 'switch');
            $p = $this->listRead($it, 'power');
            $e = $this->listRead($it, 'energy');
            if ($p !== null) {
                $total = ($total ?? 0) + (float)$p;
            }
            $out[] = [
                'i'       => $i,
                'name'    => (string)($it['Name'] ?? ''),
                'on'      => $on === null ? null : (bool)$on,
                'can'     => $this->listVid($it, 'switch') > 0,
                'power'   => $p === null ? null : round((float)$p, 1),
                'energy'  => $e === null ? null : round((float)$e, 2),
                'hasP'    => $this->listVid($it, 'power') > 0,
                'hasE'    => $this->listVid($it, 'energy') > 0,
                'confirm' => !empty($it['Confirm'])
            ];
        }
        return json_encode(['items' => $out, 'total' => $total === null ? null : round($total, 1), 'missing' => $this->listMissing()]);
    }

    private function push(): void
    {
        $this->UpdateVisualizationValue($this->buildState());
    }
}
