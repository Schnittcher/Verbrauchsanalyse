# Geraetewaechter
Der Gerätewächter vergleicht den Verbrauch jedes Geräts und jedes Gruppenzählers der letzten Tage mit seinem eigenen Normalverhalten aus den Wochen davor. Weicht ein Gerät deutlich ab, zum Beispiel ein Kühlschrank, der schleichend mehr zieht, oder eine Pumpe, die gar nicht mehr läuft, setzt er eine Alarmvariable und schreibt eine Meldung. Die Kachel zeigt auffällige Geräte zuerst, mit den letzten 14 Tagen als Säulen.

![geraetewaechter](../docs/screenshots/geraetewaechter.png)


## Inhaltsverzeichnis
- [Geraetewaechter](#geraetewaechter)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. So wird verglichen](#2-so-wird-verglichen)
  - [3. Kachel](#3-kachel)
  - [4. Variablen](#4-variablen)
  - [5. Funktionen](#5-funktionen)
  - [6. Spenden](#6-spenden)
  - [7. Lizenz](#7-lizenz)

## 1. Konfiguration

Die Instanz ist ein Kind der Instanz [Verbrauchsquellen](../Verbrauchsquellen/README.md) und wird beim Anlegen mit ihr verbunden. Geprüft werden alle Verbraucher und alle Gruppen mit eigenem Zähler.

Feld | Beschreibung
------------ | ----------------
Medium | Welches Medium der [Verbrauchsquellen](../Verbrauchsquellen/README.md) die Instanz auswertet: Strom, Wasser oder Gas. Es muss dort befüllt sein. Standard: Strom.
Tage, die das Normalverhalten festlegen | So viele Tage vor den geprüften Tagen bilden das Normalverhalten, 14 bis 90. Standard: 28.
Letzte Tage, die geprüft werden | So viele ganze Tage bis gestern werden verglichen, 1 bis 7. Der heutige Tag zählt nicht, er ist noch nicht zu Ende. Standard: 3.
Abweichung, die als auffällig gilt | Mindestabweichung in Prozent. Standard: 40 %.
Kleinste Änderung je Tag, die zählt | Mindestunterschied in kWh (oder m³) pro Tag. Verhindert Meldungen bei Geräten mit sehr kleinem Verbrauch. Standard: 0,05.
Auch Geräte melden, die deutlich weniger als üblich verbrauchen | Meldet auch ein Gerät, das kaum noch etwas verbraucht, zum Beispiel weil es ausgefallen ist. Standard: an.
Archivdiagramm bei Klick öffnen | Ein Klick auf ein Gerät öffnet den Archivdialog seiner Variable. Auf Touch-Geräten zeigt das erste Tippen den Hinweis, das zweite öffnet. Standard: an.
Aktualisierungsintervall | Alle wie viele Minuten geprüft wird. Standard: 60 Minuten.

## 2. So wird verglichen

* Je Gerät entsteht aus dem Archiv der Verbrauch je Tag. Tage nach dem ersten Archiveintrag ohne Datensatz zählen als 0, denn ein Zähler, der sich nicht ändert, wird nicht archiviert.
* Zum Vergleich dient der **Median** der Tage des Normalverhaltens, nicht der Mittelwert, damit einzelne Ausreißer ihn nicht verschieben. Je geprüftem Tag gilt der Median der Tage mit **demselben Wochentag**, wenn es davon mindestens drei gibt, sonst der Median aller Tage. So löst ein Waschtag keine Meldung aus.
* Ein Gerät ist **auffällig**, wenn alle drei Bedingungen gelten: die Abweichung erreicht die eingestellte Prozentzahl, der Unterschied erreicht die kleinste Änderung je Tag, und die Abweichung ist deutlich größer als die übliche Schwankung des Geräts (mindestens das Dreifache der robusten Streuung, MAD, mit einer Untergrenze von 10 % des Normalwerts).
* Hat ein Gerät weniger als sieben Tage Verlauf im Zeitraum des Normalverhaltens, steht dort "Zu wenig Daten".
* Voraussetzung ist ein Archiv für die Variable und bei Zählern die Zähler-Aggregation oder eine Leistungsvariable. Variablen ohne Archiv oder mit anderer Einheit werden übersprungen.

## 3. Kachel

Oben steht der Zustand: grün, wenn alles normal ist, sonst rot mit der Zahl der auffälligen Geräte. Darunter stehen die Geräte, auffällige zuerst. Jedes zeigt den Verbrauch der geprüften Tage je Tag und den Normalwert, bei Auffälligkeit die Abweichung und die letzten 14 Tage als Säulen (die geprüften Tage farbig, rot bei zu viel, blau bei zu wenig). Hinweise zeigen Werte je Tag.

Die Kachel zeigt die maximierte Ansicht erst ab Symcon 9.1, unter 9.0 bleibt sie leer.

## 4. Variablen

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Auffälliges Gerät | `Alarm` | Boolean | An, wenn mindestens ein Gerät auffällig ist.
Anzahl auffälliger Geräte | `Count` | Ganzzahl | Zahl der Auffälligkeiten.
Meldung | `Message` | Text | Eine Zeile je Gerät, zum Beispiel "NAS: 0.90 kWh am Tag statt 0.62".

## 5. Funktionen

`GWA_Check(integer $InstanzID): int`
Prüft alle Geräte sofort, setzt Variablen und Kachel und gibt die Zahl der Auffälligkeiten zurück.

## 6. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 7. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
