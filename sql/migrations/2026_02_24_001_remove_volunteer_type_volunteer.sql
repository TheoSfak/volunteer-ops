-- Migration: 2026_02_24_001_remove_volunteer_type_volunteer
-- Description: Removes the legacy 'VOLUNTEER' volunteer_type value.
--              All existing VOLUNTEER users become RESCUER (Εθελοντής Διασώστης).
--              Only 2 types remain: TRAINEE_RESCUER and RESCUER.

-- Step 1: Migrate all existing VOLUNTEER users to RESCUER
UPDATE `users` SET `volunteer_type` = 'RESCUER' WHERE `volunteer_type` = 'VOLUNTEER';

-- Step 2: Remove VOLUNTEER from the ENUM and change default to RESCUER
ALTER TABLE `users`
    MODIFY COLUMN `volunteer_type` ENUM('TRAINEE_RESCUER','RESCUER') NOT NULL DEFAULT 'RESCUER';
