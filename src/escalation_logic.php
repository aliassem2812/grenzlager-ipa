<?php

// 405 – Eskalations-Logik implementieren (ab 90 Tage)
// Pakete, die länger als 90 Tage im Lager liegen, werden automatisch
// auf Status 900 (Vernichtung) gesetzt und protokolliert.

require_once __DIR__ . '/config/bootstrap.php';

use Grenzlager\Util\Db;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Twilio\Rest\Client;

$pdo = Db::pdo();

// Neuer Status für eskalierte Pakete
$neuerStatus = 900;

// Schritt 1: Aktive Pakete laden, die länger als 90 Tage im Lager liegen
// Status 200 = Active (im Lager)
// Archivierte (410), abgeholte (300) und bereits eskalierte (900) werden ignoriert
$sql = "SELECT
            p.fld_parcels_id,
            p.fld_parcels_status,
            p.fld_parcels_receivedAt,
            p.fld_parcels_number,
            c.fld_clients_email,
            c.fld_clients_phone,
            CONCAT(c.fld_clients_firstName, ' ', c.fld_clients_lastName) AS client_name
        FROM grzl_tbl_parcels p
        JOIN grzl_tbl_clients c ON p.fld_parcels_clientsId = c.fld_clients_id
        WHERE p.fld_parcels_status IN (200)
        AND p.fld_parcels_receivedAt <= DATE_SUB(NOW(), INTERVAL 90 DAY)";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$pakete = $stmt->fetchAll();

// Schritt 2: Prüfen ob überhaupt Pakete gefunden wurden
if (empty($pakete)) {
    echo "Keine Eskalation notwendig.";
    return;
}

