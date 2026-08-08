# Changelog

## 0.76.0-beta.1 (2026-08-08)

- **Neuer Hersteller: APsystems EZ1** (Mikrowechselrichter der EZ1-/EZ1-M-Serie), rein lesend.
  Der erste Treiber im Modul, der **kein Modbus** spricht: Der EZ1 hat keine
  Modbus-Schnittstelle, sondern eine lokale HTTP-/JSON-API auf **Port 8050**. Der Treiber nutzt
  den übergebenen Modbus-Client ausschließlich als Träger von Host und Port und macht keinen
  einzigen Modbus-Zugriff.
  - **Werte:** Gesamtleistung und Leistung je Kanal, Ertrag heute/gesamt (gesamt und je Kanal),
    Alarme (Netzausfall, DC-Kurzschluss je Kanal, Ausgangsfehler) als Sammel-Boolean, Klartext
    und optional einzeln, Geräteinformation (Seriennummer, Firmware, WLAN, Nenn-/Mindestleistung)
    sowie das eingestellte Einspeiselimit.
  - **Die beiden Kanäle liegen bewusst auf `mppt1_power`/`mppt2_power`**, damit der
    Strangvergleich des `InverterHubMonitor` (`mppt_string_compare`) einen verschatteten oder
    defekten Kanal ohne Sonderbehandlung findet.
  - **Kein Netzzähler:** Der EZ1 misst nur seine eigene Erzeugung. Es gibt deshalb bewusst kein
    `meter_total` und keine Hauslast — diese Größen aus der Erzeugung zu schätzen wäre erfunden.
    Netz-/Hauslast-/Batteriekreise der Kachel bleiben bei einer reinen EZ1-Anlage leer.
  - **Keine Steuerung:** `/setMaxPower` und `/setOnOff` sind nicht umgesetzt, es gibt keine
    `GroupControl` — `IHUB_GetFunctions` meldet damit korrekt `controllable = false`.
  - **Einrichtung:** Der lokale Modus muss erst am Gerät freigeschaltet werden (App
    „AP EasyPower" → per Bluetooth verbinden, nicht über die Cloud → Einstellungen →
    „Lokaler Modus" → aktivieren, „Continuous"). Firmware 1.1.1 kennt den Menüpunkt noch nicht.
  - **Intervall:** Das Gerät antwortet langsam (an Firmware 1.10.3 gemessen 2,6–9,3 s je Abruf
    bei 1,0–3,5 s Verbindungsaufbau). `readFast()` macht deshalb genau **einen** Abruf;
    Schnell-Intervall bitte auf mindestens 20 s stellen.
- **Neues Prüfskript `.tools/test-apsystems.php`** — fährt den Treiber gegen ein echtes Gerät,
  schneidet die Klasse dafür aus `module.php` heraus (testet also den echten Code, keine Kopie)
  und prüft: kein Modbus-Zugriff, jeder gesetzte Ident deklariert und umgekehrt, Summen
  schlüssig, und bei nicht erreichbarem Gerät sauberes `false`/`connected = false` ohne
  zurückbleibende Messwerte. `php -l` und die beiden anderen Prüfer sehen von einem HTTP-Pfad
  nichts — dieser Test schließt die Lücke.

## 0.75.0-beta.1 (2026-07-26)

- **Sungrow SH-Hybrid: Registerpfad grundlegend korrigiert und erweitert.** An einem SH10RT
  (WiNet-S) fielen im Hybrid-Pfad viele Werte falsch aus (z. B. „PV-Leistung" = in Wahrheit die
  Phasenspannung, Energiezähler als −65536-Artefakte). Ursachen und Behebung:
  - **Automatische Offset-Erkennung** (`detectOffset`): Sungrow-Firmware/-Dongles adressieren
    uneinheitlich (PDU = Doku-Nummer −1 oder direkt). Der Offset wird nun zur Laufzeit anhand
    der Phasenspannungen (Doku 5019–5021) bestimmt — behebt die „off-by-one"-Fehlwerte, ohne
    Geräte mit direkter Adressierung zu brechen.
  - **32-Bit-Werte jetzt korrekt Low-Word-first** (`u32le`/`s32le`) — der eingebaute Big-Endian-
    `u32` lieferte hier vertauschte/negative Werte.
  - **Register auf die maßgebliche SH-Karte umgestellt und live verifiziert:** Phasenspannungen,
    Gesamt-DC-Leistung, Gesamt-Wirkleistung (13034), Netzleistung (13010), Phasenströme
    (13031–13033), Batterie (13020–13025), Energie (PV/Netzbezug/Einspeisung/Eigenverbrauch).
- **Neue Werte:** Innentemperatur (5008), Einspeisung heute/gesamt, Netzbezug heute/gesamt,
  Eigenverbrauch heute, Batterie-Lade-/Entladeenergie, **Leistung je MPPT-String** (U×I),
  Leistungsfluss-Klartext (aus 13001-Bits) und eine **Störungs-Boolean**. Betriebsstatus-Enum
  auf die echten System-State-Codes (13000) umgestellt.
- **Entfernt** (auf SH unverifizierte/fehlerhafte Register): Smart-Meter-je-Phase (5603) und
  Backup-Gruppe (5726). Netzbezug/Einspeisung stehen jetzt korrekt in der Energie-Gruppe.
- **InverterHubDiscovery:** Ein-Klick-Schaltfläche „Ganzes Subnetz durchsuchen" (erkennt das
  lokale /24 automatisch); die manuelle Start-/End-IP-Eingabe liegt nun im eingeklappten
  Panel „Erweitert: manueller IP-Bereich".

## 0.74.0-beta.1 (2026-07-25)

- **Neuer Verbund-Vertrag `IHUBMON_GetDiagnostics($id)`**, mit NRGDashboard abgestimmt: Erster
  Schritt der Zielarchitektur, bei der die Diagnose-LOGIK bei uns bleibt (wir kennen die
  Wechselrichter-Details), die DARSTELLUNG aber künftig zentral über NRGDashboard läuft statt
  über eine eigene Kachel. `InverterHubMonitor` bleibt vorerst vollständig nutzbar — dies ist
  eine zusätzliche Schnittstelle, kein Ersatz.
  Der Vertrag liefert drei Diagnose-Einträge (sofern die Voraussetzungen erfüllt sind):
  - **Ertrag vs. Prognose** — gemessene Leistung (als Variablen-Referenz) gegen die aus
    PV-Prognose-Generatorparametern × Einstrahlung berechnete Erwartung (als Wert), mit
    Bewertung (normal/auffällig/kritisch) ab einer nennenswerten Erwartung.
  - **MPPT-Strangvergleich** — erkennt einen deutlich schwächeren Einzelstrang (Verschattung/
    Defekt-Indikator).
  - **Isolationswiderstand (Riso)** — neue Einstellung „Warnschwelle" (kΩ, Standard 0 = aus).
    **Bewusst kein Herstellervorgabewert ohne Bestätigung** — die Bewertung greift erst, wenn
    der Nutzer selbst eine Schwelle einträgt (offener Tester-Wunsch von kea/Dietmar, damit
    erstmals nutzbar).
  Konvention (mit NRGDashboard vereinbart, gilt als Muster für künftige Diagnose-Verträge
  anderer Hub-Module): gemessene Rohgrößen als Variablen-**Referenz** (Dashboard zeichnet
  Zeitreihen selbst aus dem IPS-Archiv), berechnete Vergleichswerte als **Wert** (unser
  Domänenwissen), Bewertung als **Metadaten** (`level`/`threshold`/`reason`) — die Einstufung
  trifft immer der Anbieter, nie der Konsument (analog zum bewusst fehlenden `level` bei
  `TIBBERGR_GetPriceCurve`). `contractVersion` von Anfang an ('1.0').

## 0.73.2-beta.1 (2026-07-24)

- **Alle 15 Treiberklassen + die gemeinsame Schnittstelle ebenfalls mit Modul-Präfix.** Der
  vorige Fix (`ModbusTcpClient`) deckte nur die Hilfsklasse ab — dieselbe Kollisionsgefahr
  bestand für `GoodweDriver`, `SmaDriver`, `VictronDriver` usw.: generische Namen, die ein
  anderes Modul im Verbund ebenso vergeben könnte (MeterHub plant z. B. einen eigenen
  SMA-Zähler-Treiber). Alle 15 Treiberklassen und `InverterDriverInterface` heißen jetzt
  `IHUB_<Name>` (z. B. `IHUB_SmaDriver`, `IHUB_InverterDriverInterface`) — echte Umbenennung,
  kein `class_exists()`-Guard, damit eine Kollision beim Laden sofort auffällt statt zufällig
  eine der beiden Implementierungen stillschweigend zu gewinnen. Mechanisch wortgrenzenbasiert
  durchgeführt und gegen Teilstring-Kollisionen zwischen den 16 Namen geprüft, danach mit
  `.tools/check-class-scope.php` verifiziert (246 Aufrufe, alle korrekt). Abgestimmt mit
  MeterHub (`MHUB_`-Präfix) und ChargerHub, gleiches Namensraum-Muster in allen drei Modulen.

## 0.73.1-beta.1 (2026-07-24)

- **Kritisch: interne Hilfsklasse `ModbusTcpClient` umbenannt in `IHUB_ModbusTcpClient`.**
  MeterHub und ChargerHub deklarieren beide ebenfalls eine globale Klasse namens
  `ModbusTcpClient` — sobald ein Konsument (z. B. das EMS) mehr als eines dieser Module im
  selben PHP-Prozess lädt, führte das zu „Cannot redeclare class ModbusTcpClient" (Fatal
  Error), real aufgetreten beim ersten EMS-Discovery-Test. Rein interne Umbenennung (private
  Hilfsklasse, kein öffentlicher Vertrag), keine Verhaltensänderung. Verbund-Konvention
  daraus: globale Klassennamen brauchen künftig einen Modul-Präfix, wie Idents und Profile.

## 0.73.0-beta.1 (2026-07-24)

- **Neuer Verbund-Vertrag `IHUB_GetFunctions($id)`**, analog zu `MHUB_GetFunctions`: liefert
  Identität, Steuerfähigkeit und die wichtigsten Variablen-IDs (PV-/AC-/Batterie-/Netzleistung,
  SOC, Verbindungsstatus) einer InverterHub-Instanz, mit `contractVersion` von Anfang an. Feld
  `virtual` ist bei jeder heutigen (physischen) Instanz `false` — reserviert für den geplanten
  InverterHubVirtual (Anlagen-Aggregat), damit ein Konsument ihn nie versehentlich als weiteres
  Einzelgerät in die Steuerung nimmt.
- **Neue Einstellung „Steuerhoheit dieser Instanz"** (nur sichtbar bei Treibern mit
  Steuerregistern: GoodWe, Deye, Sungrow): `ems` (Normalfall) / `external` (ein anderer Akteur
  schreibt, z. B. Sunny Home Manager) / `none`. Steht die Instanz auf `external`/`none`,
  **verweigert das Modul jeden Schreibzugriff** — auch wenn ihn ein Aufrufer versucht
  (Verteidigung in der Tiefe: Das EMS soll `controlAuthority` selbst prüfen, aber ein Fehler dort
  darf hier nicht zu einer ungewollten Schreibaktion führen). `IHUB_GetFunctions` meldet
  `controllable` (kann dieser Treiber überhaupt steuern) und `controlAuthority` (wer gerade
  darf) getrennt.

## 0.72.3-beta.1 (2026-07-24)

- **Vollständiges Layout-Audit aller fünf Module gegen die einheitliche Formular-Konvention**
  (eigenständig durchgeführt, Prinzipien aus der eigenen Commit-Historie und CLAUDE.md abgeleitet).
  Ergebnis: „Was ist neu" (aufgeklappt, versionsscharf) → „Dokumentation & Hilfe" (eingeklappt,
  mit Version) → Fachpanels → Symcon-Forum-Hinweis (dismissible, einmalig) ist jetzt in allen
  fünf Modulen identisch umgesetzt.
  - InverterHubTile und InverterHubEnergy hatten bisher **gar keinen** Forum-Hinweis — ergänzt.
  - InverterHubMonitor hatte ebenfalls keinen Forum-Hinweis — ergänzt.
  - Beim Einbau selbst gefunden und vor dem Push korrigiert: Ein erster Ansatz hätte in Tile und
    Energy ein ZWEITES „Dokumentation & Hilfe"-Panel neben dem bereits vorhandenen erzeugt
    (Duplikat). Gegen ein kleines Test-Skript verifiziert, dass nach der Korrektur in beiden
    Formularen genau ein Doku-Panel mit korrekter Versionszeile existiert.
  - Spaltenbreiten (feste px + genau eine „auto"-Spalte je Liste) und Feldanordnung (gestapelt,
    keine Row-/ColumnLayout-Mischungen bei Eingabefeldern) bereits vorbildlich — keine Änderung
    nötig.

## 0.72.2-beta.1 (2026-07-24)

- **Versionsnummer jetzt an einer dauerhaften Stelle im Formular** (alle fünf Module: InverterHub,
  InverterHubTile, InverterHubDiscovery, InverterHubEnergy, InverterHubMonitor). Das
  „Was ist neu"-Banner ist dismissible und verschwindet irgendwann — die Version stand bis jetzt
  nirgends sonst. Neu: eine Zeile „ℹ️ Version X.Y.Z (Build N)" im Doku-Panel bzw. am Formularanfang
  (Tile/Energy, die kein Doku-Panel haben). Verbund-Konvention (EMS, 24.07.2026).
- **„Was ist neu"-Banner der übrigen vier Module nachgezogen**, aus demselben Grund wie beim
  Hauptmodul (0.72.1): Alle standen unverändert bei 0.45. Tile, Sankey und Monitoring zeigen jetzt
  ihre tatsächlichen heutigen Änderungen; Discovery hatte heute keine nutzerrelevante Änderung und
  bleibt daher bewusst unverändert (die Prüfung „gehört da was rein?" darf „nein" ergeben).

## 0.72.1-beta.1 (2026-07-24)

- **„Was ist neu"-Banner nachgezogen.** Er stand seit seiner Einführung unverändert auf 0.45,
  obwohl seither dutzende nutzerrelevante Änderungen dazukamen. Jetzt aktualisiert auf 0.72 mit
  den wichtigsten Punkten seit damals (neuer Hersteller FoxESS, SMA-/Victron-/GoodWe-Korrekturen,
  Energiezähler-Fix, Kachel-Verbesserungen, Strompreis-Reiter im Monitoring). Der
  Bestätigungs-Mechanismus selbst war bereits korrekt pro Version (Vergleich gegen die
  Versionskonstante) — nur die Konstante wurde nicht mitgezogen.

## 0.72.0-beta.1 (2026-07-24)

- **Neuer Hersteller (Read-Only-Vorabversion): FoxESS H1/H3.** Basierend auf der vollständig
  gelesenen offiziellen Registerdokumentation (V1.01). Liest PV1/PV2, Batterie (inkl. BMS-Werte
  und Batteriestatus), Netz, AC-Wirkleistung, EPS/Ersatzstrom, Smart-Meter-Leistung,
  Temperaturen, Betriebsstatus und alle Energiezähler (Tag/Gesamt) aus dem dokumentierten
  Echtzeit-Block (96 zusammenhängende Register, Funktionscode 0x04 wie in der Doku belegt).
  **Bewusst noch nicht enthalten:** Konfigurations-/Steuerregister (Work Mode, Lade-Fenster,
  SOC-Grenzen, Export-Limit) und die Fernsteuerungs-Sollwertregister — für beide ist aus der
  Dokumentation nicht zweifelsfrei ableitbar, ob sie denselben Funktionscode nutzen wie der
  Echtzeit-Block; das wird erst an echter Hardware verifiziert, bevor es gebaut wird. Ebenso
  nicht angeboten: die Factory-Reset- und Lösch-Kommandos. Beta-Tester: StephanBERN.

## 0.71.3-beta.1 (2026-07-24)

- **Bestehende Energiezähler-Variablen werden jetzt HISTORIENERHALTEND korrigiert.** Build 182
  behob den falschen Aggregationstyp nur für neu angelegte Variablen. Wer die betroffenen
  Energie-Werte (Ertrag, Bezug, Einspeisung usw.) schon vorher hatte, bekam den Fehler weiterhin
  angezeigt — eine Korrektur hätte bisher Löschen und Neuanlegen der Variable erfordert, was die
  komplette Archivhistorie vernichtet hätte. Das ist nicht nötig: Der Aggregationstyp ist eine
  reine Archiv-Einstellung (`AC_SetAggregationType`), unabhängig von den gespeicherten Werten.
  Ab diesem Build korrigiert das Modul bestehende Energie-Variablen beim nächsten
  `ApplyChanges` automatisch von „Standard" auf „Zähler" — ohne die Variable anzufassen, ohne
  einen einzigen Archivwert zu verändern oder zu löschen. Kein Handeln des Nutzers nötig.

## 0.71.2-beta.1 (2026-07-24)

- **Energiezähler-Variablen (kWh) bekamen beim Anlegen den falschen Archiv-Aggregationstyp.**
  Neu angelegte Energie-Variablen (Ertrag, Bezug, Einspeisung usw. — Profil `~Electricity`/
  `IHB.Wh`) wurden fest auf „Standard/Mittelwert" archiviert statt auf „Zähler". Für einen
  kumulativen kWh-Wert ist das falsch: `AC_GetAggregatedValues` liefert damit bei
  Zeitreihen-Auswertungen den Rohstand statt des Periodenverbrauchs, und eine manuelle
  Umstellung auf „Zähler" wurde beim nächsten Neuanlegen der Variable wieder überschrieben.
  Jetzt wird beim erstmaligen Archivieren anhand des Profils erkannt, ob es sich um einen
  kumulativen Energiewert handelt, und entsprechend „Zähler" statt „Standard" gesetzt. Betrifft
  nur neu angelegte Variablen; bereits bestehende werden wie gehabt nie automatisch verändert.
  Gemeldet von sirkentucky.

## 0.71.1-beta.1 (2026-07-23)

- **Monitor: PV-Prognose-Instanz wird bei mehreren nicht mehr stillschweigend geraten.** Bisher
  nahm der Monitor bei mehreren installierten PV-Prognose-Instanzen kommentarlos die erste. Jetzt
  gilt: bei genau einer wird sie automatisch genommen, bei mehreren muss man im Formular
  auswählen (Feld erscheint nur dann) — sonst werden bewusst keine Erwartungswerte berechnet,
  statt die falsche Quelle zu verwenden. Eine ungültige Auswahl fällt still auf die Automatik
  zurück, damit ein Update keine laufende Installation lahmlegt. Der Normalfall (genau eine
  Instanz) ändert sich nicht. Muster und schonender Migrationsweg mit der Prognose-Sitzung
  abgestimmt (die denselben Fehler in ihrem Build 43 behoben hatte).

## 0.71.0-beta.1 (2026-07-23)

- **GoodWe: BMS-Zelldiagnostik ergänzt (18 neue Werte je Batterie-Paar).** Pro Batterie jetzt
  Paket-Temperatur, höchste/niedrigste Zellspannung und -temperatur sowie die Nummer der
  jeweiligen Zelle (Register 37003–37023 für Batterie 1, 39001–39021 für Batterie 2; Offsets aus
  dem GoodweET-Vorgängermodul übernommen, an der Anlage verifiziert). Die Zellspannungsspreizung
  (max − min) ist ein Frühindikator für Batteriealterung. Die Werte liegen in den Gruppen
  „Batterie 1/2" und sind standardmäßig nicht archiviert (Diagnose, kein Verlaufsbedarf).
  Damit deckt der GoodWe-Treiber alle 138 Datenpunkte des GoodweET-Moduls ab — Grundlage für die
  verlustfreie Ablösung über MigrationsHub (jeder alte Ident hat jetzt ein Ziel).

## 0.70.1-beta.1 (2026-07-23)

- **Eingesteckte Wallbox wird nicht mehr ausgegraut, auch wenn sie gerade nicht lädt.** Bisher
  hing die Aktiv-Darstellung eines Knotens allein an der Leistung (< 20 W = grau). Eine Wallbox,
  deren „Verbunden-Bedingung" erfüllt ist (Fahrzeug eingesteckt), erschien dadurch grau, sobald
  das Laden pausierte oder das Fahrzeug voll war — obwohl der konfigurierte Zustand „eingesteckt"
  zutraf. Jetzt gilt eine eingesteckte Wallbox als aktiv: farbiger Ring mit Ladestand statt grau.
  Die Fluss-Animation (Blitze/Leuchten) bleibt leistungsabhängig, ein ruhender Knoten pulsiert
  also nicht. Ohne konfigurierte Verbunden-Bedingung bleibt es beim leistungsabhängigen
  Verhalten. Gemeldet von sirkentucky.

## 0.70.0-beta.1 (2026-07-23)

