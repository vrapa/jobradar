ALTER TABLE sources ADD lock_version INT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE opportunity_project_care (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NOT NULL,
    value TINYINT(1) NULL,
    reason TEXT NULL,
    confidence DECIMAL(4,3) NULL,
    verified_at DATETIME(6) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    KEY project_care_history (opportunity_id, id),
    FOREIGN KEY (opportunity_id) REFERENCES opportunities(id),
    FOREIGN KEY (source_version_id) REFERENCES source_versions(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    CHECK (value IS NULL OR (value IN (0,1) AND reason IS NOT NULL AND confidence BETWEEN 0 AND 1 AND verified_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE opportunity_discoveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    search_definition_id BIGINT UNSIGNED NOT NULL,
    discovered_at DATETIME(6) NOT NULL,
    UNIQUE KEY discovery_unique (opportunity_id, search_definition_id),
    FOREIGN KEY (opportunity_id) REFERENCES opportunities(id),
    FOREIGN KEY (search_definition_id) REFERENCES source_search_definitions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

