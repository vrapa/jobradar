CREATE TABLE tender_states (
    user_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    workflow_status VARCHAR(32) NOT NULL DEFAULT 'active',
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id, opportunity_id),
    KEY tender_states_status (user_id, workflow_status, updated_at),
    CONSTRAINT tender_states_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT tender_states_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
    CONSTRAINT tender_states_status_valid CHECK (workflow_status IN ('active', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tender_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    previous_status VARCHAR(32) NOT NULL,
    workflow_status VARCHAR(32) NOT NULL,
    previous_lock_version INT UNSIGNED NOT NULL,
    lock_version INT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(200) NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json JSON NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY tender_event_request (user_id, idempotency_key),
    KEY tender_event_history (user_id, opportunity_id, id),
    CONSTRAINT tender_events_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT tender_events_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
    CONSTRAINT tender_events_type_valid CHECK (event_type IN ('closed', 'reopened')),
    CONSTRAINT tender_events_previous_status_valid CHECK (previous_status IN ('active', 'closed')),
    CONSTRAINT tender_events_status_valid CHECK (workflow_status IN ('active', 'closed')),
    CONSTRAINT tender_events_actor_valid CHECK (actor_type IN ('user', 'assistant'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
