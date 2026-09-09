CREATE TABLE application_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    previous_status VARCHAR(32) NOT NULL,
    workflow_status VARCHAR(32) NOT NULL,
    idempotency_key VARCHAR(200) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    payload_json JSON NOT NULL,
    result_json JSON NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY application_event_request (user_id, idempotency_key),
    KEY application_event_history (user_id, opportunity_id, id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (opportunity_id) REFERENCES opportunities(id),
    CHECK (event_type IN ('prepared', 'submitted', 'response_received', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE external_tasks ADD COLUMN synced_status VARCHAR(32) NOT NULL DEFAULT 'open';
