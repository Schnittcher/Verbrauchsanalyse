# Top Verbraucher
Wertet die Variablen der verbundenen Verbrauchsquellen aus dem Archiv aus und zeigt die größten Verbraucher für Heute, Woche, Monat und Jahr. Verbraucher lassen sich in Gruppen und Untergruppen ordnen, etwa ein Server-Rack mit NAS, Proxmox-Host und Switch. Die Instanz legt Variablen für Skripte und Ereignisse an und zeigt eine Kachel mit Rangliste.

![top-verbraucher](../docs/screenshots/top-verbraucher.png)

![top-verbraucher-wasser](../docs/screenshots/top-verbraucher-wasser.png)


## Inhaltsverzeichnis
- [Top Verbraucher](#top-verbraucher)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Kachel](#3-kachel)
  - [4. Funktionen](#4-funktionen)
  - [5. Spenden](#5-spenden)
  - [6. Lizenz](#6-lizenz)

## 1. Konfiguration

Die Instanz ist ein Kind der Instanz [Verbrauchsquellen](../Verbrauchsquellen/README.md) und wird beim Anlegen mit ihr verbunden. Einheit, Preis, Verbraucher, Hauptzähler und Gruppen werden dort gepflegt.

Feld | Beschreibung
------------ | ----------------
Medium | Welches Medium der [Verbrauchsquellen](../Verbrauchsquellen/README.md) die Instanz auswertet: Strom, Wasser oder Gas. Es muss dort befüllt sein. Standard: Strom.
Werte der Verbrauchsquellen überschreiben | Mit dem Haken erscheinen die Felder Preis je Einheit, Preisvariable, Bewohner und Wohnfläche. Sie gelten nur für diese Instanz und ersetzen die Werte aus den [Verbrauchsquellen](../Verbrauchsquellen/README.md), die anderen Werte kommen weiter von dort. So lässt sich dieselbe Auswertung mit anderen Werten nutzen, ohne einen zweiten Splitter anzulegen. Standard: aus.
Einträge in der Rangliste | Anzahl der Einträge der obersten Ebene. Der Rest erscheint als "Sonstige".
Archivdiagramm bei Klick öffnen | Ein Klick auf das Diagramm der Kachel öffnet den Archivdialog der Quellvariable, siehe Abschnitt Kachel. Standard: an. Ausgeschaltet bleiben die Hinweise beim Überfahren, der Klick tut nichts.
Aktualisierungsintervall | Abstand der Neuberechnung in Minuten. Standard: 5.

"Jetzt berechnen" rechnet sofort neu. Variablen ohne Archiv oder mit anderer Einheitenfamilie werden übersprungen, die Instanz zeigt dann den Status "Einige Variablen sind nicht archiviert und wurden übersprungen". Ist keine aktive Instanz Verbrauchsquellen verbunden, zeigt sie den Status "Es ist keine aktive Instanz Verbrauchsquellen verbunden".

Berechnung: Für Zähler mit Zähler-Aggregation zählt die Summe der positiven Differenzen, ein Zählertausch verfälscht nichts. Bei Standard-Aggregation dient Maximum minus Minimum im Zeitraum, das ist ungenauer. Die Vorperiode reicht genauso weit wie der laufende Zeitraum (heute 14 Uhr gegen gestern 14 Uhr). Kosten mit Preisvariable: Das Modul multipliziert den Verbrauch des Hauptzählers je Stunde mit dem Preis dieser Stunde und summiert über den Zeitraum. Dafür muss die Preisvariable archiviert sein, ein Hauptzähler gesetzt sein und dieser Zähler-Aggregation (oder eine Leistungseinheit) haben. Preise mit "ct" in der Einheit (zum Beispiel ct/kWh) werden durch 100 geteilt, alle anderen gelten als Euro. Fehlt für eine Stunde ein Preis, gilt der zuletzt bekannte. Ist eine Bedingung nicht erfüllt, rechnet das Modul mit dem festen Preis, bei einer nicht archivierten Preisvariable zeigt die Instanz außerdem den Status "Einige Variablen sind nicht archiviert und wurden übersprungen". Kosten je Gruppe oder Gerät gibt es nicht.

Der Hauptzähler ist die Basis für 100 %. "Nicht erfasst" ist Hauptzähler minus Summe der Verbraucher, nie negativ.

## 2. Variablen

Je Zeitraum (`today`, `week`, `month`, `year`):

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Verbrauch | `Total_<Zeitraum>` | Kommazahl | Gesamtverbrauch, ohne Hauptzähler die Summe der Verbraucher.
Kosten | `Cost_<Zeitraum>` | Kommazahl | Verbrauch mal Preis.
Top-Verbraucher | `Top_<Zeitraum>` | Text | Rangliste, zum Beispiel "NAS 3.20 kWh; Switch 1.10 kWh".
Auswertungsdaten | `Data` | Text | Alle Zeiträume als JSON mit Hierarchie, Anteilen und Vorperiode. Versteckt.

Je Zeitraum entstehen zusätzlich `PerResident_<Zeitraum>` (Verbrauch je Bewohner) und `PerArea_<Zeitraum>` (Verbrauch je m²), sobald in den Verbrauchsquellen Bewohner oder Wohnfläche eingetragen sind. Ohne die Angaben entfallen sie.

Alle Variablen sind schreibgeschützt und werden vom Modul berechnet.

## 3. Kachel
Die Instanz erscheint in der Kachel-Visualisierung. Beim Überfahren oder Antippen der Balken erscheint ein Hinweis mit den Werten (auf Touch-Geräten bleibt er bis zum nächsten Tippen).

* Chips für Heute, Woche, Monat und Jahr sowie der Umschalter "% von Gesamt" / "% von Gruppe". Die Auswahl merkt sich der Browser.
* Zwei Karten: Gesamtverbrauch mit Pfeil zur Vorperiode und Kosten. Sind Bewohner oder Wohnfläche eingetragen, steht unter dem Gesamtverbrauch auch der Verbrauch je Bewohner und je m².
* Rangliste der obersten Ebene, Gruppen und Einzelgeräte gemischt. Gruppen klappen per Klick auf, ihr Balken ist nach Mitgliedern segmentiert, der Rest der Gruppe ist grau.
* Letzte Zeile "Nicht erfasst" in Grau.
* Ein Klick auf einen Balken oder ein Segment eines Gruppenbalkens öffnet den Archivdialog der zugehörigen Variable (Gerät oder Gruppenzähler), in dem sich der ganze Verlauf durchblättern lässt. Auf Touch-Geräten zeigt das erste Tippen den Hinweis, das zweite öffnet den Dialog. In den Einstellungen abschaltbar.

Die Kachel zeigt die maximierte Ansicht erst ab Symcon 9.1, unter 9.0 bleibt sie leer.

## 4. Funktionen

`void TVB_Calculate(integer $InstanzID);`
Berechnet alle Zeiträume neu, schreibt die Variablen und aktualisiert die Kachel. Läuft automatisch im eingestellten Intervall.

## 5. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 6. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
