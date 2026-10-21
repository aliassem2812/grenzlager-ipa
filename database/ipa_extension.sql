-- TABELLE: grzl_tbl_reminder_queue

CREATE TABLE grzl_tbl_reminder_queue (
 `fld_reminder_queue_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fld_reminder_queue_parcelsId` int(10) UNSIGNED NOT NULL,
  `fld_reminder_queue_sendAt` datetime NOT NULL,
  `fld_reminder_queue_type` enum('initial_reminder','followup_reminder','fee_reminder','escalation') NOT NULL,
  `fld_reminder_queue_status` enum('pending','processing','failed') NOT NULL DEFAULT 'pending',
  `fld_reminder_queue_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `fld_reminder_queue_lastError` text DEFAULT NULL,
  `fld_reminder_queue_createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `fld_reminder_queue_updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fld_reminder_queue_id`),
  KEY `idx_reminder_queue_parcel` (`fld_reminder_queue_parcelsId`),
  KEY `idx_reminder_queue_due` (`fld_reminder_queue_sendAt`, `fld_reminder_queue_status`),
  CONSTRAINT `fk_reminder_queue_parcel`
    FOREIGN KEY (`fld_reminder_queue_parcelsId`)
    REFERENCES `grzl_tbl_parcels` (`fld_parcels_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- TABELLE: grzl_tbl_notification_log

CREATE TABLE grzl_tbl_notification_log (
 `fld_notification_log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fld_notification_log_parcelsId` int(10) UNSIGNED NOT NULL,
 `fld_notification_log_channel` enum('email','whatsapp') NOT NULL,
 `fld_notification_log_type` enum('arrival','initial_reminder','followup_reminder','fee_reminder','escalation') NOT NULL,
 `fld_notification_log_recipient` varchar(255) DEFAULT NULL,
  `fld_notification_log_subject` varchar(255) DEFAULT NULL,
  `fld_notification_log_message` text DEFAULT NULL,
  `fld_notification_log_status` enum('success','failed') NOT NULL,
  `fld_notification_log_error` text DEFAULT NULL,
  `fld_notification_log_sentAt` datetime NOT NULL DEFAULT current_timestamp(),
  `fld_notification_log_createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`fld_notification_log_id`),
  KEY `idx_notification_log_parcel` (`fld_notification_log_parcelsId`),
  KEY `idx_notification_log_sentAt` (`fld_notification_log_sentAt`),
  KEY `idx_notification_log_channel` (`fld_notification_log_channel`),
  CONSTRAINT `fk_notification_log_parcel`
    FOREIGN KEY (`fld_notification_log_parcelsId`)
    REFERENCES `grzl_tbl_parcels` (`fld_parcels_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- TABELLE: grzl_tbl_storage_fees

 CREATE TABLE grzl_tbl_storage_fees (
  `fld_storage_fees_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `fld_storage_fees_parcelsId` int(10) UNSIGNED NOT NULL,
  `fld_storage_fees_amount` decimal(10,2) NOT NULL,
  `fld_storage_fees_currency` varchar(3) NOT NULL DEFAULT 'CHF',
  `fld_storage_fees_type` enum('storage_fee','surcharge') NOT NULL DEFAULT 'storage_fee',
  `fld_storage_fees_daysCounted` int(10) UNSIGNED NOT NULL,
  `fld_storage_fees_reason` varchar(255) DEFAULT NULL,
  `fld_storage_fees_createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`fld_storage_fees_id`),
  KEY `idx_storage_fees_parcel` (`fld_storage_fees_parcelsId`),
  KEY `idx_storage_fees_createdAt` (`fld_storage_fees_createdAt`),
  CONSTRAINT `fk_storage_fees_parcel`
    FOREIGN KEY (`fld_storage_fees_parcelsId`)
    REFERENCES `grzl_tbl_parcels` (`fld_parcels_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
