<?php

require_once __DIR__ . '/config/bootstrap.php';

use Grenzlager\Util\Db;

$parcelId  = 1;
$channel   = 'email';
$type      = 'initial_reminder';
$recipient = 'test@example.com';
$subject   = 'Test Erinnerung';
$message   = 'Dies ist ein Testeintrag für das Benachrichtigungs-Logging.';
$status    = 'success';
$error     = null;

try {
    $pdo = Db::pdo();

    $sql = "INSERT INTO grzl_tbl_notification_log (
                fld_notification_log_parcelsId,
                fld_notification_log_channel,
                fld_notification_log_type,
                fld_notification_log_recipient,
                fld_notification_log_subject,
                fld_notification_log_message,
                fld_notification_log_status,
                fld_notification_log_error,
                fld_notification_log_sentAt
            ) VALUES (
                :parcelId,
                :channel,
                :type,
                :recipient,
                :subject,
                :message,
                :status,
                :error,
                NOW()
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':parcelId'  => $parcelId,
        ':channel'   => $channel,
        ':type'      => $type,
        ':recipient' => $recipient,
        ':subject'   => $subject,
        ':message'   => $message,
        ':status'    => $status,
        ':error'     => $error
    ]);

    echo 'Log-Eintrag erfolgreich gespeichert.';
} catch (PDOException $e) {
    echo 'Fehler beim Speichern des Log-Eintrags: ' . $e->getMessage();
}