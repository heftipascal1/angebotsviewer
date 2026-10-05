<?php
/**
 * Versionsverlauf eines Angebots.
 *
 * Jede Inhalts-Änderung (neue HTML-Datei oder neues ZIP) legt eine neue
 * Version an, statt die alte zu überschreiben. Der Admin bestimmt, welche
 * Version gerade angezeigt wird (Standard: die neueste). Link, Passwort und
 * Statistik hängen am Angebot und bleiben über alle Versionen gleich.
 *
 * Ablage auf der Platte (uploads/):
 *   Version 1:  <slug>.html   bzw. Ordner <slug>/        (wie bisher)
 *   Version N:  <slug>_vN.html bzw. Ordner <slug>_vN/
 *
 * Die Spalten offers.filename / bundle_type / entry_file / original_filename
 * zeigen immer auf die AKTIVE Version – so funktionieren view.php und
 * file.php unverändert.
 */

/** Speicherschlüssel (Dateiname ohne .html bzw. Ordnername) einer Version. */
function versionStorageKey(string $slug, int $versionNo): string {
    return $versionNo <= 1 ? $slug : $slug . '_v' . $versionNo;
}

/** Alle Versionen eines Angebots, neueste zuerst. */
function versionsForOffer(PDO $db, int $offerId): array {
    $stmt = $db->prepare("SELECT * FROM offer_versions WHERE offer_id = ? ORDER BY version_no DESC");
    $stmt->execute([$offerId]);
    return $stmt->fetchAll();
}

/** Eine Version per ID (nur wenn sie zum Angebot gehört). */
function versionById(PDO $db, int $offerId, int $versionId): ?array {
    $stmt = $db->prepare("SELECT * FROM offer_versions WHERE id = ? AND offer_id = ?");
    $stmt->execute([$versionId, $offerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Die gerade angezeigte Version (null bei Altbestand ohne Versionsdaten). */
function currentVersion(PDO $db, array $offer): ?array {
    $vid = (int)($offer['current_version_id'] ?? 0);
    if ($vid <= 0) return null;
    return versionById($db, (int)$offer['id'], $vid);
}

/** Nächste freie Versionsnummer. */
function nextVersionNo(PDO $db, int $offerId): int {
    $stmt = $db->prepare("SELECT COALESCE(MAX(version_no), 0) FROM offer_versions WHERE offer_id = ?");
    $stmt->execute([$offerId]);
    return (int)$stmt->fetchColumn() + 1;
}

/**
 * Legt einen Versions-Datensatz an (Dateien müssen bereits liegen).
 * Liefert die neue Versions-ID.
 */
function createVersion(PDO $db, int $offerId, int $versionNo, string $bundleType, string $filename,
                       ?string $entryFile, string $originalFilename, string $note = ''): int {
    $note = trim(preg_replace('/\s+/u', ' ', $note));
    if (mb_strlen($note) > 200) $note = mb_substr($note, 0, 200);
    $stmt = $db->prepare("
        INSERT INTO offer_versions (offer_id, version_no, bundle_type, filename, entry_file, original_filename, note)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$offerId, $versionNo, $bundleType, $filename, $entryFile, $originalFilename, $note !== '' ? $note : null]);
    return (int)$db->lastInsertId();
}

/**
 * Macht eine Version zur angezeigten: kopiert ihre Datei-Angaben ins Angebot
 * und merkt sich die ID. Liefert false, wenn die Version nicht existiert.
 */
function activateVersion(PDO $db, int $offerId, int $versionId): bool {
    $v = versionById($db, $offerId, $versionId);
    if ($v === null) return false;
    $db->prepare("
        UPDATE offers
        SET current_version_id = ?, bundle_type = ?, filename = ?, entry_file = ?, original_filename = ?
        WHERE id = ?
    ")->execute([$v['id'], $v['bundle_type'], $v['filename'], $v['entry_file'], $v['original_filename'], $offerId]);
    return true;
}

/** Löscht die Dateien einer Version von der Platte (Einzeldatei oder Ordner). */
function deleteVersionFiles(array $version): void {
    if (($version['bundle_type'] ?? 'single') === 'bundle') {
        bundleDeleteDir(bundleDir((string)$version['filename']));
    } else {
        $path = dirname(__DIR__) . '/uploads/' . basename((string)$version['filename']);
        if (is_file($path)) @unlink($path);
    }
}

/**
 * Entfernt eine Version samt Dateien. Die aktive Version kann nicht gelöscht
 * werden. Liefert eine Fehlermeldung oder null bei Erfolg.
 */
function deleteVersion(PDO $db, array $offer, int $versionId): ?string {
    $v = versionById($db, (int)$offer['id'], $versionId);
    if ($v === null) return 'Version nicht gefunden.';
    if ((int)($offer['current_version_id'] ?? 0) === (int)$v['id']) {
        return 'Die gerade angezeigte Version kann nicht gelöscht werden. Zeige zuerst eine andere Version an.';
    }
    deleteVersionFiles($v);
    $db->prepare("DELETE FROM offer_versions WHERE id = ?")->execute([$v['id']]);
    return null;
}

/** Löscht alle Versionen (Dateien + Datensätze) eines Angebots – beim Löschen des Angebots. */
function deleteAllVersions(PDO $db, array $offer): void {
    foreach (versionsForOffer($db, (int)$offer['id']) as $v) {
        deleteVersionFiles($v);
    }
    $db->prepare("DELETE FROM offer_versions WHERE offer_id = ?")->execute([(int)$offer['id']]);
    // Altbestand ohne Versions-Datensatz: aktuelle Datei trotzdem entfernen
    deleteVersionFiles($offer);
}

/** Kurztext "Version 3 · Stand 05.10.2026" für Admin und Kundenseite. */
function versionLabel(?array $version): string {
    if ($version === null) return '';
    $label = 'Version ' . (int)$version['version_no'];
    if (!empty($version['created_at'])) {
        $label .= ' · Stand ' . date('d.m.Y', strtotime($version['created_at']));
    }
    return $label;
}
