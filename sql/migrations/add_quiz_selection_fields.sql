-- Add quiz selection fields to match exam functionality
-- This allows admins to configure:
-- 1. How many questions to randomly select from the pool
-- 2. What percentage is needed to pass
-- 3. Time limit (already exists)

ALTER TABLE `training_quizzes`
ADD COLUMN `questions_per_attempt` INT DEFAULT 10 AFTER `category_id`,
ADD COLUMN `passing_percentage` INT DEFAULT 70 AFTER `questions_per_attempt`;

-- Update existing quizzes with default values
UPDATE `training_quizzes` 
SET `questions_per_attempt` = 10, 
    `passing_percentage` = 70
WHERE `questions_per_attempt` IS NULL;

-- Add fields to quiz_attempts table to track selected questions and pass/fail
ALTER TABLE `quiz_attempts`
ADD COLUMN `selected_questions_json` TEXT NULL AFTER `user_id`,
ADD COLUMN `passing_percentage` INT NULL AFTER `time_taken_seconds`,
ADD COLUMN `passed` TINYINT(1) DEFAULT 0 AFTER `passing_percentage`;

-- Add quizzes_passed tracking to user progress
ALTER TABLE `training_user_progress`
ADD COLUMN `quizzes_passed` INT DEFAULT 0 AFTER `quizzes_completed`;