- **Netzbezug-Balken nutzen bevorzugt den abrechnungsgenauen Zähler.** Ist über MeterHub ein
  Netzzähler mit `function == 'grid'` UND `authority == 'billing'` vorhanden (z. B. Inexogy am
  Netzübergabepunkt), speisen die Balken im Strompreis-Reiter jetzt aus dessen geeichter
  Bezugsenergie (Beschriftung dann „Netzbezug (abrechnungsgenau)"). Fehlt ein solcher Zähler,
  bleibt es beim bisherigen Verhalten: Integration aus der Wechselrichter-Netzleistung. Rein
  optional, hinter `function_exists`-Guard — ohne MeterHub oder ohne billing-Zähler ändert sich
  nichts.
  Die Bezugsenergie des Zählers ist ein kumulativer Zählerstand; sie wird über die
  Counter-Aggregation des Archivs zu Intervall-Differenzen aufgelöst (an der Live-Anlage
  verifiziert: `AC_GetAggregatedValues` liefert bei Counter-Variablen den Verbrauch je Periode,
  Überläufe/Zählerwechsel behandelt das Archiv selbst) — bewusst keine rohe Differenzbildung.

## 0.69.1-beta.1 (2026-07-22)

- **Strompreis-Reiter: Balken zeigen nur noch den Netzbezug, nicht mehr die Netto-Netzenergie.**
  Eingespeiste Energie wird zur festen Einspeisevergütung verkauft (Bestandsanlage 18,36 ct),
  nicht zum dynamischen Preis — sie neben die Preiskurve zu legen, verwässerte den Fokus. Jetzt
  zeigen die Balken allein den Bezug (die Energie, deren Kosten = Bezug × dynamischer Preis);
  während Einspeisung bleibt der Balken leer. Umbenannt zu „Netzbezug". Auf Hinweis von Dietmar.

## 0.69.0-beta.1 (2026-07-22)

- **Strompreis-Reiter zeigt jetzt die Netzenergie je Viertelstunde als Balken** — neben der
  Preis-Stufenkurve. Damit ist auf einen Blick erkennbar, in welchen (teuren/günstigen) Slots
  wie viel Energie bezogen (Balken nach oben) oder eingespeist (nach unten) wurde. Linke Achse
  kWh (Balken), rechte Achse ct/kWh (Preis). Die Netzleistung als Linie ist aus diesem Reiter
  gewichen, weil Leistung (kW) und Energie (kWh) sich keine Achse teilen können und die Energie
  die für den Preis relevante Größe ist (Preis × Energie); die Netzleistung bleibt im Reiter
  „PV & Einstrahlung"/Energie erhalten.
  Die Energie wird aus der Netz-Leistung integriert (nicht aus einem kumulativen Zählerstand) —
  das vermeidet die Zählertausch-/Einheitenwechsel-Fallen und hält es bei reiner Anzeige. Eine
  Kostenrechnung (Rechnungsprüfung) ist das bewusst NICHT; die bleibt nach Verbund-Absprache
  Sache des EMS. Angeregt von Dietmar.

## 0.68.1-beta.1 (2026-07-22)

- **Strompreis-Reiter fokussiert auf die Netzinteraktion.** Bisher standen im Preis-Reiter auch
  PV-Erzeugung und Batterie-Leistung — die haben mit dem Preis nichts zu tun (der sinnvolle
  Bezug ist Preis × Netzenergie = Kosten). Der Reiter zeigt jetzt nur noch **Netzleistung** und
  den **Strompreis** und ist auf die Tagesansicht beschränkt (die Preisquelle liefert nur
  heute/morgen). PV und Batterie bleiben in ihren eigenen Reitern. Angeregt von Dietmar.

## 0.68.0-beta.1 (2026-07-22)

- **Neuer Schalter „Messwerte automatisch archivieren" (Standard: an) — Vorbereitung der
  GoodweET-Ablösung.** Bisher aktivierte das Modul die Archivierung seiner Messwert-Variablen
  fest beim Anlegen. Das steht der geplanten Migration im Weg: MigrationsHub übernimmt die
  Historie eines Altgeräts per `AC_ChangeVariableID` (volumenunabhängig, hängt nur die
  Variablen-ID am bestehenden Archiv um) — das gelingt aber nur an einer Zielvariable **ohne
  eigene Historie**. Eine frisch angelegte InverterHub-Instanz hätte durch die
  Auto-Archivierung binnen Sekunden Historie und würde die Migration blockieren. Der Schalter
  lässt sich für Migrationsziele ausschalten; nach der Migration schaltet MigrationsHub das
  Logging selbst ein. Für alle normalen Installationen ändert sich nichts (Standard an).
  Nebeneffekt der Umstellung: Die Auto-Archivierung greift jetzt nur noch bei **neu angelegten**
  Variablen — wer die Archivierung einer Variable von Hand ausgeschaltet hat, dem wird sie nicht
  mehr bei jedem „Übernehmen" wieder eingeschaltet.

## 0.67.2-beta.1 (2026-07-22)

- **Victron: Hauslast in der Stromflusskachel war um den Netzbezug zu hoch.** Die Kachel
  berechnet die Hauslast normalerweise als „Wechselrichter-AC-Ausgang − Netzeinspeisung". Bei
  Victron ist die AC-Variable aber gar nicht der Wechselrichter-Ausgang, sondern schon direkt
  der **AC-Hausverbrauch** (Victron-System-Dienst). Dadurch wurde der Netzbezug fälschlich
  aufaddiert: 385 W Last + 639 W Netz erschienen als 1,033 kW Hauslast. Jetzt erkennt die
  Kachel Victron-Quellen und nimmt deren AC-Wert direkt als Hauslast — Anzeige stimmt mit der
  VictronConnect-App überein (im Testfall 385 W). String-Wechselrichter (SMA, GoodWe, Fronius
  …) sind nicht betroffen, dort bleibt die Bilanz unverändert. Gefunden von loerdy.

## 0.67.1-beta.1 (2026-07-22)

- **SMA: DC-Leistung und Batterie werden jetzt tatsächlich gelesen — Kern des Solar-/Batterie-
  Problems gefunden.** Das SMA-Eigenprofil (30000er-Register: Isolationswiderstand, DC-Leistung
  30773/30961, Batterie 30845 ff. und Batterieleistung 31393/31395) muss als **Input-Register
  (Funktionscode 04)** gelesen werden, nicht als Holding (03). Bisher las das Modul sie als
  Holding — das Gerät antwortete mit „nicht belegt", weshalb die Batterie unerkannt blieb und
  die aus ihr gespeiste Leistung als Solarerzeugung erschien. Die Registeradressen waren die
  ganze Zeit richtig, nur der Zugriffsweg war falsch; ein Tester hat es an seinem Gerät belegt.
  Die SunSpec-Modelle (Wechselrichter, Zähler) bleiben unverändert Holding-Register — das sind
  zwei getrennte Registerwelten auf zwei verschiedenen Unit-IDs.
  Damit sollten auf SMA-Hybridgeräten erstmals echte DC-Leistung, Batterie-Ladezustand,
  -Leistung, -Spannung und -Temperatur ankommen — und die PV-Anzeige stimmt, weil die
  Batterieleistung nun bekannt ist und verrechnet wird, statt die AC-Näherung zu brauchen.

## 0.67.0-beta.1 (2026-07-22)

- **GoodWe: Irreführende Beschriftung eines Steuerschalters korrigiert — konnte ungewollt zur
  Nulleinspeisung führen.** Register 47509 heißt bei GoodWe `Feed_Power_Enable` und schaltet
  **nicht die Einspeisung, sondern deren Begrenzung**. Beschriftet war es als „Einspeisung
  Ja/Nein" — wer den Schalter einschaltete, um die Einspeisung zu *erlauben*, aktivierte in
  Wahrheit die Begrenzung, und zwar auf den hinterlegten Wert. Steht dort 0 W, ist das Ergebnis
  Nulleinspeisung: das genaue Gegenteil der Absicht, und bei laufender Einspeisevergütung teuer.
  Belegt an einer realen Anlage: Schalter „Aus", Grenze 0 W — und trotzdem 42,7 kWh Einspeisung
  am selben Tag.
  Neu beschriftet als **„Einspeisebegrenzung aktiv"** und **„Einspeisegrenze (W)"**; die
  Registerhinweise nennen jetzt die tatsächliche Wirkung. Zusätzlich warnt das Modul im
  Meldungslog, wenn die Begrenzung eingeschaltet wird, während die Grenze auf 0 W steht — das
  kann gewollt sein (Direktvermarktung, Netzbetreiberauflage), ist aber meist ein Irrtum.
  **Idents bleiben unverändert** (`ctl_export_enable`, `ctl_export_limit`), bestehende Skripte
  und Verknüpfungen funktionieren weiter. Gemeldet von der Tibber-Sitzung.

## 0.66.8-beta.1 (2026-07-22)

- **Batterie wurde auf dem Wechselrichter nicht erkannt — Scheinerzeugung blieb dadurch bestehen.**
  Die Korrektur aus 0.66.7 griff nicht, weil sie am falschen Register hing: Die Lade-/Entlade-
  leistung (31393/31395) steht nur im Registerprofil des **SMA Data Manager**, nicht in dem des
  Wechselrichters — dort antwortet sie mit „nicht belegt". Das Gerät galt deshalb als
  batterielos, und die aus der Batterie gespeiste AC-Leistung erschien weiterhin als
  Solarerzeugung (577 W „Solar" bei tatsächlich 0 W Erzeugung und 588 W Entladung).
  Erkennungsmerkmal ist jetzt der **Ladezustand (30845)**, der im Geräteprofil steht und
  belegt ist. Wird eine Batterie erkannt, deren Leistung aber nicht gelesen, bleibt die
  PV-Leistung bei 0 W statt zu raten. Reine PV-Wechselrichter ohne Batterie behalten die
  Näherung über die AC-Leistung.

## 0.66.7-beta.1 (2026-07-22)

- **SMA-Hybrid: Nachts wurde die Batterieentladung als Solarerzeugung angezeigt.** Um 21:50 Uhr
  meldete die Kachel 1,852 kW „Solar", während die Batterie mit 1,850 kW das Haus versorgte —
  es war dieselbe Energie, einmal falsch beschriftet. Ursache war die in 0.65.8 eingeführte
  Rückfallebene: Liefert ein Gerät keine DC-Leistung, wurde ersatzweise die AC-Wirkleistung als
  PV-Erzeugung übernommen, abgesichert nur durch den Betriebsstatus. Dieser Wächter trägt
  nicht — ein Hybridgerät meldet auch nachts „Normal (MPPT)", solange es aus der Batterie
  einspeist.
  Jetzt wird nur noch abgeleitet, wenn die **Herkunft der Energie geklärt** ist: Bei Geräten
  ohne Batterie bleibt AC ≈ PV zulässig; bei Hybridgeräten nur, wenn die Batterieleistung
  tatsächlich gelesen wurde (dann PV ≈ AC + Ladeleistung). Ist eine Batterie vorhanden, ihre
  Leistung aber unbekannt, werden 0 W geschrieben — lieber kein Wert als eine erfundene
  Erzeugung. Dafür werden die Batterieregister jetzt unabhängig davon gelesen, ob die
  Batteriegruppe eingeschaltet ist; geschrieben werden die Variablen weiterhin nur dann.
  Gefunden von sirkentucky.

## 0.66.6-beta.1 (2026-07-22)

- **Nachkontrolle der Sprachpflege.** Alle Texte der Gerätesuche darauf geprüft, ob beim
  Ersetzen von „scannen" das Objekt verrutscht ist (man durchsucht einen Adressbereich, aber man
  *findet* Geräte) — die Formulierungen sind korrekt. Geglättet wurde die holprige Wortbildung
  „Bitte Such-IP-Bereich eintragen" → „Bitte den IP-Bereich für die Suche eintragen".

## 0.66.5-beta.1 (2026-07-22)

- **Sprachpflege: Anglizismen in Anzeigetexten ersetzt.** „Scan/scannen" → „Suche/absuchen",
  „Portcheck" → „Port-Prüfung", „Token" → „Zugangsschlüssel". Betrifft die Gerätesuche und
  einen Hinweis in der Monitoring-Kachel. Ident-, Methoden- und Property-Namen bleiben
  unverändert — sie sind Schnittstelle und werden nie umbenannt.

## 0.66.4-beta.1 (2026-07-22)

- **Die Preiskurve war überhaupt nicht erreichbar — behoben.** Sie war der Gruppe „energy"
  zugeordnet, für die es seit 0.45.1 bewusst **keinen Reiter** mehr gibt. Damit wurde sie
  zwar berechnet, aber nirgends angezeigt. Jetzt gibt es dafür den Fokus-Reiter **„Strompreis"**
  (nach demselben Muster wie „PV & Einstrahlung"): links PV-Erzeugung, Netzleistung und
  Batterie-Leistung in kW, rechts der Preis als Stufenkurve in ct/kWh — also genau die
  Gegenüberstellung, um die es geht. Der Reiter erscheint nur, wenn eine Preisquelle vorhanden
  und die Anzeige eingeschaltet ist. Die in 0.45.1 entfernten Reiter „Leistung & Energie" und
  „Diagnose" bleiben entfernt; diese Entscheidung wird nicht angetastet.

## 0.66.3-beta.1 (2026-07-22)

- **Archivierungs-Knopf setzt den Aggregationstyp ausdrücklich auf „Standard".** Ein Preis ist
  ein Momentanwert, kein aufsummierter Verbrauch. Ohne diese Festlegung bliebe eine Variable,
  die früher einmal als **Zähler** archiviert wurde, weiterhin Zähler — die Preiskurve wäre dann
  Unsinn. Der Wert ist mit der Preisquelle abgestimmt, damit es keine Rolle spielt, über welchen
  Weg die Archivierung eingeschaltet wurde.

## 0.66.2-beta.1 (2026-07-22)

- **Knopf „Archivierung der Preisvariable einschalten".** Der Hinweis „muss archiviert werden"
  war zwar richtig, aber nicht hilfreich: Das Häkchen sitzt in IP-Symcon **an der Variable
  selbst** (Rechtsklick → Archivierung), nicht im Formular des Preismoduls — dort sucht man
  vergeblich. Jetzt steht der Knopf direkt neben dem Hinweis und erledigt es. Er erscheint nur,
  solange die Archivierung aus ist, und verschwindet nach dem Einschalten.
  Bewusst nur auf Knopfdruck und nie automatisch: Ob eine Variable in die Datenbank geschrieben
  wird, entscheidet der Anlagenbetreiber, nicht das Modul.

## 0.66.1-beta.1 (2026-07-22)

- **Strompreis-Konfiguration sagt jetzt je Quelle, was bereitsteht.** Die Kurve besteht aus zwei
  Teilen, und die Maske prüft beide einzeln statt einen allgemeinen Tipp zu geben: „Vorschau
  bereit" (kommt aus dem Preismodul) und „Rückblick bereit" (nur wenn die Preisvariable
  tatsächlich archiviert wird — das wird jetzt geprüft, nicht vermutet). Fehlt das Archiv, nennt
  der Hinweis den Grund, wo das Häkchen sitzt, und dass die Aufzeichnung **erst ab dem Setzen**
  beginnt — rückwirkend gibt es nichts. Genau daran wäre sonst der nächste Nutzer hängen
  geblieben, der das Häkchen setzt und sich über die leere linke Hälfte wundert.

## 0.66.0-beta.1 (2026-07-22)

- **Monitoring-Kachel: Strompreis im Tagesverlauf.** Neu ist eine Preiskurve auf der rechten
  Achse (ct/kWh) im Reiter „Leistung" — als **Stufenkurve**, weil ein Preis slotweise gilt und
  nicht zwischen zwei Zeitpunkten hochläuft. Sie besteht aus zwei Teilen: Der Rückblick kommt
  aus der archivierten Preisvariable, die **Vorschau** für die kommenden Stunden direkt aus dem
  Preismodul (`TIBBERGR_GetPriceCurve`, Tibber Grid Reward ab 2.2.0). Damit lässt sich die
  eigene Erzeugung unmittelbar gegen den Preisverlauf lesen.
  Details: Slot-Breite wird aus dem Vertrag übernommen (Stunden **oder** Viertelstunden),
  die Kurve wird nur so weit gezeichnet, wie Daten vorliegen (kein starres 48-h-Raster), und
  das Zeitfenster des Diagramms wird so erweitert, dass die Vorschau nicht abgeschnitten wird.
  Konfiguration im neuen Abschnitt „Strompreis"; ohne Preismodul bleibt alles wie bisher
  (Aufruf ist per `function_exists` abgesichert). Angeregt von Dietmar über die Tibber-Sitzung.

## 0.65.11-beta.1 (2026-07-22)

- **Stromflusskachel: Einheit richtet sich nach der Größenordnung.** Bisher stand überall fest
  „kW" mit drei Nachkommastellen, sodass kleine Verbraucher fast nur aus Nullen bestanden
  („0,034 kW"). Jetzt: unter 1 kW in **W** ohne Nachkommastellen (34 W, 470 W), darüber in
  **kW** mit Watt-Auflösung (4,400 kW), ab 1 MW in **MW**. Geschätzte Werte behalten ihr
  vorangestelltes ≈ und werden im Watt-Bereich auf 10 W gerundet (≈30 W), weil die Schätzung
  ohnehin nur im ~200-W-Raster liegt. Angeregt von ChristianL im Symcon-Forum.

## 0.65.10-beta.1 (2026-07-22)

- **SMA: Eigenprofil-Register (Riso, DC-Leistung, Batterie) blieben stumm, wenn als Unit-ID
  direkt die SunSpec-Kennung eingetragen war.** SMA trennt zwei Registerwelten: SunSpec liegt
  auf Geräte-Unit-ID + 123, das SMA-Eigenprofil (30000er) auf der Geräte-Unit-ID selbst. Wer —
  wie z. B. OpenEMS-Standard 126 — direkt die SunSpec-Kennung einträgt, bekam SunSpec-Werte,
  aber alle Eigenprofil-Reads liefen ins Leere: Isolationswiderstand eingefroren, DC-Leistung
  −1/NaN, Batterie ohne Werte — obwohl das Gerät die Register (auf Unit 3) nachweislich
  liefert. Die Geräte-Unit-ID wird jetzt sondiert (eingetragene ID, minus 123, Werksvorgabe 3;
  Prüfregister 30775) und für alle Eigenprofil-Zugriffe verwendet. Damit liefern auf solchen
  Anlagen auch die echte DC-Leistung (30773) und die Batteriegruppe (0.65.9) Werte, und die
  AC-Näherung entfällt von selbst.

## 0.65.9-beta.1 (2026-07-22)

- **SMA: Batterie-Unterstützung für Hybrid-/Storage-Geräte** (z. B. STP Smart Energy). Neue
  optionale Gruppe „Batterie" mit SOC (Reg 30845), Leistung (31393 − 31395, + = lädt),
  Spannung (30851) und Temperatur (30849) aus dem SMA-Eigenprofil; Registerbelegung gegen die
  SMA Modbus-TI (EDMx) und die CodeKing-Registerkarte verifiziert. Meldet das Gerät „keine
  Batterie" (NaN), bleiben die Variablen unberührt.
- **SMA: PV-Näherung bei Hybridgeräten verbessert.** Fehlt die echte DC-Leistung (siehe
  0.65.8), rechnet die Rückfallebene jetzt die Batterieleistung mit ein:
  PV ≈ AC-Ausgang + Ladeleistung. Damit stimmt der Wert auch, während die Batterie aus PV
  lädt, und nachts entladene Energie erscheint nicht als PV-Leistung.
- **Sankey-Kachel: Datumssteuerung zentriert** — analog zur Monitoring-Kachel. Gleichzeitig
  als Verbund-Konvention festgeschrieben (CLAUDE.md): Alle Kacheln mit Datumssteuerung
  bedienen sich identisch (Aufbau, Reihenfolge, Schnellwahl, Optik); Änderungen werden immer
  auf alle betroffenen Kacheln gleichzeitig angewendet.

## 0.65.8-beta.1 (2026-07-22)

- **SMA: „PV Gesamtleistung" zeigte −2 W bei laufender Produktion.** Zwei Ursachen:
  1. SMA meldet „nicht verfügbar" je nach Gerät nicht nur als 0x80000000, sondern auch als
     0xFFFFFFFF — als vorzeichenbehaftete Zahl gelesen ist das **−1**. Der STP Smart Energy
     belegt die DC-Register 30773/30961 gar nicht und lieferte so −1 je Register, zusammen die
     gemeldeten −2 W. Beide NaN-Formen werden jetzt erkannt und übersprungen.
  2. Damit die Variable auf solchen Geräten nicht leer bleibt, gibt es eine letzte
     Rückfallebene: Liefert weder das SMA-Eigenprofil noch SunSpec einen DC-Wert, wird die
     (korrekt skalierte) AC-Wirkleistung übernommen, solange der Wechselrichter einspeist
     (Status „Normal (MPPT)") — sonst 0 W. Der Wert ist dann um die Wandlerverluste zu
     niedrig und bei Hybridgeräten um die gerade geladene Batterieleistung daneben; das ist
     dokumentiert und ehrlicher als ein eingefrorener oder fehlender Wert.

## 0.65.7-beta.1 (2026-07-22)

- **Sankey-Kachel: Schnellwahl „Vorgestern / Gestern / Heute".** Wie in der Monitoring-Kachel
  (0.65.6): drei direkte Knöpfe neben der Datumsauswahl, nur in der Tagesansicht, aktueller Tag
  hervorgehoben, Tage ohne Daten ausgegraut.

## 0.65.6-beta.1 (2026-07-22)

- **Monitoring-Kachel: Schnellwahl „Vorgestern / Gestern / Heute".** In der Tagesansicht stehen
  die drei Tage jetzt als direkte Knöpfe neben der Datumsauswahl — ohne den Umweg über das
  Datumsfeld. Der gerade angezeigte Tag ist hervorgehoben, Tage ohne Archivdaten sind
  ausgegraut. In den Energie-Ansichten (Woche/Monat/Jahr) sind die Knöpfe ausgeblendet.

## 0.65.5-beta.1 (2026-07-22)

- **SMA: „PV Gesamtleistung" wurde nie befüllt.** Der Wert kam bisher aus dem SunSpec-Feld DCW —
  das SMA in seinen Geräten gar nicht belegt (Kennwert „nicht implementiert"). Beim frisch
  angelegten Gerät blieb die Variable dadurch leer, bei bestehenden blieb der letzte alte Wert
  stehen (im Extremfall der Geisterwert 4294967295 W aus der Zeit vor 0.65.4). Die DC-Leistung
  wird jetzt aus dem SMA-Eigenprofil gelesen (Register 30773 + 30961, MPP-Eingänge A und B,
  gleiche Unit-ID wie der Isolationswiderstand) und summiert. Melden beide Eingänge nachts den
  Kennwert „nicht verfügbar", wird 0 W geschrieben statt der letzte Tageswert stehen zu bleiben.
  Für Geräte, die DCW doch liefern, bleibt SunSpec als Rückfallebene erhalten.

## 0.65.4-beta.1 (2026-07-22)

- **SMA: Alle Messwerte lagen um Zehnerpotenzen daneben.** SunSpec kennt zwei Bauformen des
  Wechselrichter-Modells: Fließkomma (111/113) und Ganzzahl mit separatem Skalierungsfaktor
  (101/103). Der Ganzzahl-Zweig übernahm den **Rohwert**, ohne das zugehörige
  Skalierungsfaktor-Register auszuwerten. SMA bietet **ausschließlich** diese Bauform an,
  deshalb traf es dort jeden Nutzer — bei Fronius fiel es nie auf, weil der Datamanager die
  Fließkomma-Modelle anbietet und der Code die bevorzugt.
  Gemeldet wurden z. B. 2390 statt 239,0 V (Faktor −1) und 57 statt 0,57 A (Faktor −2).
  Gegenprobe aus dem Tester-Screenshot: 0,57 A × 239 V = 136 W, zeitgleich meldete die
  AC-Wirkleistung 137 W. Behoben für SMA und Fronius, einschließlich der Zählerleistung
  (Modell 201/203), deren Skalierungsfaktor ebenfalls fehlte.

- **SMA: „Ertrag Gesamt" blieb dauerhaft auf 0,00 kWh.** Der Gesamtertrag wurde nur im
  Fließkomma-Zweig gelesen und fehlte im Ganzzahl-Zweig vollständig — also genau in dem Zweig,
  den SMA-Geräte benutzen. Ergänzt.

- **Nicht belegte Register werden nicht mehr als Messwert übernommen.** Meldet ein Gerät für ein
  Feld den Modbus-Kennwert „nicht implementiert" (0xFFFF bzw. 0x8000 bei vorzeichenbehafteten
  Feldern), bleibt die Variable jetzt unverändert, statt −32768 W oder 65535 % anzuzeigen.

- **Warnung bei falsch eingestelltem Hersteller.** Antwortet ein Gerät auf den abgefragten
  Bereich nur mit 0xFFFF, ist es zwar erreichbar, liefert aber keine Daten — typischerweise,
  weil in der Instanz ein anderer Hersteller eingestellt ist, als das Gerät tatsächlich ist.
  Bisher entstanden daraus Geisterwerte (6553,5 V, 4294967295 W, Seriennummern aus lauter „ÿ").
  Jetzt bleibt „Verbindung" auf *nicht verbunden* und es erscheint einmalig ein Hinweis im
  Meldungslog. Aktiv für SMA, Fronius und GoodWe.

## 0.65.3-beta.1 (2026-07-22)

- **Stromflusskachel: Inhalt saß nach rechts unten versetzt und stieß dort an.** Ursache
  gefunden und behoben. Der Kachel-Host setzt dem `body` einen **Außenabstand** (gemessen:
  20 px seitlich, 60 px oben für die Titelleiste). In 0.63.3 war zusätzlich
  `body { position: relative }` eingeführt worden — dadurch wurde der `body` zum Bezugsrahmen
  für die Zeichenfläche, die den Versatz mit erbte und um genau diesen Betrag nach rechts unten
  rutschte. Ohne positionierten Vorfahren bezieht sich die Fläche auf den sichtbaren Bereich,
  unabhängig vom Außenabstand des Hosts.
  Nachgestellt und belegt: Mit dem Außenabstand und `position: relative` liegt die Zeichenfläche
  bei 20/60 — exakt der beim Tester gemessene Wert; ohne `position: relative` bei 0/0.

## 0.65.2-beta.1 (2026-07-22)

- **Stromflusskachel: Knoten wurden am Rand abgeschnitten.** Die Zeichenfläche war zu knapp
  bemessen. Der Knotenrand lag nur 26 Einheiten vor der Kante, die **Corona** ragte sogar
  24 Einheiten darüber hinaus und wurde abgeschnitten. Bei wenigen Verbrauchern fiel das kaum
  auf, weil die Diagonalen frei blieben — **ab etwa acht Knoten** sind auch die Ecken besetzt und
  der Inhalt stößt ringsum an.
  Die Zeichenfläche rechnet den Platzbedarf jetzt aus dem tatsächlichen Inhalt (äußerster
  Knotenmittelpunkt + Corona + Luft) statt mit einem festen Zuschlag. Der Inhalt wird dadurch
  auf 88 % der bisherigen Größe skaliert, dafür ist ringsum Rand und nichts wird beschnitten.

## 0.65.1-beta.1 (2026-07-22)

- **Stromflusskachel: Anordnung wirkte nach unten verschoben / unten abgeschnitten.** Rückschlag
  aus 0.63.3: Dort wurde `position: fixed` durch `position: absolute` ersetzt, weil `fixed`
  innerhalb eines iframes in Safari fehlerhaft gezeichnet wird. Damit kehrte aber genau der
  Fehler zurück, gegen den `fixed` ursprünglich eingeführt worden war — **manche Kachel-Hosts
  geben dem `body` eine größere Höhe als den sichtbaren Bereich**. Der Inhalt wird dann korrekt
  in die zu hohe Box eingepasst und wirkt dadurch nach unten verschoben bzw. stößt unten an.
  Die Box ist jetzt über `100dvh` (Rückfall `100vh`) und `max-height` fest an den sichtbaren
  Bereich gebunden — unabhängig davon, was der Host am `body` setzt. Damit sind **beide** Fälle
  erledigt: kein `fixed` mehr (Safari) und keine überhohe Box (Anordnung).
  Nachgestellt und belegt: Erzwingt man dem `body` 760 px bei 478 px Sichtfläche, wuchs das SVG
  vorher auf 760 px mit; jetzt bleibt es bei 478 px.

## 0.65.0-beta.1 (2026-07-21)

- **SMA: Verbindung kam nicht zustande — Unit-ID-Versatz von 123.** Bei SMA liegen die
  SunSpec-Daten **nicht** auf der Unit-ID, die in der SMA-Oberfläche eingestellt ist, sondern auf
  dieser Zahl **plus 123**: Bei der SMA-Vorgabe 3 also auf 126, bei eingestellter 4 auf 127.
  SMA dokumentiert das selbst; OpenEMS setzt seine Vorgabe deshalb auf 126. Wer die in der
  Oberfläche sichtbare Zahl einträgt, bekommt an Register 40000 nur 0xFFFF oder gar keine
  Antwort — genau der Befund unseres Beta-Testers.
  Der Treiber probiert den Versatz jetzt **selbst**: Findet sich unter der eingetragenen Unit-ID
  keine SunSpec-Kette, sucht er einmalig mit +123 weiter. Zusätzlich erklärt ein Hinweis in der
  Konfigurationsmaske den Zusammenhang.
  Der Isolationswiderstand stammt aus dem SMA-**eigenen** Registerprofil (30000er) und liegt auf
  der unversetzten Unit-ID — er wird jetzt entsprechend mit umgeschalteter Kennung gelesen.

## 0.64.0-beta.1 (2026-07-21)

- **Victron: Solarertrag jetzt aus dem 32-Bit-Register — Überlaufkorrektur wird überflüssig.**
  Beta-Tester loerdy hat am Gerät nachgewiesen, dass es für den Lebensdauer-Ertrag der
  Solarladeregler sehr wohl ein **32-Bit-Register** gibt: **3728** liefert den echten Wert
  (bei ihm 10.617 kWh), während das bisher genutzte 16-Bit-Register 790 denselben Zähler nur
  verstümmelt zeigt (40.631 → 4.063,1 kWh). Meine gegenteilige Angabe in 0.62.0 beruhte auf
  einer unvollständigen Fremdquelle und war falsch.
  Der Ertrag wird jetzt bevorzugt aus 3728 gelesen — damit entfallen Überlauf, Eichung und
  Zählerreset-Problematik ersatzlos. **Achtung beim Maßstab:** 3728 zählt in ganzen kWh, 790 in
  0,1 kWh. Bietet ein Gerät 3728 nicht an, greift weiterhin der bisherige Weg über 790 samt
  optionaler Überlaufkorrektur; die zugehörigen Einstellungen bleiben daher erhalten.

## 0.63.3-beta.1 (2026-07-21)

- **Stromflusskachel: unten abgeschnittener Inhalt in Safari.** Die Kachel band ihren Inhalt per
  `position: fixed` an den Viewport des Kachel-iframes. Das ist in Safari/WebKit ein bekannter
  Fehlerkreis: Fest positionierte Elemente in einem iframe werden abgeschnitten oder oben
  hängend gezeichnet, obwohl die Layout-Box korrekt gemeldet wird — mehrfach in Apples
  Entwicklerforum dokumentiert. Ein Beta-Tester sah die Kachel dadurch unten abgeschnitten,
  während dieselbe Safari-Version bei anderen fehlerfrei lief; besonders bei hochkanten Kacheln.
  Der Inhalt wird jetzt mit `position: absolute` an die body-Box gebunden — das Muster aller
  übrigen Kacheln dieses Modulverbunds, unsere war die einzige mit `fixed`.

## 0.63.2-beta.1 (2026-07-21) — Fehlerbehebung, bitte aktualisieren

- **Fronius war in Build 146 nicht lauffähig; SMA hatte seit Build 145 eine falsche Variable.**
  Beim Einbau des Isolationswiderstands ist der Code im **falschen Treiber** gelandet: SMA,
  Fronius und SolarEdge nutzen alle SunSpec und haben daher wortgleiche Codeblöcke, und die
  Änderung traf jeweils den erstbesten.
  - **Build 145:** Die Fronius-Variable „Isolationswiderstand" wurde im **SMA**-Treiber angelegt
    — dort doppelt und mit fremdem Profil, während Fronius sie gar nicht bekam. Schwerwiegender:
    Der ebenfalls dort gelandete Lesecode rief `sfVal()` auf, das nur im Fronius-Treiber
    existiert — **auch SMA-Instanzen liefen damit in einen Fatal Error**.
  - **Build 146:** Die Lesefunktion lag im SMA-Treiber, wurde aber aus dem Fronius-Treiber
    aufgerufen — das erzeugte bei Fronius-Instanzen einen **Fatal Error in jedem Lesezyklus**.
  Beides ist behoben: Variable, Profil, Lesefunktion und beide Aufrufe liegen jetzt vollständig
  im Fronius-Treiber; der SMA-Treiber ist auf seinen ursprünglichen Stand zurückgesetzt und
  behält seinen eigenen Isolationswiderstand (Register 30225) unverändert.

## 0.63.1-beta.1 (2026-07-21)

- **Fronius: Isolationswiderstand wird jetzt zum richtigen Zeitpunkt gelesen.** Der Wert entsteht
  beim Selbsttest des Wechselrichters **vor dem Zuschalten** — genau dann zeigt er, ob eine
  Strecke Feuchtigkeit zieht. In der vorigen Fassung wurde er nur im langsamen Zyklus geholt,
  wodurch diese Messung verpasst werden konnte. Jetzt gilt: Solange der Wechselrichter **nicht
  einspeist** (also Aus, Auto-Shutdown, Startet, Standby, Fehler), wird er in **jedem schnellen
  Zyklus** gelesen; speist er ein (Normal/MPPT oder Leistungsreduktion), genügt die Auffrischung
  im langsamen Zyklus.

## 0.63.0-beta.1 (2026-07-21)

- **Virtuelle Zähler in Kachel und Sankey.** Das neue MeterHub-Modul „MeterHubVirtual" bildet
  berechnete Zähler aus der Verdrahtung mehrerer echter Zähler (z. B. „Hausanschluss minus
  Wärmepumpe und Wallbox"). Solche Zähler erfüllen denselben Vertrag wie echte und erscheinen
  daher in der Stromflusskachel und im Sankey wie gewohnt — sie werden einfach in derselben
  MeterHub-Liste ausgewählt. Ohne das Modul ändert sich nichts.

- **Fronius: Isolationswiderstand.** Er wird jetzt aus dem SunSpec-Modell 122
  („Measurements Status") gelesen und in der Gruppe „Geräteinformation" angelegt.
  Zur Richtigstellung: Es hieß zuvor, der Wert sei nicht verfügbar — das galt nur für die
  Fronius **Solar API** (`CommonInverterData` kennt ihn tatsächlich nicht). Über **Modbus** ist
  er sehr wohl vorhanden; Beta-Tester kea liest ihn mit einer eigenen Modbus-Vorlage bereits
  aus. Sein Gerät meldet `Ris` = 5999 bei `Ris_SF` = 3, also 5,999 MΩ.
  Adressiert wird über die vom Gerät gemeldete Modelllänge (Ris und Ris_SF sind die beiden
  letzten der 44 Register), nicht über eine feste Registeradresse — die SunSpec-Kette liegt je
  nach Gerät unterschiedlich. Führt ein Gerät das Modell 122 nicht oder meldet es eine
  abweichende Länge, entfällt der Wert, statt einen falschen zu liefern.

## 0.62.0-beta.1 (2026-07-21)

- **Victron: Solarertrag je Laderegler und Korrektur des Zählerüberlaufs.** Beta-Tester loerdy
  hat nachgewiesen, dass der Gesamtertrag zu niedrig war: Victron stellt diesen Zähler über
  Modbus nur als **16-Bit**-Register bereit (kein `/Yield/System`, keine 32-Bit-Variante), es
  läuft bei ÷10 nach **6.553,5 kWh** über. Bei seinen zwei Reglern mit 10.616,55 und 9.628,06 kWh
  wurden dadurch 4.063,0 + 3.074,5 = **7.137,5 statt 20.244,6 kWh** gelesen.
  - **Einzelwerte je Laderegler** (Ertrag heute und gesamt) werden jetzt zusätzlich angelegt —
    auf loerdys Anregung. Sie sind für sich nützlich und machen einen Überlauf sichtbar, statt
    ihn in der Summe zu verstecken.
  - **Optionale Überlaufkorrektur** (Vorgabe: aus). Einmalig wird der tatsächliche Zählerstand
    je Laderegler aus der VictronConnect-App eingetragen; daraus ermittelt das Modul die Zahl
    der bisherigen Überläufe und zählt weitere selbst mit.
  - **Schutz gegen Fehlkorrektur:** Ein Überlauf wird nur angenommen, wenn der Zähler vorher
    nahe am Maximum stand. Ein vom Nutzer am Gerät zurückgesetzter Ertragszähler sieht sonst
    genauso aus und würde fälschlich 6.553,6 kWh addieren. Wird ein Reset erkannt, setzt das
    Modul die Korrektur aus, statt still falsch weiterzurechnen — dann ist neu zu eichen.
  - Ohne Korrektur bleibt das Verhalten unverändert; für Anlagen unter 6.553 kWh je Regler
    ändert sich nichts.

## 0.61.0-beta.1 (2026-07-21)

- **Victron: Energiezähler (Netzbezug/-einspeisung, Solarertrag).** Neue optionale Gruppe
  „Energiezähler". Zu beachten ist eine Victron-Eigenheit: Der Systemdienst (Unit-ID 100), den
  das Modul bisher nutzt, führt **überhaupt keine** Zählerstände, sondern nur Momentanleistungen.
  Die Zähler liegen auf eigenen Diensten — Netzbezug und Einspeisung beim Netzzähler
  (`com.victronenergy.grid`, dessen **Unit-ID im Formular einzutragen** ist), der Solarertrag bei
  den Solarladereglern (nutzt die vorhandenen MPPT-Unit-IDs mit).
  Netzbezug/-einspeisung werden bewusst aus den **32-Bit**-Registern gelesen; die 16-Bit-Variante
  liefe schon nach 655,35 kWh über. Beim Solarertrag-Gesamtzähler gibt es geräteseitig nur ein
  16-Bit-Register — er läuft nach **6.553,5 kWh** über, was für die Auswertung wie ein
  Zählerreset aussieht. Ein Hinweis dazu steht in der Konfigurationsmaske.
  **Noch nicht an einer realen Anlage geprüft** — die Registeradressen stammen aus der offiziellen
  CCGX-Modbus-TCP-Registerliste. Rückmeldungen zu abweichenden Werten willkommen.

## 0.60.0-beta.1 (2026-07-21)

- **Anzahl der MPPT-Eingänge einstellbar.** Die Treiber legen bisher so viele MPPT-Variablen an,
  wie die jeweilige Baureihe maximal haben kann — beim Sungrow SG-CX zwölf, bei Victron vier,
  GoodWe drei, Fronius zwei. Wer weniger Strings betreibt, bekam entsprechend viele leere
  Variablen, die den Objektbaum zumüllen und im Archiv Platz kosten. Im Konfigurationsformular
  lässt sich jetzt eintragen, wie viele Eingänge tatsächlich belegt sind; die übrigen werden
  nicht angelegt und beim Übernehmen wieder entfernt. Vorgabe bleibt 0 = alle anlegen, damit
  sich bestehende Instanzen nicht verändern. Das Feld erscheint nur bei Treibern mit mehr als
  einem MPPT-Eingang und begrenzt die Eingabe auf die vom Gerät unterstützte Höchstzahl.

## 0.59.0-beta.1 (2026-07-21)

- **HeishaMon-Anbindung (Panasonic-Wärmepumpe).** Stromflusskachel und Sankey können jetzt
  HeishaMon-Instanzen als Wärmepumpen-Verbraucher übernehmen (Vertrag `HEISHA_GetFunctions`).
  In der Kachel erscheint die elektrische Gesamtleistung; ist am HeishaMon kein externer
  Stromzähler hinterlegt, ist dieser Wert eine Schätzung im ~200-W-Raster und wird
  entsprechend gröber dargestellt. Im Sankey wird die Wärmepumpe nur berücksichtigt, wenn ein
  kumulativer kWh-Zähler vorhanden ist — aus der Leistung wird bewusst keine Energie
  hochgerechnet. Ohne installiertes HeishaMon verhält sich alles unverändert.
- **Kachel: geschätzte Werte werden als solche dargestellt.** Ein Verbraucher, dessen Leistung
  nur geschätzt ist, erscheint mit einer Nachkommastelle und vorangestelltem „≈" (z. B.
  „≈1,4 kW") statt dreistellig. „0,034 kW" bei einem 200-W-Raster hätte eine Auflösung
  vorgetäuscht, die es nicht gibt. Gemessene Werte bleiben unverändert dreistellig.

## 0.58.3-beta.1 (2026-07-21)

- **Store-Vorgabe umgesetzt:** Die Schaltfläche „Darstellung zurücksetzen" der Stromflusskachel
  schreibt die Werte nicht mehr direkt in die Instanz, sondern setzt nur die Felder der
  geöffneten Konfigurationsmaske — bestätigt wird wie üblich mit „Übernehmen". Ein Fehlklick
  bleibt dadurch folgenlos.
- **Sankey:** Hinweis im Panel „Energie-Datenpunkte", dass periodisch zurückspringende Werte
  (z. B. „Energie heute") als Quelle ungeeignet sind — die Auswertung bildet Zählerdifferenzen.

## 0.58.2-beta.1 (2026-07-21)

- **Monitoring: Prognose-Hinweis schickte Stable-Nutzer unnötig in den Beta-Kanal.** Der
  vorige Text erweckte den Eindruck, auch das Performance-Ratio brauche eine neuere
  Prognose-Version. Das stimmt nicht: Der Parameter `PVF_PR` ist bereits in der
  Stable-Version vorhanden, **Erwartungswerte und Performance-Ratio funktionieren dort ohne
  jedes Update**. Nur die **spezifische Leistung (W/m²)** benötigt die Modulangaben
  (Modulanzahl, Länge/Breite), die es erst ab Version 0.20 / Build 41 gibt — im Stable-Kanal
  fehlen diese Eingabefelder ganz und lassen sich dort auch nicht nachtragen. Der Hinweis
  benennt das jetzt eindeutig. (Von der Prognose-Seite gegen die Repo-Historie geprüft.)

## 0.58.1-beta.1 (2026-07-21)

- **Monitoring: Hinweis zum Prognose-Modul war irreführend.** Der Tipp bei ausgewähltem
  Einstrahlungssensor nannte ein Eingabefeld „Fläche je Modul (m²)", das es so nicht mehr gibt —
  die PV-Prognose berechnet die Modulfläche seit Build 40 aus Modullänge × Modulbreite (mm).
  Außerdem fehlte die Information, ab welcher Version die Modulfläche überhaupt bereitsteht:
  erst ab 0.20 / Build 41, im Stable-Kanal (0.19) noch nicht. Der Hinweis nennt jetzt Version
  und Kanal konkret und stellt klar, dass die **Erwartungswerte im Diagramm auch mit der
  Stable-Version funktionieren** — nur spez. Leistung / Performance-Ratio brauchen die neuere
  Fassung. (Angaben mit der Prognose-Seite abgeglichen.)

## 0.58.0-beta.1 (2026-07-21)

- **Sankey-Kachel: Einzelverbraucher und Energiezähler aus MeterHub.** Neues Panel
  „Einzelverbraucher aus MeterHub" — dort ausgewählte MeterHub-Instanzen liefern ihre
  Funktionszuordnung automatisch als Einzelverbraucher im Energiefluss (kWh-Bezugszähler je
  Funktion). Zusätzlich speist ein Zähler mit Funktion „Netzanschluss" die Zähler für
  Netzbezug und Netzeinspeisung, einer mit „Hausverbrauch" den Hausverbrauchszähler; direkt
  zugewiesene Variablen behalten Vorrang. Die Verbraucher-Arten wurden um dieselben zwölf
  erweitert wie in der Stromflusskachel (Waschmaschine, Spülmaschine, Backofen, Herd,
  Kühl-/Gefriergerät, Küche, Heizung, Lüftung, Beleuchtung, Server/Netzwerk, Werkstatt,
  Garage). Ohne installiertes MeterHub verhält sich die Kachel unverändert.

## 0.57.0-beta.1 (2026-07-21)

- **Stromflusskachel: Verbraucher aus MeterHub übernehmen.** Neues Panel „Verbraucher aus
  MeterHub" — dort ausgewählte MeterHub-Instanzen liefern ihre Funktionszuordnung
  automatisch als Verbraucher-Kreise (Art, Bezeichnung, Leistungsvariable). Das manuelle
  Pflegen der Verbraucher-Liste entfällt damit. Zusätzlich speist ein MeterHub-Zähler mit
  Funktion „Netzanschluss" die Netz-Leistung und einer mit „Hausverbrauch" die gemessene
  Hauslast — die Kachel läuft dadurch auch ganz ohne InverterHub-Instanz. Das
  Vorzeichen wird dabei automatisch umgedreht (MeterHub zählt + = Bezug, die Kachel
  + = Einspeisung). Ist MeterHub nicht installiert, verhält sich die Kachel unverändert.
- **Zwölf neue Verbraucher-Arten** mit eigenem Icon und eigener Farbe: Waschmaschine,
  Spülmaschine, Backofen, Herd, Kühl-/Gefriergerät, Küche, Heizung, Lüftung, Beleuchtung,
  Server/Netzwerk, Werkstatt und Garage. Damit deckt die Kachel das Funktions-Vokabular des
  MeterHub vollständig ab.
- **Intern:** Die Auswahlliste der Verbraucher-Arten wird jetzt aus `CONSUMER_TYPES` erzeugt
  statt statisch in der `form.json` gepflegt — eine Quelle, kein Auseinanderlaufen mehr.
- **Änderung aus 0.56.6 zurückgenommen (`position: absolute` → wieder `fixed`).** Das
  Maximieren-Symbol der Kachel vergrößert die Kachel gar nicht, sondern öffnet die
  **Objekt-Detailansicht** des Hosts (Variablenliste der Instanz). Das betrifft alle
  HTML-SDK-Kacheln gleichermaßen — auch Symcons eigene Referenzkachel zeigt dort ihre
  Variablen statt ihres HTML. Bei unserer Kachel wirkt diese Ansicht leer, weil die
  Tile-Instanz keine eigenen Variablen besitzt. Es lag also kein Fehler im Kachel-Layout
  vor; die Positionierung ist auf den erprobten Stand zurückgesetzt.

## 0.56.6-beta.1 (2026-07-21) — zurückgenommen, siehe 0.57.0-beta.1

- **Kachel: kein Inhalt beim Maximieren.** Das Diagramm war per `position: fixed` an den
  Viewport des Kachel-iframes gebunden statt an die Box, die der Kachel-Host vergibt. Die
  Tile-Oberfläche ist eine Flutter-Web-App, die HTML-Kacheln als iframe in einen
  Platform-View einbettet; beim Maximieren entspricht der iframe-Viewport nicht mehr dem
  sichtbaren Kachelbereich, wodurch der Inhalt nicht erschien. Diagramm und Statusplakette
  nutzen jetzt `position: absolute` gegen den body - damit folgt der Inhalt der Host-Box.
  Ein Vergleich aller auf einer Installation vorhandenen Kacheln zeigte, dass unsere die
  einzige mit `position: fixed` war; auch Symcons eigene Referenzkachel verzichtet darauf.

## 0.56.5-beta.1 (2026-07-20)

- **Kachel: Münz-Glanzlichter in Safari wieder weich.** Nach der Umstellung auf Verläufe
  waren die Glanzlichter in Safari harte Ellipsen-Blobs: Safari rendert einen
  `objectBoundingBox`-Radialverlauf auf einer nicht-quadratischen (elliptischen) Box hart
  statt weich - anders als auf einem Kreis (dort funktionierte es, wie bei der Corona). Die
  Glanzlichter werden jetzt als Kreis mit Verlauf gezeichnet und per `transform: scale` zur
  Ellipse gestaucht; der weiche Randabfall bleibt dabei erhalten. In allen Browsern weich.

## 0.56.4-beta.1 (2026-07-20)

- **Kachel: Münz-Glanzlichter und -Schatten als Vektor-Verläufe.** Das große Glanzlicht,
  der kleine Spitzglanz und der Bodenschatten der Kreise nutzten noch `filter: blur()` -
  derselbe Effekt, den Safari beim Skalieren grob rasterte (wie zuvor bei der Corona). Sie
  sind jetzt radiale SVG-Verläufe (fill) ohne Blur: in allen Browsern vektorscharf, der
  weiche Randabfall steckt im Verlauf. Optik unverändert.

## 0.56.3-beta.1 (2026-07-20)

- **Kachel: geprägte Zahlen/Icons in Safari waren körnig.** Der Relief-Filter
  (`feSpecularLighting`) erzeugte an den scharfen Glyphenkanten hochfrequente
  Glanzspitzen, die Safari (rastert SVG-Filter grob) als Rauschen zeigte. Die Höhenkarte
  wird jetzt stärker geglättet (Blur 1.3 statt 0.9) und der Glanz breiter/weicher verteilt
  (specularExponent 9 statt 14, surfaceScale 2.6 statt 3.2) - in Chrome/Firefox weiterhin
  klar geprägt, in Safari deutlich ruhiger.

## 0.56.2-beta.1 (2026-07-20)

- **Kachel: Corona in Safari war nur ein Ring statt Verlauf.** Der Verlauf wurde per
  fill-Attribut gesetzt, während die Stylesheet-Regel `.node-glow { fill: none }` galt -
  CSS schlägt das Attribut, daher zeigte Safari nur den darunterliegenden farbigen
  Kantenring. Der Verlauf wird jetzt als Inline-Style gesetzt (schlägt die Regel in allen
  Browsern); `fill:none` wurde aus der Regel entfernt. Damit erscheint der weiche Hof auch
  in Safari.

## 0.56.1-beta.1 (2026-07-20)

- **Kachel: „halber Inhalt weg" beim Zoomen behoben.** Die SVG-viewBox wurde bisher
  per JS an die jeweilige Kachelgröße nachgezogen. Im Endzustand stimmte das, beim
  laufenden Vergrößern/Verkleinern hinkte es aber einen Frame hinterher - für diesen
  Frame stand ein falsches Seitenverhältnis in einer bereits neu dimensionierten Box
  und der Inhalt sprang klein in eine Ecke. Die viewBox ist jetzt fest und quadratisch;
  das Einpassen macht allein der Browser (`xMidYMid meet`) - identisches Layout, aber
  ohne Zwischenzustand. Verifiziert über breite/hohe/quadratische Kachelformate.
- **Kachel: Statusplakette („Verbunden") als HTML-Overlay.** Sie lag im SVG und musste
  aus der viewBox positioniert werden (was das Nachziehen mitverursachte). Jetzt klebt
  sie per CSS fest in der unteren linken Ecke - unabhängig von der viewBox.
- **Kachel: Corona weicher.** Der radiale Verlauf hatte eine schmale, harte helle Bande
  (wirkte wie eine Kante). Jetzt: Maximum direkt an der Knotenkante mit langem weichem
  Auslauf nach außen - ein glühender Hof statt einer Linie.

## 0.56.0-beta.1 (2026-07-20)

- **Kachel: Corona als Vektor-Verlauf statt CSS-Blur.** Der Leucht-Halo um die Knotenpunkte
  wurde von `filter: blur()` auf echte radiale SVG-Verlaeufe (`<radialGradient>`) umgestellt.
  Blur rastert beim Skalieren der Kachel unsauber (in Safari deutlich sichtbar, teils halber
  Inhalt beim Vergroessern/Verkleinern kurz weg); der Verlauf bleibt vektorscharf. Jeder Knoten
  erhaelt einen eigenen Verlauf, dessen Farbe und Deckkraft weiterhin von der Leistung abhaengen;
  der statische Hauslast-Kreis in der Mitte ist mit umgestellt.

## 0.55.2-beta.1 (2026-07-20)

- **Monitoring: Vollbild zeigte nur grau.** Beim Vergroessern der Kachel (↗) blieb das Diagramm
  leer, weil ECharts nur die Hoehe, nicht die Breite nachzog (Container startet kurz mit Breite 0).
  chart.resize() liest jetzt Breite UND Hoehe frisch; zusaetzlich verzoegertes Nachziehen und ein
  ResizeObserver auf den Chart-Container. Im Browser verifiziert.

## 0.55.1-beta.1 (2026-07-20)

- **Monitoring: Batterie-Leistung invertierbar.** Neuer Schalter "Batterie-Leistung invertieren"
  - dreht das Vorzeichen der Batterie-Leistungskurve, falls Laden/Entladen andersherum als
  gewuenscht erscheint (die Konvention ist je Anlage verschieden). (Beta-Tester-Wunsch.)

## 0.55.0-beta.1 (2026-07-20)

- **Tile: echter Hausverbrauch direkt waehlbar.** Neues Feld "Echter Hausverbrauch (Variable)"
  in der Kachel-Konfiguration - ist es gesetzt, zeigt die Mitte den gemessenen Wert statt der
  rechnerischen Bilanz (unabhaengig von Quell-/Manuell-Modus). Damit laesst sich der originaere
  Hausverbrauch anzeigen, ohne ihn ueber die Reader-Instanz zu konfigurieren. (Beta-Tester-Wunsch.)

## 0.54.1-beta.1 (2026-07-20)

- **Fix: PHP-Notices "fwrite failed" waehrend Scan/Abfrage.** Wenn ein Geraet die (Batch-)
  Verbindung mittendrin kappt (Windows errno 10053), warf das folgende fwrite eine Notice - teils
  mehrfach als Popup. fwrite ist jetzt unterdrueckt; der tote Socket liefert einfach null (kein
  Notice, kein Reconnect-Spam). Betrifft Discovery und Reader-Client.

## 0.54.0-beta.1 (2026-07-20)

- **Discovery: kombinierter Scan (Wechselrichter + Energiezaehler).** Ist zusaetzlich das Modul
  "MeterHub" installiert, erkennt die InverterHub-Suche auch Energiezaehler (Janitza klassisch,
  Janitza UMG 800, Siemens PAC2200) und bietet sie in der Ergebnisliste an - ein Klick auf
  "Erstellen" legt dann eine MeterHub-Instanz an. So findet und installiert man Wechselrichter
  und Zaehler in einem Durchgang. Ohne MeterHub werden Zaehler wie bisher nur uebersprungen.

## 0.53.0-beta.1 (2026-07-20)

- **Discovery: Batch-Modus + Janitza-Ausschluss.** Die Hersteller-Erkennung oeffnet jetzt EINE
  Verbindung je IP fuer alle Probes (statt pro Read eine neue) - damit werden Single-Connection-
  Geraete wie der Sungrow WiNet-S ueberhaupt erst zuverlaessig erkannt. Janitza-Messgeraete
  (19000er-Karte: Frequenz 19050 + Spannung 19000) werden vorab erkannt und uebersprungen, statt
  zufaellig als Wechselrichter (zuletzt Solplanet, davor Deye/SolaX) zu erscheinen. Lese-Schleife
  bricht bei Modbus-Exceptions sofort ab (kein 3-s-Timeout je Fehlprobe).

## 0.52.1-beta.1 (2026-07-20)

- **Sungrow String: Phasenstroeme.** Netz Strom L1/L2/L3 (Register 5021-5023) ergaenzt -
  fuer die grossen CX-Modelle im Netz-Ordner.

## 0.52.0-beta.1 (2026-07-20)

- **Modbus-Client: Batch-Modus (eine Verbindung je Lesezyklus).** Bisher oeffnete jeder Register-
  Read eine eigene TCP-Verbindung. Geraete wie der Sungrow WiNet-S erlauben nur EINE Modbus-
  Verbindung und lehnen schnelle Reconnects ab - dadurch fielen spaetere Reads eines Zyklus aus
  (z. B. Sungrow-String MPPT 4-12 blieben leer). Der Sungrow-Treiber liest jetzt den ganzen
  Zyklus ueber eine offene Verbindung. Zusaetzlich bricht die Lese-Schleife bei einer Modbus-
  Exception sofort ab (kein 3-s-Timeout mehr, z. B. beim String-Erkennungs-Probe auf 13000).

## 0.51.3-beta.1 (2026-07-20)

- **Sungrow String: MPPT 4-12 kamen nicht an.** Der erweiterte MPPT-Block wird jetzt ab dem
  dokumentierten Blockanfang 5100 gelesen (ein Read direkt ab 5114 wird vom WR abgelehnt -->
  MPPT 4-12 blieben 0). Register-Offsets entsprechend angepasst.

## 0.51.2-beta.1 (2026-07-20)

- **Sungrow String: Isolationswiderstand (Riso).** „Isolationsimpedanz gegen Masse" liegt bei
  String-Modellen (SG-CX) auf Register 5070 (nicht 5071). Kommt jetzt korrekt an (z. B. 696 kΩ).

## 0.51.1-beta.1 (2026-07-20)

- **Sungrow String (SG-CX): bis zu 12 MPP-Tracker.** Die großen CX-Modelle haben bis zu 12
  MPPTs. Der String-Pfad liest jetzt MPPT 1-3 (5010-5015) und 4-12 (erweiterter Block
  5114-5136) und legt entsprechend Variablen für MPPT 5-12 an.

## 0.51.0-beta.1 (2026-07-20)

- **Sungrow: String-Wechselrichter (SG-CX / „P2"-Plattform) unterstützt.** Diese Modelle haben
  den 13000er-Hybrid-Registerblock nicht und legen alle Werte im 5000er-Block ab, mit um 1 nach
  unten verschobenen Adressen (Protokoll = Doku − 1) und 32-Bit-Werten mit niederwertigem Wort
  zuerst. Der Treiber erkennt das automatisch (13000-Block liefert eine Modbus-Exception) und
  liest dann den 5000er-Block: DC-/AC-Leistung, MPPT 1-3, Phasenspannungen, Frequenz, Power
  Factor, Blindleistung, Tages-/Gesamtertrag, Isolationswiderstand, Gerätetyp/Nennleistung.
  Hybrid-Modelle (SH) sind unverändert. Adressen an einem SG125CX-P2 verifiziert.

## 0.50.1-beta.1 (2026-07-20)

- **Discovery: Hersteller-Erkennung schonender für Single-Connection-Geräte (Sungrow WiNet-S).**
  Der in 0.49.3 eingeführte Retry wurde wieder entfernt — er verdoppelte die schnellen
  Verbindungen, die der WiNet-S ablehnt, und ließ das Probing hängen. Stattdessen gibt es jetzt
  eine kurze Pause (120 ms) zwischen den Scan-Verbindungen, damit solche Geräte die nächste
  Verbindung wieder annehmen. Der Portscan findet den WiNet-S bereits (schmaler Bereich); dies
  zielt auf die Erkennung danach.

## 0.50.0-beta.1 (2026-07-20)

- **Discovery: zuverlässiger Portscan für schmale Bereiche.** Der schnelle asynchrone Subnetz-
  Scan übersieht unter Windows und bei langsam annehmenden Geräten (z. B. Sungrow WiNet-S) offene
  Ports. Bei Suchbereichen bis 64 Adressen wird jetzt ein blockierender `fsockopen`-Portcheck
  genutzt — langsamer, aber verlässlich. Empfehlung im Formular ergänzt: einen nicht gefundenen
  Wechselrichter über einen schmalen Bereich um seine IP scannen.

## 0.49.4-beta.1 (2026-07-20)

- **Discovery: alte Suchergebnisse werden beim Scan-Start geleert.** Bisher wurde die
  Ergebnisliste erst am Scan-Ende neu geschrieben — bei einem ergebnislosen oder abgebrochenen
  Scan blieben die alten Treffer stehen und wirkten wie neue. Jetzt ist die Liste ab dem Klick
  auf „Netzwerk durchsuchen" sofort leer.

## 0.49.3-beta.1 (2026-07-20)

- **Discovery: robuster gegen „zickige" Modbus-Server (Sungrow WiNet-S).** Die Scan-Reads
  wiederholen bei Fehlschlag einmal mit 150 ms Pause. Geräte wie der Sungrow WiNet-S lehnen bei
  schnell aufeinanderfolgenden Verbindungen (während des Hersteller-Probings) gelegentlich eine
  Verbindung ab und akzeptieren die nächste kurz danach — dadurch wurden sie mal erkannt, mal
  nicht. Der Retry stabilisiert die Erkennung.

## 0.49.2-beta.1 (2026-07-20)

- **Discovery: Deye-Erkennung verschärft (weniger Fehlerkennungen).** Der Deye-Probe verlangt
  jetzt zusätzlich die Deye/Sunsynk-Seriennummer als ASCII in Holding 3-7. „Holding 0 > 0 +
  Holding 500 lesbar" allein matchte zu leicht fremde Geräte (real gemeldet: ein Gerät wurde
  fälschlich als Deye erkannt, obwohl dessen Register 3-7 leer sind).

## 0.49.1-beta.1 (2026-07-20)

- **Discovery: „Scan abbrechen" nur während des Scans sichtbar.** Der Abbrechen-Button
  erscheint erst mit dem Scan-Start (der Start-Button wird solange ausgeblendet); nach dem
  Ende/Abbruch stellt sich die Ausgangslage automatisch wieder her.

## 0.49.0-beta.1 (2026-07-20)

- **Discovery: Netzwerk-Scan abbrechbar.** Neben „Netzwerk durchsuchen" gibt es jetzt „Scan
  abbrechen". Ein laufender Scan (Portscan wie Hersteller-Prüfung) hält daraufhin an und zeigt
  die bis dahin gefundenen Wechselrichter. Umgesetzt über eine versteckte, thread-sichere
  Abbruch-Flagge, die die Scan-Schleifen prüfen.

## 0.48.1-beta.1 (2026-07-20)

- **Verbindung: Hostname statt IP möglich.** Im Feld „IP-Adresse oder Hostname" kann jetzt auch
  ein DNS-Name/mDNS (z. B. `wr-fronius.local`) eingetragen werden – die Verbindung wird per
  `fsockopen` aufgelöst. Bei fester DHCP-Reservierung/Hostname läuft die Instanz so auch nach
  einem IP-Wechsel des Wechselrichters weiter. (Vorschlag aus dem Beta-Test.)

## 0.48.0-beta.1 (2026-07-20)

- **Fronius: Smart-Meter-Energiezähler lieferten 0 Wh (Float-Meter).** Bei den Float-Metern
  (SunSpec-Modelle 211/213, u. a. neuere Fronius/GEN24) wurden versehentlich die Per-Phase-
  Register `TotWhExpPhA`/`TotWhImpPhA` (Offset 60/68) statt der Gesamtzähler `TotWhExp`/`TotWhImp`
  (Offset 58/66) gelesen — viele Meter füllen die Per-Phase-Register nicht, daher exakt 0 Wh.
  Offsets korrigiert; Bezug/Einspeisung gesamt kommen nun an.
- **Variablen-Profile sprangen bei „Übernehmen" auf „Legacy" zurück.** Ab IPS 7 leert eine vom
  Nutzer gewählte Presentation („Wertanzeige") das CustomProfile; das Modul setzte es bei jedem
  ApplyChanges neu und erzwang so wieder „Legacy". Profile werden jetzt **nur bei Neuanlage**
  einer Variable gesetzt (und gezielt beim Umschalten des Wh-Schalters). Die Wh-Skalierung hängt
  nicht mehr am Profil, sondern am Ident — eine geänderte Presentation hebelt sie nicht mehr aus.

## 0.47.3-beta.1 (2026-07-20)

- **Monitoring: Highcharts-Zoom gehärtet.** Veralteten `zoomType` entfernt (kollidiert in neuen
  Versionen mit `zooming`), kanonische `zooming: {type:'x', mouseWheel}` beibehalten und Pan
  (Shift+Ziehen) ergänzt. Zoom per Ziehen über die Zeitachse verifiziert; Reset-Button oben
  rechts.

## 0.47.2-beta.1 (2026-07-20)

- **Monitoring: SOC-Glättung verstärkt.** Glättungsfenster von 7 auf 15 Punkte (≈ 75 min)
  erhöht — die SOC-Kurve läuft nun deutlich ruhiger.

## 0.47.1-beta.1 (2026-07-20)

- **Monitoring: Batterie-SOC geglättet.** Statt des reinen Integer-Rundens werden „%"-Kurven
  (z. B. SOC) im Tagesverlauf per gleitendem Mittelwert (7 Punkte ≈ 35 min) geglättet — das
  BMS-typische Zacken-Rauschen (±1–2 %) verschwindet, der Verlauf bleibt erhalten.

## 0.47.0-beta.1 (2026-07-20)

- **Monitoring: Diagramme zoombar.** Highcharts: mit der Maus über die x-Achse ziehen oder
  Mausrad zum Zoomen, „Reset zoom"-Button erscheint automatisch. ECharts: Mausrad zum Zoomen,
  Ziehen zum Verschieben, Doppelklick setzt zurück. Zoom betrifft die Zeitachse (x).

## 0.46.1-beta.1 (2026-07-20)

- **Monitoring: Steuerung wieder klickbar.** Die zentrierte Steuerung lag im Titelband, wo die
  IPS-Kachel-Kopfzeile die Klicks abfängt. Sie sitzt jetzt horizontal zentriert direkt unter
  dem Titel (im klickbaren Inhaltsbereich).

## 0.46.0-beta.1 (2026-07-20)

- **„Was ist neu"-Banner in allen Modulen.** Nach einem Update erscheint oben im
  Konfigurationsformular jedes Moduls (InverterHub, Tile, Discovery, Energiefluss, Monitoring)
  ein aufgeklapptes Banner mit den wichtigsten Änderungen und dem Hinweis, die Einstellungen zu
  prüfen. „Verstanden – nicht mehr anzeigen" blendet es aus (Attribut `SeenNews` je Instanz);
  bei der nächsten News-Version erscheint es erneut. Neuinstallationen sehen es einmalig.

## 0.45.3-beta.1 (2026-07-20)

- **Monitoring: SOC/Prozent-Kurven ohne Nachkommastellen.** Werte mit Einheit „%" (z. B.
  Batterie-SOC) werden im Tagesverlauf auf ganze Zahlen gerundet — kein Nachkomma-Zittern mehr.
- **Monitoring: Steuerelemente mittig auf Titelhöhe.** Ansichts-/Zeitraum-Steuerung sitzt jetzt
  horizontal zentriert oben auf Höhe des Kacheltitels statt links unter dem Titel.

## 0.45.2-beta.1 (2026-07-20)

- **Monitoring: ausgeblendete Kurven bleiben dauerhaft gemerkt.** Die per Legende gewählte
  Sichtbarkeit wird jetzt zusätzlich je Instanz im `localStorage` gespeichert und beim Laden
  wiederhergestellt — die Auswahl übersteht nun auch ein Neuladen der Kachel im WebFront, nicht
  mehr nur Zeitraum-/Reiterwechsel innerhalb einer Sitzung.

## 0.45.1-beta.1 (2026-07-20)

- **Monitoring: Reiter reduziert & MPP-Tracker-Farben.** Die Reiter „Leistung & Energie" und
  „Diagnose" wurden vorerst entfernt (verbleibend: PV & Einstrahlung, MPP-Tracker, Batterie).
  Werte, deren Gruppe keinen Reiter mehr hat, werden automatisch nicht mehr angeboten/angezeigt.
  Die MPP-Tracker sind jetzt kräftig eingefärbt (Rot/Grün/Blau/Orange), die zugehörigen
  berechneten Erwartungswerte in helleren Tönungen und gestrichelt (Soll vs. Ist).

## 0.45.0-beta.1 (2026-07-20)

- **Monitoring: „Was ist neu"-Hinweis nach einem Update.** Beim Öffnen der Instanz erscheint
  oben im Konfigurationsformular ein aufgeklapptes Banner mit den wichtigsten Änderungen und
  dem Hinweis, die Einstellungen zu prüfen. Ein Klick auf „Verstanden – nicht mehr anzeigen"
  blendet es aus (gemerkt über das Attribut `SeenNews`); bei der nächsten News-Version erscheint
  es erneut. Neuinstallationen sehen es einmalig als Kurzüberblick.

## 0.44.1-beta.1 (2026-07-20)

- **Monitoring: robuste PVF-Anbindung für andere Installationen.** Die Generatorparameter werden
  jetzt bevorzugt über den stabilen Getter `PVF_GetGenerators($id)` des Prognose-Moduls bezogen
  (versionsunabhängiger Vertrag); nur ersatzweise fällt der Monitor auf das Lesen der internen
  Properties (`IPS_GetConfiguration`) zurück. Fehlt das Prognose-Modul oder hat es keine
  Generatoren, entfallen die Erwartungslinien fehlerfrei. Benötigt Prognose-Modul ≥ build 41 für
  den Getter (ältere Versionen nutzen den Fallback).

## 0.44.0-beta.1 (2026-07-20)

- **Monitoring: berechnete Erwartungswerte aus Einstrahlung × Generatorparametern.** Aus der
  PV-Prognose (PVF) werden Performance-Ratio (`PVF_PR`) und je Generator kWp + Faktor gelesen.
  Mit dem Einstrahlungssensor ergibt sich die **erwartete Leistung = kWp × Einstrahlung × PR ×
  Faktor** (bzw. die erwartete Energie über die Tages-Insolation). Diese Kurven werden
  **gestrichelt** dargestellt (Soll), die Messwerte durchgezogen (Ist) — Abweichung nach unten =
  Verschmutzung/Verschattung/Defekt.
  - **PV & Einstrahlung**: PV-Erzeugung (Ist) · „PV erwartet" (Soll, gesamter Generator) ·
    Einstrahlung.
  - **MPP-Tracker** (früher „PV & Strings"): nur noch die MPP-Tracker (Ist) und die berechneten
    Erwartungswerte je Generator (Soll). Reine PV-/Inverter-Summen dort entfernt.

## 0.43.2-beta.1 (2026-07-20)

- **Monitoring: Hinweis aufs Prognose-Modul beim Einstrahlungssensor.** Ist ein
  Einstrahlungssensor gewählt, prüft das Konfigurationsformular, ob das Modul „PV-Prognose"
  (Suite EnergiePrognose) installiert ist und eine Gesamt-Modulfläche liefert
  (`PVF_GetModuleArea`). Fehlt es, fehlt die Fläche oder ist das Modul zu alt, erscheint ein
  Hinweis; ist die Fläche vorhanden, wird sie bestätigt — Grundlage für die spätere
  spez.-Leistungs-/Performance-Ratio-Auswertung (Verschmutzungs-/Defekterkennung).

## 0.43.1-beta.1 (2026-07-20)

- **Monitoring: Tooltip & Achsen verbessert.** Der Tooltip zeigt jetzt das vollständige Datum
  (inkl. Jahr) und die Einheit hinter jedem Wert. Die linke Achse im Tagesverlauf ist in kW
  (statt W) für eine besser lesbare Skala. Die rechte Achse (z. B. Einstrahlung in W/m²) wird
  eigenständig skaliert und beschriftet, sodass PV-Leistung und Einstrahlung sauber
  nebeneinander ablesbar sind.

## 0.43.0-beta.1 (2026-07-20)

- **Monitoring: Zeiträume Woche, Gesamt und Benutzerdefiniert ergänzt.** Zusätzlich zu Tag/
  Monat/Jahr gibt es jetzt „Woche (Energie)" (Tages-Balken Mo–So, ◄ ► über 26 Wochen bzw.
  Wochen-Auswahl), „Gesamt (Energie)" (ein Balken je Jahr seit Beginn der Aufzeichnung) und
  „Benutzerdefiniert" (frei wählbarer Von–Bis-Bereich per zwei Kalenderfeldern, Tages-Balken).
- **Energie-Berechnung vereinheitlicht.** Alle Energie-Ansichten leiten sich jetzt aus einer
  gemeinsamen Tages-Basis ab (ein Archivdurchlauf je Wert statt separater Monats-/Jahres-
  Abfragen) — schneller und konsistent. Die automatische Zählertyp-Erkennung (Lifetime vs.
  Tagesreset) bleibt.

## 0.42.1-beta.1 (2026-07-20)

- **Monitoring: eigener Reiter „PV & Einstrahlung".** Fokus-Ansicht nur mit PV-Erzeugung
  (Knallrot, linke Achse W) und Einstrahlungssensor (Sonnengelb, rechte Achse W/m²) - die
  eigentliche Verschmutzungs-/Defekterkennung, ohne störende Nebenkurven. Der Reiter
  „PV & Strings" bleibt für die MPPT-Details. AC-Wirkleistung wechselt die Farbe (Violett),
  damit sie nicht mehr mit dem jetzt knallroten PV kollidiert.

## 0.42.0-beta.1 (2026-07-20)

- **Monitoring: seitliche Reiter statt Werte-Überlagerung.** Bisher trafen alle Kurven auf
  einer Achse zusammen — Werte mit anderen Einheiten (SOC %, Einstrahlung W/m², Temperatur °C,
  Isolationswiderstand kΩ) wurden dabei unbrauchbar an den Rand gequetscht. Die Werte sind
  jetzt in **thematische Reiter links** gruppiert, jeder mit passenden Achsen:
  - **Leistung & Energie** — PV, Verbrauch, Netzbezug/Einspeisung, Netzleistung, AC, Batterie
    laden/entladen (W bzw. kWh).
  - **PV & Strings** — PV, MPPT 1-4, Inverter gesamt und der Einstrahlungssensor (W links,
    W/m² rechts) — die Ansicht für die Verschmutzungs-/Defekterkennung.
  - **Batterie** — Batterie-Leistung/SOC.
  - **Diagnose** — Modultemperatur (°C) und Isolationswiderstand (kΩ).
  Ein Reiter erscheint nur, wenn er belegte Werte hat; Reiter ohne sinnvolle Energie (Diagnose)
  zeigen nur den Tagesverlauf. Die Zeitraum-Steuerung (Tag/Monat/Jahr, ◄ ►, Kalender) bleibt oben.

## 0.41.1-beta.1 (2026-07-20)

- **Monitoring: ausgeblendete Kurven bleiben erhalten.** Beim Datums-/Zeitraum- oder
  Ansichtswechsel wird die per Legende gewählte Sichtbarkeit der Kurven nicht mehr
  zurückgesetzt (gilt für Highcharts und ECharts).

## 0.41.0-beta.1 (2026-07-20)

- **Monitoring: Werte ankreuzen statt Tabelle pflegen.** Die Kurven-Tabelle entfällt. Man
  wählt jetzt oben die **InverterHub-Instanz** als Quelle; darunter erscheinen nur die dort
  tatsächlich vorhandenen, archivierten Werte zum **Ankreuzen** (PV-Erzeugung, Verbrauch,
  Netzbezug, Einspeisung, Batterie laden/entladen/Leistung/SOC, MPPT 1-4, AC-Wirkleistung,
  Modultemperatur, Isolationswiderstand …). **Farbe, Achse und Einheit sind je Wert
  voreingestellt** - wichtige Kurven (PV = Gold, Bezug = Blau, Einspeisung = Grün …) sind
  vorbelegt. Ein externer Einstrahlungssensor (W/m²) bleibt separat wählbar.
- **Energie-Ansichten korrigiert (Stufe 2 war leer).** Monat/Jahr nutzen jetzt die echten
  **Energiezähler** aus dem InverterHub über den Zähler-Zuwachs statt einer Ableitung. Der
  Zählertyp wird automatisch erkannt: Lifetime-Zähler (z. B. „PV Gesamt") → Tag-zu-Tag-
  Zuwachs, Tagesreset-Zähler (z. B. „Bezug Heute") → Max−Min je Tag. Damit verschwindet der
  frühere Fehler, bei dem der kumulierte Zählerstand als Leistung interpretiert und zu
  absurden Balken (~253 000 kWh) führte. Reine Leistungswerte (z. B. MPPT) werden weiterhin
  integriert.

## 0.40.0-beta.1 (2026-07-19)

- **Monitoring Stufe 2: Wochen-/Monats-/Jahres-Ansicht (Energie-Balken).** Die Monitoring-
  Kachel hat jetzt einen Ansichts-Umschalter (wie beim Sankey): „Tag (Verlauf)" zeigt die
  Intraday-Zeitreihe, „Monat (Energie)" die Tages-Energie als Balken über den Monat, „Jahr
  (Energie)" die Monats-Energie als Balken über das Jahr. Die Energie wird direkt aus der
  Leistungskurve abgeleitet (Ø-Leistung × Zeit) - es sind keine separaten Zählervariablen
  nötig; eine Einstrahlungskurve wird zur Einstrahlungssumme (kWh/m²). Navigation je Typ per
  ◄ ► und Kalender/Monats-Picker/Jahres-Auswahl (letzte 8 Tage / 12 Monate / 5 Jahre).


## 0.39.1-beta.1 (2026-07-19)

- **Monitoring: X-Achse auf das Produktionsfenster begrenzt.** Statt der vollen 24 Stunden
  zeigt das Verlaufsdiagramm jetzt nur den Zeitraum mit PV-Produktion (Sonnenaufgang bis
  -untergang) plus je 1 Stunde davor und danach. Das Fenster wird automatisch aus den Kurven
  ermittelt, die nachts auf ~0 fallen (Leistung/Einstrahlung); Kurven wie Temperatur oder
  Spannung beeinflussen es nicht.


## 0.39.0-beta.1 (2026-07-19)

- **Neues Modul: InverterHub Monitoring (Stufe 1).** Monitoring-Kachel mit Intraday-Zeitreihen
  aus dem IP-Symcon-Archiv (à la Meteocontrol VCOM „Tatsächliche Leistung"): beliebige
  archivierte Variablen als Verlaufsdiagramm über einen wählbaren Tag (~5-Minuten-Auflösung),
  wahlweise mit Highcharts oder ECharts. Zwei Y-Achsen — z. B. PV-Leistung links und ein
  Einstrahlungssensor (W/m²) rechts; laufen beide proportional, ist die Anlage sauber, weicht
  die Leistung nach unten ab, deutet das auf Verschmutzung/Defekt hin. Bedienung wie die
  Sankey-Kachel (◄ ► + Kalender zur Tagesauswahl, letzte 8 Tage). Weitere Ansichten
  (Wochen/Monat/Jahr, Energie-Balken, normalisierte KPIs mit kWp-Kalibrierung, automatische
  Verschmutzungs-/Defekterkennung) folgen als nächste Stufen.


## 0.38.1-beta.1 (2026-07-19)

- **Fronius: Smart-Meter-Energiezähler (Bezug/Einspeisung gesamt).** Neue Gruppe „Smart Meter
  Energie" liefert die kumulierten kWh-Zählerstände des Smart Meters — „Bezug Gesamt"
  (TotWhImp = EnergyReal_WAC_Sum_Consumed) und „Einspeisung Gesamt" (TotWhExp =
  EnergyReal_WAC_Sum_Produced), z. B. für die Energiekosten-Abrechnung. Gelesen über die
  Smart-Meter-Adresse (Unit-ID, z. B. 240).
- **Variablen-Darstellung bleibt beim „Übernehmen" erhalten.** Das Custom-Profil einer Variable
  wird jetzt nur noch gesetzt, wenn es sich tatsächlich ändert. Dadurch wird eine vom Nutzer
  gewählte (neue) Darstellung nicht mehr bei jedem „Übernehmen" auf die Profil-Vorgabe
  zurückgesetzt (gilt für alle Hersteller).


## 0.38.0-beta.1 (2026-07-19)

- **Victron: PV je Solarladeregler / MPPT.** Auf Wunsch eines Beta-Testers lassen sich die
  einzelnen Solarladeregler (MPPTs) getrennt erfassen. Jeder MPPT ist bei Victron ein eigenes
  Modbus-Gerät mit eigener Unit-ID (im GX unter Einstellungen → Services → Modbus TCP →
  verfügbare Dienste ablesbar). Im Datenpunkte-Panel die Unit-IDs kommagetrennt eintragen
  (max. 4); die neue Gruppe „PV je Solarladeregler / MPPT" liefert dann je Regler Leistung
  (Reg 789), Spannung (776) und Zustand (775: Bulk/Absorption/Float …). Register aus Victrons
  offizieller attributes.csv.


## 0.37.2-beta.1 (2026-07-19)

- **Kostal: Batterie-Leistung und Batterie-Zustand ergänzt.** Auf Wunsch eines Beta-Testers
  liest der Kostal-Treiber jetzt zusätzlich „Bat. Leistung" (Reg 582, SInt16 in W — Vorzeichen
  wie der Batteriestrom: + Entladen / − Laden) und den daraus abgeleiteten „Bat. Zustand"
  (Ruhe/Laden/Entladen). Register aus der KostalKore-Registerliste. (Die Byte-Reihenfolge CDAB
  wurde vom Tester am Gerät bestätigt — sie ist bereits Standard.)


## 0.37.1-beta.1 (2026-07-19)

- **Energiefluss: Datumsauswahl als Kalender.** Statt der Dropdown-Liste zeigt die Kachel
  jetzt ein echtes Kalender-Widget zur Auswahl — Tag als Datums-Kalender, Woche als
  Wochen-Kalender, Monat als Monats-Kalender (native Browser-Kalender-Popups); das Jahr bleibt
  ein Dropdown. Die Kalenderauswahl ist auf das navigierbare Fenster begrenzt (min/max) und
  passt sich dem Hell-/Dunkelmodus an.


## 0.37.0-beta.1 (2026-07-19)

- **Energiefluss: Zeitraum-Navigation in der Kachel.** Neben der Typ-Auswahl (Tag/Woche/Monat/
  Jahr/Gesamt) gibt es jetzt **Vor/Zurück (◄ ►)** um jeweils einen Schritt sowie eine
  **gezielte Auswahl** eines konkreten Tages/einer Woche/eines Monats/eines Jahres. Navigierbar
  sind die letzten ~31 Tage, 26 Wochen, 24 Monate und 6 Jahre. Die Perioden werden
  serverseitig vorab berechnet (mit Boundary-Cache für die Archiv-Zugriffe); das Umschalten und
  Blättern erfolgt ohne Server-Rückfrage. Neuberechnung nur alle 2 Minuten (historische Werte
  ändern sich nicht).

## 0.36.3-beta.1 (2026-07-19)

- **Energiefluss: Archiv-Auswertung korrigiert (kritisch).** Die Perioden-Energie wurde über
  aggregierte Mittelwerte (`Avg`) summiert — das stimmt nur für Variablen mit Archiv-
  Aggregation „Zähler". Bei „Standard"-Aggregation (wie z. B. bei den PV-/Batterie-Zählern des
  Testers) lieferte das völlig falsche Werte (Durchschnittswert × Buckets statt Energie, z. B.
  253.000 statt 33,7 kWh Tagesertrag). Die Auswertung nutzt jetzt die **Zähler-Differenz**
  (Stand am Periodenende − Stand am Periodenanfang, aus den Archiv-Roh-/Loggwerten). Das
  funktioniert unabhängig von der Aggregationsart und wurde live gegen reale Zähler
  gegengeprüft (Tag/Woche/Monat/Jahr korrekt). „Gesamt" reicht so weit zurück, wie Roh-
  Messwerte vorgehalten werden.

## 0.36.2-beta.1 (2026-07-19)

Energiefluss-Kachel nach Webfront-Test:

- **Oberer Abstand für den IPS-Instanznamen** — die Kopfzeile (Zeitraum-Auswahl/Datum) lag
  unter dem von IP-Symcon eingeblendeten Instanznamen und wurde davon überlagert; dadurch
  ließ sich das Zeitraum-Dropdown auch nicht bedienen (Klicks wurden abgefangen). Jetzt bleibt
  oben Platz frei — Titel, Dropdown und Diagramm überlappen nicht mehr, die Umschaltung
  funktioniert.
- **kWh-Werte am Knoten sichtbar** — jeder Knoten zeigt jetzt zusätzlich zum Namen seinen
  Energiewert (z. B. „Solar 21,8 kWh"), nicht mehr nur im Tooltip.

## 0.36.1-beta.1 (2026-07-19)

Energiefluss-Kachel nach Rückmeldung:

- **Eigener Kachel-Titel entfernt** — er überlagerte den von IP-Symcon angezeigten
  Instanznamen. Kopfzeile enthält jetzt nur noch die Zeitraum-Auswahl und das Datum.
- **Inhalt skaliert mit der Kachel** — das Diagramm füllt die verfügbare Widget-Höhe
  automatisch (statt fester Höhe) und passt sich bei Größenänderung an (ResizeObserver).
- **Zeitraum im Webfront umschaltbar** — Auswahl (Tag/Woche/Monat/Jahr/Gesamt, ggf.
  Angepasst) direkt in der Kachel per Dropdown. Alle Zeiträume werden serverseitig
  vorberechnet (Archiv), die Umschaltung erfolgt ohne Server-Rückfrage. Die Neuberechnung
  läuft gebündelt per 60-s-Timer (statt bei jeder Zähleränderung).

## 0.36.0-beta.1 (2026-07-19)

- **Energiefluss-Kachel: 3-stufiges Sankey mit Highcharts & ECharts.** Statt des eigenen
  SVG-Renderers nutzt die Kachel jetzt — wie die Prognosekachel — wahlweise **Apache ECharts**
  oder **Highcharts** (umschaltbar per „Diagramm-Engine"). Neue **3-stufige** Darstellung
  (Variante B): Erzeugung/Bezug → **Batterie als Puffer** (ein Knoten mit Zufluss = Ladung,
  Abfluss = Entladung) → Verbrauch/Einspeisung. Damit taucht die Batterie nicht mehr doppelt
  links und rechts auf, sondern ist als Zwischenspeicher sichtbar. **Interaktive Tooltips**
  (obligatorisch) zeigen je Knoten Durchsatz/Anteil (bei der Batterie zusätzlich
  „geladen / entladen") und je Fluss Quelle → Ziel mit kWh und Anteil. Höhe konfigurierbar.

## 0.35.0-beta.1 (2026-07-19)

- **Neues Modul: InverterHub Energiefluss (Sankey).** Zeigt als Sankey-Diagramm, wohin die
  Energie über einen wählbaren Zeitraum geflossen ist — Quellen (Solar, Batterie-Entladung,
  Netzbezug) links, Verbraucher (Batterie-Ladung, Hausverbrauch bzw. Einzelverbraucher,
  Netzeinspeisung) rechts, jeweils mit Anteil in %. Zeitraum: Tag/Woche/Monat/Jahr/Gesamt oder
  angepasst (Von/Bis). Die Werte kommen aus dem **IP-Symcon-Archiv** der zugewiesenen
  Energie-Zählervariablen (AC_GetAggregatedValues) — nichts wird selbst berechnet oder
  zusätzlich mitgeführt. Einzelverbraucher (Wärmepumpe, Wallbox …) werden aus dem
  Hausverbrauch herausgelöst; der Rest erscheint als „Sonstiger Verbrauch". Die Aufteilung der
  Flüsse folgt einem Energiebilanz-Modell (Netzeinspeisung/Batterie-Ladung aus PV; Verbrauch
  anteilig aus PV/Batterie/Netz).

## 0.34.1-beta.1 (2026-07-18)

- **Kachel-Hilfe aktualisiert.** Das Panel „Dokumentation & Hilfe" der Kachel beschreibt jetzt
  beide Datenquellen-Varianten (InverterHub-Instanz bzw. manuelle Datenpunkte), die
  Hauslast-Bilanz/Verluste, die Ausgabe „Hauslast (berechnet)" und die automatische
  Rückrechnung der Invers-Schalter. Veraltete Angaben (feste „Diamant-Anordnung") korrigiert.

## 0.34.0-beta.1 (2026-07-18)

- **Kachel: manueller Datenpunkt-Modus (ohne InverterHub-Instanz).** Ist im Panel „Datenquelle"
  keine InverterHub-Instanz gewählt, speist sich die Kachel aus dem neuen Panel „Manuelle
  Datenpunkte": einzeln zuweisbare Variablen für PV-Leistung, AC-Wirkleistung,
  Netz-/Zählerleistung, Batterie-Leistung, SOC und optional einen externen Hauslastzähler.
  Je Leistungswert ist die Einheit wählbar (Automatisch/W/kW/MW); Netz und Batterie haben je
  einen eigenen Invers-Schalter. Damit ist die Energiefluss-Kachel auch mit Werten anderer
  Module/Zähler nutzbar. Alle bisherigen Funktionen (Hauslast-Bilanz, Verluste, berechnete
  Hauslast-Variable, Verbraucher, Fahrzeuge) gelten im manuellen Modus gleichermaßen.

## 0.33.1-beta.1 (2026-07-18)

- **Fronius: Vorzeichen der Smart-Meter-Leistung je Phase korrigiert.** Die Phasen-Leistung
  wurde fälschlich gedreht und stand dadurch entgegengesetzt zum (korrekten) Phasenstrom
  (physikalisch unmöglich bei P = U·I). Sie wird jetzt im Roh-Vorzeichen des Zählers geführt –
  konsistent zum Phasenstrom und passend zur Fronius-Anzeige. Der Gesamtwert
  („Netz Leistung (Meter)") war korrekt und bleibt unverändert.

## 0.33.0-beta.1 (2026-07-18)

- **Neu: Energie-Ausgabe wahlweise in Wh statt kWh.** Schalter „Energie in Wh statt kWh
  ausgeben" (Datenpunkte-Panel). Aktiviert, werden alle Energiewerte (Ertrag, Bezug,
  Einspeisung, Ladung/Entladung, Hausverbrauch usw.) in der Basiseinheit Wh geführt –
  konsistent zur Leistung (W); die neue IP-Symcon-Darstellung skaliert dann selbst auf
  Wh/kWh/MWh und nutzt das lokale Dezimaltrennzeichen. Standard bleibt kWh, damit bestehende
  Instanzen keinen Sprung in der Historie bekommen. Die Umstellung erfolgt zentral (Wh-Profil
  „IHB.Wh" + Wert ×1000), unabhängig vom Hersteller.

## 0.32.4-beta.1 (2026-07-18)

- **Klarere Benennung Hauslast (Eingang vs. Ausgang).** Das Feld in der Wechselrichter-Instanz
  heißt jetzt „Externer Hauslastzähler — Eingang (optional)" und weist explizit darauf hin,
  dass es ein *Eingang* für einen gemessenen Wert ist (echter Verbrauchszähler, immer positiv)
  — im Unterschied zur in der Kachel optional *ausgegebenen* Variable „Hauslast (berechnet)".
  Ein Querverweis nennt direkt, wo der berechnete Wert ausgegeben wird.

## 0.32.3-beta.1 (2026-07-18)

- **Dokumentation & In-Modul-Hilfe auf den aktuellen Stand gebracht.** Die Hilfe im
  Instanz-Formular listet jetzt alle 13 Hersteller inkl. Anschluss-Besonderheiten (Kostal
  Port 1502/Byte-Reihenfolge, Victron Unit-ID 100, Huawei Unit-ID 1, Fronius Smart-Meter-
  Adresse, SolaX-Gateway), die modulweite Vorzeichen-Konvention samt Invers-Schaltern und die
  Riso-Verfügbarkeit. README: Treiberliste vollständig, SolarEdge-/Fronius-Umfang aktualisiert,
  Hinweise zu Invers-Schaltern (Kachel-Rückrechnung), Hauslastzähler und der neuen Variable
  „Hauslast (berechnet)" ergänzt.

## 0.32.2-beta.1 (2026-07-18)

- **Kachel: berechnete Hauslast optional als Variable.** Neuer Schalter „Berechnete Hauslast
  zusätzlich in eine Variable schreiben" (im Panel „Datenquelle"). Ist er aktiv, legt die
  Kachel die Variable „Hauslast (berechnet)" (W) an und aktualisiert sie bei jeder
  Datenänderung — nutzbar für Automationen, Charts usw.

## 0.32.1-beta.1 (2026-07-18)

- **Kachel: Schalter „Netz-Leistung invertieren" wird jetzt berücksichtigt.** Wer die
  Meter-Leistung im Wechselrichter-Modul umdreht (z. B. für die Konvention Einspeisung =
  negativ), bekam bisher in der Kachel eine falsche Netz-Flussrichtung und eine überhöhte
  Hauslast. Die Kachel rechnet den Invers-Schalter nun intern wieder auf ihre kanonische
  Konvention (+ = Einspeisung) zurück — analog zum bereits vorhandenen Batterie-Invers-
  Schalter. Datenpunkt-Vorzeichen und Kachel stimmen damit unabhängig voneinander.

## 0.32.0-beta.1 (2026-07-18)

Nach Fronius-Beta-Wünschen (zusätzliche Messwerte):

- **Fronius: PV-Strom je String** (MPPT1/MPPT2 Strom aus Model 160 DCA) in der Gruppe
  „PV-Details".
- **Fronius: Batteriespannung** (aus dem DCV der Speichermodule in Model 160) in der Gruppe
  „Batterie".
- **Fronius: Smart Meter je Phase** — neue Gruppe „Smart Meter je Phase" mit Spannung, Strom
  und Leistung pro Phase (L1/L2/L3), gelesen aus dem SunSpec-Meter-Model (Int-20x mit
  Skalierungsfaktor oder Float-21x). Leistungs-Vorzeichen wie beim Gesamtwert
  (positiv = Einspeisung).

## 0.31.4-beta.1 (2026-07-18)

- **Kachel: negativen Hauslastzähler abfangen.** Wird als „Hauslastzähler" versehentlich eine
  Variable gewählt, die negativ werden kann (z. B. ein Netz-/Einspeisezähler statt eines
  echten Hausverbrauchszählers), zeigte die Kachel eine negative Hauslast und daraus absurde
  „Wandlungsverluste". Ein Hausverbrauch ist nie negativ — solche Werte werden jetzt ignoriert
  und die Kachel bleibt bei der berechneten Bilanz-Hauslast (keine Verluste-Anzeige).

## 0.31.3-beta.1 (2026-07-18)

Nach Victron-Beta-Test:

- **Victron: PV und Batterie kamen nicht an** (nur Netz/Verbrauch). Ursache: Der Batterie-/
  DC-PV-Block wurde in einem Rutsch von Reg 840 bis 850 gelesen — dieser Bereich überspannt
  die reservierten Register 845–849, was manche GX-Firmwares mit einer Modbus-Exception
  quittieren und damit den gesamten Block scheitern lassen. Jetzt werden die DC-PV-Leistung
  (Reg 850) und der Batterieblock (840–844) getrennt gelesen; PV-Gesamtleistung, SOC,
  Batterieleistung/-spannung/-strom und -zustand kommen damit an.

## 0.31.2-beta.1 (2026-07-18)

Nach SolarEdge-Kachel-Rückmeldung:

- **SolarEdge: Batterie-Vorzeichen auf Modul-Konvention korrigiert.** SolarEdge meldet
  Batterie-Leistung/-Strom mit + = Laden (0xE174/0xE172); das Modul nutzt aber durchgängig
  + = Entladen / − = Laden. Dadurch zeigte die Kachel die Lade-/Entlade-Pfeile verkehrt
  herum. Leistung und Strom werden jetzt negiert → korrekte Flussrichtung. (Wer die
  Datenpunkt-Werte lieber in der anderen Konvention möchte, nutzt den Schalter
  „Batterie-Leistung invertieren" — die Kachel bleibt dank interner Rückrechnung korrekt.)
  Die berechnete PV-Erzeugung wurde entsprechend angepasst (PV-Gesamt + Batterie-
  Ladeleistung), sodass ihr Ergebnis unverändert bleibt.
- **Kachel: berechnete PV-Erzeugung (`pv_real`) hat Vorrang vor `pv_total`.** Ist die
  optionale PV-Erzeugung aktiv (z. B. SolarEdge StorEdge), zeigt die Kachel diesen Wert statt
  der DC-Leistung, die bei Batteriebetrieb nicht die reine PV abbildet.

## 0.31.1-beta.1 (2026-07-18)

- **Fronius: Batterie-SOC jetzt mit einer Nachkommastelle** (Float statt Integer). Fronius
  liefert den SOC ohnehin als Float (Model 124 ChaState) — so sieht man schneller, in welche
  Richtung die Ladung läuft. Bestehende Integer-SOC-Variablen werden beim Übernehmen
  automatisch auf Float migriert (neue allgemeine Typ-Migration in `RegisterVar`: ändert sich
  der gewünschte Variablentyp, wird die Variable neu angelegt, da IPS den Typ nicht
  nachträglich ändern kann).

## 0.31.0-beta.1 (2026-07-18)

- **Isolationswiderstand (Riso) für Sungrow, SMA und Kostal ergänzt.** Damit lesen jetzt
  GoodWe, Huawei, Sungrow, SMA und Kostal den für die PV-Sicherheit wichtigen Riso-Wert.
  Register je Hersteller gegen gepflegte Fremdquellen verifiziert:
  - Sungrow: Input-Register 5071 (U16, kΩ).
  - SMA: Holding 30225 aus dem proprietären SMA-Profil (uint32 in Ohm → kΩ; Sentinel
    0xFFFFFFFF „nicht verfügbar" wird übersprungen).
  - Kostal: Holding 120 (Float32 in Ohm → kΩ, mit der eingestellten Byte-Reihenfolge).
  - Growatt: optionale Gruppe „Isolationswiderstand (modellabhängig)" (Input-Register 200,
    kΩ). Laut Quelle nur bei manchen Modellen belegt (z. B. MIC 600TL-X bestätigt), daher
    opt-in und klar gekennzeichnet — bitte am eigenen Modell auf Plausibilität prüfen.
  - Für Solis, SolaX, Deye und Solplanet ist über die geprüften Modbus-Quellen kein
    zuverlässiger Riso-**Messwert** dokumentiert (nur Fehlercodes/Schwellwerte);
    Fronius/SolarEdge liefern ihn im reinen SunSpec-Modell nicht. Dort wird Riso bewusst
    nicht geraten.

## 0.30.2-beta.1 (2026-07-18)

- **Huawei: Isolationswiderstand (Riso) ergänzt** (Reg 32088, MΩ). Damit lesen jetzt GoodWe
  und Huawei den für die PV-Sicherheit wichtigen Riso-Wert aus.

## 0.30.1-beta.1 (2026-07-18)

- **Discovery erkennt jetzt auch Victron GX und Huawei SUN2000.** Damit deckt der
  Netzwerk-Scan alle unterstützten Hersteller ab. Victron wird über den Systemdienst auf
  Unit-ID 100 (Serial-Register) erkannt, Huawei über Modell-ID/Modellname (Reg 30070/30000)
  auf Unit-ID 1/0/16.

## 0.30.0-beta.1 (2026-07-18)

- **Neuer Hersteller: Huawei SUN2000 (L1/M1)** inkl. DTSU666-Zähler und LUNA2000-Batterie.
  Native Huawei-Registermap (kein SunSpec, FC 0x03, Big-Endian, int16/int32 mit Gain);
  Register/Gain verbatim aus der Bibliothek `wlcrs/huawei-solar-lib`. Ausgelesen werden:
  PV-Eingangsleistung (32064), AC-Wirkleistung (32080), Netz U/I/f (32069/32072/32085),
  Innentemperatur (32087), Betriebsstatus (32089), Ertrag Gesamt/Heute (32106/32114),
  Smart Meter DTSU666 (Leistung 37113, U/I/f), Batterie LUNA2000 (SOC 37004, Leistung 37001,
  Spannung 37003, Strom 37021, Temperatur 37022, Zustand 37000) und der Modellname (30000).
  Unit-ID des Wechselrichters meist 1 (je nach Konfiguration auch 0/16), Port 502. Vorzeichen
  von Netz (+ Einspeisung) und Batterie (+ Entladen) auf Modul-Konvention gebracht. Noch nicht
  am realen Gerät verifiziert.

## 0.29.1-beta.1 (2026-07-18)

Nach weiterem SolarEdge-Beta-Test:

- **SolarEdge: Zähler-Energie neu** — optionale Gruppe „Zähler-Energie (Bezug / Einspeisung)"
  liest die kWh-Zählerstände aus dem SunSpec-Meter-Modell (TotWhImp/TotWhExp mit gemeinsamem
  Skalierungsfaktor).
- **SolarEdge: Batterie SOH + Speicherstatus neu** — die Batteriegruppe liefert jetzt auch
  State of Health (0xF582) und den Speicherstatus (0xE186: Aus/Laden/Entladen/Ruhemodus).
  Alle Batterie-Register gegen die vom Tester exportierte StorEdge-Vorlage gegengeprüft.
- **SolarEdge: PV-Erzeugung berechnet neu** — optionale Gruppe „PV-Erzeugung berechnet". Auf
  StorEdge-Anlagen spiegelt das DC-Leistungsregister bei Batteriebetrieb nicht die reine PV-
  Erzeugung wider; die neue Variable rechnet nach der am Gerät bewährten Formel PV-Gesamt +
  Batterieleistung (nie negativ). Benötigt die aktive Batteriegruppe.

## 0.29.0-beta.1 (2026-07-18)

- **Neuer Hersteller: Victron GX (Cerbo / Venus OS)**. Liest den aggregierten Systemdienst
  `com.victronenergy.system` per Modbus TCP: PV-Gesamtleistung (DC- + AC-gekoppelt),
  Netzleistung, Batterie (SOC, Leistung, Spannung, Strom, Zustand), Hausverbrauch und aktive
  Netz-Quelle. Register verbatim aus Victrons offizieller `attributes.csv`. Besonderheit: Bei
  Victron ist die Unit-ID ein Geräte-Selektor — der Systemdienst liegt fest auf 100, das
  Modul spricht diese automatisch an (Port 502). Vorzeichen von Netz (+ Einspeisung) und
  Batterie (+ Entladen) auf Modul-Konvention gebracht. Noch nicht am realen Gerät verifiziert.

## 0.28.2-beta.1 (2026-07-18)

Nach SolarEdge-Beta-Test (SE10K-RWS48BEN4):

- **SolarEdge: Skalierungsfaktoren im Integer-Modell (Model 103) korrigiert**. Ursache der
  „falschen Kommastellen": SolarEdge überträgt Spannung/Strom/Temperatur als int16 mit
  separatem SunSpec-Skalierungsfaktor-Register (10^SF). Diese wurden für Spannung und Strom
  gar nicht und für die Temperatur mit falschem Divisor angewendet → Werte um Faktor 10/100
  daneben (225 V erschien als 2250 V, 28,9 A als 289 A, 37 °C als 372 °C). Jetzt wird je
  Messwert das zugehörige SF-Register gelesen und angewendet (Spannung, Strom, Frequenz,
  Leistung, DC-Leistung, Temperatur, Gesamtertrag sowie Zähler-Leistung).
- **SolarEdge: Gesamtertrag** wird jetzt auch im Integer-Modell gefüllt (stand vorher auf
  „Nie").
- **SolarEdge: Batterie (StorEdge) neu**. Optionale Gruppe „Batterie (StorEdge)" liest den
  SolarEdge-Batterie-1-Block ab 0xE100: SOC, Leistung, Spannung, Strom, Temperatur. Die
  Float32-Werte dieses Blocks sind – anders als der SunSpec-Inverter-Block (ABCD) – little-
  endian (CDAB); das Modul schaltet dafür gezielt um. Byte-Reihenfolge und Vorzeichen am
  realen Gerät (SE10K + Batterie) verifiziert: Die Batterieleistung folgt bereits der
  Modul-Konvention (+ Entladen / − Laden), keine Invertierung nötig; bei Bedarf per Schalter
  „Batterie-Leistung invertieren" umkehrbar.

## 0.28.1-beta.1 (2026-07-18)

Nach Kostal-Plenticore-Beta-Test:

- **Kostal: Byte-/Wortreihenfolge (CDAB/ABCD) wählbar**. Ursache der unbrauchbaren Werte
  (riesige/negative Zahlen, z. B. Spannung 1,4·10¹³ V): Der Plenticore ist ab Werk auf
  „little-endian (CDAB) Standard Modbus" gestellt, das Modul decodierte Float32 aber fest
  big-endian (ABCD/SunSpec). Neu: Auswahl „Byte-Reihenfolge" (nur bei Kostal), Vorgabe CDAB
  passend zur Werkseinstellung. Muss mit der Einstellung im Plenticore-Webinterface
  (Einstellungen → Modbus/Sunspec) übereinstimmen.
- **Robustheit gegen NaN/INF**: Liefert ein Gerät an einer Register-Adresse keinen
  gültigen Float32 (andere Byte-/Wort-Reihenfolge, kein Float an dieser Adresse), lehnte
  IP-Symcon das Setzen mit wiederholter Warnung ab („NaN/INF Werte werden nicht
  unterstützt"). Solche Werte werden jetzt zentral in `SetVarFloat` auf 0.0 gefiltert –
  keine Log-Flut mehr. (Die eigentliche Kostal-Decodierung wird nach Auswertung der
  Roh-Register des Testers separat korrigiert.)

## 0.28.0-beta.1 (2026-07-17)

Nach Fronius-Beta-Test (Symo GEN24, Batterie + Smart Meter):

- **Kritischer Bugfix Datenpunkte beim Herstellerwechsel**: Eine neu angelegte Instanz zeigte
  immer die GoodWe-Ordner (der Default-Hersteller), auch wenn vor dem Übernehmen „Fronius"
  gewählt war — man musste die Ordner erst löschen und neu wählen. Beim Registrieren werden
  jetzt alle Variablen/Kategorien entfernt, die nicht zum aktuell gewählten Treiber gehören
  (Reste eines anderen Herstellers oder deaktivierter Gruppen).
- **Fronius: Smart-Meter-Adresse konfigurierbar**. Der Fronius Smart Meter ist ein eigenes
  Modbus-Gerät auf derselben IP mit eigener Unit-ID (Vorgabe 200, je nach Konfiguration z. B.
  240). Bisher fest 200 verdrahtet, dadurch fand das Modul den Zähler bei abweichender Adresse
  nicht → keine Netzleistung. Jetzt Feld „Smart-Meter-Adresse" (nur bei Fronius). Die
  Register-Offsets für die Gesamtwirkleistung waren bereits korrekt (Direktwert, nicht
  Spannung×Strom).
- **Batterie-Ordner heißt jetzt „Batterie"** statt „bat" (fehlendes Kategorie-Label bei
  Fronius ergänzt).
- **Neu: Schalter „Batterie-Leistung invertieren"**. Der bisherige Invers-Schalter galt nur
  fürs Smart Meter. Modul-Standard bleibt + = Entladen / − = Laden; wer die umgekehrte
  Konvention möchte, aktiviert den Schalter. Die Kachel rechnet intern automatisch auf ihre
  kanonische Konvention zurück, sodass die Flussrichtung der Batterie korrekt bleibt.

## 0.27.1-beta.1 (2026-07-17)

- **Bugfix Einheit bei Verbrauchern**: Fremdquellen (z. B. Wallboxen) liefern ihre Leistung
  teils in kW statt W, wodurch der Kreis den Wert um Faktor 1000 falsch anzeigte. Die Kachel
  rechnet jetzt jede Verbraucher-Leistung einheitlich in Watt um. Neue Spalte „Einheit" je
  Verbraucher-Zeile: „Automatisch" (Vorgabe) erkennt W/kW/MW am Profil-Suffix der Variable,
  bei fehlendem/unpassendem Profil lässt sich W/kW/MW manuell wählen. Der optionale
  Hauslastzähler wird ebenso automatisch anhand seines Profils umgerechnet.

## 0.27.0-beta.1 (2026-07-17)

- **Bugfix Hauslast**: Die Bilanz nutzt jetzt die AC-Wirkleistung des Wechselrichters
  (`Hauslast = AC-Leistung − Netzeinspeisung`) statt der PV-Leistung. Die AC-Leistung ist
  bereits das, was der Wechselrichter NACH der Batterie ans Hausnetz abgibt — dadurch stimmt
  die Last auch beim Laden (PV 8 kW, Ladung 7 kW → Hauslast 1 kW statt fälschlich 8 kW) und
  braucht keine Batteriedaten mehr. Die frühere PV-basierte Formel überschätzte die Last um die
  Ladeleistung, sobald Batteriedaten fehlten oder die Batterie-Gruppe aus war. Fällt keine
  AC-Leistung vor, greift weiterhin die DC-Bilanz (PV + Batterie-Entladung − Einspeisung).
  Gegen fünf Szenarien verifiziert, u. a. die frühere Live-Messung (1824 W).
- **Knoten-Blitze jetzt sichtbar und leistungsabhängig**: Die Teslaspulen-Blitze rund um die
  Kreise werden mit der Leistung heller (waren vorher kaum zu sehen). Zusätzlich wachsen
  „Reichweiten"-Blitze von der Kreiskante Richtung Nachbarknoten — ihre Länge folgt der
  Leistung und erreicht bei 25 kW den Nachbarn. Bei geringer Leistung nur ein kurzes Zucken an
  der Kante.

## 0.26.0-beta.1 (2026-07-17)

- **Teslaspulen-Blitze jetzt auch um die Kreise**: aktive Knoten (inkl. Hauslast) bekommen
  wabernde Zickzack-Bögen entlang der Münzkante, in der jeweiligen Knotenfarbe und mit
  demselben Flacker-Rhythmus wie an den Leitungen. Jeder Knoten hat ein eigenes, stabiles
  Muster (Position/Timing aus dem Knotenwinkel abgeleitet); bei inaktiven Knoten sind die
  Blitze aus.
- **Fluss-Tempo einstellbar**: neuer Regler „Fluss-Tempo: Leistung für Höchsttempo"
  (Vorgabe 10000 W statt bisher fest 40 kW). Bei diesem Wert laufen die Dreiecke mit
  Höchsttempo — je kleiner, desto deutlicher unterscheiden sich Alltagsleistungen sichtbar.
  Tempo-Spanne zugleich von 2,2-0,5 s auf 3,0-0,4 s je Umlauf gespreizt. Beispiel mit der
  Vorgabe: 8,3 kW → 0,63 s, 1,8 kW → 1,9 s, 0,22 kW → 2,6 s.

## 0.25.0-beta.1 (2026-07-17)

- **Neu: Invers-Schalter für die Meter-Leistung** (alle Hersteller, im Datenpunkte-Panel):
  „Netz-Leistung (Meter) invertieren". Die Zählrichtung hängt vom Einbauort und der
  Verdrahtung des Zählers ab und ist daher je Anlage verschieden — statt je Hersteller zu
  raten, legt der Nutzer die Richtung selbst fest. Wirkt zentral auf `meter_total` und damit
  automatisch auch auf Netz-Farbe/Flussrichtung in der Kachel.

## 0.24.0-beta.1 (2026-07-17)

Fronius-Überarbeitung nach Beta-Test an einem Symo GEN24 12.0 Plus SC:

- **Bugfix MPPT-Werte** ("2056,4 V"): die Model-160-Offsets lasen mitten im
  IDStr-**Textfeld** der Modulblöcke statt bei DCV/DCW - die Modulblöcke beginnen erst nach
  ID + 8 Registern Text. Jetzt korrektes offizielles Layout inklusive Auswertung der
  SunSpec-Skalierungsfaktoren und der "nicht implementiert"-Kennungen (0xFFFF/0x8000).
- **Smart Meter wird jetzt gefunden**: Fronius meldet den Zähler nicht in der SunSpec-Kette
  des Wechselrichters, sondern unter einer **eigenen Unit-ID** (die "Zähleradresse",
  werksseitig 200). Der Treiber liest den Meter jetzt dort (Rückfall: eigene Kette),
  unterstützt Int- (20x, mit Skalierungsfaktor) und Float-Meter-Modelle (21x, W bei
  Offset 26) und dreht das Vorzeichen auf die Modul-Konvention (positiv = Einspeisung).
- **Neu: Batterie-Gruppe für GEN24-Hybride**: SOC aus dem Basic-Storage-Model 124
  (ChaState inkl. Skalierungsfaktor), Lade-/Entladeleistung aus den Speicherkanälen
  Modul 3 + 4 des Model 160 (positiv = Entladung, konsistent zu den anderen Treibern).

## 0.23.0-beta.1 (2026-07-17)

- **Neu in der Discovery: Ausschlussliste** („IPs ignorieren"). Dort eingetragene Adressen
  werden beim Scan komplett übersprungen — gedacht für RTU/TCP-Konverter und andere
  Modbus-Geräte, die sonst fälschlich als Wechselrichter in der Ergebnisliste erscheinen.
  Solche Geräte lassen sich technisch nicht zuverlässig von echten Wechselrichtern
  unterscheiden, da sie Modbus-Anfragen an den dahinterliegenden Bus weiterleiten und daher
  mit plausiblen Werten antworten. Mehrere IPs Komma-, Semikolon- oder Leerzeichen-getrennt;
  ungültige Einträge werden ignoriert.

## 0.22.2-beta.1 (2026-07-17)

- **Kritischer Bugfix Discovery** (gemeldet vom Sungrow-Beta-Tester: nach dem Update wird der
  Wechselrichter nicht mehr gefunden, nur noch der RTU/TCP-Konverter): Regressionsfehler aus
  0.6.3 - die Fortschrittsanzeige feuerte in **jeder** Runde der Portscan-Schleife vier
  `UpdateFormField`-Aufrufe ab. Diese RPCs fraßen das 2-Sekunden-Zeitfenster des Scans auf,
  sodass kaum noch echte Socket-Abfragen stattfanden: schnell antwortende Geräte (der
  Konverter) wurden noch erwischt, langsamere TCP-Handshakes (der Wechselrichter dahinter)
  fielen aus dem Fenster. Die Fortschrittsanzeige ist jetzt auf max. alle 300 ms gedrosselt
  und ihre Laufzeit wird dem Scan-Budget gutgeschrieben.
- Scan-Fenster von 2 s auf 3 s erhöht - Geräte hinter RTU/TCP-Konvertern/Gateways brauchen für
  den TCP-Handshake teils spürbar länger.

## 0.22.1-beta.1 (2026-07-17)

- **Statusanzeige nach unten links** verlegt, damit sie dem Diagramm nicht mehr in die Quere
  kommt, und in die Optik der Kreise eingepasst: eine Plakette mit derselben plastischen
  Fläche und feiner Kante, darin der pulsierende Punkt (bei Verbindung grün mit Leuchtschein).
  Sie ist an der Ecke der Zeichenfläche verankert (die sich ja dem Seitenverhältnis anpasst)
  und wächst mit der Textbreite mit.
- **Bugfix**: Ohne gültige Datenquelle blieb seit der Umstellung auf persistente Knoten das
  alte Diagramm mit veralteten Werten stehen. Es wird jetzt wieder ausgeblendet, sodass nur
  die Statusplakette sichtbar bleibt.

## 0.22.0-beta.1 (2026-07-17)

- **Auto ist jetzt eine „fahrende Batterie"**: das unveränderte Batteriesymbol (gleiche Größe,
  gleiche Ladestands-Füllung mit Prozentwert) plus Räder, um die Hälfte des Rad-Überstands nach
  oben gerückt, damit es mittig sitzt.
- **Eigene Farben je Verbraucher-Art** statt des bisherigen Grün-Rot-Verlaufs: Wärmepumpe in
  Feuer-Orange, Pool-Wärmepumpe/Sauna/Warmwasser in verwandten Wärmetönen, Klimaanlage und
  Pool-Pumpe in Türkis, Fahrzeuge in Violett (bewusst abgesetzt von der blauen Hausbatterie).
  Zusätzlich je Zeile eine **eigene Farbe einstellbar** (leer = Vorgabe der Art).
- **Energiefluss**: je Speiche ein glimmender Leiter, darauf laufende Dreiecke in
  Flussrichtung, dazu wabernde Blitze im Stil einer Teslaspule. Die Dreiecke richten sich
  automatisch entlang der Bahn aus (die Bahn wird in Flussrichtung erzeugt), sodass die Spitzen
  immer korrekt vorausweisen und sich beim Vorzeichenwechsel mitdrehen - z. B. Netz bei Bezug
  zum Haus, bei Einspeisung nach außen; Batterie beim Laden nach außen, beim Entladen zum Haus.
  Das Tempo folgt der Leistung (2,2 s je Umlauf bei wenig, 0,5 s bei 40 kW), Solar fließt
  konstruktionsbedingt immer zum Haus.

## 0.21.0-beta.1 (2026-07-17)

- **Automatische Zuordnung Fahrzeug ↔ Wallbox, ohne eigenen Datenpunkt.** Der bisherige Ansatz
  (eine Variable, die das angeschlossene Fahrzeug benennt) setzte etwas voraus, das die
  wenigsten Anlagen liefern. Stattdessen wird jetzt die Gleichzeitigkeit ausgewertet: Beim
  Einstecken wechseln Wallbox und Fahrzeug jedes für sich auf „verbunden". Als Zeitpunkt dient
  der von IP-Symcon ohnehin geführte Zeitstempel der letzten **Wertänderung**
  (`VariableChanged`, ändert sich nur bei echtem Wertwechsel). Die Paare werden nach zeitlicher
  Nähe sortiert und eindeutig (1:1) vergeben — bei zwei Autos an zwei Wallboxen landet so jedes
  dort, wo es eingesteckt wurde. Das Zeitfenster ist einstellbar (Vorgabe 300 s, 0 = ohne
  Begrenzung).
- **Frei konfigurierbare „verbunden"-Bedingung** je Wallbox und je Fahrzeug, da jede Quelle das
  anders meldet: Variable + Bedingung (ist gesetzt / gleich / ungleich / größer / kleiner …) +
  Vergleichswert. Damit lassen sich Boolean (z. B. „Ladeportklappe offen"), Text (z. B.
  „Ladekabeltyp", leer = kein Kabel) und Integer (z. B. go-e „Kabel-Leistungsfähigkeit",
  0 = kein Kabel) gleichermaßen auswerten.
- Das an einer Wallbox erkannte Fahrzeug wird als Zusatzzeile im Kreis angezeigt, damit die
  automatische Zuordnung nachvollziehbar bleibt.
- Ersetzt die Spalten „Fahrzeug-Zuordnung" und „Kennung" aus 0.20.0.

## 0.20.0-beta.1 (2026-07-16)

- **Wallboxen zeigen jetzt ein Auto mit Ladestand** statt der Ladesäule: Das Auto-Symbol füllt
  sich – wie das Batteriesymbol – entsprechend dem SOC des Fahrzeugs, das gerade an dieser
  Wallbox steht, mit Prozentwert darin. Ohne angeschlossenes Fahrzeug bleibt nur der Umriss.
- **Neu: Fahrzeug-Tabelle** (Bezeichnung, Kennung, Ladestand-Variable) und zwei zusätzliche
  Spalten je Wallbox-Zeile:
  - *Fahrzeug angeschlossen* – boolesche Variable der Wallbox.
  - *Fahrzeug-Zuordnung* – Variable, deren Wert das angeschlossene Fahrzeug benennt.
- **Zuordnung mehrerer Autos zu mehreren Wallboxen**: Der Wert der Zuordnungs-Variable wird
  gegen die Kennung der Fahrzeuge verglichen (Groß-/Kleinschreibung egal; leere Kennung = die
  Bezeichnung). Bei genau einem Fahrzeug darf die Zuordnung leer bleiben, dann ist die Lage
  eindeutig. Bei mehreren Fahrzeugen ohne Zuordnung wird bewusst **kein** Ladestand angezeigt,
  statt eine Zuordnung zu raten.

## 0.19.0-beta.1 (2026-07-16)

- **Verbraucher jetzt als freie Tabelle** statt fester Felder: je Zeile Art, Bezeichnung und
  Leistungs-Variable. Damit sind beliebig viele Verbraucher jeder Art möglich (auch mehrere
  Wallboxen). Verfügbare Arten: Wallbox, Wärmepumpe, Klimaanlage, Pool-Wärmepumpe, Pool-Pumpe,
  Sauna, Warmwasser, Trockner, Sonstiger Verbraucher — die Art bestimmt das Icon, eine leere
  Bezeichnung fällt auf die Vorgabe der Art zurück. Ersetzt die Felder „Wärmepumpe" und
  „Wallboxen" aus 0.18.0 (dort ggf. neu eintragen).
- **Wallbox-Icon neu**: Ladesäule mit Blitz, Kabel und Stecker statt der bisherigen Box, die
  sich nicht als Wallbox lesen ließ.
- **Bugfix Zuschnitt**: In breiten Kacheln wurden die unteren Kreise abgeschnitten, der obere
  Abstand blieb dabei konstant. Ursache war eine feste viewBox mit unsymmetrischem Leerraum
  (oben 67, unten 45 Einheiten): Der Inhalt saß darin nicht mittig und wurde nach unten
  gedrückt. Die viewBox passt sich jetzt dem tatsächlichen Seitenverhältnis der gerenderten
  Fläche an, wodurch das Zentrum immer exakt mittig liegt und der Inhalt die kürzere Seite
  voll ausnutzt. Zusätzlich hängt das SVG jetzt am Viewport der Kachel statt an der body-Box,
  die bei manchen Hosts höher ausfallen kann als der sichtbare Bereich.

## 0.18.0-beta.1 (2026-07-16)

- **Neu: Wärmepumpe und beliebig viele Wallboxen** als zusätzliche Verbraucher-Kreise in
  `InverterHubTile`. Sie stammen nicht aus dem Wechselrichter, sondern werden im neuen Panel
  „Weitere Verbraucher (optional)" als vorhandene Leistungs-Variablen ausgewählt: Wärmepumpe
  als einzelne Variable, Wallboxen als Liste mit frei wählbarer Bezeichnung (z. B. „Garage",
  „Carport") - also mehrere Wallboxen möglich. Die Kachel abonniert diese Variablen selbst und
  aktualisiert sich bei deren Änderung.
- **Anordnung skaliert jetzt mit der Knotenzahl**: Kreisgröße und -abstand werden aus der
  Anzahl berechnet (Sehnenformel), sodass sich benachbarte Kreise auch bei vielen Verbrauchern
  nie berühren. Bis acht Knoten bleibt es bei der bisherigen Größe, darüber verkleinern sich
  alle Kreise gemeinsam. Der äußere Rand liegt konstruktionsbedingt immer exakt gleich weit
  vom Zentrum - die Kachel behält damit unabhängig von der Knotenzahl ihre Größe, und die
  Statuszeile bleibt bündig. Der bisherige Vier-Knoten-Fall bleibt unverändert
  (oben/rechts/unten/links, gleiche Größe).
- Verbraucher werden zwischen Batterie und Netz eingereiht und folgen farblich derselben
  Semantik wie die Hauslast: grün, solange lokal aus PV/Batterie gedeckt, rot bei Netzbezug.
- Bugfix am Rande: bei Icons wurden `circle`/`path`-Konturen gefüllt statt als Umriss
  gezeichnet — die CSS-Ausnahme für Konturformen deckte nur `rect`/`polygon` ab.

## 0.17.0-beta.1 (2026-07-16)

- **Gleitender Wechsel aktiv/inaktiv**: der Zustandswechsel eines Kreises springt nicht mehr,
  sondern läuft weich ab - Größe, Deckkraft, Farbe (Identitätsfarbe ↔ Grau), Corona und alle
  Münz-Ebenen (Wölbung, Kantenanschliff, Glanzlicht, Glint, Bodenschatten) blenden gemeinsam
  über.
- **Neu konfigurierbar**: „Übergangszeit aktiv/inaktiv" (Standard 800 ms, 0 = ohne Animation).
- Dafür nötiger Umbau: die Knoten werden jetzt **einmalig** aufgebaut und danach nur noch
  aktualisiert, statt bei jedem Update komplett neu erzeugt zu werden — vorher startete jedes
  Element bereits im Zielzustand, weshalb CSS-Transitions grundsätzlich nicht greifen konnten.
  Ein Neuaufbau erfolgt nur noch, wenn sich die Menge der vorhandenen Datenpunkte ändert.
  Position und Skalierung stecken jetzt in einem gemeinsamen, animierbaren `transform`; die
  Knotenfarbe ist als typisierte Custom Property (`@property`) registriert, damit der Browser
  den Farbwechsel überhaupt interpolieren kann.

## 0.16.1-beta.1 (2026-07-16)

- **Anordnung korrigiert**: die äußeren Kreise sind nicht mehr auf feste
  Himmelsrichtungen genagelt (was bei fehlendem Knoten eine unschöne Lücke an einer festen
  Seite hinterließ), sondern werden wieder gleichmäßig radial um die Hauslast verteilt - die
  bevorzugte Reihenfolge/Seite (Solar oben, Batterie rechts, Netz unten, Verluste links) gibt
  dabei nur die Reihenfolge im Uhrzeigersinn vor. Bei allen vier vorhandenen Knoten ergibt sich
  weiterhin exakt oben/rechts/unten/links; fehlt einer, bleibt die Anordnung ausgewogen
  (z. B. gleichseitiges Dreieck bei drei Knoten) statt lückenhaft.

## 0.16.0-beta.1 (2026-07-16)

- **Corona verstärkt und an die Leistung gekoppelt**: klare Kennlinie von 0 (bei 0 W) bis
  Maximum (bei 40 kW), perzeptuell (Wurzel), sodass auch mittlere Leistungen sichtbar sind.
  Am Maximum wächst nicht nur die Deckkraft (bis 0,95), sondern auch Ringbreite (bis 34 px)
  und Weichzeichnung (bis 22 px) - der Halo blüht bei hoher Leistung spürbar auf.

## 0.15.2-beta.1 (2026-07-16)

- **Echte Prägung**: Icons und Leistungswerte wirken jetzt in die Münzfläche gestanzt statt nur
  leicht schattiert. Über die Alphamaske des Glyphen als Höhenkarte erzeugt eine
  Reliefbeleuchtung (`feSpecularLighting`, Licht von oben-links) metallisch glänzende Grate auf
  den erhabenen Kanten, dazu ein weicher Tiefenschatten unten-rechts.
- Beschriftung (Solar/Netz/… und Hauslast) etwas nach unten gerückt.

## 0.15.1-beta.1 (2026-07-16)

- **Münz-/Schlaglicht-Look für aktive Kreise verstärkt**: gewölbte Fläche mit gerichtetem
  Licht von oben-links, metallischer Kantenanschliff (heller Lichtfang oben-links, dunkler
  Schatten unten-rechts), ein knackiger Glint auf der Kante, eine Bodenschattierung (der Kreis
  „schwebt" über der Fläche) sowie in die Fläche geprägte (Relief-)Icons und Leistungswerte.
  Wirkt jetzt wie eine Münze unter Schlaglicht. Inaktive Kreise bleiben bewusst flach und
  dezent wie bisher.

## 0.15.0-beta.1 (2026-07-16)

- **Plastischer Look für aktive Kreise**: statt einer flachen Füllfarbe jetzt ein dezenter
  Verlauf plus weiches Glanzlicht oben links (wirkt wie ein leicht gewölbter Knopf). Inaktive
  Kreise bleiben bewusst flach wie bisher.
- **Pfeile/Lauflinien und der große Kollektor-Ring um die Hauslast sind für den Moment
  ausgeblendet** (Nutzerwunsch) — die komplette Geometrie dahinter bleibt erhalten und lässt
  sich über eine einzelne Konstante (`SHOW_FLOW`) jederzeit wieder einschalten.

## 0.14.3-beta.1 (2026-07-16)

- Icons in allen Kreisen einen Ticken höher positioniert.
- Flammen-Icon im Verluste-Kreis um 20 % vergrößert.

## 0.14.2-beta.1 (2026-07-16)

- Haussymbol im Hauslast-Kreis um 20 % verkleinert.

## 0.14.1-beta.1 (2026-07-16)

- **Bugfix**: bei inaktiven Knoten schrumpfte bisher nur der Ring (Radius-Attribut), Icon,
  Leistungswert und Beschriftung blieben in fester Pixelgröße stehen und wirkten dadurch
  unproportional groß für den kleineren Kreis. Ring/Glow werden jetzt immer in voller Größe
  gezeichnet und die komplette Knoten-Gruppe (Ring, Icon, Wert, Beschriftung) wird stattdessen
  gemeinsam per CSS-Transform skaliert — die sichtbare Größe bleibt gleich, aber wirklich
  alles im Kreis verkleinert sich jetzt gemeinsam.

## 0.14.0-beta.1 (2026-07-16)

- **Einheitliche Kreisgröße für aktive Knoten**: die Batterie hatte bisher einen eigenen,
  größeren Radius als alle anderen — jetzt sind alle aktiven Knoten (inkl. Zentrum) gleich
  groß. Inaktive Knoten (kein nennenswerter Fluss) werden stattdessen bewusst kleiner
  gezeichnet — der Größenunterschied selbst ist jetzt das Signal für „inaktiv", zusätzlich zu
  Farbe und Transparenz.
- Inaktive Knoten sind zusätzlich noch durchsichtiger (Gruppen-Opazität von 0,5 auf 0,32).
- Icons sitzen weiter oben im Kreis, Leistungswert näher an der Kreismitte.
- Platzreserve für Leistungswerte auf „33,333 kW" (breitester realistischer Wert) ausgelegt.
- Die Einheit „kW" hat jetzt dieselbe Farbe wie der Leistungswert und skaliert relativ zu
  dessen Schriftgröße (em-basiert), statt fest in einer eigenen, gedämpften Farbe/Größe zu
  stehen.

## 0.13.2-beta.1 (2026-07-16)

- **Statuspunkt exakt ausgerichtet**: der pulsierende Punkt ("Verbunden") ist jetzt exakt
  linksbündig mit der linken Kante des Verluste-Kreises und exakt obenbündig mit der Oberkante
  des Solar-Kreises, berechnet aus denselben Geometrie-Konstanten wie der Rest des Diagramms —
  vorher stand er nur ungefähr in der Nähe der oberen linken Ecke.

## 0.13.1-beta.1 (2026-07-16)

- **Kritischer Bugfix Kostal** (gemeldet von einem Beta-Tester: "einige Werte sind mehr als
  Billionen Watt"): gegen die offizielle KOSTAL-Modbus-Dokumentation geprüft und zwei
  Einheiten-Bugs gefunden — Register 118 ("Total home consumption") und 320/322/324/326
  (Ertrag Gesamt/Heute/Jahr/Monat) sind laut Hersteller-PDF in **Wh** dokumentiert, der Treiber
  behandelte sie aber als **W** bzw. ließ bei den Ertragswerten die Umrechnung komplett weg —
  bei älteren Anlagen mit hohem kumuliertem Ertrag entstehen daraus absurd große Zahlen.
  `home_total` läuft jetzt korrekt über das Energie-Profil (`~Electricity`, kWh), alle vier
  Ertragswerte werden korrekt durch 1000 geteilt.
- Batterie-Register (200/210/214/216: Strom/SOC/Temperatur/Spannung) wurden gegen dieselbe
  offizielle Dokumentation geprüft und sind korrekt adressiert — falls bei einer Anlage
  trotzdem keine Batteriewerte ankommen, bitte prüfen, ob die Datenpunkt-Gruppe „Batterie"
  in der Instanzkonfiguration aktiviert ist und ob überhaupt ein Batteriesystem am
  Wechselrichter angeschlossen ist.
- Kostal-Standardport zur Erinnerung: 1502, nicht 502 (laut Hersteller-Doku bestätigt) — daher
  der abweichende Port, den ein Beta-Tester manuell eintragen musste.

## 0.13.0-beta.1 (2026-07-16)

- **Bugfix Skalierung bei kleinen Kachelgrößen**: `width/height:100%` per CSS verlässt sich
  darauf, dass der Kachel-Host dem `<body>` zuverlässig seine tatsächliche Pixelgröße
  durchreicht — das war in der echten IP-Symcon-Kachel-Ansicht nicht immer der Fall, wodurch
  `preserveAspectRatio` gegen eine falsche (zu große) Fläche skalierte und bei kleinen
  Widget-Größen Inhalt am Rand abgeschnitten wurde. Die tatsächliche Größe wird jetzt aktiv per
  JavaScript gemessen (inkl. `ResizeObserver`) und explizit als Pixel-Attribute gesetzt.
- **Statuszeile** ("Verbunden" mit Punkt) ist jetzt an der Diagramm-Geometrie verankert (knapp
  oberhalb/links von Solar- und Verluste-Kreis) statt an einem willkürlichen Fixpunkt.
- **Stärker ausgegraut**: inaktive Knoten (kein nennenswerter Fluss) sind jetzt zusätzlich
  gedimmt (Gruppen-Transparenz) und ihr dunkler Kreishintergrund wird komplett entfernt
  (transparent), statt weiterhin als dunkler Kreis mit grauem Rand hervorzustechen.
- **Batterie größer** und der SOC-Wert steht jetzt direkt im (dafür verbreiterten) Batterie-Icon
  geschrieben, statt als separate Textzeile daneben.

## 0.12.0-beta.1 (2026-07-16)

- **Feste Positionen statt Rotationsverteilung**: Solar liegt jetzt immer oben, Netz immer
  unten, Batterie immer rechts, Verluste immer links — unabhängig davon, wie viele der Knoten
  gerade aktiv sind. Künftige Verbraucher (Wallbox, Wärmepumpe) bekommen eigene freie
  Winkelpositionen, statt alle Knoten bei jeder Änderung neu zu verteilen.
- **Bugfix Pfeilspitzen-Position**: `markerUnits` fehlte, wodurch der Standardwert
  `strokeWidth` die Pfeilspitze mit der Linienstärke (3) multiplizierte — aus einer 9px-Spitze
  wurde faktisch eine 27px-Spitze, die über den Kollektor-Ring hinausragte. Zusätzlich lag der
  Referenzpunkt in der Dreiecksmitte statt an der Spitze. Beides korrigiert, Pfeile enden jetzt
  exakt an Ring-/Kreiskante.
- Icons sitzen weiter oben im Kreis, mehr Abstand zu Wert und Beschriftung.
- Batterie-Icon breiter und liegend, stellt den SOC jetzt direkt als Füllstand im Icon dar.
- Knoten ohne nennenswerten Leistungsfluss werden jetzt komplett ausgegraut (Ring, Icon, Wert,
  Pfeile) statt nur die Leucht-Corona abzuschalten und die Akzentfarbe zu behalten.

## 0.11.2-beta.1 (2026-07-16)

- **Bugfix Randbeschneidung**: die `viewBox` war für die tatsächliche Ausdehnung der äußeren
  Kreise (inkl. Leucht-Corona) zu knapp bemessen — bei bestimmten Winkeln/Kachelgrößen wurden
  Kreise am Rand abgeschnitten. Geometrie neu durchgerechnet, jetzt mit durchgängigem
  Sicherheitsabstand.
- **Bugfix schwarze Pfeilspitzen**: beim Umbau auf variable Knotenanzahl war versehentlich nur
  noch ein einziges, geteiltes `<marker>`-Element für alle Pfeile übrig geblieben (SVG-Marker
  erben keine Farbe von der referenzierenden Linie) — dadurch erschienen alle Pfeilspitzen in
  derselben bzw. der Standardfarbe Schwarz. Jeder Knoten bekommt jetzt sein eigenes, bereits
  passend eingefärbtes Marker-Element.
- Schriftgrößen in den Kreisen verkleinert und Kreisradius leicht angepasst, damit auch
  sechsstellige Werte wie „4,654 kW" nicht mehr eng am Rand stehen.
- Kollektor-Ring um die Hauslast kräftiger (dickerer, dichterer Strich) und mittig zwischen
  Zentrums- und Außenkreisen positioniert statt zu nah am Zentrum.

## 0.11.1-beta.1 (2026-07-16)

- **Bugfix (2. Anlauf) `InverterHubTile`**: Konfigurationsformular ließ sich weiterhin nicht
  öffnen ("Nicht unterstützter Typ: ColorPicker"). Auch `ColorPicker` war falsch geraten — der
  tatsächlich korrekte IP-Symcon-Formularelement-Typ heißt `SelectColor` (offiziell
  dokumentiert). Diesmal gegen die offizielle Symcon-Dokumentation verifiziert statt geraten.

## 0.11.0-beta.1 (2026-07-16)

- **Komplettes Redesign `InverterHubTile`** nach Nutzer-Feedback (Layout wirkte nicht streng
  genug geometrisch, Beschriftung mal über/mal unter dem Wert, Verluste sollten grau statt
  farbig sein):
  - **Radiale Anordnung statt Diamant**: die Hauslast sitzt jetzt fest im Zentrum, alle
    anderen Größen (Solar, Netz, Batterie, Verluste) werden als gleichmäßig im Kreis verteilte
    äußere Knoten dargestellt — Winkel = 360° / Anzahl aktiver Knoten, exakt berechnet statt
    handgesetzter Positionen. Ein gepunkteter Kollektor-Ring um die Hauslast als Sammelpunkt,
    wie in Referenz-Apps üblich.
  - **Variable Knotenanzahl**: die Knoten werden zur Laufzeit aus den vorhandenen Datenpunkten
    erzeugt (nicht mehr vier feste Kreise). Vorbereitet für künftige Verbraucher wie Wallbox
    oder Wärmepumpe als weitere Knoten, ohne das Layout manuell anzupassen.
  - **Striktes, einheitliches Template**: jeder Knoten zeigt in exakt derselben Reihenfolge
    Icon → Wert → Beschriftung, ausnahmslos. Der SOC-Wert der Batterie wandert dafür als
    kleine Zusatzangabe über das Icon, statt als zweite, uneinheitliche Wertzeile.
  - **Verluste nur noch grau** (keine Akzentfarbe mehr), fließen immer vom Zentrum weg.
  - **Bugfix Pfeilrichtung**: bei der Umstellung wurde die Zuordnung von Fließrichtung zu
    Pfeil-Pfad zunächst vertauscht (Solar zeigte wieder Richtung Sonne) - vor Veröffentlichung
    bemerkt und korrigiert.

## 0.10.0-beta.1 (2026-07-16)

- **Neu**: optionaler Hauslastzähler. `InverterHub` bekommt ein neues Konfigurationsfeld
  „Hauslastzähler (optional)" — eine bereits vorhandene Variable mit real gemessener Hauslast
  (z. B. ein Shelly am Hausanschluss) kann ausgewählt werden. Live an einer echten Anlage
  gegengeprüft: die reine PV/Netz/Batterie-Bilanzschätzung lag konsistent ~100-120 W über dem
  tatsächlich gemessenen Wert (Wechselrichter-Eigenverbrauch/Leitungsverluste, die in der
  Bilanz nicht erfasst werden).
- **Neu in `InverterHubTile`**: ist ein Hauslastzähler konfiguriert, zeigt die Kachel die
  genauere, echte Last sowie einen zusätzlichen kleinen Kreis „Wandlungsverluste" mit der
  Differenz zur Bilanzschätzung. Ohne konfigurierten Zähler bleibt alles wie bisher (reine
  Bilanzschätzung, kein zusätzlicher Kreis).

## 0.9.1-beta.1 (2026-07-16)

- **Kritischer Bugfix Last-Berechnung**: Die Bilanzformel ging von der falschen
  Vorzeichenkonvention für die Batterieleistung aus (Subtraktion statt Addition). Live an
  einer echten GoodWe-Instanz verifiziert: `bat_total_pwr`/`bat_power` ist bei allen Treibern
  positiv = Entladung, negativ = Ladung. Beispiel (reale Werte): PV 5657 W, Netzbezug 299 W,
  Batterieladung 4132 W ergab bisher fälschlich 10088 W Last statt korrekt 1824 W.
- **Bugfix Flussrichtung Solar**: Der Pfeil auf der Solar-Leitung zeigte immer von der Mitte
  zur Sonne (als würde Strom AN die PV geliefert) statt umgekehrt. PV speist jetzt immer
  Richtung Zentrum ein, wie es physikalisch korrekt ist.
- **Neu**: Corona (Leucht-Halo) um jeden Kreis skaliert jetzt weich mit der aktuellen Leistung
  (Wurzel-Skala) statt nur an/aus zu schalten.
- **Neu**: Die gesamte Kachel (inkl. Statuszeile, vorher HTML außerhalb des SVG) liegt jetzt in
  einem einzigen SVG mit `viewBox` und skaliert dadurch vollständig proportional mit der
  Widget-Größe im Dashboard. Der bisherige manuelle „Schriftgröße"-Faktor ist dadurch
  überflüssig geworden und wurde entfernt.
- Netz-Icon von Steckdose auf Blitz-Symbol geändert, Icons und Ringe leicht vergrößert für
  bessere Lesbarkeit bei kleinen Kachelgrößen.

## 0.9.0-beta.1 (2026-07-16)

- **Überarbeitung `InverterHubTile`** nach Nutzer-Feedback (Layout und Farben wirkten
  unstimmig, Icons/Zahlen teils schwarz und unlesbar):
  - **Layout**: echtes, auf der Spitze stehendes Quadrat (Diamant) statt schiefer Anordnung —
    Solar oben, Netz links, Last rechts, Batterie unten, alle vier gleich weit vom Mittelpunkt,
    verbunden durch ein Kreuz aus Leitungen statt gebogener Äste.
  - **Farben jetzt semantisch fest**: Solar = Sonnengelb, Netz = Grün bei Einspeisung/Rot bei
    Bezug, Batterie = Blau, Last = weicher Grün-Rot-Verlauf je nach Anteil aus Netzbezug vs.
    PV/Batterie (moderater, stufenloser Farbwechsel statt hartem Umschalten).
  - **Bugfix**: Icons und Zahlen erschienen schwarz/unlesbar, weil `currentColor` in den
    SVG-Kindelementen verwendet wurde — das bezieht sich auf die CSS-Eigenschaft `color`, die
    nie gesetzt war, statt auf `fill`/`stroke`. Jetzt werden Farben direkt über `fill`/`stroke`
    vererbt, zusätzlich erschienen Pfeilspitzen schwarz, da `<marker>`-Inhalte keine Farben vom
    referenzierenden Pfad erben — jetzt werden sie passend zur aktiven Flussfarbe eingefärbt.
  - Da die Kreisfarben jetzt fest vergeben sind, entfallen die bisherigen Farb-Einstellungen
    „Akzent/Box/Text/Textfarbe" — nur Hintergrundfarbe, Schriftart und -größe bleiben
    konfigurierbar.

## 0.8.2-beta.1 (2026-07-16)

- **Bugfix `InverterHubTile`**: Konfigurationsformular ließ sich nicht öffnen
  ("Fehler beim Laden der Konfigurationsform — Nicht unterstützter Typ: ColorEditor"). Der
  korrekte IP-Symcon-Formularelement-Typ heißt `ColorPicker`, nicht `ColorEditor`.

## 0.8.1-beta.1 (2026-07-16)

- **Redesign `InverterHubTile`**: die erste Fassung wirkte optisch unfertig (grelle Volltonringe,
  gerade Linien, die mitten durch die Kreise liefen, tote SOC-Ringe). Komplett überarbeitet nach
  Vorbild bekannter Wechselrichter-Apps: dünne Ringe mit weichem Leuchten nur bei aktivem Fluss
  (neutral grau/weiß im Ruhezustand statt Farbe), sanft geschwungene Bus-Leitungen (Solar–Batterie
  senkrecht, Abzweige zu Netz/Last) mit „marschierenden Ameisen“ und Pfeilspitze statt fester
  Diagonalen, größere/klarere Typografie im deutschen Zahlenformat („5,649 kW“), Batterie-Kreis
  mit Ladestatus-Badge und SOC als Hauptwert. Netz/Batterie wechseln je nach Richtung zwischen
  Grün (Einspeisung/Entladung) und Orange (Bezug/Ladung).

## 0.8.0-beta.1 (2026-07-16)

- **Neu**: `InverterHubTile` — animierte Energiefluss-Kachel für InverterHub (Solar, Netz, Last,
  Batterie mit SOC-Füllstand), analog zu bekannten Wechselrichter-Apps. Als „Datenquelle" wird
  eine beliebige InverterHub-Instanz gewählt; die Kachel liest deren Werte per Ident, unabhängig
  vom Hersteller. Da nicht jeder Treiber dieselben Datenpunkte liefert (z. B. Growatt ohne
  Netzmessung, SMA/Fronius/SolarEdge ohne Batterie), wird ein Kreis ausgegraut statt mit
  falschen Werten befüllt, wenn die zugehörige Größe bei der gewählten Quelle fehlt. Farben,
  Schriftart und -größe sind anpassbar (Muster identisch zu `GoodweETTile`).

## 0.7.0-beta.1 (2026-07-16)

- **Neu**: Checkbox „Kommunikation aktiv" in `InverterHub`, direkt über der Hersteller-Auswahl.
  Deaktivieren stoppt Polling (Schnell-/Langsam-Timer) und Steueraktionen sofort, ohne die
  Instanz zu löschen oder die Konfiguration zu verlieren — praktisch z. B. bei Wartungsarbeiten
  am Wechselrichter oder wenn das Gerät vorübergehend vom Netz genommen wird.

## 0.6.5-beta.1 (2026-07-16)

- **Bugfix**: die in 0.6.3 eingeführte Fortschrittsanzeige erzeugte eine Warnung
  ("Instanz #<id> existiert nicht") in der Konsole, wenn das Konfigurationsformular
  während des laufenden Scans geschlossen wurde — der Scan selbst läuft unabhängig vom
  offenen Formular korrekt weiter, `UpdateFormField()` schlägt in diesem Fall aber
  erwartungsgemäß fehl. Diese (harmlose) Warnung wird jetzt unterdrückt.

## 0.6.4-beta.1 (2026-07-16)

- **Bugfix Discovery**: go-e-Wallboxen (Unit-ID 1) wurden fälschlich als Growatt erkannt — die
  Growatt-Prüfung (Status 0/1/3 + plausible Temperatur) war nicht spezifisch genug und traf
  zufällig auch auf go-e-Register zu. Als zusätzliches, hartes Kriterium wird jetzt zusätzlich
  Holding 23-27 (Seriennummer, ASCII) verlangt, analog zur bereits bestehenden Absicherung bei
  GoodWe/Sungrow/Solis/SolaX/Kostal.

## 0.6.3-beta.1 (2026-07-16)

- `InverterHubDiscovery`: Fortschrittsanzeige während der Netzwerksuche. Ein Fortschrittsbalken
  zeigt live den Portscan (geschätzt anhand verstrichenem Zeitbudget) und danach die
  Hersteller-Erkennung je gefundener IP (Prozentwert + Klartext, z. B. „Prüfe Hersteller:
  192.168.2.50 (3 von 7 offenen Ports) …"). Vorher lief die Suche ohne jede Rückmeldung, bis das
  Ergebnis fertig war.

## 0.6.2-beta.1 (2026-07-16)

- **Bugfix Sungrow**: Basisvariable `meter_total` wurde nie beschrieben (blieb dauerhaft auf 0).
  Vom Beta-Tester an seiner eigenen, seit längerem laufenden Sungrow-SH-6.0RT-Konfiguration
  bestätigtes Register (5601-5602, Meter Active Power Gesamt) als zuverlässige Quelle übernommen.
- Zusätzlich per Gegenprobe bestätigt: die aktuelle Sungrow-Adressierung (direkt, ohne Offset)
  ist korrekt — eine andere Community-Vorlage (SH10RT+SBR128) verwendet durchgehend um 1
  niedrigere Adressen für dieselben Werte, was im Umkehrschluss die hier verwendete,
  tester-bestätigte Nummerierung bekräftigt.
- Neu: `power_flow_status` (Register 13001, vom Tester bestätigt, bisher gelesen aber verworfen).

## 0.6.1-beta.1 (2026-07-16)

- `InverterHubDiscovery` erkennt jetzt auch SolarEdge, Deye, Solplanet und Kostal. Fronius und
  SolarEdge nutzen denselben SunSpec-"SunS"-Marker und werden zusätzlich über den
  Herstellernamen im Common Block unterschieden, statt sich allein auf den Marker zu verlassen.

## 0.6.0-beta.1 (2026-07-16)

Vier weitere Hersteller-Treiber, Register aus community-getesteten Modbus-Vorlagen des
[IP-Symcon-Forums](https://community.symcon.de/c/symcon/vorlagen-modbus/86) übernommen:

- **SolarEdge**: reines SunSpec, nutzt dieselbe Laufzeit-Discovery und dieselben (gegen OpenEMS
  verifizierten) Feldoffsets wie Fronius/SMA — die Vorlage bestätigt diese Offsets zusätzlich
  unabhängig
- **Deye** (SG04LP3-Serie): direkte Adressierung, Einzelregister. PV (2 Strings), Netz,
  Batterie, Hausverbrauch, Energie, Start/Stop
- **Solplanet / AISWEI** (ASW-Gen-Serie): direkte Adressierung, Read Input Register.
  PV (3 Strings), Batterie, Temperatur, Energie
- **Kostal** (PLENTICORE plus Gen. 1): direkte Adressierung, native Float32-Register.
  PV (3 DC-Eingänge), Netz, Batterie, Meter, Hausverbrauch nach Quelle, Energie, Gerätename

Im Zuge dessen Bugfix vor dem ersten Test gefunden: Kostal `bat_soc` nutzte Float-Typ mit dem
Integer-Profil `~Battery.100` (derselbe Fehlertyp wie zuvor bei GoodWe/Sungrow).

## 0.5.0-beta.1 (2026-07-16)

Gegenprüfung gegen unabhängige Quellen: die [OpenEMS](https://github.com/OpenEMS/openems)-
Referenzimplementierung (offizielle SunSpec-Modelldefinitionen, GoodWe/Fronius/SMA-Treiber)
und community-getestete Modbus-Vorlagen aus dem
[IP-Symcon-Forum](https://community.symcon.de/c/symcon/vorlagen-modbus/86).

- **GoodWe**: alle Kernregister 1:1 gegen OpenEMS und eine unabhängige, community-getestete
  Vorlage bestätigt — höchste Vertrauensstufe aller Treiber
- **Kritischer Bugfix Fronius**: SunSpec-Feldoffsets waren im von Fronius empfohlenen
  Standardpfad (Float-Modell 111/112/113) fast durchgehend falsch (ac_power, status, grid_volt,
  grid_freq, e_total betroffen), ebenso im Int+SF-Fallback und im Meter-Modell. Gegen die
  offizielle SunSpec-Modelldefinition korrigiert. Seriennummer-Offset im Common Block ebenfalls
  falsch (48 statt 40)
- **SMA komplett neu**: Treiber von SMA-eigenen nativen Registern auf SunSpec umgestellt
  (wie von OpenEMS für den SMA Sunny Tripower verwendet) — schließt die vorher bewusst offene
  AC-/Netzlücke vollständig und nutzt dieselben verifizierten Feldoffsets wie Fronius
- **SolaX**: Grundregister (Netz, PV1/2, Meter, inkl. eigenständig hergeleiteter T-Phasen-
  Adresse) durch unabhängige Community-Vorlage bestätigt. Batterie-Detailregister aus der
  Vorlage bewusst noch nicht übernommen, da sie aus einer anderen Protokollversion
  (Hybrid-G4 ModbusRTU statt ESS/TCP) stammen — Vermischen zweier Protokollversionen ohne
  Auswahlmechanismus wäre riskant, folgt als eigener Schritt

## 0.4.3-beta.1 (2026-07-15)

- **Kritischer Bugfix** (gemeldet von einem Beta-Tester, Sungrow SH 6.0 RT): Off-by-one-
  Adressfehler in Sungrow und Solis behoben — beide Treiber lasen jedes Register einen Schritt
  zu früh (Adresse = dokumentierte Registernummer − 1), was zu offensichtlichem Datenmüll führte
  (z. B. `bat_power = -52690945`). Ursache war fälschlich übernommenes SunSpec-Adressierungs-
  schema; Sungrow und Solis adressieren direkt ohne Offset
- Discovery-Erkennung deutlich robuster gegen Fehlzuordnungen: für GoodWe/Sungrow/Solis/SolaX
  wird jetzt zusätzlich zum Primärregister das jeweilige Seriennummer-/Modellregister auf
  plausiblen ASCII-Text geprüft, für Growatt zusätzlich die Temperatur auf Plausibilität
  (real gemeldet: ein Janitza PAC2200 wurde als SolaX erkannt, ein Modbus-RTU/TCP-Konverter
  als GoodWe)

## 0.4.2-beta.1 (2026-07-14)

- **Kritischer Bugfix** (gemeldet von einem Beta-Tester): Für alle Hersteller außer GoodWe
  fehlten sämtliche Datenpunkt-Gruppen-Properties bei der Instanzerstellung
  ("Eigenschaft GroupPV/GroupBat nicht gefunden"), da `Create()` nur die Properties des zum
  Erstellungszeitpunkt aktiven Default-Treibers (GoodWe) registrierte. Jetzt werden die
  Properties aller Treiber registriert, unabhängig vom gewählten Hersteller

## 0.4.1-beta.1 (2026-07-14)

- Beta-Hinweis mit Link zum Symcon-Forum-Thread in beiden Modulen (InverterHub,
  InverterHubDiscovery) ergänzt, dismissable per „Nicht mehr anzeigen"

## 0.4.0-beta.1 (2026-07-14)

- Freie Namens-Vorlage für neu angelegte Instanzen im Discovery-Modul: leer = Default
  „Hersteller + laufende Nummer" (z. B. „GoodWe 1", „GoodWe 2"), oder eigenes Muster mit
  Platzhaltern `{hersteller}` `{ip}` `{unitid}` `{nr}` (z. B. `{hersteller} Dach ({ip})`)

## 0.3.1 (2026-07-14)

- Bug: bereits über die Discovery angelegte Instanzen erschienen beim erneuten Öffnen des
  Suchformulars wieder als „Kein(e)" statt mit ihrer InstanzID — Ergebnisliste wird jetzt gegen
  vorhandene InverterHub-Instanzen (Host + Unit-ID) abgeglichen
- Sprechenderer Instanzname bei der Erstellung („GoodWe Wechselrichter (192.168.2.102)")

## 0.3.0 (2026-07-14)

- Custom Actions auf Steuervariablen werden per einmaligem Kurz-Timer nach `ApplyChanges`
  gesetzt statt synchron währenddessen — der bisherige Ansatz („erst ab dem zweiten
  ApplyChanges") griff nicht, wenn eine Instanz inklusive Konfiguration in einem einzigen
  synchronen Ablauf entsteht (genau das tut das Discovery-Modul beim Erstellen)
- Discovery-Layout vereinfacht: Suchfelder normal gestapelt in einem Panel, Ergebnistabelle in
  einem eigenen, vollbreiten Panel darunter (statt zweispaltig mit viel Leerraum)
- Modultyp von `InverterHubDiscovery` auf `5` (Discovery) korrigiert — Instanz erscheint jetzt
  unter „Discovery Instanzen" statt „Splitter Instanzen"

## 0.2.0 (2026-07-14)

- Neues Modul `InverterHubDiscovery` (Configurator): durchsucht einen IP-Bereich per
  nicht-blockierendem Parallel-Scan nach offenem Modbus-TCP-Port 502, erkennt den Hersteller
  anhand weniger dokumentierter Standard-Unit-IDs pro Hersteller über ein charakteristisches
  Register, legt per Klick eine `InverterHub`-Instanz mit vorausgefüllter IP-Adresse/Unit-ID/
  Hersteller an. Start-/End-IP werden anhand des eigenen Netzwerks vorbelegt
  (`create`-Objekt-Struktur gemäß IP-Symcon-Konfigurator-Schema)
- Icons vor den ExpansionPanel-Überschriften in `InverterHub` (🔌 Verbindung, ⏱️ Polling,
  📊 Datenpunkte, 📖 Dokumentation & Hilfe)

## 0.1.1 (2026-07-14)

- Sechs weitere Hersteller-Treiber ergänzt: **Sungrow** (SH-Hybrid, Read Input Register),
  **Solis** (Hybrid-Serie, 33000er-Register), **Growatt** (TL-X/TL3-X/MOD/MIX/SPH/WIT-
  Basisbereich, durchnummerierte Register mit H/L-Paaren), **SolaX** (Hinweis: nur über
  zusätzliches Monitoring-Modul per Modbus TCP erreichbar, Wechselrichter selbst spricht nur
  RTU), **SMA** (PV/Energie/Temperatur/Status; AC-/Netzregister bewusst ausgespart, da beim
  Erstellen nicht vollständig dokumentiert vorliegend), **Fronius** (reine
  SunSpec-Implementierung mit Laufzeit-Modell-Discovery ab Basisregister 40000 statt fester
  Adressen, wie von Fronius dokumentiert gefordert)
- `ModbusTcpClient` um `readInput()` (Read Input Register, FC 0x04) und `readFloat32()`
  (IEEE-754, SunSpec-Konvention) erweitert

## 0.1.0 (2026-07-14)

Erstveröffentlichung.

- `InverterHub`: generisches Wechselrichter-Modul mit austauschbaren Hersteller-Treibern
  (`InverterDriverInterface`) statt eines separaten Moduls pro Hersteller. Gemeinsame
  Modbus-TCP-Grundfunktionen in `ModbusTcpClient`
- Erster Treiber **GoodWe**, 1:1 aus dem produktiven GoodweET-Modul portiert (inklusive
  dokumentierter Firmware-Quirks, BMS-Sonderlogik, Inselerkennung) — das ursprüngliche
  GoodweET-Modul läuft unverändert und unabhängig weiter
- Registeradressen je Variable im Beschreibungsfeld sichtbar (Objekt-Manager)
- Dokumentations-Panel „📖 Dokumentation & Hilfe" in der Instanzkonfiguration
- Diverse Kompatibilitätskorrekturen während der Erstinbetriebnahme: PHP-Type-Hints auf die von
  IP-Symcon geforderten Skalartypen (bool/int/float/string) für öffentliche, gebridgte
  Instanzmethoden reduziert; Protected-Method-Zugriff aus Treiber-Klassen auf
  `ReadPropertyBoolean()` behoben (öffentlicher Wrapper `GetPropBool()`);
  `soc`/`bat1_soc`/`bat2_soc`/`bat1_soh`/`bat2_soh` von Float auf Integer korrigiert
  (`~Battery.100`/`~Intensity.100` sind Integer-Profile)

---

Bekannte Lücken: SolaX-BAT-Detailregister (Einzelspannung/-strom) nicht abgedeckt, Solis nur
Hybrid-Serie, SMA ohne AC-/Netzmessung, Fronius ohne Batterie-Monitoring (siehe README).
