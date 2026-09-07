CREATE TABLE companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    normalized_name VARCHAR(255) NOT NULL,
    country_code CHAR(2) NULL,
    website_url VARCHAR(2048) NULL,
    size_label VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY companies_normalized_name (normalized_name),
    KEY companies_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    market_code VARCHAR(32) NULL,
    source_type VARCHAR(32) NOT NULL,
    priority CHAR(1) NOT NULL,
    recommended_frequency_hours SMALLINT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    access_requirement VARCHAR(32) NOT NULL DEFAULT 'unknown',
    adapter_capabilities JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY sources_name_unique (name),
    KEY sources_priority_active (priority, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE opportunities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_type VARCHAR(32) NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    canonical_url VARCHAR(2048) NOT NULL,
    canonical_url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    validity_status VARCHAR(32) NOT NULL DEFAULT 'unknown',
    found_at DATETIME(6) NOT NULL,
    published_at DATETIME(6) NULL,
    last_verified_at DATETIME(6) NULL,
    current_source_version_id BIGINT UNSIGNED NULL,
    lock_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY opportunities_canonical_url_hash_unique (canonical_url_hash),
    KEY opportunities_company (company_id),
    KEY opportunities_validity_found (validity_status, found_at),
    CONSTRAINT opportunities_company_fk FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
    CONSTRAINT opportunities_lock_version_positive CHECK (lock_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE opportunity_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    external_id VARCHAR(255) NULL,
    url VARCHAR(2048) NOT NULL,
    normalized_url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY opportunity_sources_external_unique (source_id, external_id),
    UNIQUE KEY opportunity_sources_url_unique (source_id, normalized_url_hash),
    KEY opportunity_sources_opportunity (opportunity_id),
    CONSTRAINT opportunity_sources_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT opportunity_sources_source_fk FOREIGN KEY (source_id) REFERENCES sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE source_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    acquired_at DATETIME(6) NOT NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_language VARCHAR(16) NULL,
    original_title VARCHAR(500) NOT NULL,
    translated_title VARCHAR(500) NULL,
    original_text LONGTEXT NULL,
    translated_text LONGTEXT NULL,
    summary TEXT NULL,
    translation_status VARCHAR(32) NOT NULL DEFAULT 'not_requested',
    translation_method VARCHAR(100) NULL,
    incomplete TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY source_versions_content_unique (opportunity_id, content_hash),
    KEY source_versions_acquired (opportunity_id, acquired_at),
    CONSTRAINT source_versions_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE opportunities
    ADD CONSTRAINT opportunities_current_version_fk
    FOREIGN KEY (current_source_version_id) REFERENCES source_versions (id) ON DELETE SET NULL;

CREATE TABLE opportunity_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NOT NULL,
    rate_min DECIMAL(14,2) NULL,
    rate_max DECIMAL(14,2) NULL,
    currency CHAR(3) NULL,
    rate_unit VARCHAR(32) NULL,
    engagement_mode VARCHAR(64) NULL,
    rate_source VARCHAR(255) NULL,
    rate_confidence DECIMAL(4,3) NULL,
    exchange_rate DECIMAL(18,8) NULL,
    exchange_rate_date DATE NULL,
    czk_hourly_min DECIMAL(14,2) NULL,
    czk_hourly_max DECIMAL(14,2) NULL,
    workload_min DECIMAL(10,2) NULL,
    workload_max DECIMAL(10,2) NULL,
    workload_unit VARCHAR(32) NULL,
    duration_text VARCHAR(255) NULL,
    start_date DATE NULL,
    remote_mode VARCHAR(64) NULL,
    work_from_czechia VARCHAR(16) NULL,
    location VARCHAR(255) NULL,
    work_timezone VARCHAR(64) NULL,
    working_language VARCHAR(64) NULL,
    communication_mode VARCHAR(100) NULL,
    verified_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY opportunity_terms_version_unique (source_version_id),
    KEY opportunity_terms_opportunity (opportunity_id),
    CONSTRAINT opportunity_terms_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT opportunity_terms_version_fk FOREIGN KEY (source_version_id) REFERENCES source_versions (id),
    CONSTRAINT opportunity_terms_rate_order CHECK (rate_min IS NULL OR rate_max IS NULL OR rate_min <= rate_max),
    CONSTRAINT opportunity_terms_rate_confidence CHECK (rate_confidence IS NULL OR (rate_confidence >= 0 AND rate_confidence <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE technology_requirements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NOT NULL,
    technology_name VARCHAR(120) NOT NULL,
    normalized_name VARCHAR(120) NOT NULL,
    requirement_level VARCHAR(32) NOT NULL DEFAULT 'unknown',
    proven_experience TINYINT(1) NULL,
    scoring_relevance VARCHAR(32) NULL,
    evidence TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY technology_requirements_version_name_unique (source_version_id, normalized_name),
    KEY technology_requirements_opportunity (opportunity_id),
    CONSTRAINT technology_requirements_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT technology_requirements_version_fk FOREIGN KEY (source_version_id) REFERENCES source_versions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE opportunity_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NULL,
    question TEXT NOT NULL,
    question_status VARCHAR(32) NOT NULL DEFAULT 'open',
    answer TEXT NULL,
    answer_source VARCHAR(255) NULL,
    verified_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY opportunity_questions_status (opportunity_id, question_status),
    CONSTRAINT opportunity_questions_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT opportunity_questions_version_fk FOREIGN KEY (source_version_id) REFERENCES source_versions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE duplicate_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    left_opportunity_id BIGINT UNSIGNED NOT NULL,
    right_opportunity_id BIGINT UNSIGNED NOT NULL,
    similarity DECIMAL(4,3) NULL,
    reason TEXT NOT NULL,
    review_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY duplicate_candidates_pair_unique (left_opportunity_id, right_opportunity_id),
    KEY duplicate_candidates_status (review_status),
    CONSTRAINT duplicate_candidates_left_fk FOREIGN KEY (left_opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT duplicate_candidates_right_fk FOREIGN KEY (right_opportunity_id) REFERENCES opportunities (id),
    CONSTRAINT duplicate_candidates_distinct CHECK (left_opportunity_id < right_opportunity_id),
    CONSTRAINT duplicate_candidates_similarity CHECK (similarity IS NULL OR (similarity >= 0 AND similarity <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
