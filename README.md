# Angebotsviewer („Heftis Angebote")

Ein schlankes Tool zum Hochladen passwortgeschützter HTML-Angebote mit
vollständigem Aufruf-Tracking (Anzahl, Zeitpunkt, Verweildauer, Gerät).
Läuft auf jedem **PHP + MySQL** Webspace (z.B. All-Inkl) — kein Node.js, kein Docker.

---

## Funktionen

- HTML-Datei **oder ZIP** hochladen, Passwort vergeben → fertiger trackbarer Link
- **Freie Bezeichnung pro Eintrag:** Angebot, Webseite, Vorschau, Präsentation … oder
  ein eigener Begriff. Der Kunde sieht genau diesen Begriff („Vorschau ansehen",
  „Diese Webseite ist geschützt"); auch als Platzhalter `{typ}` in der Nachricht
- **Mehrseitige Angebote / Mini-Webseiten:** ZIP mit mehreren HTML-Seiten, Bildern,
  CSS, JS und Schriften hochladen — Unterseiten bleiben untereinander verlinkt
  und anklickbar, alles hinter derselben Passwort-Abfrage. Auch exportierte
  Webseiten mit absoluten Pfaden (`/css/style.css`, `/kontakt/`) funktionieren:
  solche Pfade werden bei der Auslieferung automatisch auf das Angebot umgebogen,
  Ordner-Links landen auf der jeweiligen `index.html`
- **Passwort optional:** mit Passwort muss der Kunde es zuerst eingeben, ohne
  Passwort öffnet der Link das Angebot direkt – getrackt wird in beiden Fällen
- **Eigene Überschrift** auf der Passwort-Seite pro Angebot (z.B. Firmenname
  oder „Gästebuch Hotel Sonne"); leer = Standard-Name des Tools
- **Archivieren:** archivierte Angebote zeigen dem Kunden „Leider wurde dieses
  Angebot archiviert. Bitte melde dich direkt bei uns." und lassen sich
  jederzeit wiederherstellen
- **Filter in der Übersicht** nach Status (aktiv, pausiert, abgelaufen,
  archiviert) und nach Bezeichnung – auch eigene Bezeichnungen erscheinen dort
- Kunde öffnet den Link, gibt (falls gesetzt) das Passwort ein, sieht das Angebot
- Statistik pro Angebot:
  - **Anzahl der Aufrufe** und **Unique Besucher**
  - **Genauer Zeitpunkt** jedes Aufrufs
  - **Verweildauer** (wie lange das Angebot offen war) – live per Heartbeat gemessen
  - **Gerätetyp** (Desktop / Mobil / Tablet)
  - Balkendiagramm der letzten 14 Tage
- DSGVO-freundlich: **keine Klartext-IP** gespeichert, nur ein anonymer Hash
- **Angebot bearbeiten:** Titel & Passwort ändern, Startseite eines mehrseitigen
  Angebots wählen — Link und bisherige Statistik bleiben dabei erhalten
- **Versionsverlauf:** Jeder neu hochgeladene Inhalt (HTML ⇄ ZIP jederzeit
  wechselbar) wird eine neue Version. Du bestimmst, welche Version der Kunde
  gerade sieht (Standard: die neueste), kannst jederzeit auf eine ältere
  zurückschalten und siehst in der Statistik die Aufrufe pro Version. Der Kunde
  sieht auf der Passwort-Seite „Version N · Stand Datum"
- **Sicheres Update-System:** Updates direkt aus GitHub-Releases, per
  ZIP-Upload im Admin oder per FTP einspielen – ohne bestehende
  Angebote/Statistiken zu verlieren (DB-Migrationen sind additiv)
- **E-Mail-Benachrichtigung** bei Aufruf (mit Spam-Schutz: max. 1 Mail pro
  Besucher/Angebot je 6 h)
- **Ehrliche Zahlen:** Link-Vorschauen (WhatsApp, iMessage, Outlook, Slack …)
  und Bots zählen nicht als Aufruf und lösen keine E-Mail aus. Bei Angeboten
  ohne Passwort wird erst gezählt, wenn die Seite wirklich im Browser läuft.
  Eigene Aufrufe als eingeloggter Admin werden nie gezählt
- **Zeit pro Seite** bei mehrseitigen Angeboten: welche Unterseiten wie lange
  angesehen wurden, gesamt und pro Aufruf
- **Erinnerungen per E-Mail**, wenn ein Angebot nach X Tagen noch nicht geöffnet
  wurde oder bald abläuft (optional mit Cronjob für pünktliche Prüfung)
- **Vorschau jeder Version** im Admin, ohne dass sich für den Kunden etwas ändert
- **Brute-Force-Schutz** bei Passworteingabe (Sperre nach 5 Fehlversuchen für 10 Min.)
- **Ablaufdatum** pro Angebot + **Pausieren/Reaktivieren** ohne Löschen
- **CSV-Export** der Statistik (Excel-freundlich, DSGVO-konform anonymisiert)
- **HTTPS-Erzwingung** (automatische Weiterleitung http→https)
- **Copy-Paste-Nachricht** mit Link + Passwort, Vorlage mit Platzhaltern in den
  Einstellungen anpassbar ({titel}, {link}, {passwort}, {gueltig_bis})
- Passwörter zusätzlich **verschlüsselt gespeichert** (AES-256-GCM) für die
  Copy-Paste-Anzeige; Sessions mit HttpOnly/Secure/SameSite; Sicherheits-Header

---

## Tool aktualisieren (ohne Datenverlust)

### Weg 1: direkt aus GitHub (empfohlen)

Im Admin **„Update"** öffnen und auf **„Nach Updates suchen"** klicken. Gibt es
ein neueres Release im Repository (`UPDATE_GITHUB_REPO` in
`includes/version.php`), erscheint ein Button, der das Paket herunterlädt und
einspielt. Danach – falls angezeigt – „Datenbank jetzt aktualisieren" klicken.
Voraussetzung: der Webspace darf per PHP ins Internet (curl oder
`allow_url_fopen`), was bei All-Inkl der Fall ist.

### Weg 2: ZIP-Upload im Admin (standardmäßig aus)

Ein Update-ZIP enthält Programmcode. Damit niemand mit Admin-Zugang darüber
eigenen Code auf den Server bringen kann, ist dieser Weg ab Version 1.9.1
**ausgeschaltet**. Nur wenn nötig in `config.php` ergänzen:

```php
define('ALLOW_ZIP_UPDATE', true);
```

Dann das Paket `angebotsviewer-vX.Y.Z.zip` von der GitHub-Release-Seite im Admin
unter „Update" hochladen. Akzeptiert werden nur ZIPs mit passendem Namen und
internem Marker. Das Update über GitHub (Weg 1) prüft zusätzlich die
SHA-256-Prüfsumme, die GitHub für jedes Paket mitliefert.

### Weg 3: per FTP

1. Neue Version (ZIP) lokal entpacken.
2. Per FTP **alle Dateien überschreiben — außer** dem Ordner `uploads/` und der
   Datei `config.php`. (Darin liegen deine Angebote & Zugangsdaten.)
   `install.php` brauchst du bei einem Update nicht hochzuladen. Landet sie doch
   auf dem Server, ist sie gesperrt; die Update-Seite erinnert dich ans Löschen.
3. Im Admin **„Update"** öffnen (`admin/update.php`). Falls eine
   Datenbank-Anpassung nötig ist, erscheint ein Button „Datenbank jetzt
   aktualisieren" — ein Klick, und fertig. Migrationen sind **additiv**:
   bestehende Angebote/Statistiken werden nie verändert oder gelöscht.

Die Update-Seite zeigt außerdem die installierte Version, einen Sicherheits-Check
(Schreibrechte, ob `install.php` noch herumliegt) und die Anzahl deiner
bestehenden Angebote/Aufrufe zur Kontrolle.

---

## Neues Release veröffentlichen (Entwickler)

1. `APP_VERSION` in `includes/version.php` erhöhen. Bei Schema-Änderungen
   zusätzlich `DB_VERSION` erhöhen und in `includes/migrations.php` eine
   Migration ergänzen.
2. Committen und auf den Standard-Branch pushen.
3. Die GitHub-Action `.github/workflows/release.yml` liest die Version, baut
   `angebotsviewer-vX.Y.Z.zip`, legt Tag und Release an und hängt das ZIP an
   (übergangsweise zusätzlich als `heftis-angebote-vX.Y.Z.zip`, damit ältere
   Installationen das Update noch finden).
   Existiert für die Version schon ein Release, passiert nichts. Ab dann
   finden es installierte Versionen über „Nach Updates suchen".
   Alternativ lässt sich der Workflow unter „Actions" auch manuell starten.

---

## Installation auf All-Inkl (oder anderem Webspace)

1. **Hochladen:** Das Paket `angebotsviewer-vX.Y.Z.zip` von der GitHub-Release-Seite
   laden, entpacken und den Inhalt des Ordners `angebotsviewer/` per FTP in dein
   Webspace-Verzeichnis kopieren (z.B. nach `/angebote/`).

2. **MySQL-Datenbank:** Im All-Inkl KAS eine MySQL-Datenbank anlegen
   (Name, Benutzer, Passwort notieren).

3. **Installer öffnen:** Im Browser `https://deine-domain.de/angebote/install.php`
   aufrufen. Er prüft die Voraussetzungen und fragt ab:
   - Datenbank-Host (bei All-Inkl meist `localhost`)
   - Datenbank-Name / Benutzer / Passwort
   - **Admin-Passwort** (damit loggst du dich ins Dashboard ein)
   - Basis-URL (wird automatisch vorgeschlagen)

   Der Installer legt alle Tabellen an und schreibt `config.php`. Fehlen dafür
   Schreibrechte, zeigt er den Inhalt zum Hochladen per FTP an.

4. **install.php löschen:** Am Ende per Klick („install.php löschen & zum Login")
   oder per FTP. Solange `config.php` existiert, ist der Installer ohnehin gesperrt.

5. **Schreibrechte:** Der Ordner `uploads/` muss für PHP beschreibbar sein
   (meist 755 oder 775). Bei All-Inkl normalerweise out-of-the-box ok.

6. **Fertig:** `https://deine-domain.de/angebote/` öffnen → mit dem
   Admin-Passwort einloggen.

---

## Schöne Links (`/a/xxxx`)

Das Tool nutzt `mod_rewrite` für saubere Links wie `…/angebote/a/ab12cd34`.
Funktioniert bei All-Inkl standardmäßig. Falls die Links nicht gehen
(Server ohne mod_rewrite), funktionieren auch direkt:
`…/angebote/view.php?slug=ab12cd34`

Für **mehrseitige Inhalte** muss jede Datei unter einem echten Pfad erreichbar
sein, sonst lösen relative Links (`assets/css/style.css`) nicht auf. Dafür gibt es
zwei Wege, die beide unterstützt werden:

| Weg | URL | Voraussetzung |
|-----|-----|---------------|
| `rewrite` | `/f/{slug}/{pfad}` | `mod_rewrite` (`.htaccess`) |
| `pathinfo` | `file.php/{slug}/{pfad}` | `AcceptPathInfo` (Standard) |

Beim Hochladen eines ZIPs prüft das Tool **automatisch**, welcher Weg auf dem
Server funktioniert, und merkt sich das Ergebnis (Einstellung `bundle_delivery`).

Zusätzlich steckt nach der Passworteingabe ein **signiertes Zugriffs-Token** im
Pfad (`/f/{slug}/{token}/{pfad}`, 12 h gültig, HMAC über den Slug mit
`APP_SECRET`). Das ist nötig, weil der Browser im abgeschotteten iframe keine
Cookies an nachgeladene Dateien (CSS, JS, Schriften) sendet — ohne Token käme
die Seite ohne Design an. Das Token wird serverseitig ohne Session geprüft und
leakt dank `Referrer-Policy: no-referrer` nicht nach außen. Die Admin-Vorschau
einer Version nutzt ein eigenes, an die Version gebundenes Token (1 h gültig).
Klappt keiner von beiden Wegen, wird direkt im Admin gewarnt — dann fehlt meist
die aktuelle `.htaccess` im Hauptordner.

Wenn du das Tool in einen **Unterordner** legst, in `.htaccess` ggf.
`RewriteBase /angebote/` ergänzen.

---

## Sicherheit

**Hochgeladene Inhalte sind vollständig isoliert:**

- **Nur in `uploads/`:** ZIPs werden ausschließlich in den Ordner des jeweiligen
  Angebots entpackt. Pfade mit `..`, absolute Pfade, versteckte Dateien
  (`.htaccess`, `.user.ini` …), symbolische Links und `__MACOSX`-Reste werden verworfen.
- **Nichts wird auf dem Server ausgeführt:** Nur eine Positivliste harmloser
  Dateitypen (HTML, CSS, JS, Bilder, Schriften, Medien, PDF). Dateien mit
  gefährlicher Endung an *irgendeiner* Stelle (`shell.php`, `bild.php.png`,
  `x.phtml`, `a.phar.jpg`, `seite.shtml.html` …) werden abgelehnt. `file.php`
  liest Dateien nur, es führt sie nie aus.
- **Upload-Ordner von außen gesperrt:** `uploads/.htaccess` sperrt jeden direkten
  Zugriff und schaltet die PHP-Ausführung ab. Das Tool legt die Sperre selbst an,
  repariert sie automatisch (z.B. nach einem FTP-Update) und prüft sie auf der
  Update-Seite per Live-Test.
- **Abgeschottet im Browser:** HTML, SVG und XML aus Uploads werden immer mit
  `Content-Security-Policy: sandbox` ausgeliefert – auch wenn jemand die Datei
  direkt in einem Tab öffnet. Sie laufen so in einem eigenen Ursprung und können
  nie im Namen eines eingeloggten Admins handeln.
- **ZIP-Bomben:** max. 60 MB Upload, entpackt max. 80 MB / 800 Dateien / 30 MB
  pro Datei. Die Grenzen gelten beim Schreiben hart – auch wenn das ZIP falsche
  Größen angibt, wird abgebrochen und nichts bleibt liegen.

**Weitere Schutzmaßnahmen:**

- Passwörter (Admin + Angebote) werden mit **bcrypt** gehasht gespeichert
- Uploads liegen in einem per `.htaccess` **gesperrten Ordner** und werden nur
  nach Passworteingabe über `file.php` ausgeliefert — der Link allein reicht nicht
- CSRF-Schutz im Admin-Bereich und im Installer
- Installer sperrt sich selbst, sobald `config.php` existiert
- Einzeldatei: nur `.html` / `.htm`, max. 10 MB
- `cron.php` arbeitet nur mit dem geheimen Schlüssel aus den Einstellungen
- Updates per ZIP-Upload sind standardmäßig aus; GitHub-Updates werden per
  SHA-256-Prüfsumme kontrolliert

---

## Ordnerstruktur

```
angebotsviewer/
├── install.php          ← Installer (nach Setup löschen)
├── index.php            ← Admin-Login
├── view.php             ← Öffentliche Ansicht + Passwortabfrage
├── file.php             ← Sichere Auslieferung (Einzel-HTML + Bundle-Dateien)
├── cron.php             ← Erinnerungen per Cronjob (mit Schlüssel)
├── config.php           ← Wird vom Installer erzeugt
├── config.sample.php    ← Vorlage
├── .htaccess            ← Routing + Schutz
├── admin/
│   ├── index.php        ← Dashboard (Übersicht, Filter, Archiv)
│   ├── upload.php       ← Neues Angebot hochladen
│   ├── edit.php         ← Bearbeiten + Versionsverlauf
│   ├── preview.php      ← Vorschau einer Version (wird nicht gezählt)
│   ├── stats.php        ← Statistik (Versionen, Zeit pro Seite, Aufrufe)
│   ├── settings.php     ← E-Mail, Erinnerungen, Nachrichten-Vorlage
│   ├── update.php       ← Updates (GitHub / ZIP) + DB-Migrationen
│   └── export.php, logout.php
├── api/
│   └── track.php        ← Aufruf-Start, Verweildauer, Zeit pro Seite
├── assets/
│   └── style.css
├── includes/            ← (gesperrt) DB, Auth, Tracking, Versionen, Updater …
└── uploads/             ← (gesperrt) Hochgeladene Angebote aller Versionen
```
