# Austauschrechner
Rechnet, ob sich ein neues, sparsameres Gerät lohnt. Der Verbrauch des alten Geräts kommt aus dem Archiv oder aus einer Angabe in Watt, der des neuen aus dem Energielabel oder aus Watt und Laufzeit. Der Strompreis kommt aus den Verbrauchsquellen. Die Instanz zeigt Einsparung, Amortisation und den Gewinn über die Lebensdauer. Pro Gerät legt man eine Instanz an.

![austauschrechner](../docs/screenshots/austauschrechner.png)


## Inhaltsverzeichnis
- [Austauschrechner](#austauschrechner)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Funktionen](#3-funktionen)
  - [4. Spenden](#4-spenden)
  - [5. Lizenz](#5-lizenz)

## 1. Konfiguration

Die Instanz ist ein Kind der Instanz [Verbrauchsquellen](../Verbrauchsquellen/README.md) (Einheit kWh) und wird beim Anlegen mit ihr verbunden. Von dort kommt der Preis je kWh, bei einer Preisvariable deren aktueller Wert.

Feld | Beschreibung
------------ | ----------------
Medium | Welches Medium der [Verbrauchsquellen](../Verbrauchsquellen/README.md) die Instanz auswertet: Strom, Wasser oder Gas. Es muss dort befüllt sein. Standard: Strom.
Werte der Verbrauchsquellen überschreiben | Mit dem Haken erscheinen die Felder Preis je Einheit und Preisvariable. Sie gelten nur für diese Instanz und ersetzen die Werte aus den [Verbrauchsquellen](../Verbrauchsquellen/README.md), die anderen Werte kommen weiter von dort. So lässt sich dieselbe Auswertung mit anderen Werten nutzen, ohne einen zweiten Splitter anzulegen. Standard: aus.
Altes Gerät (optional, archivierter Zähler oder Leistungsvariable) | Archivierte Variable des alten Geräts in kWh, Wh, kW oder W, zum Beispiel aus der Standby-Rangliste der Grundlast. Gemessen werden die letzten 30 ganzen Tage, hochgerechnet auf ein Jahr. Es braucht mindestens eine Woche an Stundenwerten.
Altes Gerät: mittlere Leistung im Betrieb (gilt ohne Archivdaten) | Alternative ohne Archiv: die mittlere Leistung in Watt mal die Betriebsstunden pro Tag.
Betriebsstunden pro Tag | Wie lange das Gerät am Tag läuft, 24 für Dauerläufer wie einen Kühlschrank. Gilt für die Angabe in Watt beim alten und beim neuen Gerät.
Neues Gerät: Energielabel (kWh pro Jahr, 0 = die Leistung darunter nutzen) | Jahresverbrauch laut Energielabel. Ist er größer als 0, hat er Vorrang.
Neues Gerät: mittlere Leistung im Betrieb | Alternativ die mittlere Leistung in Watt, mal die Betriebsstunden.
Anschaffungspreis | Preis des neuen Geräts in EUR.
Erwartete Lebensdauer | In Jahren. Standard: 12.
Erwartete Preissteigerung pro Jahr | Jährliche Steigerung des Strompreises in Prozent. Standard: 0.
Aktualisierungsintervall | Abstand der Neuberechnung in Minuten. Standard: 360.

So wird gerechnet: Einsparung pro Jahr ist der Verbrauch des alten minus der des neuen Geräts. Die Amortisation ist die Zeit, bis die Einsparungen den Anschaffungspreis decken. Bei einer Preissteigerung wächst die Einsparung jedes Jahr, die Instanz rechnet dafür mit der Summe der Jahresersparnisse. Der Gewinn über die Lebensdauer ist die Summe der Ersparnisse über die Lebensdauer minus der Anschaffungspreis. Das Modul rechnet nur mit Energie. Entsorgung, Restwert des alten Geräts und Zinsen fehlen.

Kachel: Die Instanz zeigt das Ergebnis in der Kachel-Visualisierung als "Lohnt sich" / "Lohnt sich nicht" mit Amortisationszeit, einem Balken über die Lebensdauer (rot bis zur Amortisation, danach grün) und Karten für alten und neuen Verbrauch sowie die Einsparung. Über "Eingaben" lassen sich Betriebsstunden, Energielabel, Leistung des neuen Geräts, Preis, Lebensdauer und Preissteigerung direkt in der Kachel ändern. Die Leistung des alten Geräts ist nur editierbar, solange keine Archivvariable gesetzt ist, sonst kommt der Wert aus dem Archiv. Eingaben werden geprüft und in die Instanzeinstellungen übernommen.

Die Instanz hat eine Kachel, in der sich die Eingaben direkt ändern lassen. Sie zeigt die maximierte Ansicht erst ab Symcon 9.1, unter 9.0 bleibt sie leer.

## 2. Variablen

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Altes Gerät pro Jahr | `OldKWhYear` | Kommazahl (kWh) | Verbrauch des alten Geräts.
Neues Gerät pro Jahr | `NewKWhYear` | Kommazahl (kWh) | Verbrauch des neuen Geräts.
Einsparung pro Jahr | `SavingKWhYear` | Kommazahl (kWh) | Negativ, wenn das neue Gerät mehr verbraucht.
Einsparung pro Jahr in Geld | `SavingCostYear` | Kommazahl (€) | Einsparung mal Strompreis.
Amortisationszeit | `PaybackYears` | Kommazahl (Jahre) | 0, wenn es nie amortisiert.
Gewinn über die Lebensdauer | `NetLifetime` | Kommazahl (€) | Negativ = Verlust.
Ergebnis | `Verdict` | Text | "Lohnt sich" oder "Lohnt sich nicht" mit Zahlen.

Alle Variablen sind schreibgeschützt und werden vom Modul berechnet.

## 3. Funktionen

`void AUS_Calculate(integer $InstanzID);`
Berechnet alles neu und schreibt die Variablen. Läuft automatisch im eingestellten Intervall.

## 4. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 5. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
