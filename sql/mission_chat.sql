CREATE TABLE mission_chat_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mission_created (mission_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
