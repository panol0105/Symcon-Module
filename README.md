# Symcon-Module

Module für IP-Symcon ab Version 7 (getestet für 9.x), Kachel-Visualisierung.

- **LampenListe**: mehrere Lampen in einer Kachel als Liste (Schalten, Dimmer, Tunable White, RGBW)
- **KlimaKachel**: ein Klima-Innengerät über das Intesis/HMS-Gateway IN776-MHI per KNX (runder Temperatur-Slider, Betriebsart, Lüfter, Lamellen, Meldungen, Fernbedienungssperre, Zeitschaltuhr)
- **KlimaRaum**: mehrere KlimaKachel-Instanzen eines Raums als ein Gerät, parallel gesteuert, mit gemeinsamer Auswertung der Rückmeldungen

## Installation

1. Diesen Ordner als öffentliches GitHub-Repository hochladen (library.json liegt ganz oben).
2. In der Symcon-Konsole: Kern-Instanzen → Modules → Hinzufügen → Repository-Adresse eintragen.
3. Instanzen anlegen, Variablen zuordnen und in der Kachel-Visualisierung hinzufügen.

## Räume mit mehreren Klimageräten

1. Für jedes Innengerät eine **KlimaKachel**-Instanz anlegen und die KNX-Variablen zuordnen. Diese Instanzen nicht in die Visualisierung aufnehmen.
2. Eine **KlimaRaum**-Instanz anlegen und die KlimaKachel-Instanzen des Raums in die Liste eintragen. Das erste Gerät ist das Hauptgerät.
3. Nur die KlimaRaum-Instanz in die Visualisierung aufnehmen.

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
