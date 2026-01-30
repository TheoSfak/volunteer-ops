-- Demo Data for VolunteerOps
-- Run this in phpMyAdmin or mysql command line

SET NAMES utf8mb4;

-- Insert shifts for each mission (2 shifts per mission)
INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at) VALUES
(1, '2026-02-01 09:00:00', '2026-02-01 12:00:00', 10, 3, NOW()),
(1, '2026-02-01 12:00:00', '2026-02-01 14:00:00', 8, 2, NOW()),
(2, '2026-02-03 10:00:00', '2026-02-03 13:00:00', 6, 2, NOW()),
(2, '2026-02-03 13:00:00', '2026-02-03 16:00:00', 6, 2, NOW()),
(3, '2026-02-05 08:00:00', '2026-02-05 12:00:00', 5, 2, NOW()),
(3, '2026-02-05 12:00:00', '2026-02-05 15:00:00', 5, 2, NOW()),
(4, '2026-02-07 09:00:00', '2026-02-07 13:00:00', 8, 3, NOW()),
(4, '2026-02-07 13:00:00', '2026-02-07 17:00:00', 8, 3, NOW()),
(5, '2026-02-08 08:00:00', '2026-02-08 11:00:00', 15, 5, NOW()),
(5, '2026-02-08 11:00:00', '2026-02-08 13:00:00', 10, 3, NOW()),
(6, '2026-02-10 09:00:00', '2026-02-10 12:00:00', 20, 8, NOW()),
(6, '2026-02-10 12:00:00', '2026-02-10 14:00:00', 15, 5, NOW()),
(7, '2026-02-12 17:00:00', '2026-02-12 20:00:00', 4, 2, NOW()),
(8, '2026-02-14 15:00:00', '2026-02-14 17:00:00', 6, 2, NOW()),
(8, '2026-02-14 17:00:00', '2026-02-14 19:00:00', 6, 2, NOW()),
(9, '2026-02-15 11:00:00', '2026-02-15 13:00:00', 10, 4, NOW()),
(9, '2026-02-15 13:00:00', '2026-02-15 15:00:00', 10, 4, NOW()),
(10, '2026-02-18 10:00:00', '2026-02-18 13:00:00', 8, 3, NOW());

-- Insert some participation requests
INSERT INTO participation_requests (shift_id, volunteer_id, status, notes, created_at) VALUES
(1, 2, 'APPROVED', NULL, NOW()),
(1, 3, 'APPROVED', NULL, NOW()),
(1, 4, 'PENDING', NULL, NOW()),
(2, 2, 'APPROVED', NULL, NOW()),
(2, 5, 'APPROVED', NULL, NOW()),
(3, 3, 'APPROVED', NULL, NOW()),
(3, 4, 'APPROVED', NULL, NOW()),
(5, 2, 'APPROVED', NULL, NOW()),
(5, 3, 'APPROVED', NULL, NOW()),
(5, 5, 'PENDING', NULL, NOW()),
(7, 4, 'APPROVED', NULL, NOW()),
(7, 5, 'APPROVED', NULL, NOW()),
(9, 2, 'PENDING', NULL, NOW()),
(9, 3, 'APPROVED', NULL, NOW());
