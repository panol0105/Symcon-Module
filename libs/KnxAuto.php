<?php
declare(strict_types=1);

/**
 * KnxAuto: legt aus eingetragenen KNX-Gruppenadressen automatisch
 * KNX-DPT-Instanzen (mit ihren Variablen) unterhalb der Modul-Instanz an,
 * hält Adresse, Datentyp und Namen aktuell und entfernt nicht mehr
 * benötigte Instanzen wieder.
 *
 * Angelegte Instanzen tragen ein Ident mit dem Präfix "KNX_".
 */
trait KnxAuto
{
    // "1/2/3" -> [1, 2, 3]; null bei ungültiger Adresse
    private function knxParse(string $ga): ?array
    {
        if (!preg_match('~^\s*(\d{1,2})\s*/\s*(\d{1,2})\s*/\s*(\d{1,3})\s*$~', $ga, $m)) {
            return null;
        }
        $a = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($a[0] > 31 || $a[1] > 7 || $a[2] > 255) {
            return null;
        }
        return $a;
    }

    // GUID des Symcon-Moduls "KNX DPT <n>" anhand des Modulnamens
    private function knxModule(int $dpt): string
    {
        static $cache = [];
        if (isset($cache[$dpt])) {
            return $cache[$dpt];
        }
        foreach (IPS_GetModuleList() as $guid) {
            if (IPS_GetModule($guid)['ModuleName'] === 'KNX DPT ' . $dpt) {
                return $cache[$dpt] = $guid;
            }
        }
        throw new Exception('Symcon-Modul „KNX DPT ' . $dpt . '“ nicht gefunden');
    }

    // Ausgewähltes KNX-Gateway (0, wenn keins ausgewählt ist)
    private function knxGateway(int $selected): int
    {
        return ($selected > 1 && IPS_InstanceExists($selected)) ? $selected : 0;
    }

    // Anzahl automatisch angelegter Instanzen ohne Gateway-Verbindung
    private function knxUnconnected(array $wanted): int
    {
        $n = 0;
        foreach (array_keys($wanted) as $ident) {
            $iid = $this->knxInstance($ident);
            if ($iid > 0 && IPS_GetInstance($iid)['ConnectionID'] === 0) {
                $n++;
            }
        }
        return $n;
    }

    // Prüft vor dem Senden, ob eine eigene KNX-Variable an einem Gateway hängt
    private function knxCanSend(int $varID): bool
    {
        $iid = IPS_GetParent($varID);
        if ($iid > 0 && IPS_InstanceExists($iid) && IPS_GetParent($iid) === $this->InstanceID
            && IPS_GetInstance($iid)['ConnectionID'] === 0) {
            $this->LogMessage('„' . IPS_GetName($iid) . '“ ist mit keinem KNX-Gateway verbunden. Bitte unter „KNX-Gruppenadressen“ das Gateway auswählen.', KL_ERROR);
            $this->SetStatus(202);
            return false;
        }
        return true;
    }

