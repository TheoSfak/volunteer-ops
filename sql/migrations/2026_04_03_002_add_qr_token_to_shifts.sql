-- Migration: Add qr_token column to shifts table for QR check-in feature
ALTER TABLE `shifts`
    ADD COLUMN `qr_token` VARCHAR(64) NULL AFTER `notes`,
    ADD UNIQUE KEY `uq_shift_qr_token` (`qr_token`);
