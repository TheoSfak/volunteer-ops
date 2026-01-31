-- Add Missing Database Indexes for Performance
-- Run this to improve query performance significantly

-- participation_requests indexes
CREATE INDEX idx_pr_shift ON participation_requests(shift_id);
CREATE INDEX idx_pr_volunteer ON participation_requests(volunteer_id);
CREATE INDEX idx_pr_status ON participation_requests(status);
CREATE INDEX idx_pr_decided_by ON participation_requests(decided_by);

-- missions indexes
CREATE INDEX idx_missions_status ON missions(status);
CREATE INDEX idx_missions_department ON missions(department_id);
CREATE INDEX idx_missions_dates ON missions(start_datetime, end_datetime);
CREATE INDEX idx_missions_deleted ON missions(deleted_at);

-- shifts indexes
CREATE INDEX idx_shifts_mission ON shifts(mission_id);
CREATE INDEX idx_shifts_dates ON shifts(start_time, end_time);

-- users indexes
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_department ON users(department_id);
CREATE INDEX idx_users_active ON users(is_active);
CREATE INDEX idx_users_email ON users(email);

-- notifications indexes
CREATE INDEX idx_notifications_type ON notifications(type);
CREATE INDEX idx_notifications_created ON notifications(created_at);

-- audit_logs indexes
CREATE INDEX idx_audit_action ON audit_logs(action);
CREATE INDEX idx_audit_user ON audit_logs(user_id);
CREATE INDEX idx_audit_table ON audit_logs(table_name);
CREATE INDEX idx_audit_created ON audit_logs(created_at);

-- task_assignments indexes (if not already exists)
CREATE INDEX idx_task_assign_task ON task_assignments(task_id);

-- subtasks indexes
CREATE INDEX idx_subtasks_task ON subtasks(task_id);
CREATE INDEX idx_subtasks_completed ON subtasks(is_completed);

-- task_comments indexes
CREATE INDEX idx_task_comments_user ON task_comments(user_id);

-- mission_chat_messages indexes
CREATE INDEX idx_chat_mission ON mission_chat_messages(mission_id);
CREATE INDEX idx_chat_user ON mission_chat_messages(user_id);
CREATE INDEX idx_chat_created ON mission_chat_messages(created_at);
