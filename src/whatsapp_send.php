<?php

// Bootstrap laden (lädt .env und Composer Autoload)
require_once __DIR__ . '/config/bootstrap.php';

use Twilio\Rest\Client;

// Twilio-Zugangsdaten aus der .env holen
$accountSid = $_ENV['TWILIO_ACCOUNT_SID'];
$authToken  = $_ENV['TWILIO_AUTH_TOKEN'];

// Empfänger und Absender (Twilio Sandbox)
$to      = 'whatsapp:' . ($_ENV['TEST_WHATSAPP_NUMBER'] ?? '');
$from    = 'whatsapp:+14155238886';
$message = 'Testnachricht aus der IPA WhatsApp Integration.';

// Nachricht senden
try {
    $client = new Client($accountSid, $authToken);

    $client->messages->create($to, [
        'from' => $from,
        'body' => $message
    ]);

    echo 'WhatsApp Nachricht erfolgreich gesendet.';
} catch (Exception $e) {
    echo 'Fehler beim Versand: ' . $e->getMessage();
}
