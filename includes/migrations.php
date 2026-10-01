<?php
/**
 * Sicheres DB-Migrationssystem.
 *
 * Grundprinzip: Bestehende Daten (Angebote, Statistiken, Uploads) werden
 * NIEMALS gelöscht oder überschrieben. Migrationen fügen nur additiv hinzu
 * (neue Spalten/Tabellen) und prüfen vorher, ob die Änderung schon existiert.
 *
 * Eine neue Migration hinzufügen:
 *   1. In version.php DB_VERSION um 1 erhöhen (z.B. von 1 auf 2)
 *   2. Hier unter dem entsprechenden Schlüssel (z.B. 2 => ...) eine Closure
 *      ergänzen, die die Änderung durchführt.
 *   3. Closure idempotent halten (siehe columnExists()-Helfer).
 */

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Liefert die aktuell installierte DB-Version.
 * Legt bei Bedarf die settings-Tabelle an und stuft eine bestehende
 * Alt-Installation (ohne settings) als Version 1 ein — ohne Datenverlust.
 */
function getDbVersion(PDO $db): int {
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $row = $db->query("SELECT v FROM settings WHERE k = 'db_version'")->fetch();
    if (!$row) {
        // Bestehende Installation als Baseline (Version 1) markieren.
        $stmt = $db->prepare("INSERT INTO settings (k, v) VALUES ('db_version', ?)");
        $stmt->execute(['1']);
        return 1;
    }
    return (int)$row['v'];
}

function setDbVersion(PDO $db, int $version): void {
    $stmt = $db->prepare("
        INSERT INTO settings (k, v) VALUES ('db_version', ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v)
    ");
    $stmt->execute([(string)$version]);
}

/**
 * Alle Migrationen, key = Ziel-Version.
 * Aktuell sind keine ausstehenden Migrationen nötig — das Grundgerüst ist
 * aber aktiv, sodass künftige Updates gefahrlos eingespielt werden können.
 */
function migrations(): array {
    return [
        // v1.3.0: Ablaufdatum, Pausieren, E-Mail-Dedupe, Brute-Force-Schutz
        2 => function (PDO $db) {
            if (!columnExists($db, 'offers', 'expires_at')) {
                $db->exec("ALTER TABLE offers ADD COLUMN expires_at DATETIME NULL DEFAULT NULL");
            }
            if (!columnExists($db, 'offers', 'is_paused')) {
                $db->exec("ALTER TABLE offers ADD COLUMN is_paused TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!columnExists($db, 'offer_views', 'notified_at')) {
                $db->exec("ALTER TABLE offer_views ADD COLUMN notified_at DATETIME NULL DEFAULT NULL");
            }
            if (!tableExists($db, 'auth_throttle')) {
                $db->exec("
                    CREATE TABLE auth_throttle (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        ip_hash VARCHAR(64) NOT NULL,
                        context VARCHAR(40) NOT NULL,
                        attempts INT NOT NULL DEFAULT 0,
                        locked_until DATETIME NULL DEFAULT NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_ip_ctx (ip_hash, context)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }
        },

        // v1.4.0: verschlüsseltes Passwort für die Copy-Paste-Nachricht
        3 => function (PDO $db) {
            if (!columnExists($db, 'offers', 'password_enc')) {
                $db->exec("ALTER TABLE offers ADD COLUMN password_enc VARCHAR(512) NULL DEFAULT NULL");
            }
        },

        // v1.5.0: mehrseitige Angebote (ZIP / Mini-Webseite)
        // bundle_type: 'single' = eine HTML-Datei (wie bisher), 'bundle' = entpacktes ZIP
        // entry_file : Startseite innerhalb des Bundles, z.B. "index.html"
        4 => function (PDO $db) {
            if (!columnExists($db, 'offers', 'bundle_type')) {
                $db->exec("ALTER TABLE offers ADD COLUMN bundle_type VARCHAR(16) NOT NULL DEFAULT 'single'");
            }
            if (!columnExists($db, 'offers', 'entry_file')) {
                $db->exec("ALTER TABLE offers ADD COLUMN entry_file VARCHAR(255) NULL DEFAULT NULL");
            }
        },

        // v1.6.0: frei wählbare Bezeichnung (Angebot / Webseite / Vorschau / eigene)
        5 => function (PDO $db) {
            if (!columnExists($db, 'offers', 'kind_label')) {
                $db->exec("ALTER TABLE offers ADD COLUMN kind_label VARCHAR(60) NOT NULL DEFAULT 'Angebot'");
            }
        },

        // v1.7.0: eigene Überschrift auf der Passwort-Seite (leer = BRAND_NAME)
        //         + Archivieren (Kunde sieht einen Archiv-Hinweis).
        // Angebote ohne Passwort brauchen keine Schema-Änderung: dort ist
        // password_hash einfach leer ('').
        6 => function (PDO $db) {
            if (!columnExists($db, 'offers', 'heading')) {
                $db->exec("ALTER TABLE offers ADD COLUMN heading VARCHAR(80) NULL DEFAULT NULL");
            }
            if (!columnExists($db, 'offers', 'is_archived')) {
                $db->exec("ALTER TABLE offers ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
            }
        },
    ];
}

/**
 * Leitet auf die Update-Seite um, falls eine DB-Migration aussteht.
 * Wird in Admin-Seiten genutzt, die neue Spalten verwenden – so landet man
 * nach einem Code-Update zuerst sicher beim "Datenbank aktualisieren"-Schritt,
 * statt auf einen Fehler zu laufen. NICHT auf update.php selbst aufrufen.
 */
function redirectIfMigrationPending(PDO $db): void {
    if (getDbVersion($db) < DB_VERSION) {
        header('Location: ' . BASE_URL . 'admin/update.php');
        exit;
    }
}

/**
 * Führt alle ausstehenden Migrationen aus. Gibt die Liste der angewendeten
 * Versionen zurück. Jede Migration läuft in einer eigenen Transaktion.
 */
function runMigrations(PDO $db): array {
    $current = getDbVersion($db);
    $applied = [];
    $all = migrations();
    ksort($all);

    foreach ($all as $version => $migration) {
        if ($version > $current && $version <= DB_VERSION) {
            // BEWUSST ohne Transaktion: MySQL committet bei DDL (ALTER/CREATE
            // TABLE) implizit — ein commit() danach liefe auf "There is no
            // active transaction". Stattdessen sind alle Migrationen idempotent
            // (columnExists/tableExists), ein erneuter Durchlauf ist gefahrlos.
            try {
                $migration($db);
                setDbVersion($db, $version);
                $applied[] = $version;
            } catch (Throwable $ex) {
                throw new RuntimeException(
                    "Migration auf Version {$version} fehlgeschlagen: " . $ex->getMessage()
                );
            }
        }
    }

    // Falls keine Migration nötig war, Versionsstand trotzdem angleichen.
    if (getDbVersion($db) < DB_VERSION) {
        setDbVersion($db, DB_VERSION);
    }

    return $applied;
}
