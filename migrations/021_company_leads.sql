ALTER TABLE opportunities
    MODIFY opportunity_type VARCHAR(32) NOT NULL DEFAULT 'offer',
    ADD CONSTRAINT opportunities_type_valid CHECK (opportunity_type IN ('offer', 'company_lead', 'tender'));

ALTER TABLE action_items DROP CHECK action_items_type_valid;
ALTER TABLE action_items ADD CONSTRAINT action_items_type_valid CHECK (action_type IN ('prepare_applications', 'prepare_outreach', 'review_opportunities', 'verify_attachment', 'verify_terms', 'review_application', 'reply', 'follow_up', 'login', 'other'));

CREATE TABLE company_lead_details (
    opportunity_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    contact_name VARCHAR(255) NULL,
    contact_role VARCHAR(255) NULL,
    channel VARCHAR(100) NULL,
    profile_url VARCHAR(2048) NULL,
    outreach_context TEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CONSTRAINT company_lead_details_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE company_lead_states (
    user_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    workflow_status VARCHAR(32) NOT NULL DEFAULT 'new',
    lock_version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id, opportunity_id),
    KEY company_lead_states_status (user_id, workflow_status, updated_at),
    CONSTRAINT company_lead_states_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT company_lead_states_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
    CONSTRAINT company_lead_states_status_valid CHECK (workflow_status IN ('new', 'awaiting_approval', 'awaiting_response', 'response_received', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE company_lead_events (
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
    UNIQUE KEY company_lead_event_request (user_id, idempotency_key),
    KEY company_lead_event_history (user_id, opportunity_id, id),
    CONSTRAINT company_lead_events_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT company_lead_events_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
    CONSTRAINT company_lead_events_type_valid CHECK (event_type IN ('prepared', 'contacted', 'response_received', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE opportunity_type_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    previous_type VARCHAR(32) NOT NULL,
    new_type VARCHAR(32) NOT NULL,
    previous_lock_version INT UNSIGNED NOT NULL,
    new_lock_version INT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    KEY opportunity_type_history_opportunity (opportunity_id, id),
    CONSTRAINT opportunity_type_history_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
    CONSTRAINT opportunity_type_history_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
