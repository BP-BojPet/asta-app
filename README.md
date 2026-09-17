# AStA-App

Euer eigenes Werkzeug für **Termin-Abstimmungen, Helfer-Einteilung, Sitzungen, Berichte und Kalender** – eine selbst gehostete Alternative zu dudle und zum Teams-Kalender, alles an einem Ort. Es läuft komplett bei euch, niemand von außen sieht etwas, und es kostet nichts extra.

Die Anleitung ist nach Rollen sortiert, und jede:r sieht nur den Teil, der sie:ihn betrifft:

- **Alle Mitglieder** lesen den ersten Teil – in der App unter [Wichtige Infos & Anleitung](info.php).
- Das **Sekretariat** bekommt dort zusätzlich seinen eigenen Abschnitt, die **Pat:innen-Verantwortlichen** ihren.
- **Vorsitz & Admin** sehen die vollständige Fassung unter [Verwaltung → Anleitung](admin/help.php), inklusive Einrichtung und Technik-Anhang.

---

## Für alle Mitglieder

### Anmelden – ganz ohne Passwort
Du meldest dich mit deiner **E-Mail-Adresse** an: Adresse eingeben, „Login-Link schicken", in den Posteingang schauen, auf den Link klicken – fertig. Kein Passwort. Der Link gilt 30 Minuten und nur einmal; „Eingeloggt bleiben" merkt dich 60 Tage auf diesem Gerät.

**Du hast die App installiert?** Auf dem iPhone öffnet der Link aus der Mail leider den Browser statt der App. Deshalb steht in derselben Mail auch ein **Code**: In der App auf „Anmelden → App installiert? Code eingeben" tippen, Code eintragen, fertig.

**Mehrere Personen mit einer Adresse?** Nach dem Klick wählst du aus, als wer du dich anmeldest; über „Konto wechseln" (Klick auf deinen Namen oben rechts) springst du hin und her.

Wer noch keinen Zugang hat, wird vom Vorsitz oder Sekretariat angelegt und eingeladen.

### Dein Dashboard
Nach dem Login landest du auf dem [Dashboard](dashboard.php). Von oben nach unten:

- **Nachrichten** von Orga, Vorsitz oder Admin. Manche haben ein Sprungziel: „Ansehen" bringt dich direkt hin, „Gelesen" blendet nur aus.
- **Offene Aufgaben** – wo du noch abstimmen musst, ob ein Bericht fehlt, ob noch ein „Evtl." offen ist, ob es neue wichtige Infos gibt oder ungelesene Abstimmungsgegenstände.
- **Deine nächsten Einsätze** und die **nächste Sitzung**.
- In der **Begrüßungskachel**: deine Streak und der Doppel-Score (Basis-Score und Eventscore, beide anklickbar).

### Kalender & Kalender-Abo
Der [Kalender](index.php) zeigt Sitzungen, Events, Abwesenheiten und Geburtstage – als Monats- oder Wochenansicht. Deine eigenen Einsätze, offene Abstimmungen und besonders wichtige Events sind hervorgehoben.

Über deinen **persönlichen Abo-Link** (im Dashboard, mit QR-Code) abonnierst du das Ganze auf Handy, Outlook oder Google. Es aktualisiert sich von selbst und enthält alle Sitzungen sowie die Schichten, zu denen du eingeteilt bist. Der Link ist geheim – bitte nicht weitergeben.

Unter dem Kalender liegen vier Knöpfe:

- **StuPa-Sitzungen ausblenden** – blendet sie in App **und** Abo aus (hängt an deinem Konto, nicht am Gerät).
- **Geburtstage ausblenden** – nur im App-Kalender.
- **Termin anlegen** – ein freier Termin mit Titel, Zeit, Ort und Notiz. Über „Für wen?" wählst du: nur für dich, für bestimmte Personen oder für alle. Er erscheint bei den Betreffenden im Kalender und im Abo.
- **Terminfinder** – siehe unten.

### Events: zusagen, einspringen, tauschen
Ein Event hat einen oder mehrere Termine bzw. Schichten. Du klickst pro Schicht **Ja / Evtl. / Nein**. Nach der Abstimmungsfrist ist Schluss.

**„Evtl." ist nur vorläufig.** Legst du dich bis zur Frist nicht fest, wird daraus automatisch **Nein**. Solange etwas offen ist, erinnert dich das Dashboard, am Tag vor der Frist zusätzlich eine Nachricht.

Bist du an dem Tag als **abwesend** eingetragen und sagst trotzdem zu, warnt dich die App.

Solange abgestimmt wird, steht die Übersicht „Wer kann wann" im Vordergrund. **Sobald der Arbeitsplan veröffentlicht ist**, steht er ganz oben, und die Abstimmungsübersicht klappt zusammen.

**Kurzfristig einspringen:** Bleiben Schichten unterbesetzt, kannst du dich selbst eintragen – auch ohne vorher zugesagt zu haben. Das geht in der [Events-Übersicht](events.php) im Block „Kurzfristig einspringen" und auf der jeweiligen Event-Seite. Möglich ist es nur, wenn du in der Zeit keinen anderen Einsatz hast.

**Schichten tauschen:** In der [Schichtbörse](tauschboerse.php) handelst du Schichten innerhalb eines Events – entweder **1:1-Tausch** oder **Abgeben**.

- Beim **Tausch** bietest du eine Schicht an; wer sie will, schlägt eine eigene als Gegenwert vor. Getauscht wird erst, wenn **du zustimmst** – niemand kann dir eine Schicht aufdrücken. Mehrere dürfen dieselbe Schicht anfragen; nimmst du eine an, verfallen die anderen.
- Beim **Abgeben** legst du eine Schicht ohne Gegenwert hinein. Sie ist als „frei" markiert und **jede:r kann sie sofort übernehmen**, ohne dich zu fragen.

Die App lässt beides nur zu, wenn es zeitlich bei allen passt. Der Eventscore zieht automatisch nach: Wer die Schicht hat, bekommt die Punkte. Von der Orga **fixierte** Schichten (Schloss-Symbol) lassen sich nicht tauschen. Die Börse öffnet mit dem veröffentlichten Arbeitsplan und schließt standardmäßig **3 Tage vor Event-Start** (der Vorlauf ist global einstellbar). Die Orga kann sie für ihr Event über „Schichtbörse offen halten" auch innerhalb der Frist oder nachträglich wieder öffnen.

Ganz unten in der Börse liegt das **Schicht-Depot** – ein komplett wertloses Spaß-Aktiendepot, das mit jedem Tausch „steigt". Es hat keinerlei Bedeutung.

### Wenn du selbst ein Event organisierst
Jedes Mitglied darf Events anlegen: [Events](events.php) → „Event anlegen". Titel, Zeitraum, Abstimmungsfrist und direkt die **Schichten** (Name, Datum, Start/Ende, Ort, Helferzahl). Die Endzeit ist Pflicht und gilt als geschätzt – sie dient vor allem der Arbeitszeit-Auswertung. Eine Schicht über Mitternacht trägst du einfach mit der früheren Endzeit ein (21:00–02:00), sie wird als nächster Tag verstanden.

- **Bis zu 3 Zusatz-Merkmale** je Event (z. B. Führerschein, Erste-Hilfe). Pro Schicht legst du fest, wie viele Träger:innen gebraucht werden; die Auto-Einteilung besetzt diese Plätze zuerst.
- **Als Entwurf anlegen** macht das Event zunächst nur für dich sichtbar – gut zum Vorbereiten.
- **Als Vorlage speichern** merkt sich Struktur und Schichten für das nächste Mal. Vorlagen dürfen alle nutzen.
- **Bis zu vier Besitzer:innen** dürfen ein Event gleichberechtigt verwalten.
- **Schichten nachträglich bearbeiten** geht jederzeit, ohne Rückmeldungen oder Einteilung zu verlieren. Nur „Löschen" entfernt eine Schicht samt Antworten.

**Den Arbeitsplan machen** (nach der Frist):

- **Zufällig (fair)** teilt nur „Ja"-Zusagen ein, meidet Abwesende und Zeitkonflikte und berücksichtigt geforderte Merkmale zuerst. Beim Klick wählst du, wonach ausgeglichen wird: **nach Schichten** (gleich viele Einsätze) oder **nach Stunden** (gleich viel Zeit). Erst bei Gleichstand gibt der Eventscore einen kleinen Ausschlag – er dominiert die Einteilung nie.
- **Manuell** per Häkchen.
- **Fixieren (Schloss)** hält eine Person auf ihrer Schicht, während der Rest neu gemischt wird. Ein Schicht-Schloss fixiert alle dort Eingeteilten auf einmal. Fixierungen bleiben auch nach dem Veröffentlichen und sperren die Schicht für die Schichtbörse.
- **Externe Helfer** trägst du im gleichnamigen Block ein – Personen ohne Login. Sie senken den Mitglieder-Bedarf der Schicht.

**Wichtig:** Solange du am Plan arbeitest, sieht ihn **nur die Orga**. Mitglieder sehen ihre Einsätze erst nach dem **Veröffentlichen** – dann überall gleichzeitig (Dashboard, Kalender, Abo, Erinnerungen, Eventscore). Beim Veröffentlichen wählst du „Ohne Mail" oder „Mit Mail senden". „Arbeitsplan ändern" nimmt ihn wieder zurück in den Entwurf.

Nach dem Veröffentlichen stehen dir zusätzlich zur Verfügung:

- der fertige Plan als **Druckansicht/PDF** (A4 quer, chronologisch, bei mehrtägigen Events mit Tages-Trennern),
- der Block **„Überschneidungen im Arbeitsplan"**, der Doppelbelegungen findet,
- der Block **„Fairteilung"** mit Schichten und Arbeitszeit je Person, Abweichung vom Schnitt und aufklappbaren Details,
- ein **✕** bei kurzfristig Eingesprungenen, um sie wieder aus der Schicht zu nehmen (sie bekommen dann einen Hinweis aufs Dashboard).

Alle Mitglieder sehen an derselben Stelle nur die schlanke Kachel **„Dein Anteil an diesem Event"** – eigene Schichten und Arbeitszeit im Vergleich zum Schnitt, ohne Namen oder Werte anderer.

**Nachricht an Teilnehmende:** Im Bearbeiten-View schreibst du allen, die zugesagt haben oder eingeteilt sind, direkt aufs Dashboard.

### AStA Get-Togethers
Neben den Helfer-Events gibt es **Get-Togethers** – interne, **völlig freiwillige** Treffen (Spieleabend, Kneipentour, Grillen). Sie stehen im Kalender (violett, 🎉) und auf der Events-Seite.

Beim Anlegen bekommen alle **einmal** Bescheid. Du wirst um eine kurze Rückmeldung gebeten – **mehr nicht**: keine Erinnerungen, kein Eintrag im Basis-Score, keine Konsequenz. Wer absagt, für den ist das Thema erledigt. Mit „Vielleicht" hältst du dir alles offen; sagst du ab, verschwindet das Treffen aus deinem persönlichen Kalender und Abo.

Auf der Detailseite siehst du namentlich, wer kommt, und die **Mitbring-Liste**: Jede:r trägt ein, was sie:er mitbringt, und die Orga kann Bedarf festlegen, der offen stehen bleibt, bis ihn jemand übernimmt. Anlegen und verwalten können Get-Togethers Sekretariat, Vorsitz und Admin unter Events → Get-Togethers.

### Sitzungen: Tagesordnung, Teilnahme, Redeliste
Unter [Sitzungen & Berichte](report.php) stehen die kommenden Sitzungen zum Ansehen und Teilen. Über den Kalender oder den Titel öffnest du die **Detailseite** mit Datum, Ort, Nummer und der vorläufigen **Tagesordnung**. Die TO hat einen öffentlichen und einen internen Teil (gelb markiert).

**TOP einreichen:** Du schlägst selbst einen Punkt vor – öffentlich oder intern, optional an einer bestimmten Position. Ohne Angabe landet er vor dem „Sonstiges" seines Teils, ein interner also vor „Sonstiges (intern)". Öffentliche TOPs brauchen eine ungefähre Dauer und eine kurze Beschreibung. Das geht nur **bis zur Einreichefrist** (üblicherweise 5 Tage vorher, dann wird eingeladen).

**Abstimmungsgegenstände:** Punkte, über die in der Sitzung abgestimmt wird, oft mit Dateien. Die solltest du **vorher lesen** und als gelesen markieren. Ungelesene erscheinen als Aufgabe im Dashboard, zwei Tage vor der Sitzung wirst du zusätzlich erinnert.

**Abmelden oder online teilnehmen:** Im Block „Deine Teilnahme" meldest du dich ab oder vermerkst, dass du nur online dabei bist – beides mit „Doch in Präsenz dabei" wieder zurücknehmbar. Ein **Grund ist Pflicht**, geht aber **ausschließlich an den Vorsitz** und ist nirgends öffentlich. Möglich ist das bis **18:00 Uhr am Sitzungstag**, danach ist der Stand eingefroren. Im Dashboard und in der Sitzungsliste gibt es dafür den kleinen Tür-Knopf. Abgemeldete landen automatisch als entschuldigt in der Protokollvorlage.

**Redeliste:** Jede Sitzung hat ihre eigene. Auf der Detailseite steht „Redeliste beitreten" – dein persönlicher Link mit Name und Pronomen. Beim Beitreten wählst du einmalig **FLINTA*- oder offene Liste**, das gilt für die ganze Sitzung. Läuft gerade eine Sitzung, führt auch der rote Knopf im Dashboard direkt hin.

- Trittst du vor dem Start bei, siehst du einen Wartebildschirm mit dem geplanten Beginn.
- Neben dem normalen Melden gibt es zwei Sonder-Meldungen: **Direkte Nachfrage** (orange) und **GO-Antrag** (rot). Sie springen vor die normalen Wortmeldungen, GO-Anträge ganz nach oben. Nochmal tippen zieht die Meldung zurück.
- Auf dem Handy öffnet sich automatisch die **Mini-Ansicht**; mit „Vollansicht" wechselst du.
- Damit dein Name mit **Pronomen** erscheint, hinterlege sie einmal in [deinem Profil](profil.php).

### Dein Referats-Bericht
Hast du ein Referat, bist du berichtspflichtig. Unter [Sitzungen & Berichte](report.php) trägst du vor jeder berichtspflichtigen Sitzung **stichpunktartig** ein, was du gemacht hast – ein Punkt pro Zeile, ohne Striche (die Aufzählungszeichen kommen automatisch, eine Vorschau zeigt das Ergebnis). Mehrere Personen im selben Referat teilen sich einen Bericht und ergänzen ihn gemeinsam.

Drei Tage vor der Sitzung taucht die Aufgabe im Dashboard auf, einen Tag vorher wird sie rot und du wirst erinnert.

### Abstimmungen & Umlaufbeschlüsse
Die Seite [Abstimmungen](umlauf.php) bündelt zwei Werkzeuge. Beide kann **jedes Mitglied** starten.

**Umlaufbeschluss** – der AStA entscheidet **außerhalb von Sitzungen verbindlich**. Beim Start legst du fest:

- **Sichtbarkeit:** namentlich (alle sehen, wer wie gestimmt hat) oder geheim (nur die Zählung, und der Zwischenstand bleibt bis zum Schluss verborgen).
- **Annahme-Regel:** einfache Mehrheit (mehr Ja als Nein unter den abgegebenen Stimmen), Mehrheit aller Stimmberechtigten (wer nicht abstimmt, wirkt wie Nein) oder einstimmig.
- **Mindestbeteiligung** (optional): 50 %, 67 % oder 75 %. Wird sie verfehlt, ist der Umlauf ungültig.
- Eine **Frist** ist Pflicht.

Abgestimmt wird mit **Ja / Nein / Enthaltung**, änderbar bis zum Abschluss. **Abstimmen ist verpflichtend**: Es gibt eine Pflicht-Aufgabe im Dashboard und Erinnerungen vor der Frist. Der Umlauf endet automatisch, sobald alle abgestimmt haben oder die Frist abläuft; das Ergebnis geht an alle. Abgeschlossene Umläufe bleiben als **Beschluss-Archiv** stehen, mit eingefrorener Zählung.

**Normale Abstimmung/Umfrage** – für alles, was kein förmlicher Beschluss sein muss. Eigene Frage, eigene Antwortoptionen, wahlweise Einfach- oder Mehrfachauswahl, namentlich oder geheim, mit oder ohne Frist. Und: **freiwillig oder verpflichtend**. Freiwillige erscheinen im Dashboard klar als „freiwillig" markiert und lassen sich mit **„Ausblenden"** dauerhaft wegklicken; verpflichtende verhalten sich wie ein Umlauf und brauchen eine Frist.

Beide Vorgänge können **Datei-Anhänge** tragen. Herunterladen können sie alle Mitglieder; nachreichen und löschen die Person, die den Vorgang gestartet hat, sowie Vorsitz und Admin.

### Terminfinder & eigene Termine
Der **Terminfinder** (Knopf unter dem [Kalender](index.php)) ist eine Doodle-artige Terminabstimmung: Du schlägst mehrere Termine vor, optional mit Frist, und **alle** werden im Dashboard verpflichtend zum Abstimmen aufgefordert (Ja / Evtl. / Nein je Vorschlag). Bis zur Frist darf jede:r die eigene Antwort ändern.

Das Ergebnis ist eine Übersicht nach Beliebtheit – oben steht der Termin, an dem die meisten können (👑). Der Terminfinder **entscheidet nichts und legt keine Sitzung an**; die legt ihr danach von Hand an. Unter dem Kalender stehen laufende und abgeschlossene Abstimmungen.

**Für Termine außerhalb des AStA** gibt es zusätzlich den [öffentlichen Terminplaner](termin/) – dasselbe Prinzip, aber ohne Konto und **für alle offen**, nicht nur für Leute an der Hochschule. Den kannst du für deine Fachschaft, dein Seminar oder jede andere Gruppe benutzen, in der nicht alle einen App-Zugang haben; er ist nicht mit der App verbunden und weiß nichts von Mitgliedern, Dashboard oder Pflichtaufgaben.

### Abwesenheit eintragen
Unter [Abwesenheit](absence.php) trägst du ein, wann du nicht kannst. Die App warnt dich dann, wenn du an so einem Tag zusagst, und der Vorsitz bekommt eine Nachricht mit Zeitraum, Grund und dem Hinweis, ob sich das mit Events überschneidet.

**Sitzungen im Zeitraum:** Du wirst dort automatisch **entschuldigt abgemeldet** – das erscheint auch so in der Protokollvorlage. Löschst du die Abwesenheit wieder, werden nur diese automatischen Abmeldungen zurückgenommen. „Doch in Präsenz dabei" geht trotz Abwesenheit jederzeit.

Liegt in deinem Zeitraum ein als **extrem wichtig** markiertes Event, bekommst du einen Hinweis – Abwesenheit dort bitte nur nach Absprache mit dem Vorsitz.

### Auslagen einreichen
Du hast für den AStA Geld ausgelegt? Unter [Auslagen](finanzen.php) füllst du das offizielle **Belegblatt** direkt in der App aus: Kontaktdaten samt IBAN (per Klick aus deiner letzten Einreichung übernehmbar), „Beantragt von", Anlass und die einzelnen **Positionen** mit Datum, Beschreibung und Betrag – mit Live-Summe.

**Belege bitte gleich anheften.** Quittungen abfotografieren oder als PDF hochladen; mehrere Dateien sind möglich. Das ist keine Pflicht, aber **ohne Beleg gibt es keine Erstattung** – vor dem Absenden fragt die App deshalb nach, und der Text richtet sich danach, was wirklich dranhängt. Die Originale bitte trotzdem aufheben. Angeheftete Dateien werden **höchstens 30 Tage** gespeichert; die Belegblatt-Daten selbst bleiben.

Nach dem Absenden siehst du unter „Deine Einreichungen" jederzeit den Stand (**in Bearbeitung** / **erstattet**) und kannst dein Belegblatt als Word-Datei herunterladen. Jedes Belegblatt hat eine **Detailseite mit Nachrichten-Verlauf**: Finanzen kann dort nachfragen („Quittung Nr. 2 fehlt"), du antwortest – beide Seiten auch mit Anhängen. Solange dein Belegblatt offen ist, kannst du es dort auch **bearbeiten oder löschen**.

**Auszahlungsaufforderungen des StuPa** laufen getrennt davon: Das **Präsidium** hat einen eigenen Zugang (ohne Mitgliedskonto) und reicht dort an, was aus einem StuPa-Topf ausgezahlt werden soll. Ein Blatt kann **mehrere Posten** tragen – wer mehrere auf einmal einreicht, bekommt sie zusammen auf **ein** Formular; sonst steht eben ein Posten drauf. Jeder Posten hat eigene Angaben zu Antragsteller:in, Zweck, Betrag, Topf und Konto. Den **Kopf des Formulars** – wievieltes Parlament und die Namen des Präsidiums – pflegt das Präsidium in seinem Bereich selbst, sodass zu Beginn einer Legislatur niemand eine Word-Datei anfassen muss. Finanzen sieht die Aufforderungen in einer eigenen Liste und kann sich das **ausgefüllte Formular als Word-Datei** herunterladen: Seite 1 ist das Blatt zum Weitergeben, Seite 2 ein Anhang mit den Kontodaten und dem Vermerk „nicht für den Druck oder zur Weiterleitung". Rückfragen laufen wie beim Belegblatt über einen Nachrichten-Verlauf – beim Präsidium kommen sie per E-Mail an, weil es kein Dashboard hat.

**Wenn du die Rolle Finanzen hast:** Auf derselben Seite stehen alle **offenen Belegblätter** (älteste zuerst) samt angehefteter Belege, mit `.docx`-Download und dem Knopf, die Einreichung nach der Überweisung als **erledigt** zu markieren. Bei Bedarf erscheint dazu eine Kachel auf deinem Dashboard. Downloads sind privat: nur Einreicher:in und Finanzen/Vorsitz/Admin. Liegt ein Beleg in Papierform vor, hakst du **„Ich erfasse das für jemand anderen"** an – solche Blätter sind als „von Finanzen erfasst" gekennzeichnet.

### Dein Profil & die Mitgliederliste
Jedes Mitglied hat ein [Profil](profil.php); erreichbar über die [Mitgliederliste](mitglieder.php) und überall dort, wo Namen auftauchen – die sind app-weit anklickbar.

Das Profil zeigt Avatar, Pronomen, Sonderfunktion, „im AStA seit", auf Wunsch Geburtstag (nur Tag und Monat), die E-Mail als Schreiben-Knopf, dein **Referat mit eigener Beschreibung** und einen **„Über mich"-Text**. Beide Texte, die Pronomen und den Geburtstag pflegst du selbst unten im eigenen Profil. Dazu kommen deine sichtbaren Erfolge und die laufende Streak. **Geheime Erfolge erscheinen nur als Anzahl, Scores bleiben komplett privat.**

