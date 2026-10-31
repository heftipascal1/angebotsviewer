<?php
/**
 * E-Mail-Erinnerungen an den Admin:
 *  - Angebot wurde X Tage nach dem Erstellen noch nicht geöffnet
 *  - Angebot läuft in X Tagen ab
 * Jede Erinnerung wird pro Angebot nur einmal verschickt.
 *
 * Ausgelöst wird die Prüfung höchstens einmal pro Stunde automatisch
 * (beim Öffnen des Dashboards oder eines Angebots) und optional über einen
 * Cronjob auf cron.php?key=… – so klappt es auch ohne Server-Cron.
 */

function reminderSettings(PDO $db): array {
    return [
        'enabled'        => getSetting($db, 'reminder_enabled', '0') === '1',
        'unopened_days'  => max(1, min(60, (int)(getSetting($db, 'reminder_unopened_days', '3') ?: 3))),
        'expiry_days'    => max(1, min(30, (int)(getSetting($db, 'reminder_expiry_days', '2') ?: 2))),
    ];
}

/** Geheimer Schlüssel für cron.php (wird beim ersten Aufruf erzeugt). */
function cronKey(PDO $db): string {
    $key = (string)getSetting($db, 'cron_key', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
        $key = bin2hex(random_bytes(16));
        setSetting($db, 'cron_key', $key);
    }
    return $key;
}

/** Führt die Prüfung aus, wenn die letzte länger als eine Stunde her ist. */
function runRemindersIfDue(PDO $db): void {
    try {
        if (getDbVersion($db) < 8) return;                      // Migration steht noch aus
        if (!reminderSettings($db)['enabled']) return;
        $last = (int)getSetting($db, 'reminder_last_run', '0');
        if ($last > time() - 3600) return;
        setSetting($db, 'reminder_last_run', (string)time());
        runReminders($db);
    } catch (Throwable $ex) {
        // Erinnerungen dürfen nie eine Seite stören
    }
}

/**
 * Prüft alle Angebote und verschickt EINE Sammel-Mail mit allen fälligen
 * Erinnerungen. Markiert nur, wenn der Versand geklappt hat.
 * @return array{unopened:int, expiring:int, sent:bool, error:?string}
 */
function runReminders(PDO $db): array {
    $out = ['unopened' => 0, 'expiring' => 0, 'sent' => false, 'error' => null];
    $cfg = reminderSettings($db);
    $to  = trim((string)getSetting($db, 'notify_email', ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $out['error'] = 'Keine gültige Benachrichtigungs-E-Mail hinterlegt.';
        return $out;
    }

    $activeSql = "o.is_active = 1 AND o.is_paused = 0 AND o.is_archived = 0
                  AND (o.expires_at IS NULL OR o.expires_at > NOW())";

    $stmt = $db->prepare("
        SELECT o.id, o.title, o.kind_label, o.created_at, o.slug
        FROM offers o
        WHERE $activeSql
          AND o.reminded_unopened_at IS NULL
          AND o.created_at <= (NOW() - INTERVAL ? DAY)
          AND NOT EXISTS (SELECT 1 FROM offer_views v WHERE v.offer_id = o.id)
        ORDER BY o.created_at
    ");
    $stmt->execute([$cfg['unopened_days']]);
    $unopened = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT o.id, o.title, o.kind_label, o.expires_at, o.slug,
               (SELECT COUNT(*) FROM offer_views v WHERE v.offer_id = o.id) AS views
        FROM offers o
        WHERE $activeSql
          AND o.reminded_expiry_at IS NULL
          AND o.expires_at IS NOT NULL
          AND o.expires_at <= (NOW() + INTERVAL ? DAY)
        ORDER BY o.expires_at
    ");
    $stmt->execute([$cfg['expiry_days']]);
    $expiring = $stmt->fetchAll();

    $out['unopened'] = count($unopened);
    $out['expiring'] = count($expiring);
    if (!$unopened && !$expiring) return $out;

    $body = "Hallo,\n\nhier sind deine Erinnerungen aus " . BRAND_NAME . ":\n";
    if ($unopened) {
        $body .= "\n— Noch nicht geöffnet (seit über " . $cfg['unopened_days'] . " Tag" . ($cfg['unopened_days'] > 1 ? 'en' : '') . ") —\n";
        foreach ($unopened as $o) {
            $body .= "• " . $o['title'] . " (" . offerKind($o) . ", erstellt am " . date('d.m.Y', strtotime($o['created_at'])) . ")\n"
                   . "  " . BASE_URL . "admin/stats.php?id=" . (int)$o['id'] . "\n";
        }
        $body .= "Vielleicht ein guter Moment zum Nachfassen.\n";
    }
    if ($expiring) {
        $body .= "\n— Läuft bald ab —\n";
        foreach ($expiring as $o) {
            $body .= "• " . $o['title'] . " (" . offerKind($o) . ", gültig bis " . date('d.m.Y', strtotime($o['expires_at'])) . ", "
                   . (int)$o['views'] . " Aufruf" . ((int)$o['views'] === 1 ? '' : 'e') . ")\n"
                   . "  " . BASE_URL . "admin/edit.php?id=" . (int)$o['id'] . "\n";
        }
        $body .= "Über „Bearbeiten“ kannst du das Ablaufdatum verlängern.\n";
    }
    $body .= "\nErinnerungen ein- oder ausschalten:\n" . BASE_URL . "admin/settings.php\n";

    $subject = '⏰ Erinnerung: '
        . ($unopened ? count($unopened) . ' ungeöffnet' : '')
        . ($unopened && $expiring ? ', ' : '')
        . ($expiring ? count($expiring) . ' läuft bald ab' : '');

    $out['sent'] = sendMailUtf8($to, $subject, $body);
    if (!$out['sent']) {
        $out['error'] = 'Der E-Mail-Versand ist fehlgeschlagen.';
        return $out;
    }
    $mark = function (string $col, array $rows) use ($db) {
        if (!$rows) return;
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $db->exec("UPDATE offers SET $col = NOW() WHERE id IN ($ids)");
    };
    $mark('reminded_unopened_at', $unopened);
    $mark('reminded_expiry_at', $expiring);
    return $out;
}
