# Heizgradtage
Zeigt den Verbrauch einer Heizung im Verhältnis zur Witterung. Der Verbrauch (Gas, Wärme oder Strom einer Wärmepumpe) wird durch die Heizgradtage geteilt. So zeigt der Vergleich mit dem Vorjahr, ob der Verbrauch wirklich sinkt oder nur der Winter milder war.

![heizgradtage-erdgas](../docs/screenshots/heizgradtage-erdgas.png)

![heizgradtage-pellets](../docs/screenshots/heizgradtage-pellets.png)


## Inhaltsverzeichnis
- [Heizgradtage](#heizgradtage)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Kachel](#3-kachel)
  - [4. Funktionen](#4-funktionen)
  - [5. Spenden](#5-spenden)
  - [6. Lizenz](#6-lizenz)

## 1. Konfiguration

Die Instanz ist ein Kind der Instanz [Verbrauchsquellen](../Verbrauchsquellen/README.md) und wird beim Anlegen mit ihr verbunden. Von dort kommt alles zur Heizung: die Variable, der Energieträger, der Heizwert und die Wohnfläche. Sie stehen im Block "Heizung" und in den Feldern darüber, nicht in dieser Instanz.

Feld | Beschreibung
------------ | ----------------
Werte der Verbrauchsquellen überschreiben | Mit dem Haken erscheinen die Felder Wohnfläche und Heizwert der Heizung. Sie gelten nur für diese Instanz und ersetzen die Werte aus den [Verbrauchsquellen](../Verbrauchsquellen/README.md), die anderen Werte kommen weiter von dort. So lässt sich dieselbe Auswertung mit anderen Werten nutzen, ohne einen zweiten Splitter anzulegen. Standard: aus.
Außentemperatur (muss archiviert sein) | Die Außentemperatur in °C. Aus ihren Tagesmittelwerten im Archiv entstehen die Heizgradtage.
Raumtemperatur | Soll-Innentemperatur der Heizgradtage. Standard: 20 °C.
Heizgrenze | Liegt die Tagesmitteltemperatur darunter, wird geheizt. Standard: 15 °C.
Archivdiagramm bei Klick öffnen | Ein Klick auf das Diagramm der Kachel öffnet den Archivdialog der Quellvariable, siehe Abschnitt Kachel. Standard: an. Ausgeschaltet bleiben die Hinweise beim Überfahren, der Klick tut nichts.
Aktualisierungsintervall | Abstand der Neuberechnung in Minuten. Standard: 60.

So wird gerechnet: Die Heizgradtage folgen der VDI 3807 (Gradtagzahl G20/15 mit den Standardwerten). Für jeden Tag, dessen Mitteltemperatur unter der Heizgrenze liegt, zählt die Differenz zur Raumtemperatur in Kelvin, wärmere Tage zählen nicht. Verglichen werden der laufende Monat und das laufende Jahr mit demselben Zeitraum im Vorjahr, jeweils bis gestern. Der laufende Tag ist nicht vollständig und zählt nicht. Tage ohne Temperaturwert im Archiv werden übersprungen. Am Monatsersten gibt es für den Monat noch keine ganzen Tage, die Werte stehen dann auf 0.

**Heizung in den Verbrauchsquellen:** Im Block "Heizung" des Splitters stehen die Heizungsvariable (archivierter Zähler in kWh, Wh, m³, l oder kg), der Energieträger und der **Heizwert je Einheit**. Mit einem Heizwert rechnen alle Variablen und die Kachel in kWh, zum Beispiel 4,8 für Pellets in kg oder rund 10 für Gas in m³. Bei einer Quelle in kWh, Wh, kW oder W gilt der Heizwert nicht. Die Heizung braucht einen Zähler im Archiv, der den Gesamtverbrauch hochzählt. Ein Lagerfüllstand eignet sich nicht, er sinkt und springt beim Nachfüllen. Ohne Heizung im Splitter meldet die Instanz "Ungültige Konfiguration".

Der Verbrauch je Heizgradtag enthält auch den Grundverbrauch, der nicht von der Witterung abhängt (zum Beispiel Warmwasser). Er eignet sich deshalb besonders für den Vergleich mit dem Vorjahr und weniger als absolute Kennzahl.

## 2. Variablen

Je Zeitraum (`Month`, `LastYearMonth`, `Year`, `LastYearYear`):

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Verbrauch | `<Zeitraum>_Consumption` | Kommazahl | Verbrauch im Zeitraum in der Einheit der Quelle, mit Heizwert in kWh.
Heizgradtage | `<Zeitraum>_DegreeDays` | Kommazahl (Kd) | Summe der Heizgradtage im Zeitraum.
je Heizgradtag | `<Zeitraum>_PerDegreeDay` | Kommazahl | Verbrauch geteilt durch Heizgradtage.

Mit einer Wohnfläche in den Verbrauchsquellen entstehen außerdem `Year_PerArea` und `LastYearYear_PerArea` mit der Heizenergie je m² für das Jahr und das Vorjahr (in der Einheit der Ausgabe, mit Heizwert in kWh/m²). In der Tabelle der Kachel steht dazu eine Zeile "je m²".

Weitere Variablen:

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Monat Veränderung zum Vorjahr | `MonthChange` | Kommazahl (%) | Verbrauch je Heizgradtag gegenüber dem Vorjahresmonat, negativ = weniger.
Jahr Veränderung zum Vorjahr | `YearChange` | Kommazahl (%) | Dasselbe für das Jahr.
Ergebnis | `Message` | Text | Kurzer Satz zum Jahr.

Alle Variablen sind schreibgeschützt und werden vom Modul berechnet. Zusätzlich gibt es die versteckte Textvariable `Data` mit den Daten der Kachel.

## 3. Kachel
Die Instanz erscheint in der Kachel-Visualisierung. Beim Überfahren oder Antippen der Balken erscheint ein Hinweis mit den Werten (auf Touch-Geräten bleibt er bis zum nächsten Tippen).

* Oben steht der Verbrauch je Heizgradtag des Jahres mit der Veränderung zum Vorjahr. Grün und nach unten heißt weniger Verbrauch, rot und nach oben mehr.
* Darunter vergleichen zwei Balken dieses Jahr mit dem Vorjahr. Eine kleine Tabelle nennt dazu Verbrauch (mit Einheit) und Heizgradtage (Kd) in zwei Spalten: links der laufende Zeitraum, rechts derselbe Zeitraum im Vorjahr. Gleiche Heizgradtage bei weniger Verbrauch heißt: Die Heizung ist sparsamer geworden, nicht nur das Wetter milder.
* Die Kachel passt sich der Höhe an und zeigt so viel, wie hineinpasst. Von wenig nach viel Platz: nur die Kennzahl, dazu die Jahresbalken, dazu die Tabelle des Jahres, dazu die Monatsbalken, zuletzt auch die Tabelle des Monats. Dadurch wird nichts abgeschnitten und es erscheint kein Scrollbalken, solange wenigstens die Kennzahl hineinpasst.

Ein Klick auf einen Balken öffnet den Archivdialog der Quellvariable, in dem sich der ganze Verlauf durchblättern lässt (in den Einstellungen abschaltbar).

Die maximierte Ansicht der Kachel zeigt erst ab Symcon 9.1 Inhalt, unter 9.0 bleibt sie leer.

## 4. Funktionen

`void HGT_Calculate(integer $InstanzID);`
Berechnet alle Zeiträume neu und schreibt die Variablen. Läuft automatisch im eingestellten Intervall.

## 5. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 6. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