**Telefonnummer – freiwillig und verborgen.** Ganz unten im eigenen Profil kannst du eine Nummer hinterlegen; niemand muss das. Sie steht danach **nirgends offen im Profil**: Andere sehen nur einen Knopf, und wer ihn drückt, liest zuerst den Satz, den du dazugeschrieben hast – etwa „nur bei Notfällen", „bitte kein WhatsApp" oder „nur während Events". Erst wer dem zustimmt, bekommt die Nummer angezeigt. Bis dahin wird sie gar nicht erst an den Browser geschickt. Leeres Feld speichern entfernt Nummer und Satz wieder.

**Magst du keine angeben?** Dann nimm im Profil den Knopf **„Keine Nummer hinterlegen"** statt „Speichern". Das ist eine vollwertige Antwort: Im Profil steht dann für die anderen, dass du telefonisch nicht erreichbar sein möchtest – so fragt auch niemand mehr nach.

**Und unabhängig davon, was jemand dazuschreibt:** Telefonnummern in dieser App sind **ausschließlich AStA-intern**. Sie werden **nicht weitergegeben** – nicht an Externe, nicht in Gruppen oder Chats, und nicht für andere Zwecke als den genannten. Dieser Satz steht in jedem Dialog, bevor eine Nummer sichtbar wird; mit dem Bestätigen sagst du ihn zu.

Die **Mitgliederliste** beginnt mit den **Ansprechpersonen** (Vorsitz, Sekretariat, Finanzen, Admin) und zeigt darunter alle übrigen Mitglieder in einer Liste – nach Referaten sortiert, aber ohne Trenn-Überschriften; das **Referat steht auf der Karte**.

Jede Karte lebt von dem, was die Person **selbst geschrieben** hat: Unter Name, Pronomen und Referat steht **„Was ich im Referat mache"** (fehlt das, rückt „Über mich" nach). Am Fuß der Karte stehen knapp zwei Zahlen – links die laufende **Streak** ab 3 Tagen, rechts die **Anzahl der Erfolge**; welche das sind, steht ebenfalls im Profil. Dazu Badges für laufende Abwesenheit, Geburtstag, „Neu dabei" und AStA-Jubiläum.

**Pinnwand:** Unten auf jedem Profil kannst du anderen **nette Worte hinterlassen** – wahlweise mit Namen oder anonym. Hat jemand Geburtstag, führt der **Gratulieren-Knopf** im Dashboard-Ständchen direkt hierher. Auf einen Eintrag können die beiden Beteiligten antworten, die jeweils andere Seite bekommt einen Hinweis aufs Dashboard. Anonymität bleibt dabei gewahrt. Entfernen dürfen einen Eintrag die Autor:in, die Profil-Inhaber:in und Admins.

**Props 🙌:** Auf jeder Mitglieder-Karte und jedem fremden Profil sitzt ein 🙌 – ein kleines, **anonymes** Dankeschön für die Arbeit der Person. Du hast **zwei Props pro Monat** und höchstens eins pro Person. Die beschenkte Person sieht beim nächsten Öffnen nur einen kurzen Hinweis – **nie**, von wem. Die Anzahl erhaltener Props steht ausschließlich im eigenen Profil.

### Externe Events – öffentliche Anmeldungen
Im **Events**-Tab gibt es den Abschnitt **Externe Events**: Anmeldungen für Studierende, außerhalb der App. Kneipentour, O-Woche, Fahrten und Workshops – alles, wo sich Leute anmelden und ihr am Ende eine Liste braucht. Zugriff haben Vorsitz und Admin sowie die Referate, die dafür freigeschaltet sind; dazu je Veranstaltung das betreuende Referat.

**Einrichten** führt ein Assistent in sechs Schritten: Grunddaten, Anmeldung, Formular, Gruppen, Sichtbarkeit, Prüfen. Fast alles ist eine Option — ob es eine Bestätigungsmail gibt, Plätze und Warteliste, ob man sich selbst wieder abmelden kann, ob Freundeskreise über einen **Code** zusammenbleiben oder jemand **mehrere Personen auf einmal** anmelden darf, und wer die Einteilung später sehen darf.

**Das Formular stellst du selbst zusammen.** Name und Mailadresse sind fest, alles andere legst du an: Kurztext, langer Text, Ein- und Mehrfachauswahl, Ja/Nein, Zahl. Bei Auswahlfeldern setzt ein `| 20` hinter der Zeile ein **Kontingent** – „Alte Kanzlei | 20" heißt zwanzig Plätze für diese Startkneipe, danach ist sie ausgegraut.

**Die Einteilung** macht die App: Feste Blöcke zuerst (gleicher Code oder gemeinsam angemeldet, absteigend nach Größe), dann füllen Einzelne die kleinste Gruppe auf – bei Gleichstand nach dem Mischkriterium, damit nicht alle aus demselben Fachbereich zusammensitzen. Was nirgends passt, bleibt liegen und wird gemeldet, statt einen Freundeskreis auseinanderzureißen. Von Hand verschieben geht jederzeit.

**Rotationsplan** (Option, Schritt 4): Für eine Kneipentour reicht „eine Gruppe pro Kneipe" nicht – jede Gruppe zieht Runde für Runde weiter. Trag dazu die **Stationen** ein (Name, Ort, Notiz, per Pfeil sortierbar) und stell **Runden**, **Startzeit** und **Minuten je Runde** ein; **0 Runden heißt: kein Rotationsplan**. Die App legt den Fahrplan an: Gruppe 1 startet an Station 1, Gruppe 2 an Station 2 und so weiter, jede Runde rückt eine Station weiter. So besucht jede Gruppe lauter verschiedene Orte und zwei treffen sich nie am selben. Gibt es weniger Stationen als Gruppen oder mehr Runden als Stationen, geht das nicht auf – die App sagt es dir statt es stillschweigend zu verbiegen. Der Plan steht als Tabelle auf der Teilnahme-Seite, jede Gruppe sieht ihren eigenen Fahrplan auf ihrer Seite, und `{{ROTATION}}` setzt ihn in jede Mail.

**Wer die Einteilung sehen darf**, entscheidet Schritt 5: niemand, nur die Angemeldeten (jede:r sieht die eigene Gruppe) oder **öffentlich** – dann gibt es eine für alle erreichbare Seite mit allen Gruppen und ihren Fahrplänen. Ob dort auch **Namen** stehen, ist eine zweite, getrennte Entscheidung; ohne sie zeigt die Seite nur Größen und Orte.

**Danach:** Nachricht an alle, an eine Gruppe oder nur an die Warteliste, „Einteilung verschicken" mit Gruppe, Treffpunkt und Uhrzeit, und ein CSV-Export für alles Weitere. Anmeldedaten löschen sich nach einer einstellbaren Frist (Vorgabe 90 Tage nach dem Termin) von selbst; Veranstaltungen ohne Termin zählen ab dem Anlegedatum.

### Wichtige Infos & der geführte Rundgang
Ein **Klick auf deinen Namen** oben rechts öffnet dein Menü: **Mein Profil**, **[Wichtige Infos & Anleitung](info.php)**, bei geteilten Geräten **Konto wechseln** und **Abmelden**. Auf der Info-Seite stehen diese Anleitung und die Dokumente, die der Vorsitz hinterlegt (How-to AStA, Geschäftsordnung, Onboarding …).

Neue Einträge erscheinen als Aufgabe im Dashboard und gelten mit deinem Besuch der Info-Seite automatisch als gelesen – ein Extra-Klick ist nicht nötig.

**Wegweiser 🧭:** Im selben Menü direkt darunter liegt der **[Wegweiser](wegweiser.php)** – das Verzeichnis aller unserer Dienste (Teams, Nextcloud, OLAT, Webseite …), der Anlaufstellen an der Uni und der Ansprechpartner:innen außerhalb. Statt zu scrollen tippst du am besten ins **Suchfeld**: Es sucht über Namen, Stichworte und Adressen, und mehrere Wörter dürfen in beliebiger Reihenfolge stehen.

**Eigene Einträge:** Fehlt etwas, trägst du es selbst ein – ganz unten unter **„Deine Einträge“**. Gedacht ist das vor allem für die Referate: Die Anlaufstellen, mit denen ihr regelmäßig zu tun habt, kennt ihr am besten. Dabei gilt: **Deine eigenen Einträge kannst du jederzeit ändern und löschen, fremde nicht.** Über **„Sichtbar für“** entscheidest du, ob dein Eintrag allen angezeigt wird oder nur bestimmten Referaten. Vorsitz und Admin sehen den Abschnitt nicht – sie arbeiten in der Verwaltung, dürfen dort alle Einträge bearbeiten und sehen, von wem welcher stammt.

**Geführter Rundgang:** Beim ersten Öffnen des Dashboards führt dich ein Rundgang mit Spotlight durch die wichtigsten Ecken der App – in Kapiteln, die sich einzeln überspringen lassen (wenn dich die Gamification z. B. nicht interessiert). Bei jedem Seitenwechsel wird der passende Menüpunkt hervorgehoben, und du klickst ihn selbst, damit du die Navigation einmal gemacht hast. Du kannst ihn jederzeit über [Infos & Anleitung](info.php) → „Rundgang starten" wiederholen.

### „Dein Profil ist noch nicht ganz fertig"
Solange in deinem Profil etwas fehlt, steht auf dem Dashboard ein freundlicher Hinweis mit genau den Punkten, die noch offen sind – Pronomen, „Was ich im Referat mache", „Über mich" und die Telefonnummer. Jeder Punkt ist direkt verlinkt.

Das ist **keine echte Aufgabe**: Es zählt nicht in die offenen Aufgaben, geht in **keinen Score** ein und löst nichts aus. Der Hinweis verschwindet einfach, sobald alles beantwortet ist – und bei der Telefonnummer zählt das Häkchen „möchte ich nicht angeben" genauso als Antwort. Der **Geburtstag** steht bewusst nicht auf der Liste.

Warum überhaupt? Weil das Profil die Stelle ist, an der neue Mitglieder nachsehen, wer eigentlich wofür zuständig ist. Ein leeres Profil hilft dort niemandem.

### Erinnerungen: Mail oder Push
Was du wie erfahren möchtest, stellst du unter [Erinnerungen & Mitteilungen](erinnerungen.php) ein (oder per Klick auf die Erinnerungs-Kachel im Dashboard). Dort steht jede Art von Mitteilung einzeln, sortiert in vier Gruppen: Events & Schichten, Sitzungen, Nachrichten & Börse, App & Basis-Score.

Je Art wählst du:

- **Kanäle** – Mail und Push getrennt an- und abschaltbar.
- **Vorlauf** – wann du erinnert wirst: Einsatz 1/2/3/7 Tage vorher, „Abstimmung offen" 1–7 Tage vor der Frist, Bericht 1–3, Abstimmungsgegenstände 1–7 Tage vor der Sitzung. Du darfst dich also auch **früher** erinnern lassen.
- **Zustell-Adresse** – trägst du eine private Adresse ein, gehen alle persönlichen Erinnerungen dorthin statt an die AStA-Adresse. Der Login-Link geht immer an die hinterlegte Adresse.

**Pflicht-Erinnerungen: Mail abwählbar, sobald Push da ist.** Für die sechs Grundpflichten – Abstimmung offen, „Vielleicht"-Frist, Bericht fällig, Abstimmungsgegenstände, neuer Umlaufbeschluss und Abstimm-Erinnerung – ist die Mail Pflicht, **außer** du bekommst diese Art per **Push auf einem angemeldeten Gerät**. Dann darfst du die Mail abschalten. **Fällt der Push weg, ist die Mail sofort wieder an** – wenn du Push für diese Art ausschaltest, das letzte Gerät abmeldest oder ein Abo stillschweigend abläuft. Ganz abbestellen lassen sich diese sechs Arten nicht.

**Push einrichten:** auf derselben Seite über den Schalter „Push auf diesem Gerät" – **pro Gerät**. Beim ersten Start der installierten App fragt das Dashboard einmal aktiv nach. **Auf dem iPhone geht Push nur aus der installierten App heraus**, im Safari-Tab bleibt der Schalter verborgen; Android kann es auch im Browser. „Alle Geräte abmelden" löscht sämtliche Push-Abos deines Kontos.

Zusätzlich gibt es die **Inaktivitäts-Erinnerung** (ab Werk aus): ein Push, wenn du die App 3 bis 6 Tage nicht geöffnet hast – als Schutz vor dem Basis-Score-Kriterium „App wöchentlich geöffnet". Während eingetragener Abwesenheiten kommt sie nicht.

### Die App aufs Handy
Ganz unten im [Dashboard](dashboard.php) steht die Karte „AStA-App installieren":

- **Android (Chrome/Edge):** ein Tipp auf „Jetzt installieren".
- **iPhone (Safari):** Teilen-Symbol → „Zum Home-Bildschirm". Danach startet sie im Vollbild mit eigenem Icon.

Die Karte verschwindet, sobald die App installiert ist.

Auf dem Handy gibt es unten eine feste **Tab-Leiste** – Dashboard · Kalender · Events · Mehr. „Mehr" öffnet ein Menü mit allen übrigen Punkten, deinem Profil, den Erinnerungen und dem Design-Umschalter. In der installierten App ist die obere Leiste ausgeblendet, die Tab-Leiste übernimmt.

**Datei-Downloads** öffnen immer in einem eigenen Tab, damit die App ihre Ansicht nicht verliert. Auf dem iPhone landen Word-, Excel- und PowerPoint-Dateien im **Teilen-Menü** („In Word öffnen", „In Dateien sichern") – die App kann sie selbst nicht anzeigen. PDFs und Bilder bekommen die normale Vorschau.

