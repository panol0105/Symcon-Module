<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/TileFont.php';

/**
 * KlimaGruppe: fasst mehrere KlimaKachel-Instanzen (Innengeräte eines Raums)
 * zu einem Gerät zusammen, steuert sie parallel und wertet ihre
 * Rückmeldungen gemeinsam aus.
 */
class KlimaGruppe extends IPSModule
{
    use TileFont;

    private const KK_GUID = '{B3F6A912-4D7E-4C58-9A21-6E0D8C3F5B74}';
    private const FORWARD = ['power', 'setpoint', 'mode', 'fan', 'vane', 'swing', 'filterReset', 'errReset'];
    private const OWN_WINDOW = 20;   // Sekunden: Rückmeldungen nach eigenem Befehl gelten nicht als Fremdbedienung
    private const SYNC_DELAY = 15;   // Sekunden bis zum automatischen Angleichen

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Title', 'Raum');
        $this->RegisterPropertyString('Units', '[]');
        $this->RegisterPropertyBoolean('ShowLock', true);
        $this->RegisterPropertyBoolean('AutoSync', false);
        $this->RegisterAttributeString('Timers', '[]');
        $this->RegisterTimer('Clock', 0, 'IPS_RequestAction($_IPS["TARGET"], "check", 0);');
        $this->RegisterTimer('AutoSync', 0, 'IPS_RequestAction($_IPS["TARGET"], "autosync", 0);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->registerVars(true);
        $this->SetTimerInterval('Clock', 20000);
        $this->SetTimerInterval('AutoSync', 0);
        $this->push();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message != VM_UPDATE) {
            return;
        }
        // Fremdbedienung erkennen (z. B. Fernbedienung am Gerät)
        if ($this->ReadPropertyBoolean('AutoSync') && $Data[1]) {
            $map = json_decode($this->GetBuffer('CtrlMap'), true) ?: [];
            $own = time() - (int)$this->GetBuffer('OwnCmd') < self::OWN_WINDOW;
            if (!$own && isset($map[(string)$SenderID])) {
                $this->SetBuffer('RefUnit', (string)$map[(string)$SenderID]);
                $this->SetTimerInterval('AutoSync', self::SYNC_DELAY * 1000);
            }
        }
        $this->push();
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $this->tileFontCss() . $html . '<script>handleMessage(' . json_encode($this->buildState()) . ');</script>';
    }

    // Zustand als JSON (KG_GetState), z. B. für eigene Skripte
    public function GetState(): string
    {
        return $this->buildState();
    }

    // Alle Geräte auf die Einstellungen des Hauptgeräts bringen (KG_Sync)
    public function Sync(): void
    {
        $this->syncUnits(0);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'lock':
                // Die Sperre wirkt am Gateway auf alle Geräte: einmal senden reicht
                foreach ($this->states() as $s) {
                    if ($s['lock'] !== null) {
                        $this->send($s['id'], 'lock', (bool)$Value);
                        break;
                    }
                }
                break;
            case 'unitPower':
                $d = json_decode((string)$Value, true);
                foreach ($this->units() as $u) {
                    if ($u['id'] === (int)($d['id'] ?? 0)) {
                        $this->send($u['id'], 'power', (bool)($d['v'] ?? false));
                    }
                }
                break;
            case 'sync':
                $this->syncUnits(0);
                break;
            case 'autosync':
                $this->SetTimerInterval('AutoSync', 0);
                $this->syncUnits((int)$this->GetBuffer('RefUnit'));
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
                $this->registerVars(false);
                break;
            default:
                if (!in_array($Ident, self::FORWARD, true)) {
                    throw new Exception('Ungültiger Ident: ' . $Ident);
                }
                $this->forward($Ident, $Value);
        }
    }

    // ---------- Geräte ----------

    private function units(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('Units'), true) ?: [] as $r) {
            $id = (int)($r['InstanceID'] ?? 0);
            if ($id > 0 && IPS_InstanceExists($id)
                && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::KK_GUID) {
                $name = trim((string)($r['Name'] ?? ''));
                $out[] = ['id' => $id, 'name' => $name !== '' ? $name : IPS_GetName($id)];
            }
        }
        return $out;
    }

    private function states(): array
    {
        $out = [];
        foreach ($this->units() as $u) {
            try {
                $s = json_decode(KK_GetState($u['id']), true);
            } catch (Throwable $e) {
                $this->SendDebug('State', $u['name'] . ': ' . $e->getMessage(), 0);
                continue;
            }
            if (is_array($s)) {
                $out[] = $s + ['id' => $u['id'], 'name' => $u['name']];
            }
        }
        return $out;
    }

    private function send(int $id, string $ident, $value): void
    {
        $this->SetBuffer('OwnCmd', (string)time());
        try {
            IPS_RequestAction($id, $ident, $value);
        } catch (Throwable $e) {
            $this->SendDebug('Senden', IPS_GetName($id) . ' ' . $ident . ': ' . $e->getMessage(), 0);
        }
    }

    // Befehl parallel an alle Geräte
    private function forward(string $ident, $value): void
    {
        foreach ($this->units() as $u) {
            $this->send($u['id'], $ident, $value);
        }
    }

    // Alle Geräte auf den Stand des Referenzgeräts bringen (0 = Hauptgerät)
    private function syncUnits(int $refId): void
    {
        $st = $this->states();
        if (count($st) < 2) {
            return;
        }
        $ref = $st[0];
        foreach ($st as $s) {
            if ($s['id'] === $refId) {
                $ref = $s;
            }
        }
        foreach ($st as $s) {
            if ($s['id'] === $ref['id']) {
                continue;
            }
            if ($s['power'] !== $ref['power']) {
                $this->send($s['id'], 'power', $ref['power']);
            }
            if ($ref['set'] !== null && abs((float)$s['set'] - (float)$ref['set']) > 0.05) {
                $this->send($s['id'], 'setpoint', $ref['set']);
            }
            if ($ref['mode'] !== null && $s['has']['mode'] && $s['mode'] !== $ref['mode']) {
                $this->send($s['id'], 'mode', $ref['mode']);
            }
            if ($ref['fan'] !== null && $s['has']['fan'] && $s['fan'] !== $ref['fan']) {
                $this->send($s['id'], 'fan', $ref['fan']);
            }
            if ($ref['swing'] === true && $s['has']['swing'] && $s['swing'] !== true) {
                $this->send($s['id'], 'swing', true);
            } elseif ($ref['swing'] !== true && $ref['vane'] !== null && $s['has']['vane']
                && ($s['vane'] !== $ref['vane'] || $s['swing'] === true)) {
                if ($s['swing'] === true) {
                    $this->send($s['id'], 'swing', false);
                }
                $this->send($s['id'], 'vane', $ref['vane']);
            }
        }
        $this->SendDebug('Sync', 'Referenz: ' . $ref['name'], 0);
    }

    // Auf alle Variablen der Geräte hören; bei geänderter Zuordnung neu registrieren
    private function registerVars(bool $force): void
    {
        $map = [];
        $ctrl = [];
        foreach ($this->units() as $u) {
            try {
                foreach (KK_GetVariableIDs($u['id']) as $vid) {
                    $map[(string)$vid] = $u['id'];
                }
                foreach (KK_GetControlVariableIDs($u['id']) as $vid) {
                    $ctrl[(string)$vid] = $u['id'];
                }
            } catch (Throwable $e) {
                $this->SendDebug('Register', $u['name'] . ': ' . $e->getMessage(), 0);
            }
        }
        ksort($map);
        $this->SetBuffer('CtrlMap', json_encode($ctrl));
        $key = json_encode($map);
        if (!$force && $key === $this->GetBuffer('VarMap')) {
            return;
        }
        foreach ($this->GetMessageList() as $id => $msgs) {
            foreach ($msgs as $m) {
                $this->UnregisterMessage($id, $m);
            }
        }
        foreach (array_keys($map) as $vid) {
            $this->RegisterMessage((int)$vid, VM_UPDATE);
        }
        $this->SetBuffer('VarMap', $key);
        if (!$force) {
            $this->push();
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
                $this->forward('power', true);
                if (($t['mode'] ?? '') !== '') {
                    $this->forward('mode', $t['mode']);
                }
                if ((int)($t['fan'] ?? -1) >= 0) {
                    $this->forward('fan', (int)$t['fan']);
                }
                if (!empty($t['temp'])) {
                    $this->forward('setpoint', (float)$t['temp']);
                }
            } else {
                $this->forward('power', false);
            }
            $this->SendDebug('Timer', 'Ausgeführt: ' . json_encode($t), 0);
        }
    }

    // ---------- Auswertung der Rückmeldungen ----------

    private function buildState(): string
    {
        $st = $this->states();
        $total = count($st);
        $on = array_values(array_filter($st, fn ($s) => !empty($s['power'])));
        // Betriebswerte: maßgeblich sind die laufenden Geräte, sonst alle
        $ref = $on ?: $st;

        // gemeinsamer Wert oder "gemischt"
        $common = function (string $k) use ($ref): array {
            $v = [];
            foreach ($ref as $s) {
                if (($s[$k] ?? null) !== null && ($k === 'set' || ($s['has'][$k] ?? true))) {
                    $v[] = $s[$k];
                }
            }
            if (!$v) {
                return [null, false];
            }
            $u = array_unique(array_map('json_encode', $v));
            return count($u) === 1 ? [$v[0], false] : [null, true];
        };
        // true, sobald ein Gerät true meldet; null, wenn keins den Wert liefert
        $any = function (array $list, string $k) {
            $seen = false;
            foreach ($list as $s) {
                if (($s[$k] ?? null) === null) {
                    continue;
                }
                $seen = true;
                if ($s[$k]) {
                    return true;
                }
            }
            return $seen ? false : null;
        };
        $hasAny = function (string $k) use ($st): bool {
            foreach ($st as $s) {
                if (!empty($s['has'][$k])) {
                    return true;
                }
            }
            return false;
        };

        [$mode, $modeMixed] = $common('mode');
        [$fan, $fanMixed] = $common('fan');
        [$swing, $swingMixed] = $common('swing');
        [$vane, $vaneMixed] = $common('vane');
        if ($swing === true) {
            $vaneMixed = false;
        }

        // Sollwert: einheitlich oder Spanne, angezeigt wird das Hauptgerät
        $sets = [];
        foreach ($ref as $s) {
            if ($s['set'] !== null) {
                $sets[] = round((float)$s['set'], 1);
            }
        }
        $setMixed = count(array_unique($sets)) > 1;
        $set = $sets ? $sets[0] : null;

        // Ist-Temperatur: Mittelwert aller Geräte
        $acts = [];
        foreach ($st as $s) {
            if ($s['act'] !== null) {
                $acts[] = (float)$s['act'];
            }
        }
        $act = $acts ? round(array_sum($acts) / count($acts), 1) : null;

        $errs = [];
        $comms = [];
        $units = [];
        $diag = [];
        foreach ($st as $s) {
            if (!empty($s['comm'])) {
                $comms[] = $s['name'];
            }
            if (!empty($s['err'])) {
                $errs[] = $s['name'] . ': ' . $s['errText'];
            }
            $units[] = [
                'id'     => $s['id'],
                'name'   => $s['name'],
                'power'  => !empty($s['power']),
                'mode'   => $s['mode'],
                'set'    => $s['set'],
                'act'    => $s['act'],
                'fan'    => $s['fan'],
                'alarm'  => !empty($s['err']) || !empty($s['comm']),
                'filter' => !empty($s['filter'])
            ];
            foreach ($s['diag'] ?? [] as $d) {
                $diag[] = ['n' => $s['name'] . ' · ' . $d['n'], 'v' => $d['v']];
            }
        }

        // angebotene Betriebsarten: nur die, die alle Geräte der Gruppe anbieten
        $modeList = null;
        foreach ($st as $s) {
            $l = $s['modeList'] ?? ['heat', 'cool', 'fan'];
            $modeList = $modeList === null ? $l : array_values(array_intersect($modeList, $l));
        }

        $mins = array_column($st, 'min');
        $maxs = array_column($st, 'max');
        $steps = array_column($st, 'step');
        $lock = $any($st, 'lock');

        return json_encode([
            'title'   => $this->ReadPropertyString('Title'),
            'total'   => $total,
            'onCount' => count($on),
            'power'   => count($on) > 0,
            'mode'    => $mode,
            'modeList' => $modeList ?? ['heat', 'cool', 'fan'],
            'set'     => $set,
            'setMin'  => $sets ? min($sets) : null,
            'setMax'  => $sets ? max($sets) : null,
            'act'     => $act,
            'actMin'  => $acts ? min($acts) : null,
            'actMax'  => $acts ? max($acts) : null,
            'fan'     => $fan === null ? null : (int)$fan,
            'vane'    => $vane === null ? null : (int)$vane,
            'swing'   => $swing === null ? null : (bool)$swing,
            'mixed'   => [
                'power' => count($on) > 0 && count($on) < $total,
                'mode'  => $modeMixed,
                'set'   => $setMixed,
                'fan'   => $fanMixed,
                'vane'  => $vaneMixed || $swingMixed
            ],
            'comm'    => $comms ? true : $any($st, 'comm'),
            'comms'   => $comms,
            'err'     => count($errs) > 0,
            'errs'    => $errs,
            'errText' => implode(', ', $errs),
            'errCode' => $any($st, 'err') === null ? null : count($errs),
            'filter'  => $any($st, 'filter'),
            'comp'    => $any($on, 'comp'),
            'thermo'  => $any($on, 'thermo'),
            'lock'    => $lock,
            'units'   => $units,
            'diag'    => $diag,
            'has'     => [
                'mode'        => $hasAny('mode'),
                'fan'         => $hasAny('fan'),
                'vane'        => $hasAny('vane'),
                'swing'       => $hasAny('swing'),
                'errReset'    => $hasAny('errReset'),
                'filterReset' => $hasAny('filterReset'),
                'lock'        => $this->ReadPropertyBoolean('ShowLock') && $lock !== null
            ],
            'min'     => $mins ? max($mins) : 16,
            'max'     => $maxs ? min($maxs) : 30,
            'step'    => $steps ? max($steps) : 1,
            'autoSync' => $this->ReadPropertyBoolean('AutoSync'),
            'timers'  => json_decode($this->ReadAttributeString('Timers'), true) ?: []
        ]);
    }

    private function push(): void
    {
        $this->UpdateVisualizationValue($this->buildState());
    }
}
