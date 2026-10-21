<?php

// Bestehende Datenbankverbindung einbinden
require_once __DIR__ . '/config/bootstrap.php';

use Grenzlager\Util\Db;

$pdo = Db::pdo();

// Schritt 1: Alle aktiven Pakete laden, die seit mindestens 10 Tagen im Lager liegen
// Status 300 = abgeholt, diese werden nicht berücksichtigt
$sql = "SELECT fld_parcels_id, fld_parcels_receivedAt
        FROM grzl_tbl_parcels
        WHERE fld_parcels_status != 300
        AND fld_parcels_receivedAt <= DATE_SUB(NOW(), INTERVAL 10 DAY)";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$parcels = $stmt->fetchAll();

if (empty($parcels)) {
    echo "Keine relevanten Pakete gefunden.";
    return;
}

// Schritt 2: Für jedes Paket die Reminder-Logik prüfen
foreach ($parcels as $parcel) {
    $parcelId    = $parcel['fld_parcels_id'];
    $receivedAt  = $parcel['fld_parcels_receivedAt'];

    // Prüfen: Gibt es bereits einen offenen Reminder für dieses Paket?
    $checkSql = "SELECT COUNT(*)
                 FROM grzl_tbl_reminder_queue
                 WHERE fld_reminder_queue_parcelsId = ?
                 AND fld_reminder_queue_status = 'pending'";

    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$parcelId]);
    $pendingCount = (int) $checkStmt->fetchColumn();

    if ($pendingCount > 0) {
        echo "Reminder existiert bereits für Paket ID " . $parcelId . "<br>";
        continue;
    }

    // Schritt 3: Letzten erfolgreich gesendeten Reminder aus dem notification_log holen
    $logSql = "SELECT fld_notification_log_sentAt
               FROM grzl_tbl_notification_log
               WHERE fld_notification_log_parcelsId = ?
               AND fld_notification_log_type IN ('initial_reminder', 'followup_reminder')
               AND fld_notification_log_status = 'success'
               ORDER BY fld_notification_log_sentAt DESC
               LIMIT 1";

    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute([$parcelId]);
    $lastReminder = $logStmt->fetch();

    // Schritt 4: Typ und Versanddatum bestimmen
    if ($lastReminder === false) {
        // Noch kein Reminder gesendet → erste Erinnerung 10 Tage nach Eingang
        $type   = 'initial_reminder';
        $sendAt = date('Y-m-d H:i:s', strtotime($receivedAt . ' +10 days'));
    } else {
        // Bereits ein Reminder gesendet → nächste Erinnerung 7 Tage nach dem letzten
        $type   = 'followup_reminder';
        $sendAt = date('Y-m-d H:i:s', strtotime($lastReminder['fld_notification_log_sentAt'] . ' +7 days'));
    }

    // Schritt 5: Neuen Reminder in die Queue eintragen
    $insertSql = "INSERT INTO grzl_tbl_reminder_queue (
                      fld_reminder_queue_parcelsId,
                      fld_reminder_queue_sendAt,
                      fld_reminder_queue_type,
                      fld_reminder_queue_status
                  ) VALUES (?, ?, ?, 'pending')";

    $insertStmt = $pdo->prepare($insertSql);
    $insertStmt->execute([$parcelId, $sendAt, $type]);

    echo "Reminder erstellt für Paket ID " . $parcelId . "<br>";
}
