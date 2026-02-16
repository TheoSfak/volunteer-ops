-- VolunteerOps - Volunteer Type Migration
-- Adds volunteer_type column to users table
-- Types: VOLUNTEER (default), TRAINEE_RESCUER (Δόκιμος Διασώστης), RESCUER (Εθελοντής Διασώστης)

ALTER TABLE `users` 
ADD COLUMN `volunteer_type` ENUM('VOLUNTEER','TRAINEE_RESCUER','RESCUER') 
NOT NULL DEFAULT 'VOLUNTEER' 
AFTER `role`;

-- Add index for filtering
CREATE INDEX `idx_volunteer_type` ON `users` (`volunteer_type`);

SELECT 'Volunteer Type Migration Completed!' as Status;
