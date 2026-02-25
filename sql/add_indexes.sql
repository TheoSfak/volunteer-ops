-- Add Missing Database Indexes for Performance
-- Run this to improve query performance significantly
-- Last updated: 2026-02-25 — added composite indexes

-- ─── participation_requests ───────────────────────────────────────────────────
CREATE INDEX idx_pr_shift ON participation_requests(shift_id);
CREATE INDEX idx_pr_volunteer ON participation_requests(volunteer_id);
CREATE INDEX idx_pr_status ON participation_requests(status);
CREATE INDEX idx_pr_decided_by ON participation_requests(decided_by);
-- Composite: shift_id+status (shift-view.php lists participants by status)
CREATE INDEX idx_pr_shift_status ON participation_requests(shift_id, status);
-- Composite: volunteer_id+status (my-participations.php)
CREATE INDEX idx_pr_vol_status ON participation_requests(volunteer_id, status);

-- ─── missions ─────────────────────────────────────────────────────────────────
CREATE INDEX idx_missions_status ON missions(status);
CREATE INDEX idx_missions_department ON missions(department_id);
CREATE INDEX idx_missions_dates ON missions(start_datetime, end_datetime);
CREATE INDEX idx_missions_deleted ON missions(deleted_at);
-- Composite: status+department_id (admin listing with dept filter)
CREATE INDEX idx_missions_status_dept ON missions(status, department_id);
-- Composite: status+start_datetime (dashboard active missions, date-sorted)
CREATE INDEX idx_missions_status_start ON missions(status, start_datetime);

-- ─── shifts ───────────────────────────────────────────────────────────────────
CREATE INDEX idx_shifts_mission ON shifts(mission_id);
CREATE INDEX idx_shifts_dates ON shifts(start_time, end_time);
-- Composite: mission_id+start_time (mission-view.php orders shifts by time)
CREATE INDEX idx_shifts_mission_time ON shifts(mission_id, start_time);

-- ─── users ────────────────────────────────────────────────────────────────────
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_department ON users(department_id);
CREATE INDEX idx_users_active ON users(is_active);
CREATE INDEX idx_users_email ON users(email);
-- Composite: role+is_active (admin volunteer listings)
CREATE INDEX idx_users_role_active ON users(role, is_active);
-- Composite: department_id+role (department member lookups)
CREATE INDEX idx_users_dept_role ON users(department_id, role);

-- ─── notifications ────────────────────────────────────────────────────────────
CREATE INDEX idx_notifications_type ON notifications(type);
CREATE INDEX idx_notifications_created ON notifications(created_at);
-- Composite: user_id+created_at (notification history sorted by date)
CREATE INDEX idx_notifications_user_created ON notifications(user_id, created_at);

-- ─── volunteer_points ─────────────────────────────────────────────────────────
-- Composite: user_id+created_at (leaderboard with date range filter)
CREATE INDEX idx_points_user_date ON volunteer_points(user_id, created_at);
-- Composite: user_id+reason (points breakdown by type)
CREATE INDEX idx_points_user_reason ON volunteer_points(user_id, reason);

-- ─── audit_logs ───────────────────────────────────────────────────────────────
CREATE INDEX idx_audit_action ON audit_logs(action);
CREATE INDEX idx_audit_user ON audit_logs(user_id);
CREATE INDEX idx_audit_table ON audit_logs(table_name);
CREATE INDEX idx_audit_created ON audit_logs(created_at);
-- Composite: user_id+created_at (audit viewer filtered by user)
CREATE INDEX idx_audit_user_created ON audit_logs(user_id, created_at);
-- Composite: table_name+record_id+created_at (full record history)
CREATE INDEX idx_audit_table_rec_date ON audit_logs(table_name, record_id, created_at);

-- ─── tasks ────────────────────────────────────────────────────────────────────
-- Composite: priority+status (task list filtered by urgency+state)
CREATE INDEX idx_tasks_priority_status ON tasks(priority, status);
-- Composite: status+deadline (overdue task queries)
CREATE INDEX idx_tasks_status_deadline ON tasks(status, deadline);

-- ─── task_assignments ─────────────────────────────────────────────────────────
CREATE INDEX idx_task_assign_task ON task_assignments(task_id);

-- ─── subtasks ─────────────────────────────────────────────────────────────────
CREATE INDEX idx_subtasks_task ON subtasks(task_id);
CREATE INDEX idx_subtasks_completed ON subtasks(is_completed);

-- ─── task_comments ────────────────────────────────────────────────────────────
CREATE INDEX idx_task_comments_user ON task_comments(user_id);

-- ─── mission_chat_messages ────────────────────────────────────────────────────
CREATE INDEX idx_chat_mission ON mission_chat_messages(mission_id);
CREATE INDEX idx_chat_user ON mission_chat_messages(user_id);
CREATE INDEX idx_chat_created ON mission_chat_messages(created_at);

-- ─── inventory_bookings ───────────────────────────────────────────────────────
-- Composite: user_id+status (bookings per user filtered by state)
CREATE INDEX idx_inv_book_user_status ON inventory_bookings(user_id, status);

-- ─── inventory_items ──────────────────────────────────────────────────────────
-- Composite: department_id+is_active+status (warehouse/shelf listing)
CREATE INDEX idx_inv_items_dept_active_status ON inventory_items(department_id, is_active, status);