### Aussehen, Erfolge & Streak
Über das **Glühbirnen-Symbol** (am Rechner oben, auf dem Handy im „Mehr"-Menü) schaltest du zwischen System-, Hell- und Dunkel-Design um. Wer im System **„Bewegung reduzieren"** aktiviert hat, bekommt alle Animationen abgeschaltet.

Unter [Achievements](achievements.php) sammelst du Erfolge – **rein zum Spaß, ohne Einfluss auf Einteilung oder Score**:

- **Streak:** Jeder Tag, an dem du in die App schaust, gibt eine Flamme. Die Kette reißt erst, wenn du dich **5 Tage in Folge nicht** meldest; kürzere Pausen kosten nichts. Tage, die von einer eingetragenen **Abwesenheit** abgedeckt sind, zählen nicht dagegen.
- **Vorlesungsfreie Zeit:** Legt das Sekretariat sie fest, ist deine Streak **geschützt** – sie kann nicht reißen, du sammelst aber ganz normal weiter. Oben im Dashboard steht dazu ein Hinweis mit Enddatum, den du über das **✕** wegklicken kannst: Er fliegt dann als Schneeflocke an den Rand (am Rechner in die Titelleiste, auf dem Handy in die obere rechte Ecke der Begrüßungskarte) und kommt mit einem Klick darauf zurück. Nach dem Ende gilt noch eine Schonfrist von 7 Tagen.
- **Erfolge** gibt es für den Einstieg (erste Schicht, erster Bericht, erster TOP, erstes Belegblatt) und fürs Dranbleiben – Schichten, Berichte, geführte Protokolle, besuchte Sitzungen, erhaltene und verschenkte Props, Umlauf- und Terminfinder-Teilnahmen, gelesene Infos, aktive Wochen und mehr. Dazu **31 geheime Erfolge**, die erst sichtbar werden, wenn du sie triffst. Wer sie kennt, verrät sie bitte nicht.
- **Belohnungen** sind **Avatar-Accessoires**, die dein Avatar wirklich trägt – sechs kombinierbare Trage-Positionen (Kopf, Hand, Hals, Gesicht – und für besondere Stücke „Hinter dir" und „Unter dir", etwa den Sonnenaufgang oder das Gras) –, dazu **Avatar-Farben**, **Streak-Stile** und **App-Designs**. Ausgerüstet wird alles im Belohnungs-Locker über den Knopf **„Anpassen"** oben auf der Achievements-Seite. Ein Streak-Stil ändert dabei nicht nur das Bild: Auch das **Zählwort** (etwa „8 Schübe" bei der Rakete, „61 Karat" beim Diamanten) und die **Sprüche beim Erreichen einer neuen Stufe** passen sich an. Es gibt fünf Stufen – bei **3, 7, 30, 50 und 100 Tagen** –, und jede sieht deutlich anders aus als die davor.
- **Ab 50 Tagen** deutet sich das schon leise an: Die Begrüßungskarte nimmt die Farbe deines Stils auf und ein paar wenige Teilchen ziehen durchs Bild – dezent, ohne dass Kacheln in Bewegung geraten.
- **Ab 100 Tagen** bleibt dein Stil dann gar nicht mehr in seiner Kachel: Er **greift auf die ganze Begrüßungskarte über** – auf die Begrüßung, die Zeile darunter, die Initialen im Avatar, die Streak-Kachel und die beiden Score-Anzeigen. Bei der **Flamme** glüht alles durch, das **Herz** lässt die Karte im Takt mitschlagen, beim **Blitz** stehen Schrift und Score-Symbole unter Strom und ein Schlag erhellt kurz das ganze Feld, der **Stern** lässt sie aufblitzen, beim **Kaffee** steigt alles sacht nach oben, beim **Sempervivium** wächst der Schein und die Kacheln recken sich, an der **Rakete** ziehen alle Elemente einen Schweif nach hinten, der **Diamant** bricht das Licht in Regenbogenfarben, die **Mondphasen** tauchen alles in silbriges Mondlicht mit wandernden Strahlen, der **Regenbogen** legt seinen Bogen unter die Karte; beim **Heiligenschein** fällt Licht in breiten Bahnen von oben ins Feld. Dazu passende Kleinigkeiten in der Fläche: Glutfunken, schwebende Herzen, Sternenstaub, Dampfschwaden, kleine wachsende Blümchen, vorbeifliegende Sterne, Prismenfunkeln, Mondlicht-Pünktchen, Regenbogen-Schimmer.
- Auch dein **Zeichen selbst** bekommt auf der 100 eine eigene Bewegung – nicht dieselbe wie auf Stufe 4, nur schneller, sondern eine ruhigere: Die Flamme steht als Säule, das Herz schlägt tief mit Nachhall, der Blitz wird zum stehenden Lichtbogen mit einem sauberen Schlag pro Runde, der Stern dreht sich langsam einmal herum, der Dampf wird zur durchgehenden Säule, die Blume wiegt sich in einer langen Brise, die Rakete **hört auf zu beben** und gleitet, und im Diamanten wandert das Licht durch die Facetten, während der Stein selbst ruhig steht, und beim Heiligenschein funkeln die Strahlen nicht mehr, sondern schlagen ein. Wer in den Geräte-Einstellungen **weniger Bewegung** eingestellt hat, bekommt die Stufe ohne Animation.
- **Avatar-Farben:** Zwölf stehen von Anfang an allen offen (Ozean, Wald, Flamingo, Matcha, Honig, Lavendel und die sechs aus der Pride-Familie) – die bleiben bewusst ruhig. Im Locker stehen sie in der Reihenfolge, in der du sie brauchst: erst deine eigenen, dann die kaufbaren, zuletzt die verschlossenen. Alle übrigen werden über Erfolge freigeschaltet und **bewegen sich**: Die Glut atmet, die Galaxie zieht durchs All, die Pusteblume lässt ihre Schirmchen davonfliegen, beim Engel pulst das Licht von oben. An dieser Bewegung erkennst du auf einen Blick, ob eine Farbe verdient ist. Wer „Ruhe bewahren" im Betriebssystem eingestellt hat, sieht sie alle still.
- **Die Farbe der Initialen** in deinem Avatar wählst du im selben Locker – und die ist **komplett frei**, nichts davon muss man sich verdienen. Das ist bewusst keine Belohnung, sondern eine Frage von Geschmack und Lesbarkeit: Auf einem dunklen Verlauf liest sich hell besser, auf einem hellen dunkel. Die Vorschau zeigt jede Farbe auf **deinem** Verlauf, damit du das vorher siehst. Die Wahl gilt überall, wo dein Avatar auftaucht. Als einzige mit Verlauf statt Farbe: **Pride** – in hellem Pastell, damit die Buchstaben auch auf der Pride-Avatar-Farbe lesbar bleiben.
- **Pride:** Den App-Skin „Pride" gibt es für das Achievement **„Gute Ansprache"** – einfach die eigenen Pronomen im Profil eintragen. Wer schon vor August 2026 dabei war, hat ihn ohnehin und behält ihn. Die Avatar-Farben und die Initialen-Farbe „Pride" bleiben **für alle da**, ohne Freischaltung. Die Fahne gibt es in sechs Fassungen, damit jede:r wählen kann, wie laut: **Sanft** als weicher Verlauf, **Fahne** und **Bahnen** mit klaren Streifen (quer bzw. längs), **Trans** als eigene Fahne, **Aquarell** als Farbwolken ohne Symbol und **Faden** als eine einzelne Regenbogen-Linie auf Nachtgrau. Der Skin ist bewusst stylisch statt kunterbunt: eine ruhige dunkle Bühne, der Regenbogen als feine Linie unter der Kopfleiste, als Lauflicht am Rand der Begrüßungskachel und als weicher Streifen, der langsam schräg durch den Seitenhintergrund zieht. Umschalten wie immer über die **Glühbirne**.
- **Kaufen mit AsT:** Im Belohnungs-Locker stehen bei Avatar-Schmuck, Avatar-Farben und Streak-Stilen zusätzlich Kacheln mit **Preisschild** – nicht an Achievements gebunden, einfach kaufbar (neun Farben, die Streak-Stile **Rakete**, **Diamant** und **Regenbogen** sowie der Schmuck). Bezahlt wird in **AsT** aus deinem **Schicht-Depot** in der Tauschbörse (jeder vollzogene Tausch bringt 100 ASX-Aktien, der Kurs steigt mit jedem Handel – rückwirkend mitgezählt). Gekauftes bleibt für immer deins. Wer mag, legt AsT im Depot in einen der drei **ETF-Sparpläne** an (niedriges, mittleres oder hohes Risiko) – die Kurse würfelt der Markt täglich neu, deterministisch für alle gleich; verkauft wird zum Tageskurs, Gewinn wie Verlust landen im verfügbaren Guthaben. Ein Klick auf eine Kachel mit Preisschild fragt zuerst, **womit** du zahlen möchtest: mit **AsT** – oder mit **10 Props** 🙌, egal wie teuer das Stück in AsT ist (die eingelösten Props sind danach weg; im Profil siehst du weiterhin, wie viele du insgesamt bekommen hast). **Royals** (Spitzenklasse) dürfen zusätzlich **alle 3 Monate ein Stück kostenlos beschlagnahmen** (das 👑 an der Kachel zeigt, wo das ginge) – nur solange sie den Status wirklich tragen; die 3-Monats-Frist läuft unabhängig davon weiter, und Beschlagnahmtes bleibt auch, wenn die Krone wandert.
- **Status-Erfolge** kann man wieder verlieren: die Medaille für die Top 3 der Schichten und die Krone samt Royal-Design für die Spitzenklasse. Sinkt der Wert, wird das Stück automatisch abgelegt.
- **Hall of Fame** oben auf der Seite zeigt die aktuelle Spitzenklasse und die längsten laufenden Streaks – alle mit dem höchsten und alle mit dem zweithöchsten Streak-Wert.

### Eventscore & Basis-Score
Zwei Werte, zwei ganz verschiedene Dinge. Beide siehst du in der Begrüßungskachel, und beide gehen **niemanden außer dir und dem Vorsitz** etwas an.

**[Eventscore](score.php)** – misst deine Mithilfe: eingeteilte Schicht +1, lange Schicht +2, pro Event gedeckelt auf 4 Punkte (bei wichtigen Events 5). **−2**, wenn du zu einem Event **gar nicht** abgestimmt hast – „Nein" zählt als abgestimmt und kostet nichts. Wer ein Event organisiert, bekommt automatisch die Event-Maximalpunktzahl minus 1. Gewertet wird, sobald die Abstimmungsfrist abgelaufen ist.

Dazu bekommst du eine **Einordnung relativ zum Gruppenschnitt**: Ausbaufähig, Okay, Gut und – als Krönung 👑 – **Spitzenklasse** für die besten beiden. Die Schwellen sind bewusst asymmetrisch: knapp unter dem Schnitt zu liegen ist normal und bleibt „Okay". Neue Mitglieder starten mit dem aktuellen Gruppenschnitt als Startpunkten, damit niemand bei 0 im Roten anfängt.

**[Basis-Score](basisscore.php)** – misst die Grundpflichten, mit **nur Minuspunkten**; **0 ist einwandfrei**. Abzüge gibt es für:

- Bericht bei einer berichtspflichtigen Sitzung vergessen: **−1**
- unentschuldigt bei einer Sitzung gefehlt: **−2**
- Eventscore aktuell im Bereich „Ausbaufähig": **−1**
- App eine volle Woche nicht geöffnet: **−1** je Woche (Wochen mit eingetragener Abwesenheit zählen nicht, die laufende Woche nie)
- wichtige Info nicht binnen 7 Tagen gelesen: **−1** je Info
- Abstimmungsgegenstände einer Sitzung nicht gelesen: **−1** je Sitzung
- an einem abgeschlossenen Umlaufverfahren nicht teilgenommen: **−2**
- an einer abgeschlossenen verpflichtenden Abstimmung nicht teilgenommen: **−1**

Bei den letzten beiden entschuldigt eine **Abwesenheit ausdrücklich nicht** – abstimmen geht von überall. Wird ein Umlauf oder eine Abstimmung dagegen **vorzeitig beendet** – also bevor die Frist abgelaufen ist, etwa weil das Stimmungsbild längst klar ist –, gibt es **für niemanden einen Abzug**: Wer bis zur Frist noch Zeit gehabt hätte, wird nicht dafür bestraft, dass früher abgebrochen wurde. Die Rückfrage beim Beenden sagt das auch dazu. Einordnung: über −3 einwandfrei, ab −3 schlecht, ab −6 Gesprächsbedarf. Es geht hier um die niederschwelligsten Erwartungen an die AStA-Arbeit – nichts davon kostet mehr als ein paar Minuten.

### Etwas stimmt nicht?
Ganz unten auf jeder Seite stehen zwei Knöpfe:

- **[Fehler melden](bug.php)** – kurzer Titel, Beschreibung, fertig. Daraus wird ein Ticket. Auf derselben Seite siehst du deine eigenen Meldungen samt Stand und kannst auf Rückfragen der Technik antworten.
- **[Feedback](feedback.php)** – für **Lob, Ideen, Kritik und Fragen**. Auch daraus wird ein Eintrag mit Verlauf, keine Mail. Du wählst die Art, schreibst den Text und bleibst, wo du warst; woher du geschrieben hast, merkt sich die App selbst. Unter „Meine Rückmeldungen" siehst du den Bearbeitungsstand, und auf ein **Abgelehnt** bekommst du immer eine Begründung.

Beides sehen **nur das Technik-Referat und du selbst**. Es gibt bewusst keine app-weite Liste.

---

## Für das Sekretariat

Alles rund um Sitzungen. Die meisten Schalter findest du auf der **Detailseite einer Sitzung** und unter [Verwaltung → Sitzungen](admin/meetings.php).

### Deine Sekki-Kachel
Auf deinem [Dashboard](dashboard.php) liegt die Kachel **„Sekki-Info"** als Live-Überblick:

- **Berichte** – Stand zur nächsten berichtspflichtigen Sitzung; ab zwei Tagen vorher steht dort, wer noch fehlt.
- **Eingereichte TOPs** zur nächsten Sitzung.
- **Einladungen** – immer die nächste Sitzung mit Status *offen* oder *freigegeben*, plus Hinweis „Teams-Link fehlt". Ab einem Tag vor dem Versand-Stichtag wird „offen" rot und zu **DRINGEND**.
- Über das **Zahnrad** stellst du direkt Reminder-Mail, Reminder-Vorlauf, Einladungs-Stichtag und TOP-Einreichefrist ein, ohne in die Verwaltung zu wechseln.

Vorsitz und Admin finden dieselbe Übersicht unter **Verwaltung → Sekretariatsaufgaben** und können im Notfall alles davon übernehmen.

### Sitzungen anlegen
- **Einzeln oder als Serie** (Standard alle zwei Wochen). Über „1. Sitzung ist Nr." startest du an jeder Nummer, ohne rückwärts zu rechnen.
- **Raum ist Pflicht** – er steht in der Einladung. Dazu der Teams-Beitrittslink.
- **Kein Endzeitpunkt:** Sitzungen laufen automatisch bis Tagesende. In der App steht „· ab 18:00", im Kalender-Abo erscheint ein Termin über den restlichen Tag.
- **Bericht benötigt** ist bei Serien automatisch gesetzt, bei einzelnen Sitzungen per Haken.
- **Als Entwurf anlegen** hält eine Sitzung (oder eine ganze Serie) erst einmal unsichtbar; „Ganze Serie freigeben" veröffentlicht alle auf einmal.
- **Ausfallen lassen** rückt die Nummerierung automatisch nach.
- **Legislaturperioden:** je Legislatur beginnt die Nummerierung neu, die Startnummer ist einstellbar.
- **StuPa-Sitzungen** (Art „StuPa") sind **reine Kalender-Info**: keine Berichtspflicht, keine Einladung, keine Rückmeldung, kein Protokoll, keine Redeliste. Sie zählen nicht in die AStA-Anwesenheit und nicht als „nächste Sitzung", werden eigenständig ab 1 nummeriert und erscheinen im Kalender in eigener Farbe.

### Tagesordnung
Jede Sitzung hat eine eigene TO; ist keine gepflegt, gilt die **Standard-Tagesordnung** (unten auf der Sitzungen-Seite). Im Live-Editor legst du TOPs an, rückst Unterpunkte ein (1.1), trennst mit dem Schloss-Symbol den **internen Teil** ab und gibst optional je TOP eine Dauer an. Beide Teile enden mit einem „Sonstiges" – eingereichte TOPs ohne gewählten Platz reihen sich davor ein, damit der Sammelpunkt Sammelpunkt bleibt.

**Eingereichte TOPs** der Mitglieder stehen oben mit Namen und lassen sich verschieben, öffentlich/intern umschalten oder entfernen. Eingereicht werden kann nur **bis zur Einreichefrist** (Standard 5 Tage vorher) – danach steht die TO fest, passend zum Einladungsversand.

Zwei Schalter je TOP, sowohl zentral in der Standard-TO als auch pro Sitzung:

- **Keine Redeliste** (durchgestrichenes Listen-Icon) – der TOP bleibt in der TO sichtbar, wird aber kein Rede-TOP. Praktisch für Begrüßung oder Genehmigung der TO.
- **Berichte-TOP** (Megafon) – ändert nichts am Protokoll, nur in der Redeliste: Alle, die berichten wollen, melden sich, die Leitung klickt „Berichte starten" und bekommt einen Sortier-Dialog in fairer Reihenfolge. Daraus entstehen Unter-TOPs „Bericht von X", jeder mit eigener Redeliste für Rückfragen.

Ein TOP ist entweder das eine oder das andere, nicht beides.

**Abstimmungsgegenstände** legst du unter der TO an – Text plus Datei-Uploads, sortierbar. Je Eintrag siehst du den **Lese-Fortschritt** (x/y Mitglieder). Die Datei-Uploads löschen sich nach 40 Tagen automatisch, die Texte bleiben.

**Datei aus der Ablage anhängen.** Liegt das Dok schon in Teams oder der Nextcloud, musst du es nicht erst herunterladen und wieder hochladen: **„Aus der Ablage wählen"** öffnet einen kleinen Datei-Browser. Oben stehen die Ablage (mit Umschalter, wenn beide eingerichtet sind) und der Pfad – jede Stufe anklickbar, um wieder hochzugehen –, darunter die Liste zum Scrollen. Angezeigt wird nur, was sich auch anhängen lässt, mit Dateigröße. **Mehrere Dateien** hakst du in einem Rutsch ab, der Dialog bleibt dabei offen; vor dem Absenden kannst du jede Auswahl wieder wegnehmen.

Denselben Knopf gibt es bei den **Wichtigen Infos** (Verwaltung → Wichtige Informationen) und bei **Umlaufbeschlüssen und Abstimmungen** – überall dort, wo das Dok typischerweise längst in der Ablage liegt. Angehängt wird dabei **beides** – eine Kopie in der App als Fallback und der Verweis aufs Original. Damit gilt dieselbe Regel wie sonst auch: Der Stand in der Ablage ist führend, „In Teams/Nextcloud öffnen" führt zum Original, und wer es dort bearbeitet, dessen Änderungen sehen alle. Eine so ausgewählte Datei kannst du in der Ablage **nicht** über die App löschen – das Häkchen „Dok in der Ablage mitlöschen" erscheint für sie gar nicht erst, denn das Original gehört dem Team und wurde nicht für den Anhang angelegt. Beim Entfernen verschwindet nur der Anhang.

### Einladungen verschicken
Es gibt **zwei Versandmodi**, umschaltbar unter **Verwaltung → Sekretariatsaufgaben** (das stellen Vorsitz oder Admin ein). **Standard ist „Nur Text ausgeben"**, weil unser Verteiler nur über ein Webinterface erreichbar ist.

- **Nur Text ausgeben:** Im Kasten „Einladung" auf der Sitzungsseite trägst du den Teams-Link ein und bekommst den fertigen Text zum Kopieren. Den verschickst du selbst über euer Verteiler-Webinterface und markierst die Einladung danach als **versendet**. „Doch nicht versendet" nimmt es zurück. Es geht in diesem Modus **keine** Mail automatisch raus.
- **Automatischer Mailversand:** Teams-Link eintragen, **freigeben** – die App verschickt am Stichtag (Standard 5 Tage vorher) automatisch an den eingestellten Verteiler, inklusive Vorschau und „Jetzt senden".

In beiden Modi erinnert die App an offene Einladungen, global und pro Sitzung abschaltbar.

Zwei Dinge, die die Einladung anders macht als die Sitzungsseite:

- **Der interne Teil bleibt vertraulich.** Statt der einzelnen internen TOPs steht dort genau eine Zeile: „TOP X: Interner Teil (nicht öffentlich nach §10 der GO)".
- **Nur Haupt-TOPs.** Unterpunkte (1.1, 1.2 …) bleiben draußen; die volle Gliederung steht auf der Sitzungsseite und in der Protokollvorlage.

Fristen, Reminder-Vorlauf, Absender- und Verteiler-Adresse sowie **Betreff und Mailtext** stehen unter Sitzungen → Einladungen. Der Mailtext ist eine Plaintext-Vorlage mit Platzhaltern (`{{TAGESORDNUNG}}`, `{{TEAMS_LINK}}`, `{{REDELISTE_GAST_LINK}}`, `{{DATUM}}`, `{{RAUM}}`); Zeilenumbrüche gelten wie eingegeben. Das Versandformat (Plaintext oder HTML) betrifft nur den automatischen Versand.

### Redeliste starten und leiten
Auf der Sitzungsseite lädt **„Redeliste starten"** die komplette Tagesordnung **und** alle aktiven Mitglieder mit Pronomen in den Redelisten-Raum und öffnet die Sitzungsleitung. Von Hand ist nichts einzutragen. Die Links für Leitung, Gast und Beamer stehen darunter.

- **Pronomen sind eine feste Auswahl, kein Freitext** – dieselben zwei Dropdowns wie im Profil (sie/er/they/dey · ihr/ihm/them/dem/dey), Kombinationen wie „er/dey" inbegriffen. Wer als Gast beitritt, **muss** sie wählen. Trägt die Leitung jemanden über „Teilnehmende hinzufügen" ein, dürfen sie offen bleiben – sie rät nicht für andere; nachtragen geht jederzeit über **✎ Pronomen** in der Teilnehmendenliste.
- In der linken Leiste erscheinen **nur Personen, die tatsächlich beigetreten sind**. Über **„Teilnehmende hinzufügen"** trägst du fehlende Mitglieder nach oder legst externe Gäste an; der Dialog bleibt offen und aktualisiert sich live, und wer selbst beitritt, überschreibt deine Listenwahl.
- Die **Beamer-/Mitlese-Ansicht** ist eine schmale Mini-Ansicht, die neben den Online-Call passt. Während des internen Teils zeigt der öffentliche Beamer nur den Hinweis „Interner Teil" – keine Namen, keine TOP-Titel.
- **Zurücksetzen** löscht den Raum, sodass die Sitzung wieder als „nicht gestartet" gilt statt als „beendet" – praktisch, wenn versehentlich gestartet wurde.
- **Die Leitung bekommt nur, wer sie ausdrücklich öffnet.** Der rote Dashboard-Schnellzugang während einer laufenden Sitzung tritt für **alle** als Teilnehmer:in bei, auch für Sekretariat und Vorsitz.

### Protokoll – von der Vorlage bis OLAT
Auf der Sitzungsseite gibt es zwei Vorlagen zum Herunterladen: **Protokollvorlage (.docx)** und **Protokollvorlage – interner Teil**. Beide füllt die App aus eurer Word-Vorlage: Layout, Logo und Kopfzeile bleiben, nur der Inhalt wird eingesetzt – Sitzungsdatum, Vorsitz, Anwesenheitsliste, TO-Übersicht und die bereits eingetragenen Referats-Berichte. Interne TOPs setzen die öffentliche Nummerierung fort. Das öffentliche Protokoll endet mit dem öffentlichen Teil.

> **Nicht in Apple Pages ausfüllen.** Pages schreibt die Datei beim Export komplett um und verpasst dabei allen Absätzen einen Einzug – in den schmalen Nummern-Spalten der Anwesenheitsliste bricht dann alles zeichenweise um und die Tabelle bläht über zwei Seiten auf. Bitte **Word oder Word im Browser** nutzen. Die Datei aus der App ist in Ordnung; der Schaden entsteht erst durch den Pages-Export.

**Direkt in Teams schreiben (empfohlen)** umgeht das Problem: Neben den Vorlage-Knöpfen steht **„In Teams schreiben"**. Der erste Klick legt einmalig einen Entwurf an, jeder weitere öffnet **denselben** Entwurf – nichts wird überschrieben. Sobald ein Entwurf existiert, liefert auch der Vorlage-Download dessen aktuellen Stand.

Der Weg bis zur Veröffentlichung:

1. **Protokollant:in wählen** im Bereich „Protokoll" auf der Sitzungsseite. Die gewählte Person bekommt die Aufgabe „Protokoll hochladen" aufs Dashboard. Ist noch niemand festgelegt, kann die **Sitzungsleitung das direkt in der Redeliste** nachholen.
2. **Fertiges Protokoll einreichen** – öffentlich und intern getrennt, und immer über die App (eine direkt in einen Teams-Kanal gelegte Datei zählt nicht – davon erfährt die App nichts, Abstimmung und Veröffentlichung bleiben dann aus). Zwei Wege, beide auf der Sitzungsseite erklärt: **Ohne Word** (empfohlen) „In Teams schreiben" – der Entwurf öffnet sich in Office im Browser – und am Ende „Fertige Fassung aus Teams übernehmen"; **mit Word** die Vorlage ausfüllen und als .docx hochladen. Beide Dateien gehen direkt in die Ablage, nicht in die App, und in getrennte Ordner. Nur das öffentliche treibt Abstimmung und Veröffentlichung.
3. **Abstimmung in der nächsten Sitzung:** Dort entsteht automatisch der Abstimmungsgegenstand „Protokoll der X. Sitzung genehmigen", mit Links zum gemeinsamen Bearbeiten. Gibt es noch keine Folgesitzung, passiert das, sobald eine angelegt wird. In der Protokoll-Karte steht, in **welcher** Sitzung die Genehmigung ansteht.
4. **Beschluss festhalten** (*angenommen / vertagt / offen*). „Angenommen" schaltet den letzten Schritt frei. **„Vertagt"** lässt den Eintrag als Beleg stehen und legt die Genehmigung in der *nächsten* Sitzung neu an.
5. **Veröffentlichen nach OLAT:** In der Sekki-Kachel erscheint die Aufgabe „Protokoll veröffentlichen". Ein Klick holt die finale Fassung, wandelt sie serverseitig nach PDF und kopiert sie nach OLAT. **Nur das öffentliche Protokoll** wird veröffentlicht, das interne nie.

**Alles an einem Ort:** Unter Verwaltung → Sitzungen → **Protokolle & Abstimmungsgegenstände** hat jedes Protokoll eine Karte mit seinem **kompletten Verlauf** als Stationen: Protokollant:in, in Teams hochgeladen (mit Öffnen-Link), die ganze Abstimmungs-Kette – zu welcher Sitzung die Genehmigung gehört, wann sie angelegt wurde, angenommen oder vertagt von wem an welchem Tag, nach einer Vertagung auch die Nachfolge-Abstimmung – und zuletzt die OLAT-Veröffentlichung. Grün ist erledigt, Bernstein ist der Schritt, an dem es gerade hängt (mit Link oder Knopf direkt daneben, OLAT auch ohne Umweg über die Sitzungsseite), grau kommt später. Gibt es noch keine Folgesitzung, steht ausdrücklich da, dass die Abstimmung automatisch in die nächste geplante Sitzung kommt. Darunter sammelt der Reiter alle **Abstimmungsgegenstände, die noch auf „offen" stehen**. Steht nach einer Sitzung noch ein Beschluss aus, erinnert zusätzlich die Dashboard-Karte **„Beschlüsse festhalten"** daran – sichtbar für Sekretariat, Vorsitz und Admin, denn ohne festgehaltenen Beschluss bleibt z. B. eine Protokoll-Veröffentlichung für immer hängen.

**Wenn sich der Kalender ändert.** Sitzungen werden abgesagt, gelöscht, verschoben, und manchmal wird die Folgesitzung erst Wochen später eingetragen. Die App gleicht die Genehmigungen deshalb bei **jeder** Änderung am Sitzungsplan ab und zusätzlich einmal täglich im Cron:

- Die Zielsitzung wird **abgesagt, gelöscht oder wieder zum Entwurf** → die Genehmigung wandert in die nächste passende Sitzung, mit ihren Gelesen-Haken.
- Eine Sitzung wird so **verschoben**, dass sie vor der Sitzung liegt, um deren Protokoll es geht → die Genehmigung wandert weiter.
- Es kommt nachträglich eine **frühere** passende Sitzung dazu → die Genehmigung rutscht dorthin. Ein Protokoll wird in der nächsten Sitzung genehmigt, nicht irgendwann.
- Das Protokoll wird **verspätet** hochgeladen, die eigentliche Folgesitzung ist längst gelaufen → die Genehmigung landet in der nächsten Sitzung, die noch kommt, nicht in einer vergangenen.
- **Nummern verschieben sich** (davor wurde etwas eingefügt oder abgesagt) → der Titel des Gegenstands zieht nach.

Die Zuordnung steht **an einer Stelle**: Der Abstimmungsgegenstand weiß, zu welcher Sitzung er gehört. Umgekehrt merkt sich die Sitzung nichts – sonst gäbe es zwei Wahrheiten, von denen eine veralten kann.

Zwei Dinge fasst die Automatik bewusst **nicht** an: einen bereits **festgehaltenen Beschluss** – der bleibt, wo er gefasst wurde – und eine Genehmigung, die jemand **von Hand gelöscht** hat. Letztere kommt nicht von selbst zurück; in der Protokoll-Karte der Quell-Sitzung steht dafür der Knopf **„Abstimmung wieder anlegen"**. Wird eine korrigierte Fassung nachgereicht, entsteht **kein zweiter** Gegenstand – der bestehende steht wieder auf „offen".

**Längst angenommen, aber nie in der App festgehalten?** Das passiert bei Altbeständen – das Protokoll wurde in einer Sitzung genehmigt, bevor der Ablauf über die App lief, und hängt nun in der Warteschleife auf die nächste Sitzung. Dafür gibt es in der Protokoll-Karte den Knopf **„Bereits angenommen? Beschluss nachtragen"** (Sekretariat/Vorsitz): Er stellt das Protokoll ohne neue Abstimmung auf „angenommen", danach geht es normal weiter zur OLAT-Veröffentlichung. Steht dagegen schon eine Abstimmung an, lehnt der Knopf ab – dann gehört der Beschluss dorthin.

**Zeigt der Verweis auf eine tote Datei?** Wer eine Protokoll-Datei in Teams „umbenennt", indem er sie herunterlädt, neu hochlädt und die alte löscht, macht den gemerkten Verweis der App kaputt – die OLAT-Veröffentlichung scheitert dann mit „HTTP 404". (Echtes Umbenennen oder Verschieben in Teams schadet dagegen nicht.) In der Protokoll-Karte gibt es dafür **„Teams-Verknüpfung reparieren"**: die richtige .docx aus der Ablage auswählen, fertig – der Stand (angenommen usw.) bleibt unangetastet. Derselbe Weg verknüpft auch ein Protokoll, das jemand von Hand nach Teams gelegt hat, ohne es neu hochladen zu müssen.

**Aus Versehen festgelegt?** Unter **„Zurücksetzen"** in der Protokoll-Karte macht Sekretariat/Vorsitz das Festlegen rückgängig – der Status steht wieder auf „offen", die Verknüpfung ist weg, eine noch **offene** Abstimmung wird eingesammelt. Ein bereits **gefasster Beschluss** bleibt als Beleg stehen, und die Dateien in Teams und OLAT bleiben liegen. Danach lässt sich das Protokoll ganz normal neu einreichen; die Protokollant:in bekommt ihre Dashboard-Aufgabe zurück. Den internen Teil gibt es separat zurückzusetzen.

Im TOP „Genehmigung des letzten Protokolls" trägt die **Protokollvorlage** die tatsächlich anstehenden Protokolle mit Sitzung und Datum ein statt einer Platzhalterzeile.

**Protokollpause:** Ist eine Person als Protokollant:in gesetzt, kann **nur sie** in der Redeliste eine Pause ausrufen. Dann legt sich ein Vollbild-Overlay über alle Ansichten; die Sitzungsleitung sieht die Pause bewusst nur als dezentes Banner und kann weiterarbeiten. Beenden dürfen sie die Protokollant:in und die Leitung.

### Berichte einsammeln
Auf deinem Dashboard siehst du, welche Referate ihren Bericht noch nicht abgegeben haben. Erinnerungen gehen automatisch raus.

Unter [Sitzungen → Berichte](admin/reports.php) siehst du je Sitzung alle Referate, kannst Berichte nachtragen und das Ganze als **Word-Datei** herunterladen – in der Reihenfolge der Referat-Liste, Referate ohne Bericht mit dem Vermerk „BERICHT WIRD NACHGEREICHT".

### Vorlesungsfreie Zeit
Die vorlesungsfreie Zeit legst **du** fest, mit Enddatum – unter Verwaltung → Sitzungen → „Vorlesungsfreie Zeit". Sobald keine ordentliche Sitzung mehr ansteht, erinnert dich das Dashboard automatisch daran.

In dieser Zeit sind die Streaks aller Mitglieder **geschützt**: Sie können nicht reißen, tägliche Flammen sammelt aber jede:r ganz normal weiter. Nach dem Ende gilt noch eine Schonfrist von 7 Tagen.

---

## Für das Pat:innenprogramm

Dieser Abschnitt ist für alle, die als **Verantwortliche** für das Pat:innenprogramm eingetragen sind, sowie für Vorsitz und Admin. Die Verwaltung liegt unter [Pat:innenprogramm](admin/paten.php).

### Ein Semester anlegen und öffnen
Jedes Semester ist ein **eigenes Programm** mit eigenem Datensatz und eigenem Link – Anmeldungen verschiedener Semester vermischen sich nie. Du wählst Semesterart und Startjahr (beim Wintersemester das erste der beiden Jahre: `2026` → WiSe 2026/27); pro Semester ist genau ein Programm möglich.

Ein neues Programm startet als **Entwurf**: Der Link funktioniert schon, zeigt aber nur eine Vorschau. Erst **„Anmeldung öffnen"** macht ihn scharf, **„Anmeldung schließen"** beendet die Annahme, **„Archivieren"** legt ihn beiseite. Mit **„Link erneuern"** machst du einen versehentlich falsch verteilten Link ungültig.

**Ein Link für beide Rollen:** Studierende bekommen genau **einen** Link – nicht je einen für Erstis und Pat:innen. Auf der Zielseite wählen sie selbst, was auf sie zutrifft, und werden dann durch dieselben Schritte geführt (Abschluss → Studiengang → ggf. Unterpunkt → Angaben → Bestätigen). Pat:innen bekommen dazwischen **eine Frage mehr**: welche weiteren Fächer ihres Studiengangs sie studieren.

**Rundmail:** Die App verschickt **nichts** an Studierende. Stattdessen steht bei jedem Programm der fertige Rundmail-Text mit eingesetztem Semester und Link zum Kopieren bereit – verschickt wird wie gewohnt über die Studi-Verteiler.

### Texte, Studiengänge, Zeitplan
**Auf der öffentlichen Seite steht kein einziger Satz fest im Code.** Absender-Zeile, Hero-Texte, beide Kacheln, alle Fragen des Assistenten, die Einwilligungstexte, die Meldungen bei geschlossener Anmeldung und die Mails – alles ist unter **Texte** pflegbar. Drei Regeln gelten überall:

- `{{PROGRAMM}}` darf in jedem Feld stehen und wird zum Semester.
- Bei mehrzeiligen Feldern ist eine **Leerzeile ein neuer Absatz**.
- Bei den **Kachel-Inhalten** ist die erste Zeile der Einleitungssatz, jede weitere ein Punkt mit Häkchen.

**Leer heißt weg, nicht Standard:** Ein gespeichertes leeres Feld **blendet das Element aus** – so wirst du Dinge los. Den Standardtext holst du nur über „Auf Standard zurücksetzen" zurück.

**Studiengänge** liegen je Abschluss in einer eigenen Liste (ein Fach, das es als Bachelor und Master gibt, wird zweimal angelegt). Ein Studiengang kann eine **Unterauswahl** bekommen – der klassische Fall ist „Gymnasiallehramt" mit den Unterpunkten Deutsch, Englisch … Die Bezeichnung der Unterauswahl (z. B. „Erstfach") bestimmt die Frage im Assistenten. Mehr als zwei Ebenen gibt es bewusst nicht. Auf **beiden Ebenen** steht unter der Auswahl ein Freitext-Kasten „Meins ist nicht dabei" – solche Anmeldungen bekommen den Vermerk „wird von Hand zugeordnet" und laufen nicht durch die Automatik.

**Weitere Fächer der Pat:innen:** Im Lehramt studiert fast jede:r mehrere Fächer, im Assistenten lässt sich aber nur eines wählen. Hat ein Studiengang eine Unterauswahl, bekommen **nur Pat:innen** deshalb direkt nach ihrer eigenen Wahl noch eine Frage: welche der übrigen Fächer desselben Studiengangs studieren sie außerdem? Mehrfachauswahl, freiwillig, und **ausschließlich Geschwister-Einträge** – über die Studiengangsgrenze geht nichts. Erstsemester sehen den Schritt nicht. Willst du ihn gar nicht, leerst du unter Texte das Feld „Weitere Fächer: Frage an die Pat:innen", dann entfällt er komplett.

**Zeitplan:** Drei freiwillige Termine – Anmeldung von, Anmeldung bis, Einteilung bis. Sie erscheinen als Termin-Leiste auf der Studi-Seite und auf der Danke-Seite, und sie gelten als Platzhalter (`{{ANMELDUNG_VON}}`, `{{ANMELDUNG_BIS}}`, `{{EINTEILUNG_BIS}}`) in jedem Text und jeder Mail. Angezeigt wird nur, was auch eingetragen ist. **Der Anmeldeschluss schließt die Anmeldung nicht automatisch** – das bleibt ein bewusster Klick; ist er verstrichen, während die Anmeldung offen ist, warnt die Verwaltung.

**Fragen von Studierenden:** Auf den Studi-Seiten schwebt ein Hilfe-Knopf, hinter dem ein **Formular** steckt. Was dort abgeschickt wird, landet unter „Fragen von der Studi-Seite" in der Verwaltung **und** als Mail an die Paten-Mail – dort genügt ein „Antworten", die Adresse der fragenden Person hängt schon dran. Verschickt wird beim nächsten stündlichen Cron-Lauf, nicht in der Sekunde. **Schau trotzdem ab und zu in die Liste:** klemmt der Mailversand, steht die Frage nur dort.

**Kontakt und Recht:** Unter „Kontakt, Impressum & Datenschutz" trägst du die **Paten-Mail** ein (dorthin gehen die Fragen aus dem Formular, und sie ist die Antwortadresse der Einteilungs-Mails) sowie Links auf **Impressum und Datenschutzerklärung**. Beides braucht die Seite, weil sie öffentlich erreichbar ist. Die mitgelieferten Einwilligungstexte sind fachlich auf das gebaut, was die App tatsächlich tut – **rechtlich geprüft sind sie nicht**, das gehört einmal von eurer Seite angeschaut.

### Anmeldungen & Einteilung
Jedes Programm hat unter **„Anmeldungen & Einteilung"** eine eigene Seite mit Kennzahlen, Gruppen und der vollständigen Anmeldeliste.

**Wunsch-Gruppengröße** stellst du pro Programm ein (Standard 3–6 Erstsemester je Pat:in). Sie ist ausdrücklich ein **Wunsch, kein Gesetz**: Sind zu wenige Pat:innen da, werden die Gruppen größer, statt Erstsemester übrig zu lassen; sind viele da, bleiben sie kleiner.

**Automatische Einteilung**, drei Knöpfe: **Alle neu verteilen**, **Nur Neue einteilen** (der Nachrücker-Fall – bestehende und von Hand gesetzte Zuteilungen bleiben) und **Einteilung leeren**. Zugeteilt wird nach Passgenauigkeit, von oben nach unten:

1. exakt dieselbe Auswahl inklusive Erstfach
2. das Fach des Erstis steht in den **weiteren Fächern** der Pat:in
3. gleicher Studiengang, anderes Erstfach
4. gleiches Fach bei gleichem Abschluss
5. gleiches Fach bei anderem Abschluss
6. nur gleicher Abschluss – *fachfremd, wird nicht automatisch zugeteilt*

Bei Gleichstand kommt die kleinste Gruppe zum Zug, und seltene Studiengänge werden zuerst verteilt.

**Fachfremd wird nicht zugeteilt.** Findet sich für ein Erstfach niemand, greift die Stufe darüber – ein Ersti mit „Gymnasiallehramt – Englisch" landet bei der Deutsch-Pat:in, weil beide Gymnasiallehramt sind; hat diese Pat:in Englisch als weiteres Fach angegeben, wird sie noch bevorzugt. Passt jemand fachlich nirgends hin, bleibt er unter **„Ohne Gruppe"** stehen und wird dort als Aufgabe ausgewiesen: Eine sichtbare Lücke ist besser als eine stille Fehlzuteilung. Von Hand lässt sich weiterhin alles zuordnen.

**Kompromisse sind sichtbar** – an der Person (Badge), am Gruppenkopf („2× Kompromiss") und als Bilanz unter den Verteil-Knöpfen. Der Filter-Chip „Kompromiss" klappt genau die betroffenen Gruppen auf, sodass du sie der Reihe nach nachbessern kannst.

**Auf viele Studierende ausgelegt:** Gruppen stehen eingeklappt als je eine Zeile und öffnen sich per Klick; auffällige Gruppen sind von vornherein offen. Darüber liegt eine mitscrollende Leiste mit **Suche** über Namen, Fächer und Mailadressen und **Problem-Chips** („10 ohne Gruppe", „1 leere Gruppe", „3× fachfremd"), die als Filter wirken. Zum Verschieben öffnet jede Person einen gemeinsamen Dialog, in dem fachlich passende Gruppen oben und grün stehen. Für alles Weitere gibt es den **CSV-Export** inklusive Zuteilung und Passung.

### Mails zur Einteilung und Absagen
Die Einteilungs-Mails sind **persönlich**: Erstsemester bekommen ihre Pat:in mit Name, Fach, Mailadresse und Vorstellung **und dazu die anderen Erstis ihrer Gruppe** (Platzhalter `{{GRUPPE}}`) – die ganze Gruppe soll sich kennenlernen, jede Person hat die Kontakte aller. Pat:innen bekommen die Liste ihrer Erstsemester. Bei „Alle vorbereiten" kommt je Gruppe zusätzlich **eine gemeinsame Kennenlern-Mail** dazu: Pat:in im An-Feld, alle Erstis im CC – ein „Allen antworten" erreicht die ganze Gruppe und startet den Gruppen-Faden. (Ein geleerter Vorlagen-Text „An die ganze Gruppe" schaltet sie ab; CC-Empfänger:innen zählen im Mail-Konto einzeln mit.) Die Einwilligung beim Anmelden deckt genau das ab: Die Standard-Texte sagen den Erstis ausdrücklich, dass ihre Angaben an die **ganze Gruppe** gehen – wer Häkchen- oder Hinweis-Texte in der Verwaltung selbst überschrieben hat, sollte sie entsprechend nachziehen. Wer keine Gruppe hat, bekommt hier nichts – dafür gibt es die **Absagen**: eigene Texte für Pat:innen ohne Erstsemester und für Erstsemester ohne Pat:in, im Ton bewusst kein Schlussstrich. Vor dem Einreihen zeigt ein Dialog die **vollständige Empfängerliste** zum Gegenlesen.

**Das Hoster-Limit bestimmt den Ablauf.** Der Hoster lässt je Absenderkonto eine feste Zahl E-Mails je 24 Stunden durch (bei Mittwald **3000**, auf Anfrage freigeschaltet – davor 400) – für alles, was aus eurer Adresse rausgeht, Login-Links eingerechnet. Ein Semester mit 300 Erstsemestern sind allein rund 355 persönliche Mails; das passt jetzt **an einem Stück**. Der Zweischritt bleibt trotzdem, weil er die Kontrolle vor dem Versand gibt:

1. **Vorbereiten** stellt die Mails in eine Warteschlange und verschickt nichts.
2. **Alle verschicken** schickt normalerweise alles auf einmal. Begrenzt wird der Klick nicht mehr vom Kontingent, sondern von der **Laufzeit** – nach dem eingestellten Zeitbudget (Vorgabe 120 Sekunden) hört er sauber auf, statt mittendrin vom Webserver abgeschnitten zu werden. Bleibt doch etwas übrig, holt es der **stündliche Cron-Lauf**.

Typischerweise ist damit alles nach ein bis zwei Tagen draußen, ohne dass jemand etwas anklicken muss; die Verwaltung nennt die voraussichtliche Dauer. Einstellbar sind Kontingent je 24 Stunden (Standard 300, der Rest bleibt für Login-Links und Erinnerungen frei), Menge je Cron-Lauf und ein Zeitbudget je Aufruf. Fehlgeschlagene Mails wandern nach drei Versuchen auf „fehlgeschlagen" und lassen sich per Knopf erneut versuchen.

**Wer steht als Absender drauf?** Verschickt wird **immer** aus der App-Adresse – das gilt für jeden Bereich der App und ist keine Einstellungssache: Beim Hoster ist genau ein Postfach zum Versenden freigegeben, und eine andere Adresse in der Absender-Zeile würde nur behaupten, die Mail käme woandersher (das kostet Zustellbarkeit und landet reihenweise im Spam). Die **Antwortadresse** ist die Paten-Mail – ein „Antworten" der Erstsemester geht also direkt an die Verantwortlichen, nicht ins App-Postfach. Denselben Weg gehen Umfragen, externe Events und der Terminplaner: Das Adressfeld in ihren Einstellungen ist die Antwortadresse.

### Wer darf hier arbeiten?
Verantwortliche legen **Admin und Vorsitz** unter „Verantwortliche" aus der Stammliste fest. Sie bekommen Zugriff auf **genau diese Verwaltungsseite** und sehen sie als eigenen Punkt „Pat:innen" in der Titelleiste – sonst nichts aus der Verwaltung.

Das Programm hat aus gutem Grund eine **eigene Datenbank** und bindet die App-Funktionen bewusst nicht ein: Der öffentliche Bereich kennt Mitglieder, Logins und Push-Abos strukturell nicht. Die Trennung ist damit keine Frage der Disziplin, sondern der Verbindung.

---

## Für Vorsitz & Admin

### Die Verwaltung auf einen Blick
[Verwaltung](admin/index.php) bündelt alles Weitere:

- [Mitglieder](admin/members.php) – Stammliste, Rollen, Referate, StuPa-Zugänge. **Deaktivieren ist nur für den Übergang gedacht:** Ist ein Konto seit 14 Tagen deaktiviert, erinnert eine Karte auf dem Dashboard des Vorsitzes ans Aufräumen (löschen oder reaktivieren) – sie bleibt, bis kein Konto mehr deaktiviert ist.
- [Events & Termine](admin/events.php) und [Sitzungen](admin/meetings.php)
- [Sekretariatsaufgaben](admin/sekretariat.php) – dieselbe Übersicht wie die Sekki-Kachel
- [Nachricht hinterlassen](admin/message.php)
- [Scores](admin/score.php), [Aktivitätsstatistik](admin/activity.php), [Achievements-Übersicht](admin/achievements.php)
- [Wichtige Informationen](admin/infos.php), [Wegweiser](admin/wegweiser.php) und [Mailtexte](admin/mailtexts.php)
- [Bug-Reports](admin/bugs.php) und [Feedback](admin/feedback.php)
- [Uploads und Automationen](uploads.php), [Word-Vorlagen](admin/vorlagen.php)
- [Umfragen](admin/umfragen.php) – hochschulöffentlich, mit Prüfung der Hochschul-Adresse
- [Terminplaner](admin/terminplaner.php) – das öffentliche Terminwerkzeug, kostenfrei für alle
- [Pat:innenprogramm](admin/paten.php)
- [Diagnose & Fehler-Log](admin/errorlog.php)
- [Gefahrenzone](admin/reset.php) – gezieltes Löschen; Einstellungen und Technik-Login bleiben immer erhalten

### Mitglieder & Referate
Unter [Mitglieder](admin/members.php) legst du Mitglieder an (Name, E-Mail), wählst **Rolle** und **Referat**, lädst ein oder deaktivierst. Die **Referat-Liste** darunter bestimmt, welche Referate es gibt und in welcher Reihenfolge sie im Word-Bericht erscheinen. Umbenennen zieht auf Mitglieder und bestehende Berichte durch. Ganz am Ende der Seite stehen die **StuPa-Zugänge**.

**Was passiert beim Löschen eines Mitglieds?** Die Regel lautet: **Persönliches geht mit, Erstelltes bleibt.**

- **Mitgelöscht** wird alles, was nur zur Person gehört: eigene Stimmen, Einteilungen und Börsen-Angebote, Zusagen, Gelesen-Haken, Abwesenheiten, Streak und Erfolge, Mitteilungs-Einstellungen, Push-Abos, empfangene Nachrichten, gegebene und erhaltene Props sowie die eigene Pinnwand.
- **Erhalten** bleibt alles, was die Person für den AStA erstellt hat: Events, Sitzungen, Get-Togethers, wichtige Infos, TOPs, **Umlaufbeschlüsse (das Beschluss-Archiv!)**, Abstimmungen, Terminfinder, geteilte Termine und Belegblätter samt Anhängen. Wo die Ersteller:in stünde, entfällt die Angabe.
- **Pinnwand-Einträge bei anderen bleiben stehen** und erscheinen als „Ehemaliges Mitglied" (bzw. weiter anonym). Verschenkte Skins bleiben bei den Beschenkten.

### Nachrichten aufs Dashboard hinterlassen
Unter [Nachricht hinterlassen](admin/message.php) legst du **ausgewählten Personen** oder allen als **Ankündigung** eine kurze Nachricht direkt aufs Dashboard. Sie steht dort über den offenen Aufgaben, bis die Person sie auf „Erledigt" setzt. Standardmäßig bleibt es beim Dashboard – pro Nachricht wählst du, ob **zusätzlich eine E-Mail** rausgeht.

Event-Anleger:innen haben dasselbe im Bearbeiten-View ihres Events, gerichtet an alle, die zurückgemeldet oder eingeteilt sind.

### Scores pflegen
Unter [Scores](admin/score.php) stehen beide Auswertungen:

- **Eventscore** – Tabelle aller Mitglieder mit Punkten und Einordnung. Der **Vorsitz** kann pro Mitglied **manuell korrigieren** (+/−) und den Score **zurücksetzen** – das setzt den Zählbeginn auf heute und entfernt alle Korrekturen, z. B. zu Beginn einer neuen Legislatur.
- **Basis-Score** – dieselbe Tabelle mit aufklappbaren Abzugs-Posten, eigener Korrektur und eigenem Reset. Darunter **„Unentschuldigt gefehlt nachtragen"**: Sitzung wählen, Mitglieder anhaken, speichern. Die App erfasst Anwesenheit nicht selbst – das trägt der Vorsitz nach dem Protokoll nach.
- **Props** – eine vertrauliche Auswertung, die **nur die Admin-Rolle** sieht (bewusst nicht der Vorsitz): wer wie viele bekommen hat, ausklappbar von wem. Dort lässt sich die Anzahl auch anpassen: **+1** gibt einen Prop, der für die Person nicht von einem echten unterscheidbar ist; **−1** nimmt still weg und baut dabei zuerst System-Props ab, damit echte Props erhalten bleiben.

Unter [Achievements-Übersicht](admin/achievements.php) gibt es einen Blick auf die Gamification (wer hat was, beliebteste Belohnungen, Ranking) – **geheime Erfolge sind mit 🤫 markiert, also bitte nicht spoilern**. Dazu können Vorsitz und Admin dort **AsT verschenken** (1 bis 100.000, mit Anlass): Der Betrag landet sofort im Schicht-Depot der Person, und sie bekommt eine **Feier-Nachricht** aufs Dashboard – mit dem Namen der schenkenden Person und dem Anlass. Gut für Preise, Danke-Momente oder Wettbewerbe.

Unter [Aktivitätsstatistik](admin/activity.php) siehst du, wer die App wie regelmäßig nutzt: aktive Mitglieder je Woche (4 bis 52 Wochen umschaltbar), zuletzt aktiv, laufende Streak, Sparkline, dazu **Push-Geräte** und ob die Person die App **installiert** nutzt. Bewusst ohne Wertung. Ganz unten liegt – **nur für die Admin-Rolle** – „Streaks anpassen" als Kulanz-Werkzeug, etwa nach technischen Problemen.

### Wichtige Informationen pflegen
Unter [Wichtige Informationen](admin/infos.php) legst du Einträge mit **Titel, Text und Dokumenten** an; sie erscheinen auf der Info-Seite. Über **„Sichtbar für"** entscheidest du je Eintrag, ob ihn **alle Mitglieder** oder **nur ein bestimmtes Referat** sehen.

Nur **neue** Einträge lösen einen Dashboard-Hinweis aus, und zwar genau bei den Mitgliedern, für die sie sichtbar sind – ein Referats-Eintrag pingt also nicht den ganzen AStA. Bearbeiten, Umsortieren und Löschen laufen still. Die Reihenfolge änderst du mit den ↑/↓-Pfeilen. Dokumente liegen geschützt und sind nur für angemeldete Mitglieder abrufbar.

### Wegweiser pflegen
Der [Wegweiser](admin/wegweiser.php) ist das Verzeichnis, das die Frage „wo liegt was und wer ist zuständig?" beantwortet. **Anlegen darf hier jedes Mitglied** – direkt auf der Wegweiser-Seite, und jede:r pflegt die eigenen Einträge selbst; die Spalte **„Von"** zeigt dir, von wem welcher stammt, und du darfst alle bearbeiten. Inhaltlich geht es um: unsere eigenen Dienste, die Anlaufstellen an der Uni und die Ansprechpartner:innen außerhalb. Ein Eintrag braucht einen **Namen** und mindestens **eine Adresse** – Web, E-Mail oder Telefon, gern auch alles drei. Fehlt bei der Web-Adresse das `https://`, wird es ergänzt; unbrauchbare Adressen weist das Formular mit einem Hinweis ab.

Die **Rubrik** bündelt Einträge zu Blöcken (z. B. „Unsere Dienste", „Uni & Verwaltung", „Fachschaften"). Sie ist ein freies Textfeld mit Vorschlagsliste – **gleiche Schreibweise, gleicher Block**, „Fachschaften" und „Fachschaft" wären zwei. Das **Symbol** darfst du wählen; bei „Automatisch" wird es aus der Adresse geraten und trifft bei den gängigen Diensten. Unter **„Sichtbar für"** hakst du an, wer den Eintrag sieht: **ohne Häkchen alle**, sonst genau die angehakten Referate – und da darf **mehr als eins** dabei sein, etwa ein Zugang, den nur Vorsitz und Finanzen brauchen. Die ↑/↓-Pfeile sortieren innerhalb der Rubrik.

Der Wegweiser löst **keine** Dashboard-Hinweise aus – er ist ein Nachschlagewerk, kein Neuigkeiten-Kanal.

### Hochschulöffentliche Umfragen
Unter [Umfragen](admin/umfragen.php) legst du Umfragen an, die **außerhalb der App** stattfinden – für alle Mitglieder der RPTU, nicht nur für den AStA. Jede Umfrage hat einen eigenen Link, den du frei verteilen kannst.

**Zuständige Referate:** Je Umfrage kannst du bei den Eckdaten Referate als zuständig eintragen. Deren Mitglieder bekommen die Umfrage als **eigenen Punkt mit ihrem Titel in der Titelleiste** (sie haben ja keinen Zugriff auf die Verwaltung) und betreuen genau diese Umfrage komplett – Fragen, Öffnen/Schließen, Ergebnis, CSV. Andere Umfragen, das Anlegen neuer und die Einstellungen bleiben bei Vorsitz und Admin; auch die Zuständigkeit selbst vergibt nur der Vorsitz.

**Erst ansehen, dann öffnen.** Auf der Bearbeiten-Seite steht ein **geheimer Vorschau-Link**. Damit lässt sich der fertige Bogen durchklicken, **solange er noch Entwurf ist** – genau so, wie ihn die Teilnehmenden sehen, mit einem gelben Band als Hinweis. Abgeschickt wird dabei nichts: keine Stimme, keine Mail. Wer den Link hat, kommt rein (dieser Bereich kennt die App-Rollen bewusst nicht) – gib ihn also nur an Leute weiter, die mitlesen sollen. Wurde er zu weit gestreut, erzeugt **„Neu"** einen anderen und der alte ist tot.

**Bilder zu einer Frage.** Je Frage lässt sich ein Bild hochladen (JPG, PNG oder WEBP, bis 12 MB) – praktisch für Grundrisse, Entwürfe oder Fotos, über die abgestimmt werden soll. Die App rechnet jedes Bild neu (längste Kante 1200 px, als JPEG): Das deckelt die Größe **und wirft die versteckten Kameradaten samt Ortsangabe weg**. Dazu gehört eine kurze Bildbeschreibung für alle, die das Bild nicht sehen können. Gelöscht wird die Datei automatisch mit – beim Ersetzen, beim Löschen der Frage und beim Löschen der Umfrage; was trotzdem übrig bleibt, holt das tägliche Aufräumen.

**Auf Englisch anbieten.** Jede Frage und die Eckdaten haben einen optionalen Block **„Englische Fassung"**. Was du dort einträgst, sehen Teilnehmende, wenn sie oben rechts auf **English** klicken; was leer bleibt, erscheint weiterhin auf Deutsch – eine halb übersetzte Umfrage ist also kein Problem. Der Umschalter taucht überhaupt nur auf, wenn irgendwo eine englische Fassung gepflegt ist. Das Gerüst der Seite (Knöpfe, Hinweise) ist fest zweisprachig, du musst nur die **Inhalte** schreiben. Auch die Bestätigungsmail lässt sich in den Einstellungen englisch hinterlegen – wer auf Englisch abgestimmt hat, bekommt sie dann auf Englisch.

**Wie es abläuft.** Der Stimmzettel steht sofort da – wer gerade motiviert ist, stimmt jetzt ab. Ganz unten kommt die Hochschul-Adresse dazu, und **gezählt wird die Stimme erst mit dem Klick auf den Bestätigungslink** in der Mail. Damit ist bewiesen, dass die Person an dieses Postfach kommt; ohne Anmeldung an der Uni-Kennung geht es nicht ehrlicher. Zeit zum Bestätigen ist bis zum Ende der Umfrage plus drei Tage. Welche Endungen zählen, stellst du ein; `rptu.de` deckt Unterdomains wie `stud.rptu.de` automatisch mit ab.

**Warum diese Reihenfolge:** Schickt man die Leute erst ins Postfach und dann zurück zum Formular, springt ein guter Teil ab. Die unbestätigte Stimme liegt so lange **verschlüsselt** bereit – der Schlüssel steckt allein im Link in der Mail, sodass sie bis zur Bestätigung niemand lesen kann, auch ihr nicht. Wer sich vorher anders entscheidet, stimmt einfach noch einmal ab: Es gilt immer die zuletzt abgegebene, noch unbestätigte Stimme.

**Geheim und trotzdem nur einmal.** Die App merkt sich zu jeder Umfrage nur einen unumkehrbaren Zahlenwert aus der Adresse und ein „hat abgestimmt" – daraus lässt sich keine Mailingliste bauen, und über zwei Umfragen hinweg ist dieselbe Person nicht wiedererkennbar. Die Antworten liegen getrennt davon und tragen kein Merkmal, das zurückführen würde; bewusst nicht einmal eine Uhrzeit. **Auch ihr könnt also nicht sehen, wer wie abgestimmt hat** – das ist keine Nachlässigkeit, sondern der Zweck. Aus demselben Grund lässt sich eine abgegebene Stimme nicht mehr ändern.

**Mailmenge im Blick.** Der Hoster lässt 3000 Mails am Tag zu, und davon muss Luft für die Login-Links bleiben. Umfragen nehmen sich aus dem gemeinsamen Topf, was frei ist – **ohne eigenen Tagesdeckel und ohne Stundenbremse**, beides steht auf 0 und ist nur für Sonderfälle da. Die Bestätigungsmail geht damit im Normalfall **sofort** raus. Reicht es gerade nicht, geht die Bestätigungsmail in eine Warteschlange, und die Person bekommt eine **ehrliche Auskunft**, wann sie ankommt – „in den nächsten Minuten" oder „voraussichtlich morgen". In den Einstellungen siehst du, wie viele Mails Umfragen und Pat:innenprogramm zusammen in den letzten 24 Stunden verschickt haben. Für die Warteschlange gibt es `cron_umfrage.php` – er ist das Netz darunter, nicht der übliche Weg: Er greift nur, wenn der Topf im Moment des Abstimmens wirklich leer war.

**Fragen zusammenstellen:** Die **Art** wählst du als Kachel – Einfachauswahl, Mehrfachauswahl (mit „höchstens X"-Deckel), Skala (mit **Schnellwahlen** wie 1–5 Zustimmung, 0–10 oder Schulnoten), Freitext und **Zwischentext** (eine Überschrift, die lange Bögen gliedert und nichts fragt). Das Formular zeigt immer nur die Felder der gewählten Art, und die **Live-Vorschau** darunter zeigt die Frage genau so, wie Teilnehmende sie sehen werden. Bei Auswahlfragen lässt sich ein **„Sonstiges: …"-Feld** anbieten – die eingetippten Antworten erscheinen im Ergebnis als eigene Liste. Fragen lassen sich umsortieren, **verdoppeln** und als freiwillig markieren.

**Was ihr seht:** während der Laufzeit nur zwei Zahlen – gezählte und noch unbestätigte Stimmen. Das Ergebnis erscheint **nach dem Ende**, öffentlich unter einem eigenen Link und intern mit CSV-Export, beides mit **Grafiken**: Balken je Antwort (die stärkste hervorgehoben), die Skala als Säulen-Verteilung mit Mittelwert-Marker und die **Beteiligung über die Zeit** als Stimmen je Tag. Einen Zwischenstand gibt es bewusst nicht: Er würde beeinflussen, wer noch nicht abgestimmt hat.

**Freitexte** zeigt die öffentliche Ergebnisseite nur, wenn du es je Umfrage erlaubst – dort stehen erfahrungsgemäß manchmal Namen. Intern siehst du sie immer.

Vor der ersten Umfrage einmalig unter **Einstellungen**: Basis-Adresse und Antwortadresse (ein Klick übernimmt beides aus den App-Einstellungen; verschickt wird immer aus der App-Adresse), erlaubte Endungen, Impressum und Datenschutz. Eine **leere Basis-Adresse füllt sich inzwischen selbst** aus den App-Einstellungen, und weicht sie von der App ab, warnt die Bearbeiten-Seite direkt am öffentlichen Link – ein Link, der ins Leere führt („diese Umfrage gibt es nicht"), fällt so sofort auf. Die **Testmail an mich** zeigt sofort, ob der Versand steht. Verzeichnis und Links beendeter Umfragen räumt die App **90 Tage nach dem Ende** selbst weg; die anonymen Stimmen bleiben.

**Nicht für Wahlen.** Das Verfahren ist eine Hürde gegen Mehrfachabstimmen und gegen Fremde von außen – keine Identitätsprüfung. Wer zwei Hochschul-Adressen hat, kann zweimal abstimmen, und `@rptu.de` haben auch Beschäftigte. Für Gremienwahlen gibt es Wahlordnung und Wahlausschuss; dafür ist diese App nicht gedacht.

### Externe Events freischalten
**Referate oder Personen – ihr entscheidet.** An vier Stellen beantwortet die App dieselbe Frage: *Wer im AStA kümmert sich darum?* Bei **was.läuft**, den **externen Events**, **je Umfrage** und beim **Pat:innenprogramm** wählt ihr in den Einstellungen zwischen **Referate** und **Personen** und hakt darunter die passenden an. **Beides zusammen geht nicht:** Beim Umschalten wird die vorherige Auswahl gelöscht – sonst läge unsichtbar eine zweite Liste herum, die beim nächsten Umschalten plötzlich wieder gälte. Vorsitz und Admin haben unabhängig davon immer Zugriff, bekommen aber nur dann Mitteilungen, wenn sie selbst eingetragen sind.

Externe Events liegen im **Events**-Tab, nicht in der Verwaltung – sie werden von den Referaten betreut. Die Seite ist in **Reiter** geteilt (*Veranstaltungen* / *Einstellungen*). Unter **Einstellungen** legst du fest, welche Referate **grundsätzlich** Zugriff haben (die sehen dann alle Veranstaltungen und können neue anlegen). Alle anderen betreuen nur das, wo sie als Referat eingetragen sind. Vorsitz und Admin sehen immer alles.

Dort stehen auch Basis-Adresse, Antwortadresse und der **eigene Deckel** für den Mailversand – siehe „Das gemeinsame Mail-Konto" weiter unten. Eine **leere Basis-Adresse füllt sich selbst** aus den App-Einstellungen, und weicht sie von der App ab, warnt die Einrichtung direkt am öffentlichen Link. Wartende Mails holt `cron_extern.php` nach – ohne ihn bleiben sie liegen, und die Diagnose meldet das, sobald wirklich etwas wartet.

In **Anmeldungen & Einteilung** zeigt die Karte **„Auf einen Blick"** die Anmeldungen je Tag, den Füllstand jeder Gruppe und die Verteilung der Auswahl-Felder als Balken – dieselben Grafik-Bausteine wie bei den Umfragen (`chart-core.php`, kommt beim Hochladen mit). Die öffentliche **Anmeldeseite** hat einen Bühnen-Kopf mit Eckdaten-Chips (Datum, Ort, Anmeldestand) und zeigt freie Plätze als Balken.

### Öffentlicher Terminplaner
Der [Terminplaner](admin/terminplaner.php) ist – wie die externe Redeliste – ein **Angebot an alle**, das der AStA kostenfrei betreibt. Bewusst **nicht** auf die Hochschule beschränkt: Wer den Link kennt, darf ihn benutzen, ob Fachschaft, Verein, Sportgruppe oder Familie. Alle finden damit einen gemeinsamen Termin, **ohne Konto und ohne Anmeldung**. Die öffentliche Seite liegt unter [`/termin/`](termin/).

**Ihr legt dort nichts an.** Wer einen Termin sucht, macht das selbst: Titel, ein paar Vorschläge, fertig. Danach gibt es genau **zwei Links** – einen zum Weitergeben und einen zum Verwalten. Der Verwaltungs-Link ist der einzige Ausweis; es gibt keine Passwörter und keine Konten. **Ist er weg, ist er weg** – auch ihr könnt eine Umfrage niemandem zuordnen. Wer beim Anlegen eine Mailadresse einträgt, bekommt beide Links zugeschickt; das ist die einzige Sicherung, und es ist die **einzige Mail**, die dieser Bereich überhaupt verschickt.

**Einstellbar ist die einzelne Umfrage** von der Person, die sie anlegt: „Vielleicht" als dritte Antwort, Ergebnis sofort / erst am Ende / nur für sie, Namen oder nur Zahlen, Antworten änderbar, Bemerkungsfeld, Mailadresse der Teilnehmenden (nicht fragen / freiwillig / Pflicht), Frist, Höchstzahl je Termin und insgesamt. Am Ende kann sie **einen Termin festlegen** – der steht dann oben auf der Seite, und alle holen ihn sich als Kalenderdatei.

**Eure Seite** ist die Aufsicht: der Betriebszustand (**läuft / keine neuen / aus** – der mittlere Zustand stoppt Neuanlagen, ohne laufende Umfragen abzuwürgen), Basis-Adresse und Antwortadresse, Impressum und Datenschutz, die Grenzen gegen Missbrauch (Vorschläge und Rückmeldungen je Umfrage, neue Umfragen je Stunde, Aufbewahrung) und die Texte der Startseite. Dazu eine Liste aller Umfragen mit Titel, Datum und Anzahl der Antworten – **bewusst ohne die Antworten selbst**: Das ist die Aufsicht über den Dienst, keine Einsicht in fremde Terminabsprachen. Löschen könnt ihr trotzdem, falls jemand den Terminplaner für etwas benutzt, das dort nicht hingehört.

**Zwei Wege, eine Entscheidung.** Beim Anlegen steht ganz oben – über allen Feineinstellungen – die Frage, wie die Leute zu ihrer Antwort zurückfinden. Sie prägt die Umfrage stärker als alles andere, weil sie festlegt, ob überhaupt Mailadressen im Spiel sind:

- **Offene Liste** (Vorgabe): keine Adressen, alle sehen die Liste und dürfen jeden Eintrag ändern. Am schnellsten, passt für Gruppen, die sich kennen.
- **Persönlicher Link per Mail**: Adresse ist Pflicht, jede Person bekommt ihren eigenen Link und ändert nur die eigene Zeile – auch Wochen später von jedem Gerät aus.

Der Umschalter setzt drei Einstellungen auf einmal. Wer sie einzeln braucht – etwa **gar nicht mehr änderbar**, wenn die Runde durch ist –, findet sie später in der Verwaltung der Umfrage; dort steht auch, welcher der beiden Wege gerade gilt.

**Antworten ändern.** Wer das darf, ist damit gesetzt – **Vorgabe: alle**. Wer gemeinsam einen Termin sucht, korrigiert auch mal für jemand anderen einen Tippfehler oder trägt für die Person nach, die gerade nicht am Rechner sitzt; von Böswilligen auszugehen hieße, den Normalfall für den Ausnahmefall unbequem zu machen. Die anderen beiden Stufen sind **nur die eigene Antwort** (über den persönlichen Link) und **gar nicht**.

Drei Wege führen zur eigenen Antwort zurück: der persönliche Link, ein **Cookie** (30 Tage, nur auf dem Server lesbar, mit einem „Dieses Gerät vergessen"-Knopf auf der Startseite) – und, wenn beim Eintragen eine Mailadresse angegeben wurde, die **Bestätigungsmail**. Die trägt denselben Link und ist der Grund, warum eine Antwort auch zwei Wochen später und von einem fremden Gerät aus noch änderbar ist, ganz ohne Konto. Sie geht **einmal** raus, nicht bei jeder Korrektur, und nur wenn die Adresse neu oder geändert ist.

**Rückmeldungen** kommen per Mail: Im Fuß jeder öffentlichen Seite steht ein Knopf, der das Mailprogramm mit vorbereitetem Betreff öffnet. Kein Formular – es gibt hier keine Konten, also niemanden, dem man antworten könnte, und jedes weitere Formular wäre eine weitere Stelle, an der Fremde uns Text schicken können. Die Adresse stellst du unter **Rückmeldungen gehen an** ein; bleibt das Feld leer, gilt die Antwortadresse. Die vorbereitete Mail enthält **bewusst nicht** die Adresse der Seite: Auf der Verwaltungsseite stünde dort der Verwaltungs-Link, und den soll niemand versehentlich mitschicken.

**Von selbst weg:** Umfragen, die 120 Tage lang niemand mehr angefasst hat, und solche ganz ohne Rückmeldung nach 30 Tagen. Das läuft bei jedem Aufruf der Verwaltungsseite mit, ein eigener Cron ist nicht nötig.

**Nicht für Wahlen** – aus demselben Grund wie bei den Umfragen: Wer den Link hat, kann antworten, so oft er will. Der Terminplaner sucht einen Termin, er stellt keine Stimmberechtigung fest.

### Gefahrenzone: die öffentlichen Bereiche
Pat:innenprogramm, Umfragen, externe Events und der Terminplaner liegen **nicht** in `data/asta.sqlite`, sondern jeder in einer eigenen Datenbankdatei. Die [Gefahrenzone](admin/reset.php) hat dafür einen eigenen Abschnitt mit Bestand, Dateigröße und je einem Löschknopf. **„Alles zurücksetzen" fasst sie bewusst nicht an** – eine laufende öffentliche Anmeldung soll nicht mit verschwinden, weil jemand die interne Stammliste leeren wollte. Für eine Sicherung kopierst du deshalb den ganzen Ordner `data/`, nicht nur die eine Datei.

Dort steht auch das **gemeinsame Mail-Konto** (siehe unten). Es zu leeren setzt nur den Zähler zurück – beim Hoster sind die Mails weiterhin gezählt, Rundmails dürften danach also mehr verschicken, als eigentlich frei ist.

Taucht in der [Diagnose](admin/errorlog.php) der Hinweis auf eine **alte `tokens`-Tabelle** auf, findest du hier den Knopf dazu: Er zeigt erst, wie viele Zeilen drinstehen, und entfernt sie dann auf Klick. Sie stammt aus einem Zwischenstand der Umfragen, wird nicht mehr benutzt und kann Mailadressen im Klartext enthalten.

### Das gemeinsame Mail-Konto
Der Hoster lässt eine feste Zahl Mails je 24 Stunden durch (bei Mittwald **3000** je Absenderkonto, auf Anfrage freigeschaltet – davor waren es 400) – und zwar für **alles**, was aus eurer Adresse rausgeht. Früher bekam jeder Bereich davon eine feste Scheibe. Das war Unfug: Die Bereiche laufen nie gleichzeitig, und eine ungenutzte Scheibe verfiel einfach.

Stattdessen gibt es jetzt **ein Konto**. Jede tatsächlich verschickte Mail wird dort eingetragen – Login-Links, Mitteilungen, Pat:innenprogramm, Umfragen, externe Events, Terminplaner. Wer verschicken will, **rechnet sich an, was die anderen schon verbraucht haben**. Hat gestern niemand gemailt, steht fast das volle Tageskontingent bereit.

Der Unterschied, um den sich alles dreht:

- **Rundmails** (Pat:innenprogramm, Umfragen, externe Events; der Terminplaner nur mit seiner einen Link-Mail) fragen vorher und hören auf, bevor der Topf leer ist. Was nicht mehr passt, bleibt in der Warteschlange und geht raus, sobald das gleitende 24-Stunden-Fenster wieder Platz macht.
- **Einzelmails der App** (Login-Links, Mitteilungen) tragen nur ein und gehen **immer** raus. Genau dafür steht die **Reserve**: Ein Login-Link darf nie daran scheitern, dass eine Rundmail den Topf leergezogen hat.

**Einstellen** lässt sich das an einer einzigen Stelle: [Verwaltung → E-Mail & Einstellungen](admin/index.php#einstellungen) – Hoster-Grenze und Reserve (empfohlen 3000 und 200).

**Neben jedem Mail-Feld steht die Empfehlung** – im gemeinsamen Konto, bei Umfragen, externen Events, Terminplaner und Pat:innenprogramm. Weicht der eingestellte Wert ab, sagt die Zeile es in Amber und nennt beide Zahlen. Das ist nötig, weil eine **einmal gespeicherte Zahl jede Vorgabe im Code schlägt**: Beim Sprung von 400 auf 3000 Mails standen überall noch die alten Werte, ohne dass irgendwo ein Hinweis darauf war. Die **Konto-Tabelle** darunter zeigt je Bereich den Verbrauch der letzten 24 Stunden, was zusammen verbraucht ist und was noch frei ist; dieselbe Tabelle steht auch bei Pat:innenprogramm, Umfragen und externen Events, damit man nirgends an einer Zahl schraubt, ohne die anderen zu sehen.

Jeder Rundmail-Bereich hat zusätzlich einen freiwilligen **eigenen Deckel**. **0 heißt: keiner** – das ist die Vorgabe, dann gilt allein der gemeinsame Topf. Setz dort nur etwas ein, wenn eine große Aussendung nicht das ganze Tageskontingent aufbrauchen soll.

**Die App steht in keiner Suchmaschine.** Jede Seite hinter dem Login – und die Anmeldung selbst – trägt „noindex". Die öffentlichen Bereiche (was.läuft, Terminplaner, Umfragen, Pat:innenprogramm, Anmeldungen) entscheiden das weiterhin selbst.

### Bug-Reports & Feedback bearbeiten
Beide Bereiche funktionieren gleich: Ticket mit Verlauf, statt Mails hin und her.

- [Bug-Reports](admin/bugs.php) – Liste mit Filter *Offen / Erledigt / Alle*. Auf der Detailseite kommentierst du und setzt erledigt bzw. öffnest wieder. Wer aus der **Technik** ein Ticket auf **erledigt** setzt, bekommt dafür den Erfolg **Technik-Engel** – mit ihm die Avatar-Farbe **„Engel"**, den Streak-Stil **„Heiligenschein"** und den Avatar-Schmuck gleichen Namens (den es sonst nur aus der Hutsammlung gibt). Der Vorsitz sieht dieselbe Seite, bekommt den Erfolg aber nicht, und Wieder-Öffnen ändert nichts. Die meldende Person bekommt jede Änderung aufs Dashboard und kann direkt antworten. **Admins** haben dafür eine eigene Kachel im Dashboard.
- [Feedback](admin/feedback.php) – dasselbe für **Lob, Ideen, Kritik und Fragen**, filterbar nach Stand und Art. Der Bearbeitungsstand läuft **Neu → Angenommen → Umgesetzt** oder **Abgelehnt**; bei „Abgelehnt" ist eine Begründung **Pflicht**, damit niemand ein wortloses Nein bekommt.

Sichtbar sind beide Bereiche nur für das Technik-Referat und die einreichende Person. Über die eigene Aktion wird niemand benachrichtigt.

### Gemeinsame Ablage: Teams oder Nextcloud
Damit niemand eigene Office-Lizenzen braucht, können Dokumente aus der App direkt dort geöffnet werden, wo ihr ohnehin arbeitet. Der Knopf **„In Teams öffnen"** (bzw. „In Nextcloud öffnen") kopiert die Datei beim **ersten** Klick einmalig in die Ablage und führt ab dann immer zum **selben** Dokument.

**Ab dann ist der Stand in der Ablage führend:** Der Download-Knopf in der App liefert automatisch die aktuelle Fassung von dort. Nur wenn die Ablage nicht erreichbar ist, kommt die lokale Kopie.

Der Knopf ist rollenbasiert – bearbeiten sollen die Zuständigen, sehen sollen alle:

- **Infos-Dokumente:** Vorsitz und Admin
- **Sitzungs-Anhänge:** Sekretariat, Vorsitz, Admin
- **Umlauf-/Abstimmungs-Anhänge:** die Ersteller:in des Vorgangs sowie Vorsitz und Admin
- **Protokoll-Entwürfe:** Protokollant:in und Verwaltung
- **Beleg-Anhänge bewusst nicht** – sie sind app-privat (Einreicher:in und Finanzen); in einer Teams-Bibliothek wären sie für alle sichtbar

Beim **Löschen einer Datei** in der App fragt der Dialog per Häkchen mit, ob das Dokument in der Ablage gleich mit in den Papierkorb soll.

> **Eine Datei liegt immer nur in *einer* Ablage.** Stellst du unter [Uploads und Automationen](uploads.php) → „Ablage für gemeinsames Bearbeiten" um, wirkt das nur auf **neue** Dokumente; was in Teams liegt, bleibt in Teams. Sonst gäbe es zwei bearbeitbare Fassungen derselben Datei und niemand wüsste, welche gilt. Dieselbe Karte zeigt, wie viele Dokumente derzeit wo liegen.

**Protokolle bleiben in Teams** (so gewollt): Entwürfe könnt ihr wahlweise in der Nextcloud schreiben, das fertige Protokoll nimmt die App über Teams entgegen – daran hängen der Abstimmungsgegenstand und die OLAT-Veröffentlichung als PDF, und die PDF-Wandlung macht Microsoft Graph.

**Belegblätter ins Nextcloud-Archiv** lassen sich separat einschalten: Jedes eingereichte Belegblatt landet dann als PDF unter `<Ordner>/<Jahr>/`, die Belege als eigene Dateien daneben. Wird ein Belegblatt in der App gelöscht, **bleibt** die Datei dort – sie ist das Archiv. **Achtung:** Auf einem Belegblatt stehen Anschrift und IBAN. Dieser Ordner ist bewusst getrennt von dem der App-Dokumente und sollte **nur mit dem Finanzen-Team** geteilt sein.

Die Einrichtung beider Anbindungen steht unten im Technik-Anhang.

### Automationen & Cron
Unter [Uploads und Automationen](uploads.php) liegen oben die Abläufe, unten (eingeklappt) die technische Einrichtung. **Zielordner klickst du überall im Ordner-Browser an**, ohne einen Pfad zu tippen; fehlende Ordner werden angelegt.

- **Berichte-Dok → Ablage:** erzeugt **am Sitzungstag ab einer einstellbaren Uhrzeit** (Standard 18:00) automatisch die Berichte-Datei einer berichtspflichtigen Sitzung und lädt sie hoch. Die Uhrzeit liegt bewusst **nach der 18:00-Rückmeldefrist**, damit der Stand endgültig ist – deshalb genügt ein täglicher Cron-Lauf. Einstellbar sind an/aus, Uhrzeit, Ziel-Ablage, Ordner, Jahres-Unterteilung, Dateiname-Muster (`{sitzung}`, `{datum}`, `{datumzeit}`) und „nur wenn mindestens ein Bericht eingetragen ist". Pro Sitzung passiert das höchstens einmal; waren zur Auslösezeit noch keine Berichte da, holen die Läufe der nächsten drei Tage es nach.
- **Protokoll-Workflow:** hier stellst du die beiden Teams-Ordner (öffentlich und intern) und den OLAT-Ordner ein.
- **Jahres-Unterteilung** ist standardmäßig an: abgelegt wird unter `…/JAHR/`, wobei das Jahr aus dem **Sitzungsdatum** kommt, nicht aus dem Upload-Datum.

Beides treibt ein gemeinsamer Cron-Job, der **einmal täglich kurz nach der Auslösezeit** laufen muss (z. B. 18:01) – zusätzlich zum täglichen Erinnerungs-Cron. Die Cron-Karte zeigt den letzten Lauf und warnt, wenn er noch nie oder seit über 26 Stunden nicht gelaufen ist. Zum Prüfen gibt es „Cron jetzt ausführen" (echter Probelauf beider Teile) und „Jetzt testen" (eine einzelne Berichte-Datei, unabhängig vom Zeitfenster).

**Die Seite ist in Reiter geteilt** – *Automationen*, *SharePoint*, *OLAT*, *Nextcloud*. Die Abläufe stehen also für sich, und jede Anbindung hat ihren eigenen Platz zum Einrichten und Testen; nach einem Test landest du wieder dort, wo du warst.

**Ablauf-Warnung fürs Client-Secret:** Zu den Microsoft-Zugangsdaten gehört das „Gültig bis"-Datum. Ab 30 Tagen vor Ablauf erscheint eine deutliche Meldung mit Schritt-für-Schritt-Anleitung und Direktlink – im Uploads-Reiter **und** oben auf dem Dashboard von Vorsitz und Admin. So bricht der Upload nicht unbemerkt ab.

### Word-Vorlagen
Unter [Word-Vorlagen](admin/vorlagen.php) tauschst du die `.docx`-Vorlagen für **Protokoll**, **Protokoll (interner Teil)**, **Berichte**, das **Belegblatt** und die **Auszahlungsaufforderung des StuPa** ohne Code aus: angepasste Datei hochladen, aktuelle herunterladen oder auf den mitgelieferten Standard zurücksetzen. Die Platzhalter müssen erhalten bleiben:

- **Protokoll:** `((Sitzungsdatum))`, `((VORSITZ))`, `((RefX))`, `((Tagesordnungspunkte))`, `((TO-2))`
- **Berichte:** `((SITZUNG))`, `((DATUM))`, `((BERICHTE))`
- **Belegblatt:** `((NAME))`, `((STRASSE))`, `((PLZORT))`, `((BANK))`, `((BIC))`, `((IBAN))`, `((VA))`/`((VS))`/`((VP))`, `((PROJEKT))`, `((ANLASS))`, `((BN))`/`((BD))`/`((BS))`/`((BB))` (Beleg-Zeile, wird je Position geklont), `((SUMME))`, `((DATUM))`
- **Auszahlungsaufforderung:** `((LEG))` Nummer des Parlaments (steht in der **Kopfzeile**), `((PRAESIDIUM))` Namen des Präsidiums; dazu `((AA))` Antragsteller:in, `((AZ))` Verwendungszweck, `((AB))` Betrag, `((AK))` Kontoinhaber:in, `((AT))` Topf – diese Zeile wird je Posten geklont; im Anhang auf Seite 2 `((AKI))` und `((AIB))` (IBAN), ebenfalls je Posten

**Legislatur-Nummer und Präsidiums-Namen pflegt das Präsidium selbst** in seinem eigenen Bereich unter „Angaben fürs Formular" – an der Vorlage ist dafür nichts zu ändern. Zu Beginn einer neuen Legislatur tragen sie dort die neue Nummer und die neuen Namen ein, fertig.

Hochgeladene Vorlagen liegen geschützt und überstehen ein Deployment.

### Mailtexte
Unter [Mailtexte](admin/mailtexts.php) sind Betreff und Text **aller automatischen System-Mails** frei bearbeitbar – Login-Link, Einteilung, Abmeldung, alle Erinnerungen, Tausch-Vorschlag, neue Nachricht. Platzhalter wie `{{VORNAME}}`, `{{EVENT}}` oder `{{LINK}}` werden beim Versand ersetzt, je Vorlage ist HTML oder Plaintext wählbar, und „Auf Standard zurücksetzen" stellt jede Vorlage wieder her. Die **Sitzungs-Einladung** wird separat unter Sitzungen → Einladungen gepflegt.

### Diagnose & Selbsttest
Unter [Diagnose & Fehler-Log](admin/errorlog.php) landen alle Programmfehler mit Zeitpunkt und aufgerufener Seite in einer geschützten Datei – so lassen sich auch „stille" Fehler nachvollziehen. Der **Selbsttest** prüft die zentralen Funktionen auf einen Klick und **schreibt dabei grundsätzlich nichts**. Er hat vier Ebenen:

- **Frühwarnungen** – ein **Cron-Herzschlag** (jedes Cron-Skript stempelt seinen erfolgreichen Lauf; fehlt der Stempel oder ist er älter als 26 Stunden, warnen Selbsttest **und** Dashboard rot – das ist der häufigste reale Ausfall) und eine Datenbank-Integritätsprüfung.
- **Logik-Prüfungen** gegen konkrete Erwartungswerte – Score-Schwellen, Spitzenklasse-Regel, Fristen, Schichten über Mitternacht.
- **Smoke-Checks** der Render-Pfade mit echten Beispieldaten; fehlen Daten, steht dort sichtbar „übersprungen" statt stillschweigend nichts.
- **Ein Dry-Run des Erinnerungs-Versands:** Alle Sender laufen im Trockenlauf und melden, wer beim nächsten Cron-Lauf was bekäme – ohne etwas zu verschicken.

Dazu **Zustell-Tests** („Testmail an mich", „Test-Push an mich"), die die echte Strecke bis zum eigenen Postfach bzw. Gerät prüfen, eine **Mailvorlagen-Prüfung** auf fehlende Pflicht-Platzhalter und der **Datei-Vorschau-Test (iOS/PWA)** mit vier Mini-Testdateien. Kommen neue zentrale Funktionen dazu, wird der Selbsttest mitgepflegt.

**Test-Werkzeuge (nur Admin-Rolle und Technik-Login, nicht Vorsitz):** Zum Ausprobieren lassen sich temporär – nur für die eigene Sitzung, ohne echte Erfolge zu vergeben – der Royal-Modus und alle Belohnungen freischalten sowie Erfolgs-Toasts und die Streak-Animation abspielen. Dort lassen sich auch die **50- und die 100-Tage-Stufe vorführen** – die Aura erscheint dann in Begrüßungs- und Streak-Karte, obwohl die eigene Streak noch nicht so weit ist. Bitte dafür **nicht** die eigene Streak hochsetzen: Der Bestwert steigt mit und vergibt dauerhaft die Streak-Erfolge samt Belohnungen. Jede Freischaltung lässt sich dort wieder beenden.

Dazu kommen drei Werkzeuge für Dinge, die sonst an Ereignissen hängen, die es gerade gar nicht gibt – in der vorlesungsfreien Zeit etwa keine Sitzung, keine Abstimmung, kein Beleg: Die **Feier-Momente** lassen sich einzeln abspielen; eine **Test-Telefonnummer** landet im eigenen Profil, sodass sich der Zustimmungs-Dialog genau so ausprobieren lässt wie auf einem fremden Profil; und vier **Beispiel-Einträge im Wegweiser** machen die Suchleiste testbar. Test-Nummer und Beispiel-Einträge lassen sich mit einem Klick wieder entfernen – echte Daten bleiben unberührt.

### Sicherheit: Technik-Login & halbjährliche Kontrolle
Der **Technik-Login** ist ein gemeinsames Passwort unter `/admin/` – nur für **Ersteinrichtung und Notfall**, ohne persönliches Profil. Solange jemand darüber angemeldet ist, weist ein Warnschild oben darauf hin.

Weil er die letzte Bastion ist, erscheint **alle 6 Monate** auf dem Dashboard des **Vorsitzes** die Aufgabe **„Technik-Login bestätigen"**: einmal das Verwaltungs-Passwort eingeben, mehr nicht. Es wird nur geprüft, nichts geändert und nichts freigeschaltet. Fehlversuche landen mit Namen im Fehler-Log, nach fünf Fehlversuchen ist das Feld 15 Minuten gesperrt. **Nach jeder Passwort-Änderung ist die Kontrolle sofort wieder fällig** – so fällt auf, wenn ein neues Passwort nur beim Technik-Referat liegt. Den Stand zeigt die Verwaltung über dem Passwort-Formular.

**Notfall:** Ist das Technik-Passwort verloren, kann ein Admin oder der Vorsitz es in der Verwaltung per **Magic-Link an die eigene E-Mail** neu setzen, ohne das alte zu kennen.

---

## Rollen – wer darf was

- **Mitglied** – abstimmen, einspringen, tauschen, Events anlegen und einteilen, Abwesenheiten, eigener Bericht, Auslagen einreichen, Abstimmungen und Terminfinder starten.
- **Sekretariat** – zusätzlich Sitzungen, Tagesordnungen, Einladungen, Redeliste, Protokoll-Workflow und Berichte.
- **Finanzen** – wie Mitglied, arbeitet zusätzlich die eingereichten **Belegblätter** ab (eigener Punkt in der Titelleiste, Dashboard-Kachel bei Bedarf).
- **Admin** – alles: Mitglieder, Referate, Einstellungen, Gefahrenzone. Bei uns liegt das **Technik-Referat** bei dieser Rolle, deshalb hängen Bug-Reports und Feedback daran.
- **Vorsitz** – wie Admin, und zusätzlich darf nur der Vorsitz die **Scores bearbeiten und zurücksetzen**. Nur der Vorsitz sieht die halbjährliche Technik-Login-Kontrolle; nur Admin sieht die Props-Auswertung.
- **Pat:innen-Verantwortliche** – eine Zusatzberechtigung, keine Rolle: Zugriff auf genau die Pat:innen-Verwaltung, sonst nichts.
- **Technik-/Owner-Login** – das gemeinsame Notfall-Passwort, siehe oben.

---

## Technik-Anhang (Einrichtung)

**Voraussetzungen:** PHP 8.1+ mit `pdo_sqlite` (Datenbank) und `zip` (Word-Export) – bei Mittwald aktiv. Schreibrechte in `data/` und `redeliste-data/`.

### Installation
1. Den Ordner `asta-planer/` per SFTP/rsync ins Web-Verzeichnis laden (z. B. `html/planer/`). Den Ordner `data/` dabei **ausschließen**, damit die echte Datenbank nicht überschrieben wird.
2. `…/planer/admin/` öffnen und beim ersten Mal das Technik-Passwort festlegen.
3. Unter Verwaltung Mitglieder, Referate, Sitzungen und das erste Event anlegen. Die **Basis-URL** unter Einstellungen hinterlegen – sie wird für Links in Mails und für die Linkvorschauen gebraucht.

### Trägerangaben: wer die App betreibt

Alles, was nach eurem AStA aussieht, steht unter **Verwaltung → Träger**: Name, Web-Auftritt, Kontaktadresse und die Marke. Nichts davon steckt im Programm. Wer die App übernimmt, trägt dort seins ein und muss keine Datei anfassen.

**Name & Kontakt** werden beim Speichern in *alle* Bereiche geschrieben – App, was.läuft, Terminplaner, Pat:innenprogramm, Umfragen und öffentliche Anmeldung führen je eine eigene Datenbank, nennen aber denselben Träger.

**Die Marke** (Logo, App-Symbol) wird auf derselben Seite hochgeladen und landet in `data/branding/`. Das ist Absicht: Dort übersteht sie jedes Einspielen – der rsync fasst `data/` nie an – und sie geht bei einer Weitergabe der App nicht mit. Solange dort nichts liegt, gilt der mitgelieferte Platzhalter aus `assets/`.

Ein Feinheit, die leicht zu übersehen ist: **Kopf-Logo (App)** und **Kopf-Logo (öffentlich)** sind zwei verschiedene Zuschnitte desselben Logos. Das der App sitzt in einem 54 × 38 großen Kasten und wird als Data-URI direkt in die Seite geschrieben, damit es Teil des ersten Bildes ist. Wer die beiden vertauscht, verschiebt das Logo in der Titelleiste sichtbar; der Selbsttest merkt es.

Wer die Angaben lieber direkt setzt: Jeder Bereich hat eine eigene Datenbank und deshalb einen eigenen Eintrag. Im Programm steht zu keinem davon ein Name – nur ein neutraler Rückfallwert.

| Bereich | Schlüssel |
|---|---|
| App (`data/asta.sqlite`) | `org_name`, `org_name_kurz`, `org_url`, `org_kontakt_mail` |
| was.läuft | `traeger_name`, `traeger_kurz`, `traeger_url`, `uid_domain`, `share_base` |
| | `ort`, `ort_adj`, `ort_lang` |
| | `traeger_recht`, `traeger_anschrift`, `kontakt_recht`, `kontakt_datenschutz`, `aufsichtsbehoerde` |
| Terminplaner | `traeger_name`, `traeger_name_lang`, `traeger_url` |
| Pat:innenprogramm | `traeger_name`, `kontakt_mail` |
| Umfragen | `traeger_name` |
| Öffentliche Anmeldung | `traeger_name` |

**Der Ort.** Das Veranstaltungsportal fragt „was.läuft … in <Ort>?". Der Name **was.läuft** gehört zum Angebot und bleibt – er ist bewusst nicht ortsgebunden, und ein gemeinsamer Name über mehrere Hochschulen hinweg hilft Studierenden mehr als viele Eigenbauten. Der Ort steht in drei Formen in den Einstellungen: schlicht („Landau"), als Eigenschaftswort („Landauer Kulturszene", wird aus dem Ort gebildet) und ausgeschrieben für Suchmaschinen („Landau in der Pfalz").

**`uid_domain` bitte in Ruhe lassen.** Sie steckt in der Kennung jedes Termins im Kalender-Abo. Wird sie geändert, legen alle abonnierten Kalender sämtliche Termine ein zweites Mal an.

Der Selbsttest prüft das Gegenteil: Er meldet, wenn eine dieser Angaben **im Quelltext** auftaucht statt in den Einstellungen – und sagt auch, in welcher Datei. So bleibt die App weitergabefähig, ohne dass jemand daran denken muss.

**Lizenz:** Der Programmcode steht unter der MIT-Lizenz (`LICENSE`). Mitgelieferte Fremdteile – flatpickr, Tabler Icons, qrcodejs, die Schrift Outfit und die Standard-Fotos – haben eigene Lizenzen, nachzulesen in `THIRD-PARTY.md`; ihre Lizenztexte liegen in `licenses/` und müssen mitgeliefert werden. Der Selbsttest prüft, dass sie da sind.

**Nicht vergessen bei einer Übernahme:** Impressum und Datenschutzerklärung von was.läuft bauen sich aus den Angaben oben zusammen – sie sind ein Gerüst, kein fertiger Rechtstext. Vor dem Öffentlichgehen gehören sie gelesen und im Texte-Register der Verwaltung überarbeitet.

### Aktualisieren (Deploy per rsync)
```bash
rsync -avz \
  --exclude '.git/' \
  --exclude '.DS_Store' \
  --include 'data/.htaccess' \
  --exclude 'data/*' \
  --exclude 'umfrage/bilder/' \
  --exclude 'redeliste-data/' \
  ~/Downloads/asta-planer/ \
  <benutzer>@<server>:/html/planer/
```

**`data/*` schließt alles aus, was im Betrieb entsteht** – Datenbanken, Fehler-Log, hochgeladene Dateien, Vorlagen und die Marke unter `data/branding/`, auch künftige Ordner. Nur die `.htaccess` geht mit; die `--include`-Zeile muss dafür **vor** der `--exclude`-Zeile stehen, rsync nimmt die erste passende Regel. `umfrage/bilder/` liegt außerhalb von `data/`, weil die öffentliche Umfrage die Bilder direkt ausliefert, und braucht deshalb eine eigene Zeile.

Ohne `--delete` entfernt rsync auf dem Server nichts. Wird eine Datei im Programm gelöscht oder umbenannt, bleibt die alte dort liegen und muss von Hand weg.

**`.git/` MUSS ausgeschlossen bleiben.** Sonst liegt die vollständige Projektgeschichte auf dem Webserver und lässt sich unter `…/planer/.git/` herunterladen – mitsamt allem, was jemals in einem Commit stand. Der Selbsttest schlägt Alarm, falls das Verzeichnis dort auftaucht.

Der **abschließende Schrägstrich** bei `asta-planer/` ist wichtig, sonst landet der Ordner verschachtelt. Die Ausschlüsse halten Live-Datenbanken, Fehler-Log, hochgeladene Dateien, eigene Vorlagen, die Marke, die Bilder des Veranstaltungsportals und der Umfragen sowie die Redelisten-Daten auf dem Server unangetastet.

**Warum der ganze Ordner und nicht einzelne Dateien:** Unter `data/` liegen inzwischen sechs Datenbanken (`asta`, `pat`, `umfrage`, `extern`, `termin`, `mailpool`) samt ihren `-wal`/`-shm`-Dateien, dazu Uploads, Vorlagen, Bilder und die Marke. Entsteht eine davon lokal auch nur einmal versehentlich – es genügt, eine Seite lokal aufzurufen –, überschreibt die leere Datei beim nächsten Hochladen die echten Daten. Eine Liste einzelner Dateien müsste man bei jedem neuen Bereich nachpflegen; `data/*` erwischt auch das, woran beim Deploy niemand mehr denkt.

### Wichtig beim Hochladen: `assets/.htaccess`
Die Datei sagt dem Server, dass Stylesheets, Skripte, Schriften und Bilder **lange zwischengespeichert** werden dürfen. Fehlt sie, fragt der Browser bei **jedem Seitenwechsel** für jede dieser Dateien nach, ob sie noch aktuell ist – das sind mehrere Netz-Runden, bevor überhaupt etwas gezeichnet wird, und die Seite baut sich sichtbar auf (in Firefox deutlich zu sehen, Safari kaschiert es hinter den Seitenübergängen).

Gefahrlos ist das, weil **jede Adresse eine Versions-Kennung trägt** (`?v=<Änderungszeit>`): Nach einem Deploy ändert sich die Adresse, der Browser holt die Datei neu. Wer eine Datei unter `assets/` neu einbindet, muss `asset_v()` verwenden – sonst bliebe sie bei den Leuten ein Jahr lang eingefroren. Der **Selbsttest prüft das**: Eine Einbindung ohne Kennung wird dort rot, bevor sie jemandem wehtut.

### Logo im Stylesheet
Das Logo der Kopfleiste steckt **als Data-URI in `assets/style.css`** (Regel `.brand-logo-img`), nicht als eigene Bilddatei. Grund: Das Stylesheet blockiert das erste Zeichnen ohnehin und liegt dauerhaft im Browser-Cache – so ist das Logo Teil des ersten Bildes und kann nicht sichtbar nachgeladen werden. Als `<img>` war es eine eigene Anfrage und wurde in Firefox spürbar nachgereicht.

**Wenn das Logo sich ändert:** `assets/logo-header.png` austauschen (Favicon, App-Icon und die StuPa-/Pat:innen-Seiten nutzen weiter die Datei) **und** die Data-URI in der `.brand-logo-img`-Regel neu erzeugen – sonst zeigt die Kopfleiste weiter das alte Logo.

### Legislaturperioden gelten über ihren Zeitraum
Eine Periode wird mit **Name und Beginn** angelegt – mehr braucht sie nicht. Welche Sitzungen dazu
gehören, ergibt sich aus deren **Datum**: Es zählt die Periode mit dem letzten Beginn, der nicht nach
der Sitzung liegt; sie endet automatisch, wenn die nächste anfängt. Es gibt deshalb **kein
Legislatur-Feld mehr** beim Anlegen einer Sitzung.

Vorher war das ein Dropdown pro Sitzung – und wer es übersah, dessen Sitzung bekam **gar keine
Nummer** und hieß überall „Ordentliche Sitzung" statt „12. Ordentliche Sitzung": im Kalender, im
Protokoll-Dateinamen, im Titel der Genehmigung, in der Einladung. Gewarnt hat nichts.

- **Beginn ist Pflicht.** Eine Periode ohne Beginn könnte nichts einsammeln; der Selbsttest meldet so
  etwas, ebenso zwei Perioden am selben Tag.
- **„1. Sitzung ist Nr."** bleibt und gehört der Periode: Wenn die ersten Sitzungen vor der App lagen,
  zählt sie z. B. ab 7. Beim Anlegen einer Serie wird der Wert in die Periode geschrieben, in die die
  erste Sitzung fällt.
- Ausgefallene Sitzungen und Entwürfe zählen nicht mit; StuPa-Sitzungen zählen **eigenständig ab 1**.
- **Beginn und Startnummer bleiben änderbar** – ein Vertipper im Datum wäre sonst nur durch Löschen
  und Neuanlegen zu heilen. Achtung: Beides nummeriert die betroffenen Sitzungen sofort neu.
- **Löschen nimmt den Sitzungen nicht die Nummer.** Sie fallen in die davorliegende Periode und
  zählen dort weiter. Nur wenn es gar keine Periode mehr gibt, tragen sie keine Nummern.

**Sitzungen haben kein Ende** – sie laufen bis Tagesende. Das wird beim Lesen berechnet
(`meeting_ends_at()`) statt gespeichert; die Spalte `ends_at` wird für Sitzungen nicht mehr
geschrieben. Die Anzeige („ab 18:00") bleibt unverändert, weil genau dieses Tagesende das Kennzeichen
für ein offenes Ende ist.

### Verlinkung innerhalb der App
Wird in einem Text ein anderer Bereich genannt, soll man ihn anklicken können – statt einer
Klickanleitung („Verwaltung → Uploads und Automationen"). Dafür gibt es **eine** Quelle:

- **`app_places()`** in der `lib.php` kennt jeden verlinkbaren Ort: Adresse, Name, Symbol und wer
  hindarf. **`app_place('a_uploads')`** rendert daraus den Link – mit dem richtigen Pfad (aus
  `admin/` heraus automatisch mit `../`) und nur, wenn die angemeldete Person den Ort öffnen darf.
  Sonst erscheint der Name als fetter Text statt als toter Link. Optional mit Anker:
  `app_place('a_sitzungen', 'Sitzungen → Einladungen', 'einladungen')`.
- **`meeting_link()`, `event_link()`, `umlauf_link()`** machen dasselbe für einzelne Sitzungen,
  Events und Abstimmungen – analog zum schon vorhandenen `member_link()` für Namen. Ohne ID geben
  sie nur Text zurück, damit nie ein Link ins Leere entsteht.
- Optik: Klasse **`.entity-link`** (wie `.member-link`) – erbt die Textfarbe, nur eine gepunktete
  Linie darunter. So zerreißt ein Link mitten im Satz die Zeile nicht.

Der Selbsttest prüft, dass jedes Ziel als Datei existiert, dass jeder Ort eine Rechteprüfung hat,
dass die Pfade im Unterordner stimmen und dass keine neue Klickanleitung im Fließtext auftaucht.

**Faustregel:** Die Überschrift der Seite, auf der man gerade steht, wird nicht verlinkt. Alles
andere, was einen eigenen Ort hat, schon.

### Cron-Jobs
Im **Mittwald-Kundencenter → Cronjobs** anlegen (Key jeweils aus dem Frontend):

Die vollständige Liste mit fertigen Adressen, empfohlenen Zeiten und dem letzten Lauf steht in der
[Verwaltung unter „Cron-Jobs einrichten"](admin/index.php#cron) – sie wird aus `cron_jobs()` in der
`lib.php` gebaut, ist also immer vollständig. Im Überblick:

| Lauf | Takt | Wofür |
|---|---|---|
| `cron_reminders.php` | **täglich** (morgens) | Alle Erinnerungen, Sitzungseinladungen, abgelaufene Umläufe auswerten, Streak-Pflege, Altdaten wegräumen |
| `cron_automations.php` | **alle 15 Min** | Berichte-Dok erzeugen und hochladen; Protokoll-Genehmigungen mit dem Sitzungskalender abgleichen. Ein Leerlauf kostet ~13 ms und **keinen** Netzaufruf – der enge Takt ist Absicht, damit ein ausgefallener Lauf nicht einen ganzen Tag kostet |
| `cron_patmail.php` | **stündlich** | *Nur mit Pat:innenprogramm.* Netz für den Versand: holt, was ein Klick zeitlich nicht geschafft hat, wiederholt Fehlschläge, leitet Fragen aus dem Hilfe-Formular weiter |
| `cron_umfrage.php` | **alle 10–15 Min** | *Nur mit Umfragen.* Liegengebliebene Bestätigungsmails, abgelaufene Einträge wegräumen |
| `cron_extern.php` | **alle 10–15 Min** | *Nur mit öffentlichen Anmeldungen.* Liegengebliebene Mails – **und Löschfristen**: unbestätigte Anmeldungen nach 48 h, Anmeldedaten nach der Frist |
| `cron_waslaeuft.php` | **alle 15 Min** | *Nur mit dem Veranstaltungsportal.* Verschickt die Push-Mitteilungen („Erinnere mich" + Abos) – der enge Takt ist Absicht, eine Erinnerung „2 Stunden vorher" darf nicht erst abends kommen. Einmal täglich räumt derselbe Lauf Vergangenes samt Bildern weg |

Alle gehen alternativ per SSH/CLI (dann ohne `?key=`). Alle sind idempotent: ein häufigerer Takt schadet
nie, ein verpasster Lauf wird nachgeholt. Ob sie laufen, sagt der Cron-Herzschlag auf der Diagnose-Seite
und die Statuszeile je Lauf in der Verwaltung.

### Microsoft 365 anbinden
Einmalige Einrichtung durch eine:n M365-Admin:

1. In **Entra ID → App-Registrierungen** eine neue App anlegen.
2. Unter **API-Berechtigungen** eine Application-Permission für Microsoft Graph erteilen: **`Sites.Selected`** (empfohlen, least privilege – der App danach gezielt Zugriff auf eure Site geben) oder ersatzweise `Sites.ReadWrite.All`. Anschließend **Admin-Consent** erteilen.
3. Unter **Zertifikate & Geheimnisse** ein **Client-Secret** erzeugen und den Wert kopieren – er wird nur einmal angezeigt.
4. In der App unter [Uploads → Zugangsdaten](uploads.php) eintragen: Verzeichnis-ID (Tenant), Anwendungs-ID (Client), Client-Secret samt Ablaufdatum, SharePoint-Host und Site-Pfad.
5. **Verbindung testen** – die App listet die Dokumentbibliotheken auf; die richtige auswählen.
6. Mit einem **Test-Upload** prüfen.

Die Zugangsdaten liegen in der lokalen Datenbank, nicht im Repo. Der Server authentifiziert sich app-only; ein eingeloggter Microsoft-Nutzer ist nicht nötig.

### OLAT anbinden (WebDAV)
1. In OLAT unter **Einstellungen → WebDAV/Passwörter** ein eigenes **WebDAV-Passwort** setzen.
2. In der App unter Uploads → OLAT eintragen: WebDAV-URL, Benutzername, Passwort und optional einen Basisordner.
3. **Verbindung testen** – danach klickst du dich im Ordner-Browser durch die Ebenen; die geöffnete Ebene wird zum Basisordner. Kursordner heißen im WebDAV nach dem **Kurstitel**, nicht nach der Kurs-ID, und erscheinen nur, wenn du Besitzer:in oder Betreuer:in des Kurses bist.

### Nextcloud anbinden
Unter Uploads → Technik → Nextcloud: **Adresse** (nur `https://cloud.…`, den WebDAV-Pfad hängt die App selbst an), **Benutzername** und ein **App-Passwort**.

Bitte kein normales Kennwort – ein App-Passwort (Nextcloud → Einstellungen → Sicherheit) lässt sich einzeln widerrufen und funktioniert auch bei aktiver Zwei-Faktor-Anmeldung. Sinnvoll ist ein eigenes Konto für die App (z. B. `asta-app`), auf dessen Ordner die Mitglieder Zugriff haben. Danach Verbindung testen, Basisordner durchklicken und optional einen Test-Upload machen.

Ob sich Dateien dort im Browser **bearbeiten** lassen, entscheidet die Nextcloud selbst: Ist **Collabora Online** oder **OnlyOffice** installiert, erkennt die App das und beschriftet den Knopf entsprechend.

### Redeliste
Die interne Redeliste ist mit eingebunden (`redeliste.html` + `redeliste-sync.php`, Daten in `redeliste-data/`, legt sich bei Bedarf selbst an; alte Räume räumen sich nach 18 Tagen auf). Jede Sitzung bekommt automatisch einen eigenen Raum-Token und einen Leitungs-Schlüssel; die Links stehen auf der Sitzungs-Detailseite.

Der Sync ist **server-autoritativ**: Jede Aktion ist eine kleine, atomar angewandte Operation mit Versions-Cursor. Dadurch erscheinen Meldungen auch dann, wenn der Leitungs-Tab gerade nicht aktiv ist, nichts geht verloren, und alle Rollen lesen nur tatsächliche Änderungen.

Daneben gibt es eine **öffentliche, von der App unabhängige Redeliste** als einzelne HTML-Datei außerhalb dieses Repos – gleiche Sync-Architektur und gleicher Look, aber ohne App-Anbindung, mit freiem Sitzungstitel und wahlweise einer gemeinsamen quotierten Liste.

### Pat:innenprogramm
Beim Hochladen den Ordner `pat/` (mit eigener `.htaccess`), `pat-db.php`, `admin/paten.php` und `assets/pat.css` mitnehmen. Die eigene Datenbank legt sich beim ersten Aufruf an. Damit Link und Vorschau funktionieren, muss die **Basis-URL** hinterlegt sein. Öffentliche Adresse: `…/planer/pat/?p=<Schlüssel>`.

### Terminplaner (öffentlich)
Beim Hochladen den Ordner `termin/` (mit eigener `.htaccess`), `termin-db.php`, `admin/terminplaner.php` und `assets/termin.css` mitnehmen. Die eigene Datenbank legt sich beim ersten Aufruf an. Ohne hinterlegte **Basis-Adresse** kann der Terminplaner keine Links anzeigen – das ist die einzige Pflichtangabe. Öffentliche Adressen: `…/planer/termin/` (Start), `…/planer/termin/t.php?t=<Schlüssel>` (Teilnahme) und `…/planer/termin/verwalten.php?k=<Schlüssel>` (Verwaltung). Einen Cron braucht er nicht.

### was.läuft (öffentliches Veranstaltungsportal)
**Adressen eingeben:** Überall, wo eine Web-Adresse hingehört (Event-Link, Anmeldung, Veranstalter-Website, Banner, Impressum, Datenschutz, Basis-Adresse, eigenes QR-Ziel), genügt die **kurze Form** – `beispiel.de/was.laeuft` oder `www.verein.de`. Das `https://` setzt die App selbst davor; nur was gar keine Web-Adresse ist, wird abgelehnt. Auf Aushängen und Teilen-Bildern steht die Adresse ebenfalls kurz, ohne `https://www.`.

Beim Hochladen den Ordner `veranstaltungen/`, dazu `wl-db.php`, `push-core.php` (gemeinsamer Web-Push-Kern, braucht auch die App), `veranstaltungen.php`, `cron_waslaeuft.php`, `assets/wl.css`, `assets/wl-push.js`, den Bilder-Ordner `assets/wl-standard/`, den Promo-Ordner `assets/wl-promo/`, die Logo-Ableitungen `assets/logo-420.png` und `assets/logo-verlauf.png` (AStA-Logo im Marken-Verlauf) und die Marken-Dateien `assets/wl-icon.svg`, `wl-icon-32.png`, `wl-icon-180.png`, `wl-og.png` sowie die PWA-Icons `wl-icon-192.png`, `wl-icon-512.png`, `wl-icon-512-maskable.png` mitnehmen (Tab-Icon, Home-Bildschirm-Icon und das Vorschaubild, das Messenger und Suchmaschinen bei geteilten Links zeigen – Beiträge mit eigenem Foto zeigen weiterhin ihr Foto). Die eigene Datenbank legt sich beim ersten Aufruf an. Öffentliche Adresse: `…/planer/veranstaltungen/`.

**Wer erfährt davon?** Sobald eine **Einreichung** wartet, eine **Änderung** an einem veröffentlichten Beitrag zur Freigabe liegt oder ein Beitrag **gemeldet** wurde, bekommen die Mitglieder der Referate, die in den Einstellungen als **zuständig** angetippt sind, eine Nachricht aufs Dashboard und – bei eingeschaltetem Push – aufs Gerät. Bewusst **nur diese**: Zugriff auf die Verwaltung haben Vorsitz und Admin immer, eine Mitteilung bekommt aber nur, wer die Arbeit auch macht. Wer als Vorsitz oder Admin mitbenachrichtigt werden will, tippt sein eigenes Referat einfach mit an. Die Mitteilungen sind **gebündelt** – drei Einreichungen in einer Viertelstunde ergeben eine Nachricht, nicht drei – und jede Person kann sie unter **Erinnerungen & Mitteilungen** („was.läuft: Neues zu tun") abschalten.

**Beitrag melden (Reiter „Meldungen"):** Unter jeder Veranstaltung auf der öffentlichen Seite steht ein leiser Aufklapper **„Stimmt etwas nicht? Beitrag melden"** – ohne Konto, in zehn Sekunden erledigt: Grund auswählen, optional zwei Sätze dazu, optional eine Mailadresse für Rückfragen. Gemeldet wird von falschen Uhrzeiten über abgesagte Termine bis zu Bildrechten und anstößigen Inhalten. **Alles davon landet im Reiter „Meldungen"**, nicht in einem Postfach: mit Zähler am Reiter, Link direkt zum Beitrag und einem **Erledigt-Haken samt Notiz**, was ihr entschieden habt. Erledigtes bleibt mit dieser Notiz stehen – so ist später nachvollziehbar, wie ihr mit einer Beschwerde umgegangen seid. **Bitte zeitnah durchsehen:** Ihr zeigt fremde Inhalte öffentlich, und das ist der einzige Weg, auf dem euch jemand auf ein Problem hinweisen kann.

**Die Seite spricht Englisch, wo sie selbst spricht.** Ruft jemand was.läuft mit einem englischsprachigen Browser auf, erscheinen **Navigation, Filter, Kategorien, die Angaben an den Kacheln** („free", „accessible"), die Knöpfe („Add to calendar", „Remind me", „Share") und – am wichtigsten – **alle Datumsangaben** auf Englisch: aus „Do 7. Aug" wird „Thu 7 Aug". Unten in der Fußzeile steht ein **Umschalter DE | English**; die Wahl gilt für den Besuch und schlägt die Browsersprache. Das ist nötig, weil viele Studis ihr Handy auf Englisch stehen haben.

**Was bewusst deutsch bleibt:** alles, was ihr selbst schreibt (die Texte im Texte-Reiter), die Beiträge der Gruppen, der Bereich zum Eintragen und die Verwaltung. Eine maschinelle Übersetzung eurer Texte wäre schlechter als gar keine – wer etwas auf Englisch anbieten will, schreibt es selbst. Auf der englischen Fassung steht dazu ein ehrlicher Hinweis in der Fußzeile: die Beiträge stammen von den Gruppen und sind meist deutsch.

**Englische Kurzfassung (Einstellungen → Sprache, ab Werk aus):** Ist der Schalter an, können Gruppen beim Eintragen freiwillig einen **englischen Titel und eine englische Zeile** ergänzen. Wer die Seite auf Englisch aufruft, sieht diese zuerst (die deutsche Überschrift rutscht klein darunter); alle anderen sehen die englische Zeile als zusätzlichen Absatz beim Text. Der Rest der Seite bleibt auf Deutsch – bewusst: halbe Zweisprachigkeit ist schlimmer als eine klare Sprache mit einem verständlichen Einstieg. Ist der Schalter aus, tauchen die Felder gar nicht erst auf.

**Besuche (Reiter „Besuche"):** Die Verwaltung zeigt, wie viele Menschen die Seite besucht haben – als Zahlen für heute, den gewählten Zeitraum (7 Tage bis 1 Jahr), im Schnitt pro Tag und seit Beginn, dazu ein Balkendiagramm je Tag. **Datensparsam gebaut:** Aus Adresse und Browser-Kennung entsteht zusammen mit einem **Zufallswert, der täglich ersetzt wird**, eine Prüfzahl; danach ist sie zu nichts mehr zurückzurechnen. Gespeichert bleiben am Ende nur **zwei Zahlen je Tag**. Kein Cookie, keine gespeicherte IP, keine Wiedererkennung über den Tag hinaus, kein Verlauf, kein fremder Dienst – Bots zählen nicht mit. Wer an mehreren Tagen kommt, zählt je Tag einmal; die Summe heißt deshalb bewusst **Besuche**, nicht Personen.

**Die Seite ist eine PWA:** Wer sie am Handy „Zum Home-Bildschirm" legt (Android bietet *Installieren* von selbst an), bekommt sie als eigene App mit dem „w."-Icon, im Vollbild und mit Schnellzugriffen auf *Kurse* und *Eintragen* (Icon lange drücken). Dahinter stehen `veranstaltungen/manifest.json`, der Service Worker `veranstaltungen/sw.js` und die Rückfall-Seite `offline.html` – alle drei liegen im Ordner und kommen beim Hochladen automatisch mit. Seiten laden **immer erst frisch aus dem Netz** (Termine dürfen nicht alt sein); ohne Verbindung zeigt die App die zuletzt gesehene Fassung oder die Offline-Seite. Die Kern-Bilder der Oberfläche (AStA-Logo, was.läuft-Logo, App-Icon) legt der Worker gleich bei der Installation in einen festen Vorrat — so fehlen sie auch bei wackligem Netz nicht. Wichtig bei Änderungen an `sw.js` oder `offline.html`: die `VERSION` in `sw.js` hochzählen, sonst hält der Browser am alten Stand fest.

Die App hat ihre **eigene Erklär-Seite** (`veranstaltungen/app.php`, verlinkt in der Fußzeile und über die schlanke Hinweis-Zeile der Startseite): Anleitung fürs Installieren auf iPhone und Android, ein Direkt-Installieren-Knopf, wo der Browser ihn anbietet, und ein FAQ (Kosten, warum kein Store, Mitteilungen, Deinstallieren). Auf Android & Co. zeigt schon die **Hinweis-Zeile selbst einen „App installieren"-Knopf**, der den Installieren-Dialog des Systems direkt öffnet — ein Tipp, fertig; das iPhone behält dort „So geht's", weil Apple keinen Direktweg anbietet. Auch die **Fehlermeldungen der Mitteilungs-Knöpfe** verweisen dorthin: Sie erscheinen als eigener Dialog mitten im Blick (nicht mehr als Kasten am Seitenende) mit einem Knopf „So geht's: die App" — etwa wenn ein Desktop-Browser kein Push kann oder die Erlaubnis blockiert ist.

**Das ist der einzige Bereich, der bewusst anders aussieht** – eigenes Stylesheet, dunkler Kopf, Bild zuerst. Wer hier etwas gestaltet, tut das in `assets/wl.css`; die `style.css` der App wird nicht mitbenutzt.

**Wie es läuft:** Ihr legt in der Verwaltung unter **was.läuft** jede Gruppe als *Veranstalter* an – die **Mailadresse ist der Anmeldename** (Pflicht, doppelt geht nicht). Beim Anlegen geht automatisch eine **Zugangs-Mail** raus; über den Link darin legt die Gruppe ihr **Passwort selbst** fest. Passwort weg? Die Gruppe hilft sich über *Passwort vergessen?* auf der Einreich-Seite selbst, oder ihr drückt *Zugang-Mail* in der Liste. Diese Mails laufen über den gemeinsamen Mail-Topf (Bereich „was.läuft", eigener Tagesdeckel) – ohne hinterlegte **Basis-Adresse** kann keine Zugangs-Mail gebaut werden. Was eingereicht wird, landet im Freigabe-Stapel; erst euer Klick stellt es öffentlich. Gruppen, denen ihr das zutraut (etwa euch selbst), könnt ihr auf *ohne Freigabe veröffentlichen* stellen.

Die Verwaltungsseite ist in **Reiter** geteilt – *Beiträge* (Freigabe + Öffentlich), *Veranstalter*, *Bilder & Banner*, *Texte*, *Promo*, *Meldungen*, *Besuche*, *Einstellungen*. Die Zahlen-Kacheln oben bleiben auf jedem Reiter stehen und führen per Klick zum passenden Bereich; kleine Zähler an den Reitern zeigen wartende Freigaben, wartende Änderungen und offene Zugangsanfragen.

**Die Gruppen bekommen automatisch Bescheid.** Bei jeder eurer Entscheidungen geht eine Mail an die Gruppen-Adresse: Freigabe (mit Link zum Beitrag und zum fertigen Werbematerial), Ablehnung (mit eurer Begründung und dem Hinweis auf *Überarbeiten & neu einreichen*), übernommene und verworfene Änderungen. Die Meldung in der Verwaltung sagt ehrlich dazu, ob die Mail rausging — ohne hinterlegte Gruppen-Mail oder bei erschöpftem Mail-Kontingent unterbleibt sie. Gesteuert wird das wie bei den anderen Bereichen unter **Einstellungen → Mails an die Veranstalter**: **Absender-Adresse** und **Absender-Name** (anders als sonst darf hier die **was.läuft-Adresse selbst der Absender** sein — leer gelassen gilt das gemeinsame Postfach; Antworten gehen an die Kontakt-Adresse), ein Schalter für die Bescheid-Mails (Zugangs- und Passwort-Mails gehen immer — ohne die käme keine Gruppe mehr rein), der **Tagesdeckel** am gemeinsamen Mail-Topf (Vorgabe 300) samt Rest-Anzeige für heute, und *Testmail an mich* prüft den Versand — bewusst auch bei abgeschaltetem Schalter. **Abgelehntes verschwindet nicht mehr wortlos:** Es steht im Bereich der Gruppe mit eurer Begründung, und *Überarbeiten & neu einreichen* öffnet das vorbefüllte Formular — mit dem Abschicken landet der Beitrag wieder frisch in eurem Freigabe-Stapel.

**Beiträge sind nicht in Stein gemeißelt.** Die Gruppen können jeden eigenen Beitrag **bearbeiten**: Wartendes wird direkt überschrieben, bei einem **Live-Beitrag bleibt die bisherige Fassung online** und die Änderung landet als **„Änderungen an Live-Beiträgen"** im Beiträge-Reiter – dort steht nur, was sich unterscheidet (alt → neu), und *Übernehmen* stellt die neue Fassung scharf (gesetzte Erinnerungen wandern bei Terminänderungen automatisch mit), *Verwerfen* lässt alles beim Alten. Gruppen mit *ohne Freigabe veröffentlichen* speichern auch live direkt. So zwingt ein Tippfehler niemanden mehr zu „löschen und neu einreichen" – das hätte alle Erinnerungen und geteilten Links gekostet.

**Absagen statt löschen:** Fällt eine Veranstaltung aus, markiert die Gruppe sie in ihrem Bereich als **abgesagt** (geht auch aus eurer Beiträge-Liste). Der Beitrag bleibt mit roter Banderole sichtbar, Anmelde-, Kalender- und Erinnerungs-Knöpfe verschwinden, und **alle, die sich erinnern lassen wollten, bekommen eine Absage-Mitteilung** aufs Gerät – statt vor verschlossener Tür zu stehen. Eine Absage lässt sich jederzeit zurücknehmen. Für Termine, die nur ausfallen statt zu verschwinden, ist das der ehrliche Weg; Löschen bleibt für Versehen.

**Betriebszustand** (Einstellungen): drei Karten – **Läuft** (Seite offen, Gruppen können eintragen), **Aufnahmestopp** (Seite bleibt sichtbar, aber niemand kann Neues einreichen) **Geschlossen** (für alle zu) und **Vorabstart**. Geschlossen heißt dabei nicht Fehlerseite: Wer dann einen geteilten Link anklickt, landet auf einer eigenen Seite im Look des Portals – Marke, ein Satz, dass es wiederkommt, der Weg zu eurer Kontaktadresse – und unten **Impressum und Datenschutz**, denn die Seite bleibt öffentlich erreichbar. Verlinkt wird nur, was in den Einstellungen eingetragen ist.

Der **Vorabstart** ist die Phase vor dem Launch: Draußen steht eine Countdown-Seite – die Tage bis zum Start riesig in den Markenfarben, darunter das ausgeschriebene Datum. Den **Starttermin** tragt ihr direkt unter den Karten ein, **Uhrzeit optional**; ohne Datum bleibt es beim Satz allein. Mit Uhrzeit zählt die Seite am letzten Tag Stunden und zuletzt Minuten herunter, statt einen ganzen Tag lang „heute" zu sagen. Ganz unten steht klein der Absender: das AStA-Logo mit „Ein Angebot des AStA RPTU in Landau". **Die Sonderregel:** Einreichen und Veranstalter-Login bleiben in dieser Phase offen – die Countdown-Seite verlinkt sie mit „Ihr seid Veranstalter? Zum Login" – die Gruppen melden sich an und tragen ihre ersten Veranstaltungen ein, damit am Starttag etwas dasteht. Ist der Termin erreicht, erinnert euch die Verwaltungsseite ans Umschalten (automatisch passiert das nicht).

**Angemeldete Gruppen sehen das ganze Portal** – auch wenn es geschlossen ist oder noch nicht gestartet hat. Wer sich einmal eingeloggt hat, kommt an Startseite, Kurse, Veranstalter-Seiten und die eigenen Beiträge heran und kann prüfen, wie am Starttag alles aussieht. Ein gelbes Band unter der Kopfleiste sagt dabei die ganze Zeit „Nur für euch sichtbar" und nennt im Vorabstart den Starttermin – damit niemand den internen Stand für den öffentlichen hält und Links herumschickt, die für alle anderen auf der Countdown-Seite enden.

**Demo-Modus** (Einstellungen): Solange er an ist, steht auf der Startseite und über jeder Veranstaltung, dass die Seite noch im Aufbau und noch nicht öffentlich ist. Er ändert sonst nichts – alles bleibt bedienbar, Einträge, Anmeldungen und Mails laufen normal weiter. Er ist deshalb unabhängig vom **Betriebszustand** und lässt sich mit ihm kombinieren. Den **Wortlaut** könnt ihr direkt darunter ändern; leer heißt Standardsatz.

Unter **Texte → Rechtliches** stehen **Impressum und Datenschutzerklärung in einer eigenen, kurzen Fassung für was.läuft** – die Seite tut weniger als die Hauptseite des AStA, also darf hier auch weniger stehen. Beide sind dort im Klartext bearbeitbar: Ein durch eine Leerzeile getrennter Block wird zu einem Abschnitt, seine erste Zeile zur Überschrift; eine Zeile, die nur aus einer Adresse besteht, wird zum Link. Leer lassen heißt „keine eigene Seite" – dann führen alle Verweise wieder auf die Adressen aus den Einstellungen. Die beiden Seiten sind **immer erreichbar**, auch bei Vorabstart und Geschlossen.

Unter **Texte** lassen sich die Sätze der öffentlichen Seiten direkt umschreiben – Startseite, Kurse-Seite, die Eintragen-Seite, die Über-Seite und die Fußzeile (gleiches Prinzip wie beim Pat:innenprogramm). Ein **leeres Feld blendet das Element aus**, bei mehrzeiligen Feldern ist eine Leerzeile ein neuer Absatz, und *Auf Standard zurücksetzen* holt die mitgelieferten Formulierungen zurück. Die Marken-Sätze der großen Überschriften („was.läuft … in Landau?") sind bewusst fest – sie hängen an der Wort-Animation.

Die **Über-Seite** (`…/veranstaltungen/ueber.php`, verlinkt in der Fußzeile und – nur am Desktop – in der Kopfleiste) ist die Visitenkarte: die Marke riesig auf dunkler Bühne, darüber die Zeile *„Ein Angebot deines AStA"*, darunter der Abschnitt *Von Studis, für Studis* und die fünf Netzwerk-Kacheln – Kultur-Einrichtungen, Stadt Landau, Hochschulgruppen, Studierendenwerk, Fachschaften. Die frei formulierten Sätze dazwischen stehen im Texte-Reiter (Gruppe *Über-Seite*); Kacheln und Aufbau sind fest. Eine Zahlen-Leiste (kommende Veranstaltungen, Kurse, eintragende Gruppen) erscheint **von selbst**, sobald genug eingetragen ist, dass die Zahlen für die Seite sprechen – vorher bleibt sie weg.

Jeder Live-Beitrag hat eine **Teilen-Seite** („Weitersagen", `teilen.php`): fertige **Instagram-Bilder** (Story und Post mit Titel, Datum, Ort, Foto und QR-Code des Beitrags, im Browser gerendert und als PNG herunterladbar), der **Link**, ein **QR-Code** und ein vorformulierter **Begleittext** zum Kopieren – auf dem Handy zusätzlich der System-Teilen-Knopf. Veranstalter kommen direkt nach dem Eintragen hin, über *Eure Beiträge* auf der Einreich-Seite und über *Teilen* auf jeder Beitragsseite; ihr selbst über *Teilen* in der Beiträge-Liste. Der Sinn: **kein Doppelaufwand** – wer sein Event einträgt, hat das Werbematerial gleich mit.

Unter **Aussehen & Ziel** stellt die Teilen-Seite das Material um: **„Eure Marke zuerst"** setzt das hinterlegte **Gruppen-Logo** (bzw. den Gruppennamen, solange keines hochgeladen ist – das Logo pflegt die Gruppe im Promo-Bereich ihres Logins) groß nach oben, was.läuft rückt klein an den Fuß. Und der **QR-Code kann statt auf den Beitrag auf eine eigene Adresse zeigen** (etwa die Anmeldeseite der Gruppe) – das passiert komplett im Browser, der Server speichert dazu nichts. Dazu gibt es den Block **Für eure Webseite**: ein quer liegendes **Web-Banner (1200 × 630)** zum Herunterladen plus einen fertigen **HTML-Schnipsel** zum Einbetten – die Gruppe lädt das Bild auf ihre eigene Seite hoch und trägt im Schnipsel nur den Bildpfad ein. So wandert ein was.läuft-Eintrag mit zwei Handgriffen auch auf die Vereins- oder Fachschafts-Homepage.

Unter **Promo** liegt auch das **Banner für eure Website**: ein fettes Band mit Marke, einem Satz und einem Knopf, das Besuchende über das × wegklicken können – danach bleibt es auf ihrem Gerät drei Wochen verschwunden. Der fertige Code steht dort zum Kopieren; in WordPress fügt ihr auf der Startseite ein Elementor-Widget **„HTML"** ein und setzt ihn hinein. Es lädt nichts von außen – kein Plugin, keine Schrift, kein fremdes Skript.

**Der Code muss nur einmal eingesetzt werden.** Danach steuert ihr alles von hier: der **Schalter** im Promo-Reiter schaltet das Banner auf der Website an und aus, **Überschrift, Satz und Knopfbeschriftung** stehen unter *Texte → Banner für fremde Websites*. Änderungen sind nach spätestens fünf Minuten draußen (so lange merkt sich die Website die Antwort). Ist der Schalter aus oder die Überschrift leer, erscheint gar nichts – und wenn der Planer einmal nicht erreichbar ist, bleibt die Website einfach ohne Banner, statt ein veraltetes zu zeigen.

Unter **Promo** liegt das Werbezeug. Herzstück ist der **Vorlagen-Generator**: Spruch aus der Liste wählen („Keine Ahnung, was du am Wochenende machen sollst?" & Co.) oder **selbst schreiben**, Format aussuchen (Insta-Story/-Post, Flyer A6, Plakat A4/A3, Aufsteller A1 – große Formate tragen automatisch mehr Infos samt AStA-Logo) und als druckfertiges PNG oder als SVG für die Druckerei herunterladen. Über **Alle Texte bearbeiten** lässt sich jede Zeile der Vorlage umschreiben oder leeren (leer = Zeile weglassen). Daneben: Markenfarben, Logo-Downloads, ein live erzeugter **QR-Code**, die **Logo-Verwandlungs-Schleife** (AStA → was.läuft, 7 s, zum Mitschneiden), zwei **Pressetexte** zum Kopieren und die Schritt-für-Schritt-Karte für die **Kurz-Adresse** (z. B. `beispiel.de/was.laeuft`) (eine Zeile in der `.htaccess` der Hauptseite). *Pressekit herunterladen* bündelt alles als ZIP.

Der öffentliche **„+ Eintragen"-Knopf** zeigt die Anmeldung und nimmt **Zugangsanfragen** entgegen: Gruppen, die dabei sein wollen, tragen Name und Mailadresse ein. Die Anfragen erscheinen bei den Veranstaltern; *Übernehmen* füllt das Anlege-Formular vor, beim Anlegen wird die Anfrage abgeräumt und die Zugangs-Mail geht direkt an die Gruppe.

Die Kategorie wählen die Gruppen im Formular über **Pillen** (eine bestehende ist Pflicht); über **„+ Kategorie vorschlagen"** können sie in einem eigenen Fenster eine neue beschreiben. Diese **Kategorie-Vorschläge** erscheinen als Stapel im Veranstalter-Reiter — bewusst ohne Automatik: Eine neue Kategorie braucht einen Eintrag in `wl_cats()` (wl-db.php) samt Standard-Fotos und bleibt eure Entscheidung; *Entfernen* räumt den Vorschlag ab.

**„Wer darf kommen?" ist eine Pflichtfrage.** Beim Anlegen entscheiden die Gruppen zwischen **Offen für alle** und **Nur für Studierende** — zwei gleichrangige Kacheln, nichts ist vorbelegt, und ohne Antwort geht das Formular nicht durch (serverseitig geprüft, nicht nur im Browser). Steht die Einschränkung, zeigt die Beitragsseite sie als eigene Zeile mit Hinweis „Ohne Studiausweis kein Einlass", und in der Freigabe trägt der wartende Beitrag ein entsprechendes Etikett. Beiträge aus der Zeit vor dieser Frage stehen auf „offen für alle" — das war ja auch der Stand. *Ein Filter auf der Startseite ist das (noch) nicht.*

Beim Anlegen können die Gruppen zu einem kostenpflichtigen Beitrag einen **Studi-Rabatt** eintragen (Freitext, z. B. „3 € mit Studiausweis") — das Feld erscheint, sobald ein Preis drinsteht, und die Beitragsseite zeigt den Rabatt als eigene Zeile „Für Studis" direkt unterm Preis. Beim Preis ergänzt die App bei einer **nackten Zahl automatisch das „€"** („5" wird zu „5 €"); Freitext wie „Spende" bleibt unangetastet.

In der Verwaltung lassen sich Veranstalter neben dem **Stilllegen** (vorübergehend raus, alles bleibt erhalten) auch **endgültig löschen**: Dabei gehen alle Beiträge der Gruppe (samt Erinnerungen und Bilddateien), ihre eigenen Bilder, das Logo und die Abos auf die Gruppe mit — nach einer deutlichen Rückfrage, denn das lässt sich nicht rückgängig machen.

Nach dem Anmelden landet die Gruppe in **ihrem Bereich** (Dashboard): oben die Lage in vier Zahlen (Beiträge online, wartende Freigaben, Abos auf die Gruppe, **Aufrufe der eigenen Beiträge**), darunter die Wege — **+ Veranstaltung oder Kurs anlegen** (erst dieser Klick öffnet das Formular), die eigene **Veranstalter-Seite** (dort sitzt der **Bearbeiten-Knopf** fürs Profil — Seite und Profil sind eine Sache) und **Promo** — und die Liste **Eure Beiträge** mit direktem Absprung zu Teilen/Ansehen. Je Beitrag gibt es dort außerdem **Bearbeiten**, **Absagen** (siehe oben) und einen **Löschen**-Knopf (mit Rückfrage; nur die eigenen Beiträge, offene Erinnerungen darauf werden mit abgeräumt). Live-Beiträge zeigen in der Liste ihre **Aufrufe** und wie viele **Erinnerungen** Studis darauf gesetzt haben — der Rückkanal, der zeigt, dass sich das Eintragen lohnt. Gezählt wird datenschutzfreundlich: reines Hochzählen je Beitrag, einmal pro Besuch, ohne Personenbezug und ohne zusätzliches Cookie; Suchmaschinen-Crawler zählen nicht mit. Hier ist auch der Platz, an dem künftige Veranstalter-Funktionen andocken.

Unter **Promo** pflegt die Gruppe ihr **Logo** (erscheint als Absender-Marke auf der Veranstalter-Seite und den Event-Detailseiten; ohne Logo bleibt der farbige Kurz-Punkt) und ihre **eigenen Bilder** — höchstens **30 pro Gruppe**, einmal hochgeladen stehen sie beim Anlegen jeder Veranstaltung zur Auswahl. Ein Bild, das gerade an einem Beitrag hängt, lässt sich nicht löschen; das Logo taucht im Bild-Wähler bewusst nicht auf.

**Zwei Sorten Inhalt:** *Veranstaltungen* haben ein Datum und sind danach vorbei. *Kurse* haben einen Rhythmus („mittwochs 18:00") und liegen in einem eigenen Bereich, damit sie die Wochenübersicht nicht zustellen.

**Bilder – der Punkt, an dem es rechtlich ernst wird.** Sobald ihr ein eingereichtes Bild öffentlich stellt, seid **ihr** der Verbreiter, nicht die Gruppe. Wer ein eigenes Bild hochlädt, muss deshalb bestätigen, die Rechte daran zu haben; fehlt das Häkchen, geht der Upload gar nicht erst durch. Im Freigabe-Stapel steht bei jedem Beitrag mit Bild, ob die Bestätigung vorliegt. Damit niemand in Versuchung gerät, etwas aus dem Netz zu ziehen, füllt ihr das **Bildarchiv**: Diese Bilder stehen allen Einreichenden zur Auswahl. Nehmt dort nur auf, was wirklich geklärt ist – freie Bilddatenbanken, eigene Fotos, schriftliche Erlaubnis. Beiträge **ohne** Bild bekommen ein **Standard-Foto passend zur Kategorie** – die liefern wir mit (je **acht** pro Kategorie, alle frei lizenziert; die Detailseite nennt die Fotograf:innen von selbst). Die Gruppen können im Formular eines davon **gezielt auswählen**; wer nichts wählt, bekommt automatisch eines zugeteilt. Da muss niemand etwas tun. Wer die Auswahl erweitern will: Die Liste samt Herkunft steht in `wl_standard_bilder()` (wl-db.php) – neue Einträge brauchen **immer** Bildnachweis und Fundort.

Hochgeladene Bilder werden verkleinert (1600 px für die Detailseite, 640 px für die Kacheln) und dabei von versteckten Zusatzdaten befreit – **auch vom Aufnahmeort**, den Handykameras mitspeichern. Ob das auf dem Server möglich ist, hängt an PHP: nötig ist die Erweiterung `imagick` oder `GD`. Dass beim Hoster ImageMagick als Programm installiert ist, genügt allein **nicht**. Was tatsächlich greift, steht in der Verwaltung beim Bildarchiv und in der Diagnose.

**Empfohlen** hebt einen Beitrag in der Terminliste mit einem Stern-Chip auf dem Bild hervor – die Reihenfolge bleibt chronologisch. Empfohlene Kurse ziehen im Kurs-Streifen nach vorn. **Banner** sind großflächige Ankündigungen für Kampagnen wie die O-Woche. Das Bild dazu wählt ihr im Formular über **Vorschau-Kacheln**: fünf **mitgelieferte Panorama-Motive** (Konfetti, Lichtermeer, Bühne, Feuerwerk, Campus — alle CC0, Liste in `wl_banner_bilder()` in wl-db.php) plus alles aus eurem Bildarchiv; „Kein Bild" zeigt die Marken-Lichter. Beides sparsam einsetzen: Sind alle empfohlen, ist es keiner.

Ein **Kalender-Abo gibt es bewusst nicht** – pro Veranstaltung nur „Zum Kalender hinzufügen". Der Cron (`cron_waslaeuft.php`, **alle 15 Minuten** – seit dem Push-Paket, siehe Cron-Anleitung in der Verwaltung) verschickt bei jedem Lauf die Push-Mitteilungen und löscht einmal täglich Vergangenes nach der eingestellten Frist samt Bildern; Archivbilder bleiben immer.

**Suchmaschinen finden die Seite von selbst.** Jede Beitragsseite trägt unsichtbare **strukturierte Daten** (schema.org/Event – Titel, Termin, Ort, Preis, Veranstalter, bei Absagen „EventCancelled"), mit denen Google Veranstaltungen direkt in der Event-Suche anzeigt – „was geht heute in Landau" ist genau die Suche, die das Portal gewinnen soll. Dazu gibt es eine **Sitemap** unter `…/veranstaltungen/sitemap.php` (entsteht bei jedem Aufruf frisch aus der Datenbank, braucht die hinterlegte Basis-Adresse). Einmalig lohnt sich: die Sitemap in der [Google Search Console](https://search.google.com/search-console) einreichen oder als Zeile `Sitemap: https://…/veranstaltungen/sitemap.php` in die `robots.txt` des Webspace aufnehmen – danach pflegt sich das von selbst.

**Erinnerungen & Abos (Push, ohne Konto):** Auf jeder Veranstaltungsseite gibt es **„🔔 Erinnere mich"** – 2 Stunden vor Beginn (ohne Uhrzeit: am Tag um 9) kommt eine Mitteilung aufs Gerät; wird der Termin verschoben, wandert die Erinnerung mit. Dazu lassen sich **Veranstalter abonnieren** (auf ihrer Seite, an jedem Beitrag und in der Übersicht) sowie **Kategorien** oder Kombinationen („nur Partys der Fachschaft X") unter *Erinnerungen & Abos* (Fußzeile) – dort wird auch alles wieder abbestellt. Gespeichert wird nur die Push-Adresse des Browsers, kein Name, keine Mail. Auf dem **iPhone** geht Push erst in der installierten App („Zum Home-Bildschirm") – die Seite erklärt das an Ort und Stelle, und die **App-Karte** auf der Startseite wirbt genau damit. Technik: eigene VAPID-Schlüssel und eigene Abo-Tabellen in der wl-Datenbank; die Krypto teilt sich die App mit dem Portal über den gemeinsamen Kern `push-core.php`.

**Veranstalter-Seiten:** Jede aktive Gruppe hat unter `veranstalter.php` eine öffentliche **Visitenkarte** – Kurzprofil, Website/Instagram und ihre kommenden Termine samt Abo-Knopf. Das Profil pflegt die Gruppe selbst im Einreich-Bereich (*Euer öffentliches Profil*); leere Felder verschwinden einfach. Die Übersicht aller Veranstalter ist in der Fußzeile verlinkt, jeder Beitrag verlinkt seine Gruppe.

### Datensicherung
Die gesamte Datenbank steckt in **einer Datei**: `data/asta.sqlite`. **Sicherung = diese Datei kopieren.** Der Ordner `data/` ist gegen Zugriff von außen gesperrt.

Die öffentlichen Bereiche haben eigene Dateien: `data/pat.sqlite`, `data/umfrage.sqlite`, `data/extern.sqlite`, `data/termin.sqlite`, `data/wl.sqlite` und `data/mailpool.sqlite`. Die Bilder des Veranstaltungsportals liegen als Dateien in `data/wl-bilder/` – für eine vollständige Sicherung gehört dieser Ordner dazu.

Die öffentlichen Bereiche liegen absichtlich getrennt: `data/pat.sqlite`, `data/umfrage.sqlite`, `data/extern.sqlite` und `data/termin.sqlite`. Weil dort Daten von Menschen landen, die keine Mitglieder sind, gehören sie **mit in die Sicherung** – ebenso die Begleitdateien `-wal` und `-shm`, falls vorhanden. Am einfachsten sicherst du deshalb **den ganzen Ordner `data/`**, nicht einzelne Dateien.

Der tägliche Cron löscht **Hintergrund-Rauschen nach 90 Tagen**: alte Dashboard-Nachrichten, Erinnerungs-Protokolle und abgelaufene Login-Tokens. Inhaltliche Daten bleiben unangetastet.

### Sicherheit
Login per einmaligem, zeitlich begrenztem Magic-Link. Technik-Passwort gehasht gespeichert und halbjährlich vom Vorsitz zu bestätigen. Alle Formulare CSRF-geschützt. Jeder persönliche Kalender-Link hat einen geheimen Token. Push läuft ohne Fremdpakete über VAPID und RFC-8291-Verschlüsselung; Push-Fehler brechen nie den auslösenden Vorgang ab. Hochgeladene HTML- und SVG-Dateien werden nie inline ausgeliefert.