    private function knxInstance(string $ident): int
    {
        $id = (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return ($id > 0 && IPS_InstanceExists($id)) ? $id : 0;
    }

    // Variable der automatisch angelegten Instanz (0, wenn es keine gibt)
    private function knxVar(string $ident): int
    {
        $iid = $this->knxInstance($ident);
        if ($iid === 0) {
            return 0;
        }
        foreach (IPS_GetChildrenIDs($iid) as $c) {
            if (IPS_VariableExists($c)) {
                return $c;
            }
        }
        return 0;
    }

    /**
     * Gleicht die KNX-Instanzen mit der gewünschten Liste ab.
     * $wanted: ident => ['ga' => '1/2/3', 'dpt' => 9, 'dim' => 1, 'write' => true, 'name' => 'Solltemperatur']
     * Rückgabe: Liste der Fehlermeldungen
     */
    private function knxSync(array $wanted, int $selected): array
    {
        $errors = [];
        $gateway = $this->knxGateway($selected);
        if ($wanted && $gateway === 0) {
            $errors[] = 'Kein KNX-Gateway ausgewählt. Bitte unter „KNX-Gruppenadressen“ das KNX-Gateway auswählen und übernehmen.';
        }
        foreach ($wanted as $ident => $w) {
            try {
                $this->knxEnsure($ident, $w, $gateway);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        // nicht mehr benötigte Instanzen entfernen
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $c) {
            $o = IPS_GetObject($c);
            if ($o['ObjectType'] === 1 && strpos($o['ObjectIdent'], 'KNX_') === 0 && !isset($wanted[$o['ObjectIdent']])) {
                IPS_DeleteInstance($c);
            }
        }
        foreach ($errors as $e) {
            $this->LogMessage($e, KL_ERROR);
        }
        return $errors;
    }

    private function knxEnsure(string $ident, array $w, int $gateway): void
    {
        $a = $this->knxParse((string)$w['ga']);
        if ($a === null) {
            throw new Exception('Ungültige Gruppenadresse „' . $w['ga'] . '“ bei ' . $w['name'] . ' (Format Haupt/Mittel/Unter, z. B. 3/1/20)');
        }
        $guid = $this->knxModule((int)$w['dpt']);
        $iid = $this->knxInstance($ident);
        if ($iid > 0 && IPS_GetInstance($iid)['ModuleInfo']['ModuleID'] !== $guid) {
            IPS_DeleteInstance($iid);   // Datentyp geändert
            $iid = 0;
        }
        if ($iid === 0) {
            $iid = IPS_CreateInstance($guid);
            IPS_SetParent($iid, $this->InstanceID);
            IPS_SetIdent($iid, $ident);
        }
        // mit dem ausgewählten Gateway verbinden
        $con = IPS_GetInstance($iid)['ConnectionID'];
        if ($gateway > 1 && $con !== $gateway) {
            if ($con > 0) {
                IPS_DisconnectInstance($iid);
            }
            IPS_ConnectInstance($iid, $gateway);
        }
        IPS_SetName($iid, $w['name']);
        IPS_SetInfo($iid, 'Automatisch angelegt von ' . IPS_GetName($this->InstanceID) . ' (GA ' . implode('/', $a) . ')');
        IPS_SetProperty($iid, 'Address1', $a[0]);
        IPS_SetProperty($iid, 'Address2', $a[1]);
        IPS_SetProperty($iid, 'Address3', $a[2]);
        IPS_SetProperty($iid, 'Dimension', (int)$w['dim']);
        IPS_SetProperty($iid, 'CapabilityReceive', true);
        IPS_SetProperty($iid, 'CapabilityWrite', (bool)$w['write']);
        IPS_SetProperty($iid, 'EmulateStatus', (bool)$w['write']);
        if (IPS_HasChanges($iid)) {
            IPS_ApplyChanges($iid);
        }
        $vid = $this->knxVar($ident);
        if ($vid > 0 && IPS_GetName($vid) !== $w['name']) {
            IPS_SetName($vid, $w['name']);
        }
    }

    // Prüfsumme der Wunschliste, damit nur bei Änderungen abgeglichen wird
    private function knxHash(array $wanted, int $gateway): string
    {
        $missing = 0;
        foreach (array_keys($wanted) as $ident) {
            if ($this->knxInstance($ident) === 0) {
                $missing++;
            }
        }
        return md5(json_encode([$wanted, $gateway, $missing, $this->knxUnconnected($wanted)]));
    }

    // Formular-Elemente für Gateway-Auswahl
    private function knxGatewayElements(): array
    {
        return [
            ['type' => 'SelectInstance', 'name' => 'KnxGateway', 'caption' => 'KNX-Gateway (für Gruppenadressen erforderlich)'],
            ['type' => 'Label', 'caption' => 'Trägst du eine Gruppenadresse ein (Format 3/1/20), legt das Modul beim Übernehmen die passende KNX-Instanz samt Variable mit dem jeweiligen Namen an. Eine ausgewählte Variable hat Vorrang vor der Gruppenadresse.']
        ];
    }
}
