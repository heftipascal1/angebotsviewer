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
2. Committen und auf den Standard-Branch pushen.
3. Die GitHub-Action `.github/workflows/release.yml` liest die Version, baut
   `heftis-angebote-vX.Y.Z.zip`, legt Tag und Release an und hängt das ZIP an.
   Existiert für die Version schon ein Release, passiert nichts. Ab dann
   finden es installierte Versionen über „Nach Updates suchen".
   Alternativ lässt sich der Workflow unter „Actions" auch manuell starten.
