<?php
declare(strict_types=1);

/**
 * ListPart: gemeinsame Logik für Listen-Kacheln (Steckdosen, Beschattung).
 * Jede Zeile hat Rollen (z. B. Schalten, Status), die über eine Gruppenadresse
 * oder eine ausgewählte Variable belegt werden. Benötigt KnxAuto.
 *
 * Das Modul liefert listRoles():
 *   Rolle => [GA-Spalte, Variablen-Spalte ('' = keine), Namenszusatz, schreibend, DPT, Dimension]
 */
trait ListPart
{
    abstract protected function listRoles(): array;

    protected function listItems(): array
    {
        return json_decode($this->ReadPropertyString('Items'), true) ?: [];
    }

    protected function listIdent(string $role, string $ga): string
    {
        return 'KNX_' . substr(md5($role . '|' . trim($ga)), 0, 12);
    }

    protected function listValid(int $id): bool
    {
        return $id > 1 && IPS_VariableExists($id);
    }

    // Variable einer Rolle: ausgewählte Variable, sonst die aus der Gruppenadresse angelegte
    protected function listVid(array $it, string $role): int
    {
        [$gaCol, $varCol] = $this->listRoles()[$role];
        if ($varCol !== '') {
            $id = (int)($it[$varCol] ?? 0);
            if ($this->listValid($id)) {
                return $id;
            }
        }
        $ga = trim((string)($it[$gaCol] ?? ''));
        return $ga !== '' ? $this->knxVar($this->listIdent($role, $ga)) : 0;
    }

    // Wert lesen; null wenn keine Variable oder noch nie ein Wert vom Bus kam
    protected function listRead(array $it, string $role)
    {
        $id = $this->listVid($it, $role);
        if ($id === 0 || (int)(IPS_GetVariable($id)['VariableUpdated'] ?? 1) === 0) {
            return null;
        }
        return GetValue($id);
    }

    // Status bevorzugt, sonst Steuer-Variable
    protected function listShown(array $it, string $stat, string $ctrl)
    {
        if ($this->listVid($it, $stat) > 0) {
            return $this->listRead($it, $stat);
        }
        return $this->listRead($it, $ctrl);
    }

    protected function listWanted(): array
    {
        $wanted = [];
        foreach ($this->listItems() as $it) {
            $name = trim((string)($it['Name'] ?? '')) ?: 'Eintrag';
            foreach ($this->listRoles() as $role => [$gaCol, , $suffix, $write, $dpt, $dim]) {
                $ga = trim((string)($it[$gaCol] ?? ''));
                if ($ga !== '') {
                    $wanted[$this->listIdent($role, $ga)] = ['ga' => $ga, 'dpt' => $dpt, 'dim' => $dim, 'write' => $write, 'name' => $name . ' ' . $suffix];
                }
            }
        }
        return $wanted;
    }

    protected function listVarIDs(): array
    {
        $ids = [];
        foreach ($this->listItems() as $it) {
            foreach (array_keys($this->listRoles()) as $role) {
                $id = $this->listVid($it, $role);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    protected function listSend(int $id, $value): bool
    {
        if (!$this->listValid($id) || !$this->knxCanSend($id)) {
            return false;
        }
        $v = IPS_GetVariable($id);
        if (empty($v['VariableAction']) && empty($v['VariableCustomAction'])) {
            $this->LogMessage('„' . IPS_GetName($id) . '“ (#' . $id . ') hat keine Aktion und kann nicht geschaltet werden.', KL_WARNING);
            return false;
        }
        switch ($v['VariableType']) {
            case 0: $value = (bool)$value; break;
            case 1: $value = (int)round((float)$value); break;
            case 2: $value = (float)$value; break;
        }
        $this->SendDebug('Senden', '#' . $id . ' = ' . json_encode($value), 0);
        RequestAction($id, $value);
        return true;
    }

    // Lese-Rollen beim Gateway abfragen
    protected function listRequestStatus(): void
    {
        foreach ($this->listItems() as $it) {
            foreach ($this->listRoles() as $role => [$gaCol, , , $write]) {
                if ($write) {
                    continue;
                }
                $iid = $this->knxInstance($this->listIdent($role, (string)($it[$gaCol] ?? '')));
                if ($iid > 0 && IPS_GetInstance($iid)['ConnectionID'] > 0 && function_exists('KNX_RequestStatus')) {
                    @KNX_RequestStatus($iid);
                }
            }
        }
    }

    // Namen der Einträge, von denen eine Lese-Rolle noch nie einen Wert bekam
    protected function listMissing(): array
    {
        $out = [];
        foreach ($this->listItems() as $it) {
            foreach ($this->listRoles() as $role => [, , , $write]) {
                if (!$write && $this->listVid($it, $role) > 0 && $this->listRead($it, $role) === null) {
                    $out[] = (string)($it['Name'] ?? '');
                    break;
                }
            }
        }
        return $out;
    }

    // fehlende Rückmeldungen nachfragen: 10 Minuten lang jede Minute, danach alle 10 Minuten
    protected function listRefreshMissing(): void
    {
        if (!$this->listMissing()) {
            $this->SetBuffer('ReadCount', '0');
            return;
        }
        $count = (int)$this->GetBuffer('ReadCount');
        if (time() - (int)$this->GetBuffer('ReadLast') < ($count < 10 ? 60 : 600)) {
            return;
        }
        $this->SetBuffer('ReadLast', (string)time());
        $this->SetBuffer('ReadCount', (string)($count + 1));
        $this->listRequestStatus();
    }

    // gemeinsamer Ablauf für ApplyChanges
    protected function listApply(): void
    {
        foreach ($this->GetMessageList() as $id => $msgs) {
            foreach ($msgs as $m) {
                $this->UnregisterMessage($id, $m);
            }
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $wanted = $this->listWanted();
        $gw = $this->ReadPropertyInteger('KnxGateway');
        $hash = $this->knxHash($wanted, $gw);
        if ($hash !== $this->ReadAttributeString('KnxHash')) {
            $errors = $this->knxSync($wanted, $gw);
            $this->WriteAttributeString('KnxHash', $errors ? '' : $hash);
            $this->SetStatus($errors ? 201 : 102);
        } else {
            $this->SetStatus(102);
        }
        foreach ($this->listVarIDs() as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $this->listRequestStatus();
        $this->SetTimerInterval('Clock', 20000);
    }

    protected function listStatusCodes(): array
    {
        return [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'Mindestens eine Gruppenadresse konnte nicht angelegt werden (Details im Meldungen-Fenster)'],
            ['code' => 202, 'icon' => 'error', 'caption' => 'KNX-Instanzen sind mit keinem KNX-Gateway verbunden. Bitte Gateway unter „KNX-Gruppenadressen“ auswählen.']
        ];
    }
}
