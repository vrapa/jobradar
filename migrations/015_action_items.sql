CREATE TABLE action_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NULL,
    action_type VARCHAR(32) NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    due_at DATETIME(6) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    origin VARCHAR(32) NOT NULL DEFAULT 'user',
    created_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    cancelled_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY action_items_user_status_due (user_id, status, due_at),
    KEY action_items_opportunity (opportunity_id, status, created_at),
    CONSTRAINT action_items_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT action_items_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT action_items_type_valid CHECK (action_type IN ('verify_terms', 'review_application', 'reply', 'follow_up', 'login', 'other')),
    CONSTRAINT action_items_status_valid CHECK (status IN ('open', 'completed', 'cancelled')),
    CONSTRAINT action_items_origin_valid CHECK (origin IN ('user', 'assistant', 'system'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE external_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action_item_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    external_url VARCHAR(2048) NULL,
    last_synced_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY external_tasks_action_provider_unique (action_item_id, provider),
    UNIQUE KEY external_tasks_provider_id_unique (provider, external_id),
    CONSTRAINT external_tasks_action_fk FOREIGN KEY (action_item_id) REFERENCES action_items (id),
    CONSTRAINT external_tasks_provider_valid CHECK (provider IN ('todoist'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
