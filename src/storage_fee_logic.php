<?php

// 404 – Lagergebühr ab 30 Tagen automatisch berechnen und speichern

require_once __DIR__ . '/config/bootstrap.php';

use Grenzlager\Util\Db;

$pdo = Db::pdo();

// Gebührensatz: 5 CHF pro Tag ab Tag 31
$gebuehrProTag = 5.00;

// Schritt 1: Aktive Pakete (Status 200) laden, die länger als 30 Tage im Lager sind
$sql = "SELECT fld_parcels_id, fld_parcels_receivedAt
        FROM grzl_tbl_parcels
        WHERE fld_parcels_status = 200
        AND fld_parcels_receivedAt <= DATE_SUB(NOW(), INTERVAL 30 DAY)";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$pakete = $stmt->fetchAll();

if (empty($pakete)) {
    echo "Keine Pakete gefunden, die länger als 30 Tage lagern.";
    return;
}

// Schritt 2: Jedes Paket einzeln prüfen
foreach ($pakete as $paket) {
    $paketId = $paket['fld_parcels_id'];
    $eingang = $paket['fld_parcels_receivedAt'];

    // Schritt 3: Wurde heute bereits eine Gebühr für dieses Paket berechnet?
    // Jeder Cronjob-Lauf fügt genau 5 CHF für diesen Tag hinzu.
    // Mit CURDATE() wird sichergestellt, dass am gleichen Tag keine zweite Gebühr entsteht.
    $pruefSql = "SELECT COUNT(*)
                 FROM grzl_tbl_storage_fees
                 WHERE fld_storage_fees_parcelsId = ?
                 AND fld_storage_fees_type = 'storage_fee'
                 AND DATE(fld_storage_fees_createdAt) = CURDATE()";

    $pruefStmt = $pdo->prepare($pruefSql);
    $pruefStmt->execute([$paketId]);

    if ((int) $pruefStmt->fetchColumn() > 0) {
        echo "Gebühr existiert bereits für Paket ID " . $paketId . "<br>";
        continue;
    }

    // Schritt 4: Gesamte Lagertage berechnen (für den Beschreibungstext)
    $tageSeit = (int) (new DateTime())->diff(new DateTime($eingang))->days;

    // Schritt 5: Betrag für heute = 1 Tag × 5 CHF
    // Pro Cronjob-Lauf wird immer genau ein Tagessatz berechnet.
    $betrag = $gebuehrProTag;

    // Schritt 6: Gebühr in grzl_tbl_storage_fees speichern
    $grund = "Tagesgebühr: Tag " . $tageSeit . " im Lager (1 Tag × " . $gebuehrProTag . " CHF)";

    $insertSql = "INSERT INTO grzl_tbl_storage_fees (
                      fld_storage_fees_parcelsId,
                      fld_storage_fees_amount,
                      fld_storage_fees_currency,
                      fld_storage_fees_type,
                      fld_storage_fees_daysCounted,
                      fld_storage_fees_reason,
                      fld_storage_fees_createdAt
                  ) VALUES (?, ?, 'CHF', 'storage_fee', ?, ?, NOW())";

    $insertStmt = $pdo->prepare($insertSql);
    // daysCounted = 1, da pro Lauf immer genau 1 Tag berechnet wird
    $insertStmt->execute([$paketId, $betrag, 1, $grund]);

    echo "Tagesgebühr erstellt für Paket ID " . $paketId
        . " | Tag " . $tageSeit . " im Lager"
        . " | Betrag: " . number_format($betrag, 2) . " CHF<br>";
}
