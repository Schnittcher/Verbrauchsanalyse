# Changelog

## 26.10.2026 - Version 1.0.1

### Neu
- Heizspiegel: Der Zuschlag für Warmwasser mit Strom steht jetzt als Liste mit einer Zeile je Jahr im Formular und gilt für die Ausgabe des Heizspiegels, mit der gerechnet wird. Achtung: Hattest du die bisherigen Zuschlagsfelder geändert, übernimmt das Modul deine Werte einmalig als Zeile 2025.
- Heizspiegel: Das Formular fasst die Zuschläge in einem eigenen aufklappbaren Bereich zusammen, mit Erklärung.
- Heizgradtage: Das Modul lässt sich im Dialog "Instanz hinzufügen" auch mit "Heizgradtage" suchen.

### Fixes
- Heizspiegel: Eigene Zeilen in den Referenztabellen, die auf der obersten Ebene des Baums angelegt wurden, werden erkannt. Vorher blieb die Tabelle leer und die Instanz meldete eine ungültige Konfiguration.
- Heizspiegel und Stromspiegel: "Werte des Heizspiegels 2025 hinzufügen" bzw. die Vorgabe des Stromspiegels ersetzt eine leere Jahresgruppe, statt sie stehen zu lassen.
- Heizspiegel: Sind die Referenzwerte ungültig, nennt die Kachel den Grund (Gebäudefläche, Energieträger oder aufsteigende kWh-Grenzen).
- Verbrauchsquellen: "Variablen suchen" brach mit einem Fehler ab, wenn die Liste nur eine Zeile enthielt.

## 21.10.2026 - Version 1.0

### Neu
- Erste Version der Bibliothek Verbrauchsanalyse.
- Verbrauchsquellen: Ein Splitter mit allen Quellen für das ganze Haus. Je Medium (Strom, Wasser, Gas) Einheit, Preis (fest oder variabel), Emissionsfaktor (fest oder stündlich), Verbraucher, Hauptzähler und Gruppen mit Untergruppen und Gruppenzählern. Dazu Heizung (Variable, Energieträger, Heizwert, Preis, Wärmemengenzähler), Bewohner, Wohnfläche und Warmwasser mit Strom.
- Alle Auswertungen wählen ihr Medium im Formular und können mit dem Haken "Werte der Verbrauchsquellen überschreiben" einzelne Werte für sich ändern.
- Top Verbraucher: Rangliste der größten Verbraucher mit Gruppen, Kosten, Vorperiode und Verbrauch je Bewohner und je m² für Heute, Woche, Monat und Jahr, mit Kachel.
- Verbrauchsfluss: Sankey-Diagramm für Heute, Woche, Monat, Jahr, einen eigenen Zeitraum und live.
- Verbrauchsbericht: Liste von Berichten je Medium mit eigenem Zeitraum, Zeitplan, Inhalt (Kosten, Vergleiche, Hinweis, Kennzahlen, CO2, Geräteliste) und Ausgabe als PDF mit Diagrammen und CSV in Medienobjekten, auf Wunsch per Mail an einen eigenen Empfänger. In der Kachel wählt man einen Bericht und erstellt ihn jederzeit, auch für einen eigenen Zeitraum.
- Lastgang: Typisches Tagesprofil als Heatmap aus Wochentag und Stunde, für Gesamtverbrauch, Gruppen und Geräte.
- Lastspitzen: Höchste Leistung je Stunde und Tag, größte Spitzen und optionaler Grenzwert mit Alarmvariable.
- Grundlast: Grundlast aus den Nachtstunden mit Jahresverbrauch, Jahreskosten, Anteil und Standby-Rangliste, auf Wunsch mit Verbrauch bei Abwesenheit.
- Gerätewächter: Vergleicht jedes Gerät und jede Gruppe mit dem eigenen Normalverhalten und meldet zu hohen oder zu niedrigen Verbrauch mit Alarmvariable und Meldungstext.
- Leckagewächter: Erkennt Dauerfluss an Wasser- und Gaszählern, schätzt Verlust und Kosten pro Jahr und setzt eine Alarmvariable.
- Sparziel: Sparziel in Prozent gegenüber dem Vorjahr für Jahr oder Monat, optional witterungsbereinigt, mit Fortschritt, Hochrechnung und Einsparung in Geld.
- Austauschrechner: Einsparung, Amortisation und Gewinn über die Lebensdauer beim Austausch eines Geräts, mit Eingaben direkt in der Kachel.
- CO2Bilanz: CO2-Ausstoß für Haushalt oder Heizung mit festem oder stündlichem Emissionsfaktor, Vorperiode, Anteilen je Gruppe und Gerät und Baum-Entsprechung.
- Heizgradtage: Verbrauch der Heizung je Heizgradtag nach VDI 3807, Monat und Jahr gegen das Vorjahr, auch für Pellets in kg über den Heizwert.
- Heizspiegel: Ordnet Heizenergie und Heizkosten je m² in die Kategorien des Heizspiegels für Deutschland 2025 ein, mit Einsparpotenzial und Referenztabellen je Jahr in der Instanz.
- Stromspiegel: Ordnet den Jahresstromverbrauch in die Klassen A bis G des Stromspiegels für Deutschland 2025 ein, mit Einsparpotenzial in kWh und Euro und Referenztabellen je Jahr in der Instanz.
- Arbeitszahl: Ertrag geteilt durch Einsatz, zum Beispiel Jahresarbeitszahl einer Wärmepumpe oder Wirkungsgrad eines Pelletkessels.
- Die Kacheln passen sich der Größe an, zeigen Hinweise zu den Diagrammen auch auf Touch-Geräten und öffnen bei vielen Diagrammen den Archivdialog der Quellvariable.
