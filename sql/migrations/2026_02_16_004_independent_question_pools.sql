-- =============================================
-- Migration: Fix question pools to be independent
-- =============================================
-- When an exam/quiz is deleted, questions should remain in the pool as orphans
-- This allows reusing questions across multiple exams/quizzes

-- Drop existing foreign keys
ALTER TABLE `training_quiz_questions` 
    DROP FOREIGN KEY `training_quiz_questions_ibfk_1`;

ALTER TABLE `training_exam_questions` 
    DROP FOREIGN KEY `training_exam_questions_ibfk_1`;

-- Modify columns to allow NULL
ALTER TABLE `training_quiz_questions` 
    MODIFY COLUMN `quiz_id` INT UNSIGNED NULL COMMENT 'Nullable - questions remain in pool when quiz is deleted';

ALTER TABLE `training_exam_questions` 
    MODIFY COLUMN `exam_id` INT UNSIGNED NULL COMMENT 'Nullable - questions remain in pool when exam is deleted';

-- Add foreign keys back with ON DELETE SET NULL
ALTER TABLE `training_quiz_questions` 
    ADD CONSTRAINT `training_quiz_questions_ibfk_1` 
    FOREIGN KEY (`quiz_id`) REFERENCES `training_quizzes`(`id`) ON DELETE SET NULL;

ALTER TABLE `training_exam_questions` 
    ADD CONSTRAINT `training_exam_questions_ibfk_1` 
    FOREIGN KEY (`exam_id`) REFERENCES `training_exams`(`id`) ON DELETE SET NULL;
