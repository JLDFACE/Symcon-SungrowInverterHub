# InverterHub

IP-Symcon-Modul, das Wechselrichter verschiedener Hersteller direkt im lokalen Netz ausliest
und steuert — ein generisches Treiber-Framework statt eines Moduls pro Hersteller. Die
Anbindung läuft überwiegend per **Modbus TCP**; APsystems EZ1 bildet die Ausnahme und wird
über seine lokale **HTTP-/JSON-API** gelesen.

**Status: Beta.** Die Register-Zuordnungen basieren auf den öffentlich verfügbaren
Modbus-Protokolldokumenten der Hersteller und wurden, soweit möglich, gegen reale Anlagen
geprüft (GoodWe live verifiziert; Fronius, SolarEdge, Kostal, Victron und Huawei durch
Beta-Tester an realen Anlagen erprobt) sowie gegen unabhängige Quellen gegengeprüft — die
Referenzimplementierung [OpenEMS](https://github.com/OpenEMS/openems) für Register-/
Feldoffsets (GoodWe, Fronius, SMA), die Register-Definitionen der `huawei-solar-lib` sowie
von echten Nutzern im
[IP-Symcon-Forum](https://community.symcon.de/c/symcon/vorlagen-modbus/86) geteilte
Modbus-Vorlagen (GoodWe, SolaX, SolarEdge, Deye, Solplanet, Kostal, Victron, Huawei). Rückmeldungen zu
falschen/fehlenden Werten sind willkommen — bitte mit Hersteller, Modell und betroffenem
Register melden.

## Unterstützte Hersteller

| Hersteller | Umfang | Anmerkung |
|---|---|---|
| **GoodWe** | PV (3 MPPT-Tracker), Netz, Batterie 1+2, Meter, Energie, Backup/Insel, EMS-Steuerung | Live verifiziert, produktiv im Einsatz |
| **Sungrow** | PV (4 MPPT), Netz, Batterie, Meter, Energie, Backup, Start/Stop | SH-Hybrid-Serie |
| **Solis** | PV (4 Strings), Netz, Batterie, Meter, Energie | Nur Hybrid-Serie (33000er-Register); reine String-Wechselrichter (3000er) noch nicht unterstützt |
| **Growatt** | PV (3 Strings), Netz, Energie, Temperatur, Fehlercodes | Deckt den über TL-X/TL3-X/MOD/MIX/SPH/WIT gemeinsamen Basisregisterbereich ab |
| **SolaX** | PV (6 Strings), Netz ein-/dreiphasig, Batterie-Systemwerte, Meter/CT | **Wichtig:** Der Wechselrichter spricht nur Modbus RTU. Modbus TCP läuft ausschließlich über ein zusätzliches SolaX-Monitoring-Modul (Pocket WiFi/LAN) als Gateway — dessen IP-Adresse eintragen, nicht die des Wechselrichters |
| **SolarEdge** | PV Gesamtleistung, Netz, Meter (inkl. Bezug/Einspeisung kWh), Energie, Temperatur, Status, StorEdge-Batterie (SOC/Leistung/Spannung/Strom/Temperatur/SOH/Zustand), berechnete PV-Erzeugung, Gerätename/Seriennummer | Reines SunSpec (Integer-Modell 103 mit Skalierungsfaktoren), dieselbe Laufzeit-Discovery wie Fronius/SMA. Der StorEdge-Batterieblock (ab 0xE100) nutzt eine abweichende Byte-Reihenfolge (CDAB), was das Modul automatisch berücksichtigt. Auf StorEdge-Anlagen spiegelt das DC-Register nicht die reine PV wider – dafür die optionale Gruppe „PV-Erzeugung berechnet" aktivieren. |
| **Deye** | PV (2 Strings), Netz, Batterie, Hausverbrauch, Energie, Start/Stop-Steuerung | SG04LP3-Serie, Vorlage von einem 8K-SG04LP3 getestet |
| **Solplanet / AISWEI** | PV (3 Strings), Batterie, Temperatur, Energie | ASW-Gen-Serie |
| **Kostal** | PV (3 DC-Eingänge), Netz, Batterie, Meter, Hausverbrauch nach Quelle, Energie | Nur PLENTICORE plus Generation 1 getestet — andere Generationen/Leistungsklassen ungeprüft. **Wichtig:** Kostal nutzt standardmäßig Port **1502**, nicht 502 — beim Anlegen der Instanz ggf. manuell eintragen. |
| **SMA** | PV Gesamtleistung, Netz, Meter, Energie, Temperatur, Status, Gerätename/Seriennummer | Reine SunSpec-Implementierung mit Laufzeit-Discovery, wie von OpenEMS für SMA Sunny Tripower verwendet |
| **Fronius** | PV (MPPT: Spannung/Strom/Leistung je String), Netz, Meter (Gesamt + optional je Phase U/I/P), Energie, Batterie (GEN24-Hybrid: SOC als Float, Leistung, Spannung), Status, Gerätename/Seriennummer | Reine SunSpec-Implementierung mit Laufzeit-Discovery (keine festen Registeradressen, siehe unten). Der Smart Meter ist ein eigenes Modbus-Gerät mit eigener Unit-ID („Smart-Meter-Adresse", Vorgabe 200, je nach Konfiguration z. B. 240 — im Datenpunkte-Panel einstellbar). Im Wechselrichter muss der Modbus-Server (TCP) aktiviert sein; „Steuerung erlauben" ist nicht nötig, das Modul liest nur. |
| **Victron GX** | PV (DC + AC-gekoppelt, optional je Solarladeregler/MPPT), Netz, Batterie (SOC/Leistung/Spannung/Strom/Zustand), Hausverbrauch, Netz-Quelle | Liest den aggregierten Systemdienst `com.victronenergy.system` (Cerbo GX / Venus OS). **Wichtig:** Unit-ID ist bei Victron ein Geräte-Selektor — der Systemdienst liegt fest auf **100**, das Modul spricht diese automatisch an (die im Formular gesetzte Unit-ID wird bei Victron ignoriert). Port **502**. Im GX unter Einstellungen → Services → Modbus TCP aktivieren. Noch nicht am realen Gerät verifiziert — Vorzeichen von Netz/Batterie ggf. per Invers-Schalter anpassen. |
| **APsystems EZ1** | Leistung gesamt und je Kanal, Ertrag heute/gesamt (gesamt und je Kanal), Alarme (Netzausfall, DC-Kurzschluss je Kanal, Ausgangsfehler), Geräteinformation, Einspeiselimit (nur gelesen) | **Kein Modbus** — der EZ1 spricht eine lokale HTTP-/JSON-API auf Port **8050**; die Unit-ID wird ignoriert. Der lokale Modus muss erst am Gerät freigeschaltet werden: App „AP EasyPower" **per Bluetooth** verbinden (nicht über die Cloud) → Einstellungen → „Lokaler Modus" → aktivieren und „Continuous" wählen; Firmware 1.1.1 kennt den Menüpunkt noch nicht. Solange der lokale Modus läuft, sendet der EZ1 nichts mehr in die APsystems-Cloud. **Rein lesend** (kein `/setMaxPower`, kein `/setOnOff`). **Kein Netzzähler** — Netz-, Hauslast- und Batteriewerte bleiben leer, dafür braucht es einen echten Zähler. Das Gerät antwortet langsam (2,6–9,3 s je Abruf): Schnell-Intervall auf mindestens **20 s** stellen. An einem EZ1-M mit Firmware 1.10.3 live verifiziert. |
| **Huawei SUN2000** | PV (DC-Eingang), Netz, Wirkleistung, Temperatur, Status, Energie, Smart Meter (DTSU666), Batterie (LUNA2000: SOC/Leistung/Spannung/Strom/Temperatur/Zustand) | Native Huawei-Registermap (kein SunSpec), Register/Gain aus `huawei-solar-lib`. Unit-ID des Wechselrichters ist meist **1** (je nach Konfiguration auch 0/16 — im Wechselrichter unter Modbus TCP einstellbar), Port **502**. Modbus TCP muss im Gerät aktiviert sein. Noch nicht am realen Gerät verifiziert — Vorzeichen von Netz/Batterie ggf. per Invers-Schalter anpassen. |

Registeradressen stehen im **Beschreibungsfeld** jeder Variable (Objekt-Manager, Spalte
„Beschreibung") — praktisch zum Abgleich mit dem Herstellerhandbuch oder für eigene Skripte.

## Module in diesem Repository

### InverterHub

Die eigentliche Datenauslese-Instanz. Ein Modul, ein `Manufacturer`-Auswahlfeld — je nach
gewähltem Hersteller werden die passenden Datenpunkt-Gruppen (Checkboxen) und Register
freigeschaltet. Architektur:

- **`IHUB_ModbusTcpClient`** — gemeinsame Modbus-TCP-Grundfunktionen (Read Holding/Input Register,
  Write Single/Multiple, Datentyp-Hilfsfunktionen), von allen Modbus-Treibern genutzt.
- **`IHUB_InverterDriverInterface`** — Vertrag, den jeder Hersteller-Treiber erfüllt (Basisvariablen,
  optionale Gruppen, Profile, `readFast`/`readSlow`/`readDeviceInfo`/`writeControl`).
- **Ein Treiber je Hersteller** (`IHUB_GoodweDriver`, `IHUB_SungrowDriver`, `IHUB_SolisDriver`,
  `IHUB_GrowattDriver`, `IHUB_SolaxDriver`, `IHUB_SmaDriver`, `IHUB_FroniusDriver`,
  `IHUB_SolarEdgeDriver`, `IHUB_DeyeDriver`, `IHUB_SolplanetDriver`, `IHUB_KostalDriver`,
  `IHUB_VictronDriver`, `IHUB_HuaweiDriver`, `IHUB_FoxEssDriver`, `IHUB_ApsystemsDriver`) —
  kapselt die herstellerspezifischen Registeradressen, Skalierungsfaktoren und Eigenheiten.

  Der Transport ist dabei Sache des Treibers, nicht des Interfaces: `IHUB_ApsystemsDriver`
  bekommt denselben Modbus-Client übergeben wie alle anderen, nutzt ihn aber ausschließlich als
  Träger von Host und Port und spricht selbst HTTP. Wer einen weiteren Treiber ohne Modbus baut,
  kann sich daran orientieren — der Hub konstruiert den Client nur und verbindet nicht.

Einrichtung: Instanz anlegen, Hersteller wählen, IP-Adresse (und bei Bedarf Port/Unit-ID)
eintragen, gewünschte Datenpunkt-Gruppen aktivieren, übernehmen.

**Externer Hauslastzähler — Eingang (optional):** Unter „Externer Hauslastzähler — Eingang
(optional)" lässt sich eine bereits vorhandene Variable mit real gemessener Hauslast auswählen
(z. B. ein Shelly am Hausanschluss). Das ist ein **Eingang** — nicht zu verwechseln mit der in
der Kachel optional **ausgegebenen** Variable „Hauslast (berechnet)". Die reine PV/Netz/Batterie-Bilanzschätzung berücksichtigt Wechselrichter-
Eigenverbrauch und Leitungsverluste nicht — mit einem echten Zähler zeigt `InverterHubTile`
die genauere Last sowie die Differenz als eigenen „Wandlungsverluste"-Kreis. Wichtig: hier
einen echten Verbrauchszähler wählen (immer positiv), keinen Netz-/Einspeisezähler — negative
Werte werden ignoriert und die Kachel bleibt bei der Bilanz.

**Energie-Einheit (kWh/Wh):** Standardmäßig werden Energiewerte in kWh ausgegeben. Der Schalter
„Energie in Wh statt kWh ausgeben" (im Datenpunkte-Panel) stellt sie auf die Basiseinheit Wh um
— konsistent zur Leistung (W); die neue IP-Symcon-Darstellung skaliert dann selbst auf
Wh/kWh/MWh. Bestehende Instanzen bleiben ohne Umschalten bei kWh (kein Sprung in der Historie).

**Invers-Schalter:** Je nach Verdrahtung/gewünschter Konvention lassen sich Netz-Leistung
(Meter) und Batterie-Leistung per Schalter invertieren. Der angezeigte Datenpunkt folgt dann
der gewählten Konvention; die `InverterHubTile`-Kachel rechnet beide Schalter intern wieder
auf ihre kanonische Konvention zurück und bleibt dadurch immer korrekt.

### InverterHubDiscovery

Ein **Configurator**-Modul, das einen IP-Bereich im lokalen Netz nach Wechselrichtern auf
Modbus-TCP-Port 502 durchsucht:

1. Start- und End-IP eintragen (wird beim Anlegen anhand des eigenen Netzwerks vorbelegt,
   bleibt aber änderbar), optional eine Namens-Vorlage für neu anzulegende Instanzen.
2. „Netzwerk durchsuchen" klicken — nicht-blockierender Parallel-Scan auf Port 502.
3. Für jede offene IP wird der Hersteller anhand weniger dokumentierter Standard-Unit-IDs und
   eines charakteristischen Registers pro Hersteller erkannt (kein voller 1-247-Scan).
4. Treffer erscheinen in der Ergebnistabelle — Klick auf „Erstellen" legt eine
   `InverterHub`-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.

**IPs ignorieren:** Adressen in dieser Liste werden beim Scan komplett übersprungen — gedacht
für RTU/TCP-Konverter und andere Modbus-Geräte, die sonst fälschlich als Wechselrichter in
der Ergebnisliste erscheinen (solche Geräte leiten Modbus-Anfragen an den dahinterliegenden
Bus weiter und antworten daher mit plausiblen Werten; zuverlässig unterscheiden lässt sich
das nicht). Mehrere IPs Komma-getrennt.

**Namens-Vorlage:** leer lassen für den Standard „Hersteller + laufende Nummer" (z. B.
„GoodWe 1", „GoodWe 2"), oder ein eigenes Muster mit den Platzhaltern `{hersteller}` `{ip}`
`{unitid}` `{nr}` eintragen (z. B. `{hersteller} Dach ({ip})`).

**Bekannte Einschränkung:** Filter/Aktualisieren (oberhalb) und Erstellen/Alle erstellen/
Zielkategorie-Auswahl (unterhalb) der Ergebnistabelle sind fester Bestandteil der nativen
IP-Symcon-Konfigurator-Ansicht — ihre Position und ein „einzeln als gesehen markieren" lassen
sich modulseitig nicht beeinflussen bzw. ergänzen (IP-Symcon-API-Grenze, keine Dokumentation
dafür vorhanden).

### InverterHubTile

Energiefluss-Kachel (Visualisierung) für eine InverterHub-Instanz, unabhängig vom Hersteller.
Die **Hauslast** sitzt im Zentrum, alle übrigen Größen (Solar, Batterie, Netz, optional
Wandlungsverluste sowie frei konfigurierte Verbraucher) werden gleichmäßig **radial** darum
verteilt — in der Reihenfolge Solar (oben), Batterie (rechts), Verbraucher, Netz (unten),
Verluste (links). Fehlt ein Datenpunkt, bleibt die Anordnung ausgewogen, statt eine Lücke zu
hinterlassen. Kreisgröße und -abstand werden aus der Knotenzahl berechnet, sodass sich auch
bei vielen Verbrauchern nie Kreise überlappen und die Kachel ihre Größe behält.

Für die Solar-Anzeige nutzt die Kachel automatisch die berechnete PV-Erzeugung (`pv_real`,
z. B. bei SolarEdge StorEdge), sofern vorhanden, sonst die DC-Gesamtleistung. Über den
Schalter **„Berechnete Hauslast zusätzlich in eine Variable schreiben"** (Panel „Datenquelle")
legt die Kachel die Variable **„Hauslast (berechnet)"** an und aktualisiert sie live — nutzbar
für Automationen, Charts usw.

**Ohne InverterHub-Instanz (manuelle Datenpunkte):** Die Kachel funktioniert auch ganz ohne
InverterHub. Wird oben keine InverterHub-Instanz gewählt, speist sie sich aus dem Panel
**„Manuelle Datenpunkte"** — dort lassen sich einzelne Variablen für PV-Leistung, AC-Leistung,
Netz-/Zählerleistung, Batterie-Leistung, SOC und optional einen externen Hauslastzähler direkt
zuweisen (z. B. von einem anderen Wechselrichter-Modul oder Zählern). Je Leistungswert ist die
Einheit wählbar (Automatisch/W/kW/MW), und Netz sowie Batterie haben einen eigenen
Invers-Schalter. Alle Werte sind optional; je mehr zugewiesen ist, desto vollständiger die
Darstellung.

**Weitere Verbraucher (optional):** Im Panel „Weitere Verbraucher" lassen sich **beliebig
viele** zusätzliche Verbraucher als Tabelle pflegen — je Zeile **Art**, **Bezeichnung**,
**Leistungs-Variable** und **Einheit**. Sie kommen nicht aus dem Wechselrichter, sondern werden
aus vorhandenen Variablen gespeist und erscheinen als eigene Kreise. Verfügbare Arten (bestimmen
das Icon): Wallbox, Wärmepumpe, Klimaanlage, Pool-Wärmepumpe, Pool-Pumpe, Sauna, Warmwasser,
Trockner, Sonstiger Verbraucher. Mehrere Zeilen derselben Art sind möglich (z. B. zwei
Wallboxen „Garage" und „Carport"); eine leere Bezeichnung fällt auf die Vorgabe der Art
zurück. Die **Einheit** steht auf „Automatisch" (erkennt W/kW/MW am Profil-Suffix der Variable)
und lässt sich bei fehlendem Profil manuell setzen — intern rechnet die Kachel alles in Watt um,
sodass Quellen in kW (viele Wallboxen) korrekt dargestellt werden.

**Wallboxen mit Fahrzeug-Ladestand:** Eine Wallbox wird als **Auto** dargestellt, das – wie das
Batteriesymbol – den Ladestand des gerade angeschlossenen Fahrzeugs als Füllung samt
Prozentwert zeigt; ohne Fahrzeug bleibt nur der Umriss. Der Name des erkannten Fahrzeugs steht
als Zusatzzeile im Kreis.

Dafür gibt es die Tabelle **Fahrzeuge** (Bezeichnung, Ladestand-Variable, Verbunden-Bedingung)
sowie je Wallbox-Zeile eine eigene Verbunden-Bedingung. Eine Bedingung besteht aus **Variable +
Vergleich + Wert**, weil jede Quelle das Einstecken anders meldet:

| Beispiel | Typ | Bedingung |
|---|---|---|
| „Ladeportklappe offen" (Fahrzeug) | Boolean | ist gesetzt |
| „Ladekabeltyp" (Fahrzeug) | Text, leer = kein Kabel | ist gesetzt |
| „Kabel-Leistungsfähigkeit" (go-e) | Integer, 0 = kein Kabel | ist gesetzt |

**Welches Auto steht an welcher Wallbox?** Das ermittelt das Modul selbst — ein Datenpunkt, der
das benennt, wird *nicht* benötigt (die wenigsten Anlagen haben so etwas). Beim Einstecken
wechseln Wallbox und Fahrzeug jedes für sich auf „verbunden", und zwar praktisch gleichzeitig.
Das Modul vergleicht dafür die Zeitpunkte der letzten **Wertänderung** (IP-Symcon führt die
ohnehin mit) und ordnet die zeitlich am besten passenden Paare eindeutig zu. Bei zwei Autos an
zwei Wallboxen landet damit jedes dort, wo es tatsächlich eingesteckt wurde. Das Zeitfenster
ist einstellbar (Vorgabe 300 s; 0 = ohne Begrenzung). Bei genau einer Wallbox und genau einem
Fahrzeug ist die Lage ohnehin eindeutig — dort darf die Verbunden-Bedingung auch fehlen.

Die Farben sind semantisch fest vergeben: Solar = Sonnengelb, Netz = Grün bei Einspeisung/Rot
bei Bezug, Batterie = Blau, Verluste = Grau, Hauslast = weicher Grün-Rot-Verlauf je nach
Anteil aus Netzbezug vs. PV/Batterie. Zusätzliche Verbraucher haben je Art eine eigene Farbe
(Wärme in Feuertönen, Kühlung/Wasser in Türkis, Fahrzeuge in Violett) und lassen sich je Zeile
auch frei einfärben.

**Energiefluss:** Zwischen Hauslast und jedem Knoten läuft eine Speiche mit glimmendem Leiter,
darauf wandern Dreiecke in Flussrichtung, begleitet von wabernden Teslaspulen-Blitzen an Leitungen und Kreiskanten. Die Richtung folgt
dem Vorzeichen (Netz: Bezug zum Haus / Einspeisung nach außen; Batterie: Laden nach außen /
Entladen zum Haus), das Tempo der Leistung — die Leistung, ab der Höchsttempo erreicht wird, ist einstellbar.

Kreise mit nennenswertem Leistungsfluss erscheinen groß, farbig und plastisch (Münz-Optik mit
Wölbung, Kantenanschliff, Glanzlicht und geprägten Icons/Werten) samt Corona, deren Stärke mit
der Leistung wächst (0 W = keine, 40 kW = maximal). Kreise ohne Fluss treten klein, grau und
flach zurück; der Wechsel läuft gleitend in einstellbarer Zeit ab.

Da nicht jeder Treiber dieselben Datenpunkte liefert, entfällt ein Kreis, wenn die zugehörige
Größe bei der gewählten Quelle fehlt (z. B. keine Netzmessung bei Growatt, keine Batterie bei
SMA/Fronius/SolarEdge), statt falsche Werte zu zeigen. Ist in der Quell-Instanz ein
Hauslastzähler konfiguriert, erscheint zusätzlich der „Verluste"-Kreis (Differenz zwischen
Bilanzschätzung und echtem Zähler).

Hintergrundfarbe, Schriftart und die Übergangszeit für den gleitenden Wechsel aktiv/inaktiv
sind über die Instanzkonfiguration anpassbar; die Kachel skaliert vollständig automatisch mit
der Widget-Größe.

Einrichtung: Kachel-Instanz anlegen, unter „Datenquelle" die gewünschte InverterHub-Instanz
auswählen.

### InverterHubEnergy (Energiefluss / Sankey)

Zweite Visualisierung: ein **Sankey-Diagramm**, das zeigt, **wohin die Energie über einen
Zeitraum geflossen ist** — Quellen (Solar, Batterie-Entladung, Netzbezug) links, Verbraucher
(Batterie-Ladung, Hausverbrauch bzw. Einzelverbraucher, Netzeinspeisung) rechts, jeweils mit
Anteil in %. Zeitraum wählbar: **Tag / Woche / Monat / Jahr / Gesamt** oder **angepasst**
(Von/Bis).

Die Werte kommen ausschließlich aus dem **IP-Symcon-Archiv** der zugewiesenen
**Energie-Zählervariablen** (z. B. „Ertrag Gesamt", „Bezug/Einspeisung Gesamt", „Bat.
Laden/Entladen Gesamt") — es wird nichts selbst berechnet oder zusätzlich mitgeführt.
Voraussetzung: Die Variablen sind akkumulierende Zähler mit aktivierter Archivierung
(Aggregation „Zähler"). Alle Datenpunkte sind optional; fehlt einer, entfällt der Knoten.

Einzelne Verbraucher (Wärmepumpe, Wallbox …) lassen sich mit ihrer eigenen archivierten
Energievariable eintragen — sie werden aus dem Hausverbrauch herausgelöst, der Rest erscheint
als „Sonstiger Verbrauch".

**Darstellung:** 3-stufiges Sankey (Erzeugung/Bezug → **Batterie als Puffer** → Verbrauch/
Einspeisung). Die Batterie ist ein Zwischenknoten (Zufluss = Ladung, Abfluss = Entladung),
taucht also nicht doppelt auf. Als Engine ist wahlweise **Apache ECharts** oder **Highcharts**
wählbar (wie in der Prognosekachel). Interaktive Tooltips zeigen je Knoten Durchsatz/Anteil
(Batterie zusätzlich „geladen/entladen") und je Fluss Quelle → Ziel mit kWh und Anteil. Die
Flussaufteilung folgt einem Energiebilanz-Modell (Netzeinspeisung und Batterie-Ladung aus PV;
Verbrauch anteilig aus PV/Batterie/Netz).

### InverterHubMonitor (Monitoring)

Monitoring-Kachel mit **Intraday-Zeitreihen aus dem Archiv** (à la Meteocontrol VCOM
„Tatsächliche Leistung"). Beliebige archivierte Variablen werden als Verlaufsdiagramm über
einen wählbaren Tag dargestellt (~5-Minuten-Auflösung), wahlweise mit **Highcharts oder
ECharts**. Zwei Y-Achsen (links/rechts) — typischerweise **PV-Leistung** links und ein
**Einstrahlungssensor** (W/m²) rechts. So lässt sich **Verschmutzung oder ein Defekt** erkennen:
an einem sauberen Tag laufen Leistung und Einstrahlung proportional, bei Abweichung nach unten
ist die Anlage zu prüfen. Bedienung wie die Sankey-Kachel: ◄ ► und Kalender zur Tagesauswahl
(navigierbar sind die letzten 8 Tage).

Weitere Ansichten (Wochen-/Monats-/Jahres-Auswertung, Energie-Balken, normalisierte KPIs mit
kWp-Kalibrierung und automatische Verschmutzungs-/Defekterkennung) folgen als nächste Stufen.

#### Diagnostik-Vertrag `IHUBMON_GetDiagnostics($id)` (für NRGDashboard)

Zielarchitektur (mit dem NRGDashboard-Modul abgestimmt, 25.07.2026): Die **Diagnose-Logik**
bleibt hier — wir kennen die Wechselrichter-Details und treffen die Bewertung —, die
**Darstellung** soll künftig zentral über NRGDashboard laufen statt über eine eigene Kachel.
`InverterHubMonitor` bleibt vorerst vollständig nutzbar; der Vertrag ist ein zusätzliches
Angebot, kein Ersatz.

`IHUBMON_GetDiagnostics($id)` liefert eine Liste von Diagnose-Einträgen:

- **`yield_vs_forecast`** — gemessener Ertrag (Variablen-Referenz) gegen die aus
  PV-Prognose-Generatorparametern × Einstrahlung berechnete Erwartung (Wert), mit Bewertung.
- **`mppt_string_compare`** — erkennt einen deutlich schwächeren MPPT-Einzelstrang.
- **`riso`** — Isolationswiderstand gegen eine vom Nutzer gesetzte Warnschwelle (kΩ).

**Konvention** (gilt als Muster für künftige Diagnose-Verträge anderer Hub-Module im NRG-Stack):

| Feldart | Form | Grund |
|---|---|---|
| gemessene Rohgröße | Variablen-**Referenz** (`…ID`) | Konsument zeichnet Zeitreihen selbst aus dem IPS-Archiv |
| berechneter Vergleichswert | **Wert** | unser Domänenwissen, beim Konsumenten nicht nachzubauen |
| Bewertung | **Metadaten** (`level`, `threshold`, `reason`) | die Einstufung trifft immer der Anbieter, nie der Konsument |

`contractVersion` ist von Anfang an dabei (`'1.0'`), `level` ist `null`, wenn (noch) keine
Bewertung möglich ist (z. B. zu wenig Erzeugung für eine sinnvolle Aussage oder keine Schwelle
konfiguriert) — der Rohwert wird trotzdem geliefert.

## Fronius und SMA: Hinweis zur SunSpec-Discovery

Beide Hersteller sprechen den offenen SunSpec-Standard statt eigener Register (bei Fronius
von Fronius selbst so dokumentiert — Registeradressen sind **nicht konstant** und hängen von
der jeweiligen Modellkette ab; bei SMA folgt dieses Modul dem Vorbild von OpenEMS, das den
SMA Sunny Tripower ebenfalls rein über SunSpec anspricht). `FroniusDriver` und `SmaDriver`
durchlaufen deshalb bei jedem Lesezyklus die Modellkette ab Basisregister 40000 (Common Block,
dann Model-ID + Länge je Block), statt feste Adressen zu verwenden. Das ist etwas langsamer als
bei den anderen Herstellern, aber der zuverlässigste Weg.

## Verwandte Projekte

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, listet
[SUITE.md](https://github.com/DG65/EMS/blob/main/SUITE.md).

### EnergiePrognose (PV- und Verbrauchsprognose)

**[Prognose](https://github.com/DG65/Prognose)** liefert die Prognosen, gegen die dieses
Projekt die Messwerte stellt. Genutzt wird davon das Modul **PV-Prognose** (Präfix `PVF`):

- Der **InverterHubMonitor** berechnet aus den dort gepflegten Generatorparametern (kWp je
  Generator, Performance-Ratio) zusammen mit einem Einstrahlungssensor **Erwartungswerte** und
  stellt sie dem gemessenen Ertrag gegenüber — gestrichelt im Diagramm. Damit lassen sich
  Verschmutzung und Defekte erkennen (Soll/Ist-Vergleich).
- Ist das Prognose-Modul nicht installiert, weist die Konfigurationsmaske darauf hin und die
  Erwartungswerte entfallen; alles andere funktioniert unverändert.

### Tessie (Tesla-Fahrzeuge) — optional, rein über Konfiguration

Die Stromflusskachel kann an einer Wallbox den **Ladestand des angesteckten Fahrzeugs** und
dessen Namen anzeigen. Die Fahrzeug-Tabelle der Kachel ist dabei bewusst **herstellerneutral**:
Pro Fahrzeug werden Bezeichnung, eine „Verbunden"-Bedingung und eine SOC-Variable eingetragen —
gleich, woher diese Variablen stammen. Mit dem Modul
**[Tessie](https://github.com/DG65/Tessie)** lassen sich dafür die Fahrzeugdaten eines Teslas
nutzen (dessen `Soc`-Variable und ein Verbunden-Kennzeichen).

Es besteht **keine Code-Abhängigkeit** zu Tessie: Die Kachel ruft dort nichts auf, sondern liest
nur die eingetragenen Variablen. Jede andere Quelle funktioniert genauso.

### MeterHub (Energiezähler)

**[MeterHub](https://github.com/DG65/MeterHub)** ist das Schwester-Repository dieses Projekts:
dasselbe Framework-Prinzip, aber für **Energiezähler** statt Wechselrichter (Modbus TCP, z. B.
Siemens PAC2200, Janitza UMG604/UMG800). Beide Projekte sind eigenständig nutzbar und ergänzen
sich, wo beide installiert sind:

- **Gerätesuche:** Der `InverterHubDiscovery` findet auf Wunsch in einem Durchlauf sowohl
  Wechselrichter als auch Zähler und legt Zähler direkt als MeterHub-Instanzen an. Ist MeterHub
  nicht installiert, werden gefundene Zähler übersprungen.
- **Stromflusskachel:** Die `InverterHubTile` kann ihre Verbraucher-Kreise automatisch aus
  MeterHub-Instanzen beziehen (Funktionszuordnung → Art, Bezeichnung, Leistungsvariable). Ein
  MeterHub-Zähler mit Funktion „Netzanschluss" speist die Netzleistung, einer mit
  „Hausverbrauch" die gemessene Hauslast — die Kachel läuft dadurch auch ganz ohne
  InverterHub-Instanz. Ohne MeterHub verhält sich die Kachel unverändert.

Die Kopplung ist in beide Richtungen optional: Keines der Module setzt das andere voraus, und
fehlt das jeweils andere, entfallen lediglich die genannten Zusatzfunktionen.

## Installation

Über die IP-Symcon Modulverwaltung „Hinzufügen" mit der URL dieses Repositories:

```
https://github.com/DG65/InverterHub
```

Für den Beta-Kanal den Zweig `beta` auswählen.

### Zusammenspiel mit den anderen Modulen: wie sie sich finden

Alle Kopplungen sind **optional** — kein Modul setzt ein anderes voraus. Fehlt ein Partner,
entfallen nur dessen Zusatzfunktionen. Gefunden wird auf **drei verschiedene Arten**, und nur
eine davon läuft von selbst:

| Kopplung | Wie sie zustande kommt |
|---|---|
| Monitoring → **PV-Prognose** | **automatisch**, sobald das Prognose-Modul installiert ist |
| Gerätesuche → **MeterHub** | **automatisch**: gefundene Zähler werden als MeterHub-Instanzen angelegt |
| Kachel/Sankey → **MeterHub**, **HeishaMon** | **manuell**: Instanzen in der jeweiligen Liste im Konfigurationsformular eintragen |
| Wallbox-Ladestand (z. B. Tessie), gemessene Hauslast | **manuell**: Variable auswählen |

**Der häufigste Stolperstein:** Wer ein Partnermodul **nachträglich** installiert, muss die
Konfiguration der Kachel bzw. der Sankey-Ansicht noch einmal öffnen und die neue Instanz in die
Liste eintragen. Es passiert nichts von selbst — mit Ausnahme der PV-Prognose. Wenn also nach
der Installation von MeterHub keine Verbraucher auftauchen, fehlt fast immer dieser Schritt.

### Empfohlene Reihenfolge

Die Reihenfolge ist nicht zwingend (nichts geht kaputt), erspart aber Nacharbeit:

1. **InverterHub** installieren, Wechselrichter anlegen — oder von der **Gerätesuche** finden lassen
2. **MeterHub**, falls Energiezähler vorhanden
3. **HeishaMon**, falls Wärmepumpe vorhanden
4. **PV-Prognose** einrichten (Generatoren mit kWp eintragen)
5. **Kacheln** zuletzt: Stromfluss, Monitoring, Sankey — dann bieten deren Listen bereits alles an

### Wie viele Instanzen?

- **Wechselrichter, Zähler und Wallboxen:** beliebig viele — je Gerät eine Instanz.
- **EMS:** genau **eine**. Es ist die einzige Stelle, die steuernd auf die Batterie zugreift;
  eine zweite Instanz würde gegen die erste arbeiten.
- **Kacheln:** beliebig viele, etwa eine je Ansicht oder Raum.

## Mitwirken / Fehler melden

Rückmeldungen zu falschen Registerwerten, fehlenden Datenpunkten oder neuen unterstützten
Modellen gerne als Issue auf GitHub. Besonders hilfreich: Hersteller, Modellbezeichnung,
betroffenes Register/Ident und beobachteter vs. erwarteter Wert.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
