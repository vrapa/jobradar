CREATE TABLE opportunity_project_kinds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NOT NULL,
    values_json JSON NULL,
    reason TEXT NULL,
    confidence DECIMAL(4,3) NULL,
    verified_at DATETIME(6) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    KEY project_kind_history (opportunity_id, id),
    FOREIGN KEY (opportunity_id) REFERENCES opportunities(id),
    FOREIGN KEY (source_version_id) REFERENCES source_versions(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    CHECK (values_json IS NULL OR (
        JSON_TYPE(values_json) = 'ARRAY'
        AND JSON_LENGTH(values_json) BETWEEN 1 AND 3
        AND reason IS NOT NULL
        AND confidence IS NOT NULL AND confidence BETWEEN 0 AND 1
        AND verified_at IS NOT NULL
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
