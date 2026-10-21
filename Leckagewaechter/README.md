# Leckagewächter
Erkennt Dauerfluss an einem Wasser- oder Gaszähler. Fließt rund um die Uhr etwas, obwohl niemand etwas braucht, ist das ein Leck oder ein tropfender Spülkasten. Die Instanz zählt, seit wie vielen Stunden es ununterbrochen fließt, schätzt den Verlust pro Jahr und setzt eine Alarmvariable.

![leckagewaechter](../docs/screenshots/leckagewaechter.png)


## Inhaltsverzeichnis
- [Leckagewächter](#leckagewächter)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Kachel](#3-kachel)
  - [4. Funktionen](#4-funktionen)
  - [5. Spenden](#5-spenden)
  - [6. Lizenz](#6-lizenz)

## 1. Konfiguration

Die Instanz ist ein Kind der Instanz [Verbrauchsquellen](../Verbrauchsquellen/README.md) und wird beim Anlegen mit ihr verbunden. Im Feld Medium wählt man Wasser oder Gas. Dafür trägt man in den Verbrauchsquellen im Medium Wasser (Einheit m³ oder l) den Wasserzähler als Hauptzähler ein, mit dem Preis je m³. Von dort kommen der Zähler und der Preis.

Feld | Beschreibung
------------ | ----------------
Medium | Welches Medium der [Verbrauchsquellen](../Verbrauchsquellen/README.md) die Instanz auswertet: Strom, Wasser oder Gas. Es muss dort befüllt sein. Standard: Wasser.
Werte der Verbrauchsquellen überschreiben | Mit dem Haken erscheinen die Felder Preis je Einheit, Preisvariable und Quelle. Sie gelten nur für diese Instanz und ersetzen die Werte aus den [Verbrauchsquellen](../Verbrauchsquellen/README.md), die anderen Werte kommen weiter von dort. So lässt sich dieselbe Auswertung mit anderen Werten nutzen, ohne einen zweiten Splitter anzulegen. Standard: aus.
Quelle (nur mit dem Haken zum Überschreiben) | Archivierte Zählervariable in m³ oder l. Ohne Auswahl gilt der Hauptzähler. Zähler brauchen die Aggregation "Zähler". Energiezähler (kWh) sind nicht zulässig.
Schwelle für den Durchfluss in Litern pro Stunde | Ab dieser Menge je Stunde gilt die Stunde als "es fließt etwas". Standard: 1. Hat der Zähler eine grobe Auflösung (zum Beispiel ein Impuls je Liter), sollte die Schwelle mindestens so groß sein.
Alarm nach Stunden mit Dauerfluss | Ab dieser Zahl ununterbrochener Stunden gilt ein Leck als vermutet. Standard: 6.
Archivdiagramm bei Klick öffnen | Ein Klick auf das Diagramm der Kachel öffnet den Archivdialog der Quellvariable, siehe Abschnitt Kachel. Standard: an. Ausgeschaltet bleiben die Hinweise beim Überfahren, der Klick tut nichts.
Aktualisierungsintervall | Abstand der Neuberechnung in Minuten. Standard: 15.

So wird gerechnet: Das Modul wertet die Stundenwerte der letzten 48 ganzen Stunden aus. Die laufende Stunde zählt nicht. Von der letzten ganzen Stunde rückwärts zählt es, wie viele Stunden hintereinander mindestens die Schwelle floss. Kommt eine Stunde mit weniger, endet die Zählung. Der Verlust pro Jahr ist die kleinste Stundenmenge dieses Dauerflusses, hochgerechnet auf 8760 Stunden, denn mehr als diese Menge fließt mindestens immer. Die Kosten rechnen mit dem Preis der Verbrauchsquellen. Mehr als 48 Stunden Dauerfluss zeigt die Instanz als 48.

## 2. Variablen

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Leck vermutet | `Leak` | Ja/Nein | true, wenn der Dauerfluss mindestens die eingestellte Zahl Stunden dauert. Für Ereignisse und Benachrichtigungen.
Dauerfluss | `ContinuousHours` | Ganzzahl (h) | Ununterbrochene Stunden mit Fluss.
Mindestfluss (24 Stunden) | `MinFlow` | Kommazahl (l/h) | Kleinste Stundenmenge der letzten 24 Stunden. Ein Wert über der Schwelle heißt: Es fließt ständig etwas.
Geschätzter Verlust pro Jahr | `LossYear` | Kommazahl (m³) | Hochrechnung aus dem Dauerfluss.
Geschätzte Verlustkosten pro Jahr | `LossCostYear` | Kommazahl (€) | Hochrechnung mit dem Preis der Verbrauchsquellen.
Ergebnis | `Message` | Text | Kurzer Satz, zum Beispiel "Dauerfluss seit 12 Stunden, mindestens 3.0 l/h".

Alle Variablen sind schreibgeschützt und werden vom Modul berechnet. Zusätzlich gibt es die versteckte Textvariable `Data` mit den Daten der Kachel.

## 3. Kachel
Die Instanz erscheint in der Kachel-Visualisierung. Beim Überfahren oder Antippen der Balken erscheint ein Hinweis mit den Werten (auf Touch-Geräten bleibt er bis zum nächsten Tippen).

* Oben steht der Zustand: grüner Haken bei "Kein Leck vermutet", roter Tropfen bei "Leck vermutet", darunter der Satz aus der Variable `Message`.
* Das Diagramm zeigt den Durchfluss je Stunde der letzten 48 Stunden. Die Stunden des Dauerflusses sind hervorgehoben (rot bei Leck), die gestrichelte Linie ist die Schwelle.
* Ein Klick auf einen Balken öffnet den Standard-Dialog von Symcon für den Zähler mit dem Archivdiagramm. Dort lässt sich der ganze Verlauf durchgehen (blättern, Kalender, Tag bis Jahr, Zahnrad mit dynamischer Skalierung und CSV-Export). Auf Touch-Geräten zeigt das erste Tippen den Hinweis, das zweite öffnet den Dialog. In den Einstellungen abschaltbar.
* Die maximierte Ansicht ergänzt Karten für Dauerfluss, Mindestfluss, Verlust und Verlustkosten pro Jahr. Reicht der Platz nicht, scrollt die Kachel.

Die maximierte Ansicht der Kachel zeigt erst ab Symcon 9.1 Inhalt, unter 9.0 bleibt sie leer.

## 4. Funktionen

`void LKW_Calculate(integer $InstanzID);`
Prüft die letzten Stunden neu und schreibt die Variablen. Läuft automatisch im eingestellten Intervall.

## 5. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 6. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
