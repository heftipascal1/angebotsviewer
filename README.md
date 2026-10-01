# Heftis Angebote

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
  und anklickbar, alles hinter derselben Passwort-Abfrage
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
- **Angebot bearbeiten:** Titel & Passwort ändern, **Inhalt austauschen**
  (HTML ⇄ ZIP jederzeit wechselbar), Startseite eines mehrseitigen Angebots wählen —
  Link und bisherige Statistik bleiben dabei erhalten
- **Sicheres Update-System:** Updates direkt aus GitHub-Releases, per
  ZIP-Upload im Admin oder per FTP einspielen – ohne bestehende
  Angebote/Statistiken zu verlieren (DB-Migrationen sind additiv)
- **E-Mail-Benachrichtigung** bei Aufruf (mit Spam-Schutz: max. 1 Mail pro
  Besucher/Angebot je 6 h)
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

### Weg 2: ZIP-Upload im Admin

Das Paket `heftis-angebote-vX.Y.Z.zip` von der GitHub-Release-Seite laden und
im Admin unter „Update" hochladen. Es werden nur ZIPs mit passendem Namen und
internem Marker akzeptiert.

### Weg 3: per FTP

1. Neue Version (ZIP) lokal entpacken.
2. Per FTP **alle Dateien überschreiben — außer** dem Ordner `uploads/` und der
   Datei `config.php`. (Darin liegen deine Angebote & Zugangsdaten.)
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
2. Committen, pushen und einen Tag setzen:
   ```
   git tag v1.7.0
   git push origin v1.7.0
   ```
3. Die GitHub-Action `.github/workflows/release.yml` prüft, dass Tag und
   `APP_VERSION` übereinstimmen, baut `heftis-angebote-v1.7.0.zip` und legt
   ein Release mit dem ZIP an. Ab dann finden es installierte Versionen über
   „Nach Updates suchen".

---

## Installation auf All-Inkl (oder anderem Webspace)

1. **Hochladen:** Den kompletten Ordner `offer-tracker` per FTP in dein
   Webspace-Verzeichnis kopieren (z.B. nach `/angebote/`).

2. **MySQL-Datenbank:** Im All-Inkl KAS eine MySQL-Datenbank anlegen
   (Name, Benutzer, Passwort notieren).

3. **Installer öffnen:** Im Browser `https://deine-domain.de/angebote/install.php`
   aufrufen und das Formular ausfüllen:
   - Datenbank-Host (bei All-Inkl meist etwas wie `localhost` oder der DB-Server aus dem KAS)
   - Datenbank-Name / Benutzer / Passwort
   - **Admin-Passwort** (damit loggst du dich ins Dashboard ein)
   - Basis-URL (wird automatisch vorgeschlagen)

4. **install.php löschen:** Nach erfolgreicher Installation die Datei
   `install.php` vom Server **löschen** (wichtig!).

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
leakt dank `Referrer-Policy: no-referrer` nicht nach außen.
Klappt keiner von beiden, wird direkt im Admin gewarnt — dann fehlt meist die
aktuelle `.htaccess` im Hauptordner.

Wenn du das Tool in einen **Unterordner** legst, in `.htaccess` ggf.
`RewriteBase /angebote/` ergänzen.

---

## Sicherheit

- Passwörter (Admin + Angebote) werden mit **bcrypt** gehasht gespeichert
- Uploads liegen in einem per `.htaccess` **gesperrten Ordner** und werden nur
  nach Passworteingabe über `file.php` ausgeliefert — der Link allein reicht nicht
- PHP-Ausführung im `uploads/`-Ordner ist deaktiviert
- CSRF-Schutz im Admin-Bereich
- Einzeldatei: nur `.html` / `.htm`, max. 10 MB
- ZIP: max. 60 MB, entpackt max. 80 MB / 800 Dateien (Schutz vor ZIP-Bomben);
  übernommen werden nur ungefährliche Dateitypen (HTML, CSS, JS, Bilder, Schriften,
  Medien, PDF). **PHP & Skripte im ZIP werden verworfen**, ebenso versteckte Dateien,
  `__MACOSX`-Reste und Pfade mit `..` (kein Verzeichnis-Ausbruch)

---

## Ordnerstruktur

```
offer-tracker/
├── install.php          ← Installer (nach Setup löschen!)
├── index.php            ← Admin-Login
├── view.php             ← Öffentliche Angebots-Ansicht + Passwortabfrage
├── file.php             ← Sichere Auslieferung (Einzel-HTML + Bundle-Dateien)
├── config.php           ← Wird vom Installer erzeugt
├── config.sample.php    ← Vorlage
├── .htaccess            ← Routing + Schutz
├── admin/
│   ├── index.php        ← Dashboard (Übersicht aller Angebote)
│   ├── upload.php       ← Neues Angebot hochladen
│   ├── stats.php        ← Detail-Statistik pro Angebot
│   └── logout.php
├── includes/
│   ├── bundle.php       ← ZIP-Upload: entpacken, prüfen, Startseite finden
│   └── …
├── api/
│   └── track.php        ← Empfängt die Verweildauer-Heartbeats
├── assets/
│   └── style.css
├── includes/            ← (gesperrt) DB, Auth, Hilfsfunktionen
└── uploads/             ← (gesperrt) Hochgeladene HTML-Angebote
```