// Schritt 3: Jedes betroffene Paket einzeln verarbeiten
foreach ($pakete as $paket) {
    $paketId     = $paket['fld_parcels_id'];
    $alterStatus = $paket['fld_parcels_status'];
    $eingang     = $paket['fld_parcels_receivedAt'];
    $paketNr     = $paket['fld_parcels_number'];
    $email       = $paket['fld_clients_email'];
    $phone       = $paket['fld_clients_phone'];
    $clientName  = $paket['client_name'];

    // Schritt 4: Lagertage berechnen (zur Dokumentation)
    $tageSeit = (int) (new DateTime())->diff(new DateTime($eingang))->days;

    // Schritt 5: Bereits eskaliert? → Doppelverarbeitung verhindern
    // Prüfen ob bereits ein Eintrag mit Status 900 im StatusLog existiert
    $pruefSql = "SELECT COUNT(*)
                 FROM grzl_tbl_parcelStatusLog
                 WHERE fld_parcelStatusLog_parcelsId = ?
                 AND fld_parcelStatusLog_status = ?";

    $pruefStmt = $pdo->prepare($pruefSql);
    $pruefStmt->execute([$paketId, $neuerStatus]);

    if ((int) $pruefStmt->fetchColumn() > 0) {
        echo "Eskalation bereits protokolliert für Paket ID " . $paketId . "<br>";
        continue;
    }

    // Schritt 6: Status in grzl_tbl_parcels auf 900 (Vernichtung) setzen
    $updateSql = "UPDATE grzl_tbl_parcels
                  SET fld_parcels_status = ?
                  WHERE fld_parcels_id = ?";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([$neuerStatus, $paketId]);

    // Schritt 7: Statusänderung in grzl_tbl_parcelStatusLog protokollieren
    // Kommentar enthält alten Status, neuen Status und Lagerdauer
    $kommentar = "Automatische Eskalation: "
        . $tageSeit . " Tage gelagert. "
        . "Status geändert von " . $alterStatus . " → " . $neuerStatus . " (Vernichtung).";

    $logSql = "INSERT INTO grzl_tbl_parcelStatusLog (
                   fld_parcelStatusLog_parcelsId,
                   fld_parcelStatusLog_status,
                   fld_parcelStatusLog_comment,
                   fld_parcelStatusLog_createdAt,
                   fld_parcelStatusLog_updatedAt
               ) VALUES (?, ?, ?, NOW(), NOW())";

    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute([$paketId, $neuerStatus, $kommentar]);

    // Schritt 8: Kunde per E-Mail und WhatsApp über die Eskalation informieren
    // Nachricht ist für beide Kanäle identisch.
    $eskalationsSubject = 'Wichtige Mitteilung zu Ihrem Paket ' . $paketNr;
    $eskalationsMessage = 'Guten Tag ' . $clientName . ",\n\n"
        . 'Ihr Paket mit der Nummer ' . $paketNr . ' lagert seit ' . $tageSeit . " Tagen bei uns.\n"
        . "Da es nicht abgeholt wurde, wurde es automatisch fuer die Vernichtung vorgemerkt.\n\n"
        . "Mit freundlichen Gruessen\n"
        . 'Grenzlager Paketstelle';

    // E-Mail an Kunden senden
    if (!empty($email)) {
        $eskalationsEmailStatus = 'failed';
        $eskalationsEmailError  = null;

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
            $mail->Subject = $eskalationsSubject;
            $mail->Body    = $eskalationsMessage;
            $mail->send();

            $eskalationsEmailStatus = 'success';
            echo "Eskalations-E-Mail gesendet an " . $email . " (Paket " . $paketNr . ")<br>";

        } catch (Exception $e) {
            $eskalationsEmailError = $mail->ErrorInfo;
            echo "E-Mail Fehler bei Eskalation Paket " . $paketNr . ": " . $eskalationsEmailError . "<br>";
        }

        // E-Mail in notification_log protokollieren
        $notifSql = "INSERT INTO grzl_tbl_notification_log (
                         fld_notification_log_parcelsId,
                         fld_notification_log_channel,
                         fld_notification_log_type,
                         fld_notification_log_recipient,
                         fld_notification_log_subject,
                         fld_notification_log_message,
                         fld_notification_log_status,
                         fld_notification_log_error,
                         fld_notification_log_sentAt
                     ) VALUES (?, 'email', 'escalation', ?, ?, ?, ?, ?, NOW())";
        $notifStmt = $pdo->prepare($notifSql);
        $notifStmt->execute([$paketId, $email, $eskalationsSubject, $eskalationsMessage, $eskalationsEmailStatus, $eskalationsEmailError]);
    }

    // WhatsApp an Kunden senden
    if (!empty($phone)) {
        $eskalationsWaStatus = 'failed';
        $eskalationsWaError  = null;
        // Leerzeichen entfernen → E.164 Format für Twilio (+41791234567)
        $waNumber = 'whatsapp:' . str_replace(' ', '', $phone);

        try {
            $twilio = new Client($_ENV['TWILIO_ACCOUNT_SID'], $_ENV['TWILIO_AUTH_TOKEN']);
            $twilio->messages->create($waNumber, [
                'from' => 'whatsapp:+14155238886',
                'body' => $eskalationsMessage
            ]);

            $eskalationsWaStatus = 'success';
            echo "Eskalations-WhatsApp gesendet an " . $waNumber . " (Paket " . $paketNr . ")<br>";

        } catch (Exception $e) {
            $eskalationsWaError = $e->getMessage();
            echo "WhatsApp Fehler bei Eskalation Paket " . $paketNr . ": " . $eskalationsWaError . "<br>";
        }

        // WhatsApp in notification_log protokollieren
        $notifSql = "INSERT INTO grzl_tbl_notification_log (
                         fld_notification_log_parcelsId,
                         fld_notification_log_channel,
                         fld_notification_log_type,
                         fld_notification_log_recipient,
                         fld_notification_log_subject,
                         fld_notification_log_message,
                         fld_notification_log_status,
                         fld_notification_log_error,
                         fld_notification_log_sentAt
                     ) VALUES (?, 'whatsapp', 'escalation', ?, NULL, ?, ?, ?, NOW())";
        $notifStmt = $pdo->prepare($notifSql);
        $notifStmt->execute([$paketId, $waNumber, $eskalationsMessage, $eskalationsWaStatus, $eskalationsWaError]);
    }

    // Schritt 9: Ausgabe zur Bestätigung
    echo "Paket eskaliert: " . $paketId
        . " | Alter Status: " . $alterStatus
        . " | Neuer Status: " . $neuerStatus
        . " | Lagerdauer: " . $tageSeit . " Tage<br>";
}
