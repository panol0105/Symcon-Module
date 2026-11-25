<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/KnxAuto.php';
require_once __DIR__ . '/../libs/TileFont.php';

/**
 * FensterTuerTor: Fenster, Türen und Tore in einer Kachel.
 * Mit Öffnen/Schließen-Adresse bedienbar, sonst nur Statusanzeige.
 */
class FensterTuerTor extends IPSModule
{
    use KnxAuto;
    use TileFont;

    // Rolle => [GA-Spalte, Variablen-Spalte, Namenszusatz, schreibend]
    private const ROLES = [
        'open'     => ['GA_Open', '', 'Öffnen', true],
        'close'    => ['GA_Close', '', 'Schließen', true],
        'isOpen'   => ['GA_IsOpen', 'IsOpenID', 'geöffnet', false],
        'isClosed' => ['GA_IsClosed', 'IsClosedID', 'geschlossen', false]
    ];

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
        foreach ($this->GetMessageList() as $id => $msgs) {
            foreach ($msgs as $m) {
                $this->UnregisterMessage($id, $m);
            }
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
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
        foreach ($this->items() as $it) {
            foreach (array_keys(self::ROLES) as $role) {
                $id = $this->vid($it, $role);
                if ($id > 0) {
                    $this->RegisterMessage($id, VM_UPDATE);
                }
            }
        }
        $this->RequestStatus();
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
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        array_unshift($form['elements'], [
            'type' => 'ExpansionPanel', 'caption' => 'KNX-Gruppenadressen', 'expanded' => true,
            'items' => $this->knxGatewayElements()
        ]);
        $form['actions'] = [['type' => 'Button', 'caption' => 'Status vom Gateway abfragen', 'onClick' => 'FTT_RequestStatus($id);']];
        return json_encode($form);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $this->tileFontCss() . $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    // Ident: "open_2", "close_0"
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'check') {
            $this->refreshMissingStatus();
            $this->push();   // Bewegungsanzeige nach Ablauf der Laufzeit aktualisieren
            return;
        }
        [$dir, $idx] = array_pad(explode('_', (string)$Ident), 2, '');
        $items = $this->items();
        if (!in_array($dir, ['open', 'close'], true) || !isset($items[(int)$idx])) {
            throw new Exception('Ungültiger Ident: ' . $Ident);
        }
        $it = $items[(int)$idx];
        $id = $this->vid($it, $dir);
        if ($id === 0) {
            return;   // nur Statusanzeige
        }
        if ($this->send($id, true)) {
            $this->SetBuffer('Cmd_' . $idx, json_encode(['dir' => $dir, 't' => time()]));
            $this->push();
        }
    }

    // Status-Objekte beim Gateway abfragen (FTT_RequestStatus)
    public function RequestStatus(): void
    {
        foreach ($this->items() as $it) {
            foreach (['isOpen', 'isClosed'] as $role) {
                $iid = $this->knxInstance($this->ident($role, (string)($it[self::ROLES[$role][0]] ?? '')));
                if ($iid > 0 && IPS_GetInstance($iid)['ConnectionID'] > 0 && function_exists('KNX_RequestStatus')) {
                    @KNX_RequestStatus($iid);
                }
            }
        }
    }

    // ---------- Einträge und Variablen ----------

    private function items(): array
    {
        return json_decode($this->ReadPropertyString('Items'), true) ?: [];
    }

    private function ident(string $role, string $ga): string
    {
        return 'KNX_' . substr(md5($role . '|' . trim($ga)), 0, 12);
    }

    // Variable einer Rolle: ausgewählte Variable, sonst aus der Gruppenadresse angelegt
    private function vid(array $it, string $role): int
    {
        [$gaCol, $varCol] = self::ROLES[$role];
        if ($varCol !== '') {
            $id = (int)($it[$varCol] ?? 0);
            if ($id > 1 && IPS_VariableExists($id)) {
                return $id;
            }
        }
        $ga = trim((string)($it[$gaCol] ?? ''));
        return $ga !== '' ? $this->knxVar($this->ident($role, $ga)) : 0;
    }

    private function knxWanted(): array
    {
        $wanted = [];
        foreach ($this->items() as $it) {
            $name = trim((string)($it['Name'] ?? '')) ?: 'Tor';
            foreach (self::ROLES as $role => [$gaCol, , $suffix, $write]) {
                $ga = trim((string)($it[$gaCol] ?? ''));
                if ($ga !== '') {
                    $wanted[$this->ident($role, $ga)] = ['ga' => $ga, 'dpt' => 1, 'dim' => 1, 'write' => $write, 'name' => $name . ' ' . $suffix];
                }
            }
        }
        return $wanted;
    }

    // Statuswert: null, wenn keine Variable oder noch nie ein Wert vom Bus kam
    private function status(array $it, string $role): ?bool
    {
        $id = $this->vid($it, $role);
        if ($id === 0 || (int)(IPS_GetVariable($id)['VariableUpdated'] ?? 1) === 0) {
            return null;
        }
        return (bool)GetValue($id);
    }

    private function send(int $id, bool $value): bool
    {
        if (!$this->knxCanSend($id)) {
            return false;
        }
        $v = IPS_GetVariable($id);
        if (empty($v['VariableAction']) && empty($v['VariableCustomAction'])) {
            $this->LogMessage('„' . IPS_GetName($id) . '“ (#' . $id . ') hat keine Aktion und kann nicht geschaltet werden.', KL_WARNING);
            return false;
        }
        $this->SendDebug('Senden', '#' . $id . ' = ' . json_encode($value), 0);
        RequestAction($id, $value);
        return true;
    }

    private function refreshMissingStatus(): void
    {
        $missing = false;
        foreach ($this->items() as $it) {
            foreach (['isOpen', 'isClosed'] as $role) {
                if ($this->vid($it, $role) > 0 && $this->status($it, $role) === null) {
                    $missing = true;
                }
            }
        }
        if (!$missing) {
            $this->SetBuffer('ReadCount', '0');
            return;
        }
        $count = (int)$this->GetBuffer('ReadCount');
        if (time() - (int)$this->GetBuffer('ReadLast') < ($count < 10 ? 60 : 600)) {
            return;
        }
        $this->SetBuffer('ReadLast', (string)time());
        $this->SetBuffer('ReadCount', (string)($count + 1));
        $this->RequestStatus();
    }

    // ---------- Zustand ----------

    // Endlagen auswerten: open, closed, between (keine Endlage), error (beide), unknown
    private function position(?bool $isOpen, ?bool $isClosed, bool $hasOpen, bool $hasClosed): string
    {
        if (!$hasOpen && !$hasClosed) {
            return 'none';
        }
        if (($hasOpen && $isOpen === null) && ($hasClosed && $isClosed === null)) {
            return 'unknown';
        }
        if ($isOpen === true && $isClosed === true) {
            return 'error';
        }
        if ($isOpen === true) {
            return 'open';
        }
        if ($isClosed === true) {
            return 'closed';
        }
        if ($hasOpen && $hasClosed) {
            if ($isOpen === false && $isClosed === false) {
                return 'between';
            }
            return 'unknown';
        }
        // nur ein Kontakt vorhanden
        if ($hasOpen) {
            return $isOpen === false ? 'closed' : 'unknown';
        }
        return $isClosed === false ? 'open' : 'unknown';
    }

    private function buildState(): string
    {
        $out = [];
        foreach ($this->items() as $i => $it) {
            $hasO = $this->vid($it, 'isOpen') > 0;
            $hasC = $this->vid($it, 'isClosed') > 0;
            $pos = $this->position($this->status($it, 'isOpen'), $this->status($it, 'isClosed'), $hasO, $hasC);

            // letzte Änderung der Endlagen
            $changed = 0;
            foreach (['isOpen', 'isClosed'] as $role) {
                $id = $this->vid($it, $role);
                if ($id > 0) {
                    $changed = max($changed, (int)IPS_GetVariable($id)['VariableChanged']);
                }
            }

            // Bewegung: Befehl gesendet, Laufzeit noch nicht um, Ziel noch nicht erreicht
            $run = max(5, (int)($it['RunTime'] ?? 60));
            $cmd = json_decode($this->GetBuffer('Cmd_' . $i), true);
            $moving = null;
            $age = null;
            if (is_array($cmd)) {
                $age = time() - (int)$cmd['t'];
                $target = $cmd['dir'] === 'open' ? 'open' : 'closed';
                if ($age < $run && $pos !== $target) {
                    $moving = $cmd['dir'];
                }
            }
            $out[] = [
                'i'        => $i,
                'name'     => (string)($it['Name'] ?? ''),
                'kind'     => (string)($it['Kind'] ?? 'gate'),
                'pos'      => $pos,
                'moving'   => $moving,
                'missed'   => is_array($cmd) && $age >= $run && $age < $run + 600
                              && $pos !== ($cmd['dir'] === 'open' ? 'open' : 'closed') && $pos !== 'none'
                              ? $cmd['dir'] : null,
                'run'      => $run,
                'age'      => $age,
                'changed'  => $changed,
                'canOpen'  => $this->vid($it, 'open') > 0,
                'canClose' => $this->vid($it, 'close') > 0,
                'confirm'  => (bool)($it['Confirm'] ?? true)
            ];
        }
        return json_encode(['items' => $out]);
    }

    private function push(): void
    {
        $this->UpdateVisualizationValue($this->buildState());
    }
}
