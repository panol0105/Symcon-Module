<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/KnxAuto.php';
require_once __DIR__ . '/../libs/TileFont.php';
require_once __DIR__ . '/../libs/FbhPart.php';

/**
 * Fussbodenheizung: Kachel für die Fußbodenheizung eines Raums (KNX-Raumregler).
 */
class Fussbodenheizung extends IPSModule
{
    use KnxAuto;
    use TileFont;
    use FbhPart;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Title', '');
        $this->RegisterPropertyInteger('KnxGateway', 0);
        $this->RegisterPropertyBoolean('ChartLogging', true);
        $this->RegisterAttributeString('KnxHash', '');
        $this->fbhRegister();
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
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $wanted = $this->fbhWanted($this->prefix());
        $gw = $this->ReadPropertyInteger('KnxGateway');
        $hash = $this->knxHash($wanted, $gw);
        if ($hash !== $this->ReadAttributeString('KnxHash')) {
            $errors = $this->knxSync($wanted, $gw);
            $this->WriteAttributeString('KnxHash', $errors ? '' : $hash);
            $this->SetStatus($errors ? 201 : 102);
        } else {
            $this->SetStatus(102);
        }
        foreach ($this->fbhVarIDs() as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $this->fbhRequestStatus();
        if ($this->ReadPropertyBoolean('ChartLogging')) {
            $this->enableLogging();
        }
        $this->SetTimerInterval('Clock', 20000);
        $this->push();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message == VM_UPDATE) {
            $this->push();
        }
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'status'   => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Mindestens eine Gruppenadresse konnte nicht angelegt werden (Details im Meldungen-Fenster)'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'KNX-Instanzen sind mit keinem KNX-Gateway verbunden. Bitte Gateway unter „KNX-Gruppenadressen“ auswählen.']
            ],
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Title', 'caption' => 'Präfix für KNX-Variablen (leer = Instanzname)'],
                ['type' => 'ExpansionPanel', 'caption' => 'KNX-Gruppenadressen', 'expanded' => true, 'items' => $this->knxGatewayElements()],
                $this->fbhFormPanel(true),
                ['type' => 'CheckBox', 'name' => 'ChartLogging', 'caption' => 'Ist- und Solltemperatur für das Diagramm im Archiv aufzeichnen']
            ],
            'actions'  => [['type' => 'Button', 'caption' => 'Status vom Gateway abfragen', 'onClick' => 'FBH_RequestStatus($id);']]
        ]);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $this->tileFontCss() . $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'check':
                $this->fbhCheckTimers(json_decode($this->ReadAttributeString('FbhTimers'), true) ?: []);
                $this->refreshMissingStatus();
                return;
            case 'chart':
                $this->UpdateVisualizationValue(json_encode(['chart' => json_decode($this->GetChartData((int)$Value), true)]));
                return;
            case 'fstatus':
                $this->fbhRequestStatus();
                return;
        }
        if ($this->fbhAction((string)$Ident, $Value)) {
            $this->push();
            return;
        }
        throw new Exception('Ungültiger Ident: ' . $Ident);
    }

    // Status-Objekte beim Gateway abfragen (FBH_RequestStatus)
    public function RequestStatus(): void
    {
        $this->fbhRequestStatus();
    }

    // Zustand als JSON (FBH_GetState)
    public function GetState(): string
    {
        return $this->buildState();
    }

    // Verlauf für das Diagramm (FBH_GetChartData), Zeitraum in Stunden
    public function GetChartData(int $hours): string
    {
        $hours = max(1, min(24 * 31, $hours));
        $to = time();
        $from = $to - $hours * 3600;
        $ac = $this->archiveID();
        $series = [];
        foreach ($this->fbhTemps() as [$kind, $name, $id]) {
            $series[] = [
                'name'   => $kind === 'fist' ? 'Ist' : 'Soll',
                'kind'   => $kind === 'fist' ? 'ist' : 'soll',
                'color'  => $kind === 'fist' ? '#2563eb' : '#e5534b',
                'dash'   => $kind === 'fsoll',
                'step'   => $kind === 'fsoll' && $hours <= 48,
                'points' => $this->series($ac, $id, $from, $to, $hours)
            ];
        }
        return json_encode(['hours' => $hours, 'from' => $from, 'to' => $to, 'archive' => $ac > 0, 'series' => $series]);
    }

    // ---------- intern ----------

    private function prefix(): string
    {
        $p = trim($this->ReadPropertyString('Title'));
        return $p !== '' ? $p : IPS_GetName($this->InstanceID);
    }

    private function refreshMissingStatus(): void
    {
        if (!$this->fbhMissing()) {
            $this->SetBuffer('ReadCount', '0');
            return;
        }
        $count = (int)$this->GetBuffer('ReadCount');
        if (time() - (int)$this->GetBuffer('ReadLast') < ($count < 10 ? 60 : 600)) {
            return;
        }
        $this->SetBuffer('ReadLast', (string)time());
        $this->SetBuffer('ReadCount', (string)($count + 1));
        $this->fbhRequestStatus();
    }

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
        foreach (array_column($this->fbhTemps(), 2) as $id) {
            if (!AC_GetLoggingStatus($ac, $id)) {
                AC_SetLoggingStatus($ac, $id, true);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($ac);
        }
    }

    private function series(int $ac, int $id, int $from, int $to, int $hours): array
    {
        if ($ac === 0 || !$this->fbhValid($id) || !AC_GetLoggingStatus($ac, $id)) {
            return [];
        }
        $pts = [];
        if ($hours <= 48) {
            $before = AC_GetLoggedValues($ac, $id, 0, $from, 1);
            if ($before) {
                $pts[] = [$from, round((float)$before[0]['Value'], 2)];
            }
            foreach (array_reverse(AC_GetLoggedValues($ac, $id, $from, $to, 0)) as $r) {
                $pts[] = [(int)$r['TimeStamp'], round((float)$r['Value'], 2)];
            }
            if ($pts) {
                $pts[] = [$to, end($pts)[1]];
            }
        } else {
            foreach (array_reverse(AC_GetAggregatedValues($ac, $id, 0, $from, $to, 0)) as $r) {
                $pts[] = [(int)$r['TimeStamp'], round((float)$r['Avg'], 2)];
            }
        }
        if (count($pts) > 600) {
            $step = (int)ceil(count($pts) / 600);
            $pts = array_values(array_filter($pts, fn ($k) => $k % $step === 0, ARRAY_FILTER_USE_KEY));
        }
        return $pts;
    }

    private function buildState(): string
    {
        return json_encode($this->fbhState());
    }

    private function push(): void
    {
        $this->UpdateVisualizationValue($this->buildState());
    }
}
