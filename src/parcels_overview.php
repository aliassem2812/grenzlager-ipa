<?php
/**
 * Grenzlager Management - Übersicht Pakete
 * Arbeitsschritt 409: Übersicht Pakete, Status, Logs
 *
 * Zeigt alle aktiven und eskalierten Pakete.
 * Jede Zeile ist aufklappbar und zeigt Lagertage, alle Benachrichtigungen und Gebühren.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

use Grenzlager\Middleware\Auth;
use Grenzlager\Util\Db;

// Alle angemeldeten Benutzer dürfen diese Seite sehen
Auth::requireLogin();

$pdo = Db::pdo();

// -----------------------------------------------------------------------
// SQL 1: Pakete laden (Status 200 = Eingebucht, 900 = Vernichtung)
// -----------------------------------------------------------------------
$sql = "
    SELECT
        p.fld_parcels_id,
        p.fld_parcels_number,
        p.fld_parcels_status,
        p.fld_parcels_receivedAt,
        DATEDIFF(NOW(), p.fld_parcels_receivedAt)                      AS tage_im_lager,
        CONCAT(c.fld_clients_firstName, ' ', c.fld_clients_lastName)   AS client_name,
        COALESCE(sf.total_fees, 0.00)                                  AS total_fees,
        nl.last_type,
        nl.last_channels,
        nl.last_sent
    FROM grzl_tbl_parcels p
    LEFT JOIN grzl_tbl_clients c
        ON p.fld_parcels_clientsId = c.fld_clients_id
    LEFT JOIN (
        SELECT fld_storage_fees_parcelsId, SUM(fld_storage_fees_amount) AS total_fees
        FROM grzl_tbl_storage_fees
        GROUP BY fld_storage_fees_parcelsId
    ) sf ON p.fld_parcels_id = sf.fld_storage_fees_parcelsId
    -- Neueste Benachrichtigung: GROUP_CONCAT damit E-Mail & WhatsApp in einer Zeile
    LEFT JOIN (
        SELECT
            n1.fld_notification_log_parcelsId,
            n1.fld_notification_log_type   AS last_type,
            n1.fld_notification_log_sentAt AS last_sent,
            GROUP_CONCAT(DISTINCT n1.fld_notification_log_channel ORDER BY n1.fld_notification_log_channel) AS last_channels
        FROM grzl_tbl_notification_log n1
        INNER JOIN (
            SELECT fld_notification_log_parcelsId, MAX(fld_notification_log_sentAt) AS max_sent
            FROM grzl_tbl_notification_log
            GROUP BY fld_notification_log_parcelsId
        ) n2
            ON  n1.fld_notification_log_parcelsId = n2.fld_notification_log_parcelsId
            AND n1.fld_notification_log_sentAt    = n2.max_sent
        GROUP BY n1.fld_notification_log_parcelsId
    ) nl ON p.fld_parcels_id = nl.fld_notification_log_parcelsId
    WHERE p.fld_parcels_status IN (200, 900)
    ORDER BY p.fld_parcels_receivedAt DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $parcels = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("parcels_overview.php SQL-Fehler: " . $e->getMessage());
    $parcels = [];
}

// -----------------------------------------------------------------------
// SQL 2: Alle Benachrichtigungen pro Paket laden (für Detailansicht)
// -----------------------------------------------------------------------
$alleLogs = [];
if (!empty($parcels)) {
    // Paket-IDs sammeln
    $paketIds = array_column($parcels, 'fld_parcels_id');
    $platzhalter = implode(',', array_fill(0, count($paketIds), '?'));

    $logSql = "SELECT
                   fld_notification_log_parcelsId,
                   fld_notification_log_type,
                   fld_notification_log_channel,
                   fld_notification_log_status,
                   fld_notification_log_sentAt
               FROM grzl_tbl_notification_log
               WHERE fld_notification_log_parcelsId IN ($platzhalter)
               AND fld_notification_log_status = 'success'
               ORDER BY fld_notification_log_sentAt ASC";

    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute($paketIds);
    $alleLogRohdaten = $logStmt->fetchAll();

    // Nach Paket-ID gruppieren
    foreach ($alleLogRohdaten as $log) {
        $alleLogs[$log['fld_notification_log_parcelsId']][] = $log;
    }
}

// -----------------------------------------------------------------------
// Hilfswerte
// -----------------------------------------------------------------------
$typeLabels = [
    'arrival'           => 'Ankunft',
    'initial_reminder'  => 'Erste Erinnerung',
    'followup_reminder' => 'Folge-Erinnerung',
    'fee_reminder'      => 'Gebühren-Erinnerung',
    'escalation'        => 'Eskalation',
];

$channelLabels = [
    'email'    => 'E-Mail',
    'whatsapp' => 'WhatsApp',
];

$pageTitle = "Übersicht Pakete";
?>
<?php require_once __DIR__ . '/_root/siteHeaders.php'; ?>

    <main class="main">
        <div class="container">

            <div class="page-header">
                <h3 class="card__title">
                    <i class="fas fa-list-alt"></i> Übersicht: Pakete, Status &amp; Logs
                </h3>
            </div>

            <p style="margin-bottom: 1rem; color: #6c757d;">
                Pakete (aktiv + eskaliert): <strong><?= count($parcels) ?></strong>
                &nbsp;·&nbsp; <small>Zeile anklicken für Details</small>
            </p>

            <?php if (empty($parcels)): ?>
                <div class="empty-state">
                    <h3>Keine Pakete vorhanden</h3>
                    <p>Es wurden keine aktiven oder eskalierten Pakete gefunden.</p>
                </div>
            <?php else: ?>

                <div class="table-container">
                    <div class="table-responsive sticky-header">
                        <table class="table table-striped align-middle" id="pakete-tabelle">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Paketnummer</th>
                                    <th>Kunde</th>
                                    <th>Status</th>
                                    <th>Tage im Lager</th>
                                    <th>Lagergebühren</th>
                                    <th>Letzte Benachrichtigung</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parcels as $parcel):
                                    $paketId    = $parcel['fld_parcels_id'];
                                    $statusCode = (int) $parcel['fld_parcels_status'];
                                    $tage       = (int) $parcel['tage_im_lager'];

                                    // Status-Badge
                                    if ($statusCode === 900) {
                                        $badgeStyle = 'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;';
                                        $statusText = 'Vernichtung';
                                    } else {
                                        $badgeStyle = '';
                                        $statusText = 'Eingebucht';
                                    }

                                    // Detaildaten für dieses Paket
                                    $logsDesPackets = $alleLogs[$paketId] ?? [];
                                ?>
                                <!-- Hauptzeile (aufklappbar) -->
                                <tr class="toggle-detail" data-ziel="detail-<?= $paketId ?>">
                                    <td><i class="pfeil">▶</i></td>
                                    <td><strong><?= htmlspecialchars($parcel['fld_parcels_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($parcel['client_name']) ?></td>
                                    <td>
                                        <span class="parcel-status <?= $statusCode === 900 ? '' : 'received' ?>"
                                              <?= $badgeStyle ? 'style="' . $badgeStyle . '"' : '' ?>>
                                            <?= $statusText ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        // Tage-Anzeige mit Farbe: ab 30 Tage orange, ab 90 rot
                                        if ($tage >= 90) {
                                            echo '<span style="color:#dc3545;font-weight:600;">' . $tage . ' Tage</span>';
                                        } elseif ($tage >= 30) {
                                            echo '<span style="color:#fd7e14;font-weight:600;">' . $tage . ' Tage</span>';
                                        } else {
                                            echo $tage . ' Tage';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ((float)$parcel['total_fees'] > 0): ?>
                                            <strong><?= number_format((float)$parcel['total_fees'], 2, '.', "'") ?> CHF</strong>
                                        <?php else: ?>
                                            <span class="text-muted">–</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($typeLabels[$parcel['last_type']] ?? '–') ?>
                                    </td>
                                </tr>

                                <!-- Detailzeile (versteckt, wird per JS aufgeklappt) -->
                                <tr class="detail-row" id="detail-<?= $paketId ?>" style="display:none;">
                                    <td colspan="7">
                                        <div class="detail-inhalt">
                                            <div class="detail-spalten">

                                                <!-- Block 1: Paketinfos -->
                                                <div class="detail-block">
                                                    <h6><i class="fas fa-box"></i> Paketinfos</h6>
                                                    <ul>
                                                        <li>Eingang: <?= date('d.m.Y', strtotime($parcel['fld_parcels_receivedAt'])) ?></li>
                                                        <li>Im Lager seit: <strong><?= $tage ?> Tagen</strong></li>
                                                        <li>Lagergebühren total: <strong>
                                                            <?= (float)$parcel['total_fees'] > 0
                                                                ? number_format((float)$parcel['total_fees'], 2, '.', "'") . ' CHF'
                                                                : 'Keine' ?>
                                                        </strong></li>
                                                    </ul>
                                                </div>

                                                <!-- Block 2: Alle Benachrichtigungen -->
                                                <div class="detail-block">
                                                    <h6><i class="fas fa-bell"></i> Benachrichtigungen</h6>
                                                    <?php if (empty($logsDesPackets)): ?>
                                                        <span class="text-muted" style="font-size:0.85rem;">Keine Benachrichtigungen</span>
                                                    <?php else: ?>
                                                        <ul>
                                                        <?php
                                                        // Gleiche Benachrichtigungen (gleicher Zeitstempel) zusammenfassen
                                                        $gruppierteLogs = [];
                                                        foreach ($logsDesPackets as $log) {
                                                            // Auf Minute runden: E-Mail + WhatsApp desselben Versands
                                                            // werden zusammengefasst, auch wenn sentAt um Sekunden abweicht
                                                            $key = $log['fld_notification_log_type'] . '|' . date('Y-m-d H:i', strtotime($log['fld_notification_log_sentAt']));
                                                            if (!isset($gruppierteLogs[$key])) {
                                                                $gruppierteLogs[$key] = [
                                                                    'type'     => $log['fld_notification_log_type'],
                                                                    'sentAt'   => $log['fld_notification_log_sentAt'],
                                                                    'channels' => [],
                                                                ];
                                                            }
                                                            $gruppierteLogs[$key]['channels'][] = $log['fld_notification_log_channel'];
                                                        }
                                                        foreach ($gruppierteLogs as $eintrag):
                                                            $kanäle = implode(' & ', array_map(fn($ch) => $channelLabels[$ch] ?? $ch, array_unique($eintrag['channels'])));
                                                        ?>
                                                            <li>
                                                                <?= date('d.m.Y H:i', strtotime($eintrag['sentAt'])) ?>
                                                                – <?= htmlspecialchars($typeLabels[$eintrag['type']] ?? $eintrag['type']) ?>
                                                                <span class="text-muted">(<?= htmlspecialchars($kanäle) ?>)</span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>

                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </main>

<script>
// Zeile anklicken → Detailzeile aufklappen / zuklappen
document.querySelectorAll('.toggle-detail').forEach(function(zeile) {
    zeile.addEventListener('click', function() {
        var zielId  = this.getAttribute('data-ziel');
        var detail  = document.getElementById(zielId);
        var pfeil   = this.querySelector('.pfeil');

        if (detail.style.display === 'none') {
            detail.style.display = 'table-row';
            pfeil.classList.add('offen');
        } else {
            detail.style.display = 'none';
            pfeil.classList.remove('offen');
        }
    });
});
</script>

</body>

</html>
