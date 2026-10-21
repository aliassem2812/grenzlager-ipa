<?php

// ============================================================
// cron_reminder.php – Vollständiger automatischer Ablauf
//
// Dieser Cronjob wird von Plesk automatisch ausgeführt.
// Er verarbeitet alle fälligen Erinnerungen und führt
// anschliessend die Gebühren- und Eskalationslogik aus.
//
// Ablauf:
//   1. Fällige Reminder aus der Queue laden
//   2. Paket + Kunde laden und Status prüfen
//   3. E-Mail an echte Kundenadresse senden
//   4. WhatsApp an echte Kundennummer senden
//   5. Versand in notification_log protokollieren
//   6. Reminder aus Queue entfernen
//   7. Nächste Erinnerung in 7 Tagen planen
//   8. Reminder-Queue für neue Pakete befüllen
//   9. Lagergebühren berechnen
//  10. Eskalation prüfen (ab 90 Tage → Status 900)
// ============================================================

// Bootstrap laden (Autoloader, .env, Datenbankverbindung)
require_once __DIR__ . '/config/bootstrap.php';

use Grenzlager\Util\Db;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Twilio\Rest\Client;

// ============================================================
// Mutex-Lock: Verhindert parallele Ausführung des Cronjobs.
// Wenn bereits eine Instanz läuft, wird diese beendet.
// So werden keine doppelten Nachrichten gesendet.
// ============================================================
$lockFile = sys_get_temp_dir() . '/cron_reminder.lock';
$lock = fopen($lockFile, 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Cronjob läuft bereits in einem anderen Prozess – Abbruch.";
    fclose($lock);
    exit;
}

$pdo = Db::pdo();

// ============================================================
// Schritt 1a: Reminder-Queue zuerst befüllen
// Neue Einträge für Pakete erstellen, die noch keinen
// pending Reminder haben (initial nach 10 Tagen).
// Muss VOR dem Versand laufen, damit keine Dopplungen entstehen.
// ============================================================
require __DIR__ . '/reminder_logic.php';
echo "Reminder-Logik ausgeführt<br>";

// ============================================================
// Schritt 1b: Fällige Reminder aus der Queue laden
// Nur Einträge, die jetzt oder bereits früher fällig waren
// und noch nicht verarbeitet wurden (status = 'pending').
// Paket- und Kundendaten werden direkt per JOIN mitgeladen.
// ============================================================
$sql = "SELECT
            rq.fld_reminder_queue_id,
            rq.fld_reminder_queue_parcelsId,
            rq.fld_reminder_queue_type,
            p.fld_parcels_number,
            p.fld_parcels_status,
            p.fld_parcels_receivedAt,
            c.fld_clients_email,
            c.fld_clients_phone,
            CONCAT(c.fld_clients_firstName, ' ', c.fld_clients_lastName) AS client_name
        FROM grzl_tbl_reminder_queue rq
        JOIN grzl_tbl_parcels p  ON rq.fld_reminder_queue_parcelsId = p.fld_parcels_id
        JOIN grzl_tbl_clients c  ON p.fld_parcels_clientsId = c.fld_clients_id
        WHERE rq.fld_reminder_queue_sendAt <= NOW()
        AND rq.fld_reminder_queue_status = 'pending'
        LIMIT 50";

$stmt = $pdo->query($sql);
$reminders = $stmt->fetchAll();

