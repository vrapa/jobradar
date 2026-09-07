CREATE TABLE source_search_definitions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    version INT UNSIGNED NOT NULL,
    query_text TEXT NULL,
    filters_json JSON NULL,
    pagination_strategy VARCHAR(100) NULL,
    result_limit INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY source_search_definitions_version_unique (source_id, name, version),
    KEY source_search_definitions_active (source_id, active),
    CONSTRAINT source_search_definitions_source_fk FOREIGN KEY (source_id) REFERENCES sources (id),
    CONSTRAINT source_search_definitions_version_positive CHECK (version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE source_access_states (
    source_id BIGINT UNSIGNED NOT NULL,
    access_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
    verified_at DATETIME(6) NULL,
    verification_origin VARCHAR(100) NULL,
    login_url VARCHAR(2048) NULL,
    browser_profile_label VARCHAR(255) NULL,
    safe_instructions TEXT NULL,
    intervention_required TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (source_id),
    CONSTRAINT source_access_states_source_fk FOREIGN KEY (source_id) REFERENCES sources (id),
    CONSTRAINT source_access_states_status_valid CHECK (access_status IN ('unknown', 'available', 'login_required', 'blocked', 'error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE runner_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_identifier CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(255) NOT NULL,
    runner_version VARCHAR(100) NULL,
    device_status VARCHAR(32) NOT NULL DEFAULT 'inactive',
    last_seen_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY runner_devices_public_identifier_unique (public_identifier),
    KEY runner_devices_status_seen (device_status, last_seen_at),
    CONSTRAINT runner_devices_status_valid CHECK (device_status IN ('inactive', 'online', 'offline', 'revoked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE search_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    requested_by_user_id BIGINT UNSIGNED NOT NULL,
    request_status VARCHAR(32) NOT NULL DEFAULT 'waiting_for_runner',
    idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    runner_device_id BIGINT UNSIGNED NULL,
    lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_expires_at DATETIME(6) NULL,
    cancelled_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY search_requests_user_idempotency_unique (requested_by_user_id, idempotency_key_hash),
    KEY search_requests_status_requested (request_status, requested_at),
    CONSTRAINT search_requests_user_fk FOREIGN KEY (requested_by_user_id) REFERENCES users (id),
    CONSTRAINT search_requests_runner_fk FOREIGN KEY (runner_device_id) REFERENCES runner_devices (id) ON DELETE SET NULL,
    CONSTRAINT search_requests_status_valid CHECK (request_status IN ('waiting_for_runner', 'checking_access', 'running', 'waiting_for_login', 'resume_requested', 'complete', 'partial', 'cancelled', 'error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE search_request_sources (
    search_request_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    search_definition_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (search_request_id, source_id),
    CONSTRAINT search_request_sources_request_fk FOREIGN KEY (search_request_id) REFERENCES search_requests (id),
    CONSTRAINT search_request_sources_source_fk FOREIGN KEY (source_id) REFERENCES sources (id),
    CONSTRAINT search_request_sources_definition_fk FOREIGN KEY (search_definition_id) REFERENCES source_search_definitions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE search_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    search_request_id BIGINT UNSIGNED NOT NULL,
    runner_device_id BIGINT UNSIGNED NULL,
    candidate_profile_id BIGINT UNSIGNED NULL,
    scoring_rule_set_id BIGINT UNSIGNED NULL,
    run_status VARCHAR(32) NOT NULL DEFAULT 'running',
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    completion_reason TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY search_runs_request_unique (search_request_id),
    KEY search_runs_status_started (run_status, started_at),
    CONSTRAINT search_runs_request_fk FOREIGN KEY (search_request_id) REFERENCES search_requests (id),
    CONSTRAINT search_runs_runner_fk FOREIGN KEY (runner_device_id) REFERENCES runner_devices (id) ON DELETE SET NULL,
    CONSTRAINT search_runs_profile_fk FOREIGN KEY (candidate_profile_id) REFERENCES candidate_profiles (id) ON DELETE SET NULL,
    CONSTRAINT search_runs_rules_fk FOREIGN KEY (scoring_rule_set_id) REFERENCES scoring_rule_sets (id) ON DELETE SET NULL,
    CONSTRAINT search_runs_status_valid CHECK (run_status IN ('running', 'complete', 'partial', 'cancelled', 'error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE search_run_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    search_run_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    source_status VARCHAR(32) NOT NULL DEFAULT 'planned',
    query_text TEXT NULL,
    filters_json JSON NULL,
    pages_traversed INT UNSIGNED NULL,
    horizon_from DATETIME(6) NULL,
    horizon_to DATETIME(6) NULL,
    displayed_count INT UNSIGNED NULL,
    detail_opened_count INT UNSIGNED NULL,
    stored_count INT UNSIGNED NULL,
    updated_count INT UNSIGNED NULL,
    duplicate_count INT UNSIGNED NULL,
    rejected_count INT UNSIGNED NULL,
    started_at DATETIME(6) NULL,
    finished_at DATETIME(6) NULL,
    incomplete_reason TEXT NULL,
    error_code VARCHAR(100) NULL,
    login_required TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY search_run_sources_run_source_unique (search_run_id, source_id),
    KEY search_run_sources_status (search_run_id, source_status),
    CONSTRAINT search_run_sources_run_fk FOREIGN KEY (search_run_id) REFERENCES search_runs (id),
    CONSTRAINT search_run_sources_source_fk FOREIGN KEY (source_id) REFERENCES sources (id),
    CONSTRAINT search_run_sources_status_valid CHECK (source_status IN ('planned', 'running', 'complete', 'partial', 'waiting_for_login', 'error', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE search_run_opportunities (
    search_run_id BIGINT UNSIGNED NOT NULL,
    search_run_source_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    processing_result VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (search_run_id, search_run_source_id, opportunity_id),
    KEY search_run_opportunities_opportunity (opportunity_id),
    CONSTRAINT search_run_opportunities_run_fk FOREIGN KEY (search_run_id) REFERENCES search_runs (id),
    CONSTRAINT search_run_opportunities_source_fk FOREIGN KEY (search_run_source_id) REFERENCES search_run_sources (id),
    CONSTRAINT search_run_opportunities_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT search_run_opportunities_result_valid CHECK (processing_result IN ('created', 'updated', 'duplicate', 'rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
