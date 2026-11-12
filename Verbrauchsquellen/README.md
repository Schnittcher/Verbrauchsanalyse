# Verbrauchsquellen
Gemeinsame Quellenliste für alle Auswertungen: Für die Medien Strom, Wasser und Gas werden je Einheit, Preis, Verbraucher, Hauptzähler und Gruppen gepflegt, dazu die Heizung, Bewohner und Wohnfläche. Die Auswertungen werden mit dieser Instanz verbunden, wählen in ihrem Formular das Medium und lesen die Konfiguration von hier. So reicht ein Splitter für ein ganzes Haus.

## Inhaltsverzeichnis
- [Verbrauchsquellen](#verbrauchsquellen)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Zusammenspiel mit den Auswertungen](#2-zusammenspiel-mit-den-auswertungen)
  - [3. Spenden](#3-spenden)
  - [4. Lizenz](#4-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Währung | Währung für alle Preise und Kosten der Auswertungen: Euro, Schweizer Franken, Pfund, US-Dollar, Schwedische, Norwegische oder Dänische Krone, Zloty, Tschechische Krone oder "Eigene". Das Symbol erscheint in den Variablen, Kacheln, im Bericht, in CSV und PDF. Die Währung ist reine Beschriftung, die Module rechnen nicht um. Standard: Euro.
Eigenes Währungssymbol | Nur bei "Eigene": das Symbol oder Kürzel, das hinter den Beträgen steht. Darf nicht leer sein.
Währung aus Preisvariable übernehmen, wenn eindeutig | Mit dem Haken liest die Instanz die Währung aus der Einheit der Preisvariablen (zum Beispiel "CHF/kWh" oder "ct/kWh"). Ist sie eindeutig, gilt sie statt der Auswahl. Bei "$" oder "kr" ist sie es nicht, weil mehrere Länder dasselbe Zeichen nutzen, dann gilt die Auswahl. Unter dem Feld steht, was erkannt wurde und welche Währung gilt. Standard: aus.
Bewohner (0 = nicht genutzt) | Anzahl der Personen im Haushalt. Top Verbraucher und Verbrauchsbericht zeigen damit den Verbrauch je Bewohner, die CO2Bilanz den Ausstoß je Bewohner, der Stromspiegel nutzt sie für die Klasse.
Wohnfläche (0 = nicht genutzt) | Fläche in m². Top Verbraucher und Verbrauchsbericht zeigen den Verbrauch je m², die Heizgradtage und der Heizspiegel die Heizenergie je m².
Das Warmwasser wird mit Strom erwärmt (Durchlauferhitzer oder Boiler) | Für Stromspiegel (Klasse mit Warmwasser) und Heizspiegel (Zuschlag je m²). Standard: aus.
Heizung (optional) | Eigener Block für die Heizung, unabhängig von den Verbrauchern. Siehe unten.
Strom, Wasser, Gas | Je Medium eine aufklappbare Box mit Einheit, Preis, der Liste der Verbraucher, der Schaltfläche "Variablen neu suchen" und der Liste der Gruppen. Ein Medium ohne Einträge bleibt ungenutzt. Gasverbraucher im Medium Gas (zum Beispiel Herd) zählen nicht für die Heizung, die Heizung steht im Block "Heizung". Die Felder der Box stehen im nächsten Abschnitt.

Felder je Medium (Strom, Wasser, Gas):

Feld | Beschreibung
------------ | ----------------
Einheit | Einheit der Variablen des Mediums. Strom: kWh, Wh, kW oder W. Wasser: m³ oder l. Gas: m³, kWh oder Wh. Wh und kWh werden intern zu kWh vereinheitlicht, l zu m³. Leistungswerte (W, kW) werden über den Zeitraum zu kWh integriert. Standard: Strom kWh, Wasser und Gas m³.
Fester Preis je kWh oder Einheit | Grundlage der Kosten, solange keine Preisvariable gesetzt ist, in der oben gewählten Währung. Standard: Strom 0,30, Wasser 4,00, Gas 1,20.
Preisvariable (optional, ersetzt den festen Preis) | Variable mit dem aktuellen Preis, zum Beispiel eines dynamischen Stromtarifs. Top Verbraucher rechnet damit die Kosten je Stunde, Grundlast nimmt den aktuellen Wert.
Preisvariable ist angegeben in | Automatisch (aus der Einheit), Hauptwährung oder Cent. Manche Preisvariablen liefern den Preis in der kleinen Einheit, zum Beispiel 28,5 mit der Einheit "ct/kWh" statt 0,285 "€/kWh". Die Module rechnen intern immer in der Hauptwährung, bei Cent wird der Wert deshalb durch 100 geteilt. "Cent" meint 1/100 der Währung, also auch Pence, Rappen oder Øre. Bei "Automatisch" erkennt die Instanz Cent an der Einheit (ct, cent, p, Rp, øre, ¢), sonst gilt der Preis unverändert. Unter dem Feld steht, was erkannt wurde. Ist die Einheit der Variable nicht eindeutig, den Wert hier von Hand wählen. Die Einstellung gilt je Preisvariable, Strom kann also in ct/kWh und Gas in €/kWh vorliegen. Standard: Automatisch.
Emissionsfaktor in g CO2 je kWh oder Einheit | Für die CO2-Rechnung der Auswertungen (CO2Bilanz, Verbrauchsbericht). 0 = kein CO2. Standard: Strom 380 (deutscher Mix, ungefähr), Wasser 0, Gas 2000 je m³.
Variable mit dem aktuellen Faktor in g/kWh | Optional, ersetzt den festen Wert in CO2Bilanz und Verbrauchsbericht. Hat die Variable ein Archiv, zählt jede Stunde mit ihrem Faktor, sonst gilt der feste Wert.

Die Schaltfläche "Variablen neu suchen" durchsucht alle Variablen nach der gewählten Einheit (Suffix der Darstellung oder des Profils) und ergänzt neue Treffer in der Liste. Bereits eingetragene Zeilen bleiben erhalten. Die Suche läuft nur auf Knopfdruck. Die Änderungen gelten erst nach "Übernehmen".

Der Block **Heizung** (aufklappbar) beschreibt die Heizung des Gebäudes und gilt für Auswertungen wie Heizgradtage und Heizspiegel. Er steht neben der Geräteliste, weil eine Heizung oft in einer anderen Einheit misst (kg Pellets, m³ Gas) als die Geräte in kWh:

Feld | Beschreibung
------------ | ----------------
Verbrauch der Heizung | Archivierte Zählervariable (oder Leistung) der Heizung.
Wärmemengenzähler der Heizung | Optional. Zähler der abgegebenen Wärme in kWh, Wh oder MWh. Die Arbeitszahl nimmt ihn als Ertrag, wenn dort kein eigener Ertrag gewählt ist. Er ergänzt die Verbrauchsvariable, ersetzt sie aber nicht.
Energieträger der Heizung | Keine Angabe, Erdgas, Heizöl, Fernwärme, Holzpellets, Strom (Wärmepumpe, Direktheizung) oder Sonstiges. Der Heizspiegel braucht ihn.
Energie je Einheit der Heizungsvariable | Heizwert in kWh je Einheit, zum Beispiel 4,8 für Pellets in kg oder rund 10 für Gas in m³. 0 bedeutet, die Variable misst schon Energie.
Preis je Einheit der Heizungsvariable | Preis je Einheit für die Heizkosten, in der oben gewählten Währung. 0 = nicht genutzt.
Emissionsfaktor der Heizung | g CO2 je kWh der Heizung. 0 = Wert des Energieträgers (Erdgas 201, Heizöl 266, Fernwärme 180). Für Holzpellets und Sonstiges ist ein Wert nötig, für Strom (Wärmepumpe) gibt es keinen, das CO2 steckt im Strom.

Die Heizgradtage und der Heizspiegel lesen die Heizung von hier, sie haben keine eigenen Felder dafür.

Spalten der Verbraucherliste:

Spalte | Beschreibung
------------ | ----------------
Variable | Die ausgewertete Variable.
Pfad, Archiv, Aktueller Wert | Anzeige. Variablen ohne Archiv sind ausgegraut und werden nicht ausgewertet.
Rolle | Verbraucher, Hauptzähler oder Ignorieren. Es darf höchstens einen Hauptzähler geben. Die Suche schlägt die Rolle vor, wenn der Name "Gesamt", "Zähler" oder "Netz" enthält.
Gruppe | Gruppe des Verbrauchers, leer = oberste Ebene. Jede Variable gehört zu genau einer Gruppe.
Anzeigename | Optional, ersetzt den Variablennamen.

Spalten der Gruppenliste:

Spalte | Beschreibung
------------ | ----------------
Name | Eindeutiger Name der Gruppe.
Icon | Name eines eingebauten Symbols: server, bolt, home, flame, droplet, fridge, plug, car, bulb, wifi. Andere Namen zeigen einen Punkt.
Farbe | Farbe der Gruppe in der Kachel.
Obergruppe | Optional, höchstens 3 Ebenen.
Gruppenzähler | Optional. Mit Zähler gilt dessen Wert, die Mitglieder schlüsseln auf und die Differenz erscheint als "Rest der Gruppe". Ohne Zähler ist der Wert die Summe der Mitglieder.

Die Auswahl der Gruppen in den Listen kennt neue Gruppen erst, nachdem sie übernommen wurden und das Formular neu geöffnet wurde.

Die Instanz meldet einen Fehler bei einer Einheit, die nicht zum Medium passt, bei doppelten Variablen, unbekannten oder zyklischen Gruppen, mehreren Hauptzählern, einer fehlenden Preisvariable, wenn ein Gruppenzähler zugleich Verbraucher ist, bei "Eigene" ohne Währungssymbol oder wenn die Preisvariablen eindeutig verschiedene Währungen haben (zum Beispiel Strom in CHF und Gas in €). Eine Instanz kann nur eine Währung führen, Beträge werden nicht umgerechnet und ließen sich sonst nicht zusammenrechnen. Das Formular zeigt unter der Währung, welche Währungen erkannt wurden. Die Einstellung "Währung aus Preisvariable übernehmen" ändert nichts daran: Auch mit der Auswahl "Euro" ist es ein Fehler, wenn zwei Preisvariablen verschiedene Währungen zeigen. Zeigt eine Preisvariable dagegen eine andere Währung als die Auswahl, ist das nur ein Hinweis, die Auswahl gilt.

## 2. Zusammenspiel mit den Auswertungen
Die Instanz hat keine Variablen. Alle Auswertungen (*Top Verbraucher*, *Grundlast*, *Leckagewächter*, *Heizgradtage*, *Sparziel*, *Arbeitszahl*, *Austauschrechner*, *Verbrauchsbericht*, *Verbrauchsfluss*, *Lastgang*, *Lastspitzen*, *CO2Bilanz*, *Geraetewaechter*, *Stromspiegel* und *Heizspiegel*) sind ihre Kinder und werden beim Anlegen mit ihr verbunden. Ändert sich die Konfiguration, bauen sich die Kinder von selbst neu auf. Ist die Instanz fehlerhaft oder nicht aktiv, zeigen die Kinder den Status "Es ist keine aktive Instanz Verbrauchsquellen verbunden".

Die Instanz kennt drei Medien (Strom, Wasser, Gas), weil pro Auswertung nur eine Einheitenfamilie erlaubt ist. Jede Auswertung wählt in ihrem Formular ein Medium und bekommt dessen Einheit, Preis, Verbraucher und Gruppen. Zwei Auswertungen für Strom und Wasser hängen so am selben Splitter. Heizung, Bewohner und Wohnfläche gelten für alle Medien. Zusätzlich lässt sich in der Arbeitszahl, im Sparziel und im Verbrauchsbericht das Medium "Heizung" wählen: Dann ist die Heizungsvariable der Hauptzähler, mit ihrer Einheit, dem Preis je Einheit und dem Emissionsfaktor des Heizungsblocks. Mehrere Splitter sind weiter möglich. Jede Auswertung kann einzelne Werte (Preis, Emissionsfaktor, Bewohner, Fläche, Warmwasser, Heizwert) mit dem Haken "Werte der Verbrauchsquellen überschreiben" für sich ändern, ohne einen zweiten Splitter. Die Währung kommt immer von der Instanz Verbrauchsquellen, eine überschriebene Preisvariable gilt in dieser Währung. Wer in zwei Währungen rechnen will, legt eine zweite Instanz Verbrauchsquellen an.

## 3. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 4. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
