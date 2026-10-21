# Test Plan

Time-based logic can't be tested by waiting 10, 30 or 90 days. Instead, the arrival date of a test parcel is **backdated in the database**, and the cron job (`cron_reminder.php`) is then run manually or by the scheduler.

## Test cases

| ID | Area | Scenario | Expected result | Result |
|---|---|---|---|---|
| T-01 | Cron job | Due reminders are processed automatically | Runs without errors | ✅ Passed |
| T-02 | Reminders | Parcel older than 10 days | First reminder is queued | ✅ Passed |
| T-03 | Reminders | After a reminder was sent | Follow-up queued 7 days later | ✅ Passed |
| T-04 | Email | Reminder sent via SMTP | Email arrives in inbox | ✅ Passed |
| T-05 | WhatsApp | Reminder sent via Twilio sandbox | Message received on phone | ✅ Passed |
| T-06 | Fees | Parcel older than 30 days | Storage fee calculated and stored | ✅ Passed |
| T-07 | Fees | Cron runs again on the same day | No duplicate fee | ✅ Passed |
| T-08 | Escalation | Parcel older than 90 days | Status set to 900 (disposal) | ✅ Passed |
| T-09 | Escalation | Escalation performed | Entry in status history | ✅ Passed |
| T-10 | Logging | Any message sent | Entry in notification log | ✅ Passed |
| T-11 | Duplicates | Cron runs repeatedly | No reminder sent twice | ✅ Passed |
| T-12 | Dashboard | Parcel with fees and messages | Fees and history shown correctly | ✅ Passed |

## Example: first reminder (T-02)

```sql
-- Make the parcel 11 days old
UPDATE grzl_tbl_parcels
SET fld_parcels_receivedAt = DATE_SUB(NOW(), INTERVAL 11 DAY)
WHERE fld_parcels_id = :id;

-- Make the queued reminder due now
UPDATE grzl_tbl_reminder_queue
SET fld_reminder_queue_sendAt = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
WHERE fld_reminder_queue_parcelsId = :id
  AND fld_reminder_queue_status = 'pending';
```

Run the cron job. Expected: email + WhatsApp sent, two rows in `grzl_tbl_notification_log`, and a new `followup_reminder` in the queue with `sendAt = now + 7 days`.

## Example: duplicate protection (T-07, T-11)

Run the cron job a second time without changing any data. Expected output:

```
Reminder existiert bereits für Paket ID X
Keine fälligen Reminder vorhanden.
Gebühr existiert bereits für Paket ID X
```

No new message is sent, and no new fee or log row is created.

## Example: escalation (T-08 – T-10)

```sql
UPDATE grzl_tbl_parcels
SET fld_parcels_receivedAt = DATE_SUB(NOW(), INTERVAL 91 DAY)
WHERE fld_parcels_id = :id;
```

Expected: status changes 200 → 900, an entry in `grzl_tbl_parcelStatusLog`, an escalation email and WhatsApp, and a red "disposal" badge on the dashboard. A second run reports `Keine Eskalation notwendig` (no escalation needed).
