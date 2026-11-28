<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/KlimaBase.php';
require_once __DIR__ . '/../libs/FbhPart.php';

/**
 * KlimaFBH: Klimagerät und Fußbodenheizung eines Raums in einer Kachel.
 * Klima-Teil wie KlimaKachel (libs/KlimaBase.php), FBH-Teil aus libs/FbhPart.php.
 * Kachel und Formular stammen aus dem Ordner KlimaKachel; der FBH-Bereich
 * erscheint dort nur, wenn FBH-Daten vorhanden sind.
 */
class KlimaFBH extends KlimaBase
{
    use FbhPart;

    public function Create()
    {
        parent::Create();
        $this->fbhRegister();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($this->fbhAction((string)$Ident, $Value)) {
            $this->push();
            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(parent::GetConfigurationForm(), true);
        // FBH-Bereich direkt nach den Klima-Grundeinstellungen einfügen
        array_splice($form['elements'], 3, 0, [$this->fbhFormPanel(true)]);
        return json_encode($form);
    }

    public function RequestStatus(): void
    {
        parent::RequestStatus();
        $this->fbhRequestStatus();
    }

    // ---------- Erweiterungen der Klima-Basis ----------

    // Kachel (module.html) und Formular (form.json) der Klima-Kachel verwenden
    protected function moduleDir(): string
    {
        return dirname(__DIR__) . '/KlimaKachel';
    }

    protected function extraVarIDs(): array
    {
        return $this->fbhVarIDs();
    }

    protected function knxWanted(): array
    {
        $prefix = trim($this->ReadPropertyString('Title'));
        if ($prefix === '') {
            $prefix = IPS_GetName($this->InstanceID);
        }
        return array_merge(parent::knxWanted(), $this->fbhWanted($prefix));
    }

    protected function missingFeedback(): array
    {
        return array_merge(parent::missingFeedback(), $this->fbhMissing());
    }

    protected function temperatures(): array
    {
        return array_merge(parent::temperatures(), $this->fbhTemps());
    }

    protected function checkTimers(): void
    {
        // FBH-Anteile der Schaltzeiten (Betriebsart/Sollwert FBH), danach die Klima-Anteile
        $this->fbhCheckTimers(json_decode($this->ReadAttributeString('Timers'), true) ?: []);
        parent::checkTimers();
    }

    protected function buildState(): string
    {
        return json_encode(array_merge(json_decode(parent::buildState(), true), $this->fbhState()));
    }
}
