-- Migration: Add personal data fields to users table
-- Ταυτότητα, ΑΜΚΑ, Άδεια Οδήγησης, Αριθμός Κυκλοφορίας, Μεγέθη Στολής, Μητρώα

ALTER TABLE users ADD COLUMN IF NOT EXISTS id_card VARCHAR(20) NULL AFTER phone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS amka VARCHAR(11) NULL AFTER id_card;
ALTER TABLE users ADD COLUMN IF NOT EXISTS driving_license VARCHAR(30) NULL AFTER amka;
ALTER TABLE users ADD COLUMN IF NOT EXISTS vehicle_plate VARCHAR(20) NULL AFTER driving_license;
ALTER TABLE users ADD COLUMN IF NOT EXISTS pants_size VARCHAR(10) NULL AFTER vehicle_plate;
ALTER TABLE users ADD COLUMN IF NOT EXISTS shirt_size VARCHAR(10) NULL AFTER pants_size;
ALTER TABLE users ADD COLUMN IF NOT EXISTS blouse_size VARCHAR(10) NULL AFTER shirt_size;
ALTER TABLE users ADD COLUMN IF NOT EXISTS fleece_size VARCHAR(10) NULL AFTER blouse_size;
ALTER TABLE users ADD COLUMN IF NOT EXISTS registry_epidrasis VARCHAR(50) NULL AFTER fleece_size;
ALTER TABLE users ADD COLUMN IF NOT EXISTS registry_ggpp VARCHAR(50) NULL AFTER registry_epidrasis;
