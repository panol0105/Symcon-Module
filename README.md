# Symcon-Module

Module für IP-Symcon ab Version 7 (getestet für 9.x), Kachel-Visualisierung.

- **LampenListe**: mehrere Lampen in einer Kachel als Liste (Schalten, Dimmer, Tunable White, RGBW)
- **KlimaKachel**: ein Klima-Innengerät über das Intesis/HMS-Gateway IN776-MHI per KNX (runder Temperatur-Slider, Betriebsart, Lüfter, Lamellen, Meldungen, Fernbedienungssperre, Zeitschaltuhr)
- **FensterTuerTor**: Fenster, Türen und Tore in einer Liste; mit Öffnen/Schließen-Adressen bedienbar, sonst nur Statusanzeige
- **KlimaGruppe**: mehrere KlimaKachel-Instanzen eines Raums als ein Gerät, parallel gesteuert, mit gemeinsamer Auswertung der Rückmeldungen

## Fenster, Türen und Tore

Pro Eintrag: Name, Art (Tor, Tür, Fenster) und die Gruppenadressen (alle DPT 1.001):

| Spalte | Bedeutung |
|---|---|
| GA Öffnen / GA Schließen | Befehl, es wird eine 1 gesendet. Leer = nur Statusanzeige |
| GA geöffnet / GA geschlossen | Endlagen-Rückmeldungen. Eine davon reicht (z. B. Fensterkontakt nur „geöffnet“) |
| Laufzeit | so lange zeigt die Kachel nach einem Befehl „Öffnet …“ / „Schließt …“ mit Fortschrittsbalken |
| Nachfragen | Befehl muss in der Kachel ein zweites Mal bestätigt werden (empfohlen für Tore) |

Zustände: Geöffnet, Geschlossen, Zwischenstellung (keine Endlage gemeldet), Endlagen-Fehler (beide gleichzeitig), Keine Rückmeldung. Wird die Endlage innerhalb der Laufzeit nicht erreicht, zeigt die Kachel einen Hinweis.

## Schrift

Die Kacheln nutzen die Schrift Poppins aus dem lokalen Ordner `libs/fonts` (eingebettet, keine Internetverbindung nötig). Poppins steht unter der SIL Open Font License, siehe `libs/fonts/OFL-Poppins.txt`.

## Update von Version 2.2 oder älter

Das Gruppen-Modul hieß früher „KlimaRaum“ und hat ab Version 2.3 eine eigene Kennung.

1. Eine neue Instanz „KlimaGruppe“ anlegen, die Geräte eintragen und die neue Instanz in die Visualisierung aufnehmen.
2. Die alte Raum- bzw. Gruppen-Instanz löschen.
3. Den Ordner `KlimaRaum` aus dem Repository löschen.

## Installation

1. Diesen Ordner als öffentliches GitHub-Repository hochladen (library.json liegt ganz oben).
2. In der Symcon-Konsole: Kern-Instanzen → Modules → Hinzufügen → Repository-Adresse eintragen.
3. Instanzen anlegen, Variablen zuordnen und in der Kachel-Visualisierung hinzufügen.

## KNX-Gruppenadressen direkt eintragen

In der **KlimaKachel** steht neben jeder Variablen-Auswahl ein Feld „oder Gruppenadresse“, in der **LampenListe** gibt es pro Lampe Spalten für die Gruppenadressen (jeweils mit eigener Status-Adresse).

Beim Klick auf „Übernehmen“ legt das Modul für jede eingetragene Adresse eine passende KNX-Instanz unterhalb der Modul-Instanz an:

- Datentyp passend zum Datenpunkt (z. B. Solltemperatur DPT 9.001, Lüfterstufe DPT 5.010, Betriebsart je nach Codierung DPT 20.105 oder 5.010, Helligkeit DPT 5.001, Farbtemperatur DPT 7.600, Farbe DPT 232.600)
- Instanz und Variable bekommen den Namen „<Name in der Kachel> <Datenpunkt>“, z. B. „Wohnzimmer Fenster Solltemperatur Status“
- Status-Adressen werden nur lesend angelegt, Steuer-Adressen schreibend
- wird eine Adresse geändert, passt das Modul die bestehende Instanz an; wird sie gelöscht, entfernt es die Instanz
- unter „KNX-Gruppenadressen“ kann das KNX-Gateway gewählt werden; ohne Auswahl verbindet Symcon automatisch

Format: dreistufig Haupt/Mittel/Unter, z. B. 3/1/20. Eine ausgewählte Variable hat Vorrang vor der Gruppenadresse. Bei ungültigen Adressen zeigt die Instanz einen Fehlerstatus, Details stehen im Meldungen-Fenster.

## Räume mit mehreren Klimageräten

1. Für jedes Innengerät eine **KlimaKachel**-Instanz anlegen und die KNX-Variablen zuordnen. Diese Instanzen nicht in die Visualisierung aufnehmen.
2. Eine **KlimaGruppe**-Instanz anlegen und die KlimaKachel-Instanzen des Raums in die Liste eintragen. Das erste Gerät ist das Hauptgerät.
3. Nur die KlimaGruppe-Instanz in die Visualisierung aufnehmen.

### Auswertung der Rückmeldungen

| Wert | Anzeige im Raum |
|---|---|
| Ein/Aus | an, sobald ein Gerät läuft; bei teilweisem Betrieb „2 von 3 an“ und Ring um die Ein-Aus-Taste |
| Sollwert | Wert des Hauptgeräts; weichen laufende Geräte ab, erscheint die Spanne als Hinweis |
| Ist-Temperatur | Mittelwert aller Geräte, bei mehr als 0,5 °C Abweichung mit Spanne |
| Betriebsart, Lüfter, Lamellen | nur bei Übereinstimmung aller laufenden Geräte markiert, sonst „Gemischt“ |
| Störung, Verbindung | jede Meldung mit Gerätename |
| Filter | sobald ein Gerät meldet |
| Verdichter / Temperatur erreicht | Verdichter, sobald einer läuft; „erreicht“ erst, wenn alle laufenden Geräte fertig sind |

Weichen die Geräte voneinander ab, zeigt die Kachel „≠ … · angleichen“. Ein Tipp bringt alle Geräte auf den Stand des Hauptgeräts.
Mit „Abweichungen automatisch angleichen“ übernimmt das Modul eine Bedienung an einem einzelnen Gerät (z. B. Fernbedienung) nach 15 Sekunden für alle Geräte des Raums.
Im Status-Fenster lässt sich jedes Gerät auch einzeln ein- und ausschalten.