if (empty($reminders)) {
    echo "Keine fälligen Reminder vorhanden.<br>";
} else {

    // Merkliste: bereits verarbeitete Paket-IDs in diesem Lauf.
    // Verhindert, dass dasselbe Paket zweimal benachrichtigt wird,
    // falls versehentlich mehrere pending-Einträge existieren.
    $bereitsVerarbeitet = [];

    // Schritt 2: Jeden fälligen Reminder einzeln verarbeiten
    foreach ($reminders as $reminder) {

        $reminderId  = $reminder['fld_reminder_queue_id'];
        $parcelId    = $reminder['fld_reminder_queue_parcelsId'];

        // Schutz: Paket in diesem Lauf schon verarbeitet? → überspringen
        if (in_array($parcelId, $bereitsVerarbeitet)) {
            // Doppeleintrag aus Queue entfernen
            $delStmt = $pdo->prepare("DELETE FROM grzl_tbl_reminder_queue WHERE fld_reminder_queue_id = ?");
            $delStmt->execute([$reminderId]);
            echo "Doppelter Reminder für Paket ID " . $parcelId . " entfernt.<br>";
            continue;
        }
        $type        = $reminder['fld_reminder_queue_type'];
        $parcelNr    = $reminder['fld_parcels_number'];
        $parcelStatus = (int) $reminder['fld_parcels_status'];
        $email       = $reminder['fld_clients_email'];
        $phone       = $reminder['fld_clients_phone'];
        $clientName  = $reminder['client_name'];

        // Pakete mit Status != 200 (nicht aktiv im Lager) überspringen.
        // Abgeholte (300), archivierte (410) oder eskalierte (900)
        // Pakete brauchen keine Benachrichtigung mehr.
        if ($parcelStatus !== 200) {
            echo "Paket " . $parcelNr . " hat Status " . $parcelStatus . " – Reminder wird entfernt.<br>";
            $delStmt = $pdo->prepare("DELETE FROM grzl_tbl_reminder_queue WHERE fld_reminder_queue_id = ?");
            $delStmt->execute([$reminderId]);
            continue;
        }

        // Nachrichtentext zusammenstellen (wird für E-Mail und WhatsApp verwendet)
        $subject = 'Ihr Paket ' . $parcelNr . ' liegt zur Abholung bereit';
        $message = 'Guten Tag ' . $clientName . ",\n\n"
                 . "Ihr Paket mit der Nummer " . $parcelNr . " liegt bei uns im Grenzlager.\n"
                 . "Bitte holen Sie Ihr Paket so bald wie moeglich ab.\n\n"
                 . "Mit freundlichen Gruessen\n"
                 . "Grenzlager Paketstelle";

        // ============================================================
        // Schritt 3: E-Mail an Kundenadresse senden
        // ============================================================
        if (!empty($email)) {
            $emailStatus = 'failed';
            $emailError  = null;

            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $_ENV['SMTP_HOST'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $_ENV['SMTP_USERNAME'];
                $mail->Password   = $_ENV['SMTP_PASSWORD'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = (int) $_ENV['SMTP_PORT'];
                $mail->setFrom($_ENV['FROM_EMAIL'], $_ENV['FROM_NAME']);
                $mail->addAddress($email, $clientName);
                $mail->isHTML(false);
                $mail->Subject = $subject;
                $mail->Body    = $message;
                $mail->send();

                $emailStatus = 'success';
                echo "E-Mail gesendet an " . $email . " (Paket " . $parcelNr . ")<br>";

            } catch (Exception $e) {
                $emailError = $mail->ErrorInfo;
                echo "E-Mail Fehler für Paket " . $parcelNr . ": " . $emailError . "<br>";
            }

            // ============================================================
            // Schritt 5a: E-Mail-Versand in notification_log protokollieren
            // ============================================================
            $logSql = "INSERT INTO grzl_tbl_notification_log (
                           fld_notification_log_parcelsId,
                           fld_notification_log_channel,
                           fld_notification_log_type,
                           fld_notification_log_recipient,
                           fld_notification_log_subject,
                           fld_notification_log_message,
                           fld_notification_log_status,
                           fld_notification_log_error,
                           fld_notification_log_sentAt
                       ) VALUES (?, 'email', ?, ?, ?, ?, ?, ?, NOW())";

            $logStmt = $pdo->prepare($logSql);
            $logStmt->execute([$parcelId, $type, $email, $subject, $message, $emailStatus, $emailError]);
        }

        // ============================================================
        // Schritt 4: WhatsApp an Kundennummer senden
        // fld_clients_phone enthält die Nummer bereits im Format +41 79 123 45 67.
        // Twilio braucht E.164 ohne Leerzeichen: +41791234567
        // Dazu werden alle Leerzeichen aus der Nummer entfernt.
        // ============================================================
        if (!empty($phone)) {
            $waStatus = 'failed';
            $waError  = null;
            // Alle Leerzeichen entfernen → +41791234567
            $waNumber = 'whatsapp:' . str_replace(' ', '', $phone);

            try {
                $twilio = new Client($_ENV['TWILIO_ACCOUNT_SID'], $_ENV['TWILIO_AUTH_TOKEN']);
                $twilio->messages->create($waNumber, [
                    'from' => 'whatsapp:+14155238886',
                    'body' => $message
                ]);

                $waStatus = 'success';
                echo "WhatsApp gesendet an " . $waNumber . " (Paket " . $parcelNr . ")<br>";

            } catch (Exception $e) {
                $waError = $e->getMessage();
                echo "WhatsApp Fehler für Paket " . $parcelNr . ": " . $waError . "<br>";
            }

            // ============================================================
            // Schritt 5b: WhatsApp-Versand in notification_log protokollieren
            // ============================================================
            $logSql = "INSERT INTO grzl_tbl_notification_log (
                           fld_notification_log_parcelsId,
                           fld_notification_log_channel,
                           fld_notification_log_type,
                           fld_notification_log_recipient,
                           fld_notification_log_subject,
                           fld_notification_log_message,
                           fld_notification_log_status,
                           fld_notification_log_error,
                           fld_notification_log_sentAt
                       ) VALUES (?, 'whatsapp', ?, ?, NULL, ?, ?, ?, NOW())";

            $logStmt = $pdo->prepare($logSql);
            $logStmt->execute([$parcelId, $type, $waNumber, $message, $waStatus, $waError]);
        }

        // ============================================================
        // Schritt 6: Erfolgreich verarbeiteten Reminder aus Queue löschen
        // ============================================================
        $delStmt = $pdo->prepare("DELETE FROM grzl_tbl_reminder_queue WHERE fld_reminder_queue_id = ?");
        $delStmt->execute([$reminderId]);

        // ============================================================
        // Schritt 7: Nächste Erinnerung in 7 Tagen planen
        // Nur wenn das Paket noch aktiv ist (Status 200).
        // ============================================================
        $nextSendAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        $nextSql = "INSERT INTO grzl_tbl_reminder_queue (
                        fld_reminder_queue_parcelsId,
                        fld_reminder_queue_sendAt,
                        fld_reminder_queue_type,
                        fld_reminder_queue_status
                    ) VALUES (?, ?, 'followup_reminder', 'pending')";

        $nextStmt = $pdo->prepare($nextSql);
        $nextStmt->execute([$parcelId, $nextSendAt]);

        echo "Nächste Erinnerung geplant für Paket " . $parcelNr
             . " am " . date('d.m.Y', strtotime('+7 days')) . "<br>";

        // Paket-ID als verarbeitet markieren → kein Doppelversand in diesem Lauf
        $bereitsVerarbeitet[] = $parcelId;
    }
}

echo "Benachrichtigungen abgeschlossen.<br><br>";

// ============================================================
// Schritt 8: Lagergebühren berechnen
// 5 CHF pro Tag ab Tag 31 für aktive Pakete (Status 200).
// Keine doppelten Einträge – wird geprüft vor dem Einfügen.
// ============================================================
require __DIR__ . '/storage_fee_logic.php';
echo "Gebühren-Logik ausgeführt<br>";

// ============================================================
// Schritt 10: Eskalation prüfen
// Pakete älter als 90 Tage → Status 900 (Vernichtung).
// Wird im parcelStatusLog protokolliert.
// ============================================================
require __DIR__ . '/escalation_logic.php';
echo "Eskalations-Logik ausgeführt<br>";

// ============================================================
// Alle Prozesse abgeschlossen
// ============================================================
echo "Cronjob vollständig abgeschlossen<br>";

// Mutex-Lock freigeben
flock($lock, LOCK_UN);
fclose($lock);
