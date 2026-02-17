-- Add cohort_year column to users table for tracking trainee rescuer cohorts/classes
-- This allows grouping trainees by year for statistics and comparisons

ALTER TABLE users 
ADD COLUMN cohort_year YEAR NULL COMMENT 'Χρονιά σειράς δοκίμων διασωστών' AFTER volunteer_type;

-- Add index for performance when filtering by cohort year
CREATE INDEX idx_cohort_year ON users(cohort_year);

-- Note: Existing users will have NULL cohort_year - admins must update manually
-- New trainee rescuers should have cohort_year set when created
