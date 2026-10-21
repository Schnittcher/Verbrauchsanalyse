# CO2Bilanz
Rechnet den Verbrauch aus den Verbrauchsquellen in CO2 um, entweder für den Haushalt (Verbraucher und Gruppen) oder für die Heizung, je Bereich eine Instanz: Summe für Heute, Woche, Monat, Jahr oder einen eigenen Zeitraum, Vergleich mit der Vorperiode, Anteile je Gruppe und Gerät, Ausstoß je Bewohner und eine Baum-Entsprechung. Der Emissionsfaktor ist fest oder kommt stündlich aus einer Variable mit dem Faktor des Netzes.

![co2bilanz-haushalt](../docs/screenshots/co2bilanz-haushalt.png)

![co2bilanz-heizung-erdgas](../docs/screenshots/co2bilanz-heizung-erdgas.png)

![co2bilanz-heizung-pellets](../docs/screenshots/co2bilanz-heizung-pellets.png)


## Inhaltsverzeichnis
- [CO2Bilanz](#co2bilanz)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Kachel](#2-kachel)
  - [3. Variablen](#3-variablen)
  - [4. Funktionen](#4-funktionen)
  - [5. Spenden](#5-spenden)
  - [6. Lizenz](#6-lizenz)

## 1. Konfiguration

Die Instanz ist ein Kind der Instanz [Verbrauchsquellen](../Verbrauchsquellen/README.md) und wird beim Anlegen mit ihr verbunden. Von dort kommen Verbraucher, Gruppen, Hauptzähler und die Zahl der Bewohner.

Der Emissionsfaktor steht in den [Verbrauchsquellen](../Verbrauchsquellen/README.md): je Medium ein fester Faktor in g CO2 je kWh oder Einheit (Strom 380) oder eine Variable mit dem stündlichen Faktor, für die Heizung der Faktor im Block "Heizung" (0 = Wert des Energieträgers). Die Instanz hat dafür keine eigenen Felder. Fehlt der Faktor, meldet sie einen Fehler und die Kachel erklärt ihn.

Feld | Beschreibung
------------ | ----------------
Wofür die Bilanz gerechnet wird | Haushalt (Verbraucher, Gruppen und Hauptzähler der Verbrauchsquellen) oder Heizung (der Block "Heizung" der Verbrauchsquellen). Für beides legst du zwei Instanzen an. Das Formular zeigt nur die Felder zum gewählten Bereich. Standard: Haushalt.
Medium | Nur im Bereich Haushalt: Welches Medium der [Verbrauchsquellen](../Verbrauchsquellen/README.md) die Instanz auswertet: Strom, Wasser oder Gas. Es muss dort befüllt sein. Standard: Strom.
Werte der Verbrauchsquellen überschreiben | Mit dem Haken erscheinen die Felder Emissionsfaktor und Faktor-Variable (Haushalt), Heizwert und Faktor der Heizung (Heizung) sowie Bewohner. Sie gelten nur für diese Instanz und ersetzen die Werte aus den [Verbrauchsquellen](../Verbrauchsquellen/README.md), die anderen Werte kommen weiter von dort. So lässt sich dieselbe Auswertung mit anderen Werten nutzen, ohne einen zweiten Splitter anzulegen. Standard: aus.
CO2-Bindung eines Baums pro Jahr | Für die Baum-Entsprechung. Standard: 12,5 kg.
Zeitraum beim Start | Heute, Woche, Monat oder Jahr, jeweils bis jetzt. Standard: Monat.
Aktualisierungsintervall | Alle wie viele Minuten neu gerechnet wird. Standard: 60 Minuten.

## 2. Kachel

Oben wählst du den Zeitraum: Heute, Woche, Monat, Jahr oder Eigener Zeitraum (Von und Bis, beide Tage eingeschlossen, höchstens zehn Jahre, nicht in der Zukunft).

* **CO2-Ausstoß:** Summe in kg, ab einer Tonne in t.
* **Zur Vorperiode:** Veränderung gegenüber dem gleich langen Abschnitt davor, also gestern bis zur selben Uhrzeit, die Vorwoche, der Vormonat oder das Vorjahr bis zum selben Tag. Grün bedeutet weniger Ausstoß. Beim eigenen Zeitraum gilt der gleich lange Zeitraum direkt davor.
* **Je Bewohner:** Ausstoß geteilt durch die Zahl der Bewohner, wenn die Verbrauchsquellen sie kennen.
* **Bäume, die es in einem Jahr binden:** Ausstoß geteilt durch die Bindung eines Baums.
* **Mittlerer Emissionsfaktor** und der zugrunde liegende Verbrauch.
* **Verteilung:** Balken und Legende der obersten Ebene, darunter alle Gruppen und Geräte mit Anteil. "Nicht erfasst" ist der Rest gegenüber dem Hauptzähler. Hinweise zeigen Ausstoß und Anteil.

**Heizung:** Eine Instanz mit Bereich Heizung rechnet nur die Heizung aus den Verbrauchsquellen. Der Verbrauch der Heizungsvariable wird mit dem Heizwert aus dem Block in kWh umgerechnet (bei Erdgas in m³ und Heizöl in Litern ohne Heizwert mit etwa 10 kWh je Einheit) und mit dem Faktor der Heizung multipliziert. Ohne Heizung, mit Energieträger **Strom** (Wärmepumpe, der Strom zählt in der Instanz für den Haushalt, so wird nichts doppelt gezählt), ohne Faktor (Holzpellets, Sonstiges) oder ohne Heizwert meldet die Instanz einen Fehler und die Kachel erklärt ihn. Die Faktor-Variable (stündlicher Netzfaktor) gilt nur für den Haushalt.

Rechnung: Verbrauch aus dem Archiv mal Faktor. Mit Faktor-Variable wird je Stunde multipliziert und summiert. Gruppen mit Gruppenzähler zeigen dessen Wert und den Rest der Gruppe, wie in Top Verbraucher.

Die Kachel zeigt die maximierte Ansicht erst ab Symcon 9.1, unter 9.0 bleibt sie leer.

## 3. Variablen

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
CO2 heute | `Today` | Kommazahl (kg) | Seit Mitternacht.
CO2 in der Woche | `Week` | Kommazahl (kg) | Seit Montag.
CO2 im Monat | `Month` | Kommazahl (kg) | Seit dem Ersten.
CO2 im Jahr | `Year` | Kommazahl (kg) | Seit dem 1. Januar.
CO2 je Bewohner im Jahr | `PerResident` | Kommazahl (kg) | Nur mit Bewohnerzahl in den Verbrauchsquellen.
Bäume, die es in einem Jahr binden | `Trees` | Kommazahl | Aus dem Ausstoß im Jahr.

## 4. Funktionen

`CO2_Update(integer $InstanzID);`
Berechnet alles neu, setzt die Variablen und aktualisiert die Kachel.

## 5. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 6. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
