-- =============================================
-- Migration: Add category_id to question tables
-- =============================================
-- Questions must always belong to a category
-- This allows better organization and filtering of question pools

-- =============================================
-- STEP 1: Add category_id columns (nullable initially)
-- =============================================

-- Add to quiz questions
ALTER TABLE `training_quiz_questions` 
    ADD COLUMN `category_id` INT UNSIGNED NULL COMMENT 'Required - questions always belong to a category' 
    AFTER `id`;

-- Add to exam questions
ALTER TABLE `training_exam_questions` 
    ADD COLUMN `category_id` INT UNSIGNED NULL COMMENT 'Required - questions always belong to a category' 
    AFTER `id`;

-- =============================================
-- STEP 2: Populate category_id from existing quiz/exam
-- =============================================

-- Update quiz questions to inherit category from their quiz
UPDATE `training_quiz_questions` tqq
INNER JOIN `training_quizzes` tq ON tqq.quiz_id = tq.id
SET tqq.category_id = tq.category_id
WHERE tqq.category_id IS NULL;

-- Update exam questions to inherit category from their exam
UPDATE `training_exam_questions` teq
INNER JOIN `training_exams` te ON teq.exam_id = te.id
SET teq.category_id = te.category_id
WHERE teq.category_id IS NULL;

-- For orphan questions (no quiz/exam), assign to first available category
UPDATE `training_quiz_questions` 
SET category_id = (SELECT MIN(id) FROM `training_categories` WHERE is_active = 1)
WHERE category_id IS NULL;

UPDATE `training_exam_questions` 
SET category_id = (SELECT MIN(id) FROM `training_categories` WHERE is_active = 1)
WHERE category_id IS NULL;

-- =============================================
-- STEP 3: Make category_id required (NOT NULL)
-- =============================================

ALTER TABLE `training_quiz_questions` 
    MODIFY COLUMN `category_id` INT UNSIGNED NOT NULL COMMENT 'Required - questions always belong to a category';

ALTER TABLE `training_exam_questions` 
    MODIFY COLUMN `category_id` INT UNSIGNED NOT NULL COMMENT 'Required - questions always belong to a category';

-- =============================================
-- STEP 4: Add foreign keys and indexes
-- =============================================

-- Add foreign key for quiz questions
ALTER TABLE `training_quiz_questions` 
    ADD CONSTRAINT `training_quiz_questions_category_fk` 
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE;

-- Add index for quiz questions
ALTER TABLE `training_quiz_questions` 
    ADD INDEX `idx_quiz_questions_category` (`category_id`);

-- Add foreign key for exam questions
ALTER TABLE `training_exam_questions` 
    ADD CONSTRAINT `training_exam_questions_category_fk` 
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE;

-- Add index for exam questions
ALTER TABLE `training_exam_questions` 
    ADD INDEX `idx_exam_questions_category` (`category_id`);
