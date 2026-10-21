<?php
// 407 – E-Mail Versand implementieren
// Sendet eine Test-E-Mail über PHPMailer und SMTP

require_once __DIR__ . '/config/bootstrap.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail = new PHPMailer(true);

    // SMTP-Einstellungen aus der .env-Datei
    $mail->isSMTP();
    $mail->Host       = $_ENV['SMTP_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['SMTP_USERNAME'];
    $mail->Password   = $_ENV['SMTP_PASSWORD'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = (int)$_ENV['SMTP_PORT'];

    // Absender
    $mail->setFrom($_ENV['FROM_EMAIL'], $_ENV['FROM_NAME']);

    // Empfänger (eigene Testadresse)
    $mail->addAddress($_ENV['TEST_EMAIL'] ?? $_ENV['FROM_EMAIL']);

    // E-Mail-Inhalt
    $mail->isHTML(false);
    $mail->Subject = 'Test E-Mail IPA';
    $mail->Body    = 'Dies ist eine Test-E-Mail aus der IPA.';

    $mail->send();

    echo 'E-Mail erfolgreich gesendet.';

} catch (Exception $e) {
    echo 'Fehler beim Versand: ' . $mail->ErrorInfo;
}
