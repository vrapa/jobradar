ALTER TABLE sources ADD purpose_tags_json JSON NULL;
ALTER TABLE source_search_definitions ADD steps_json JSON NULL;

CREATE TABLE search_run_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    search_run_source_id BIGINT UNSIGNED NOT NULL,
    step_key VARCHAR(64) NOT NULL,
    step_status VARCHAR(32) NOT NULL,
    displayed_count INT UNSIGNED NOT NULL DEFAULT 0,
    checkpoint_json JSON NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY run_step (search_run_source_id,step_key),
    FOREIGN KEY (search_run_source_id) REFERENCES search_run_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE search_step_opportunities (
    step_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (step_id,opportunity_id),
    FOREIGN KEY (step_id) REFERENCES search_run_steps(id),
    FOREIGN KEY (opportunity_id) REFERENCES opportunities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
