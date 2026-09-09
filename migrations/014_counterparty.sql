CREATE TABLE opportunity_counterparty (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NOT NULL,
    value VARCHAR(32) NULL,
    reason TEXT NULL,
    confidence DECIMAL(4,3) NULL,
    verified_at DATETIME(6) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    KEY counterparty_history (opportunity_id,id),
    FOREIGN KEY (opportunity_id) REFERENCES opportunities(id),
    FOREIGN KEY (source_version_id) REFERENCES source_versions(id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    CHECK (value IS NULL OR (value IN ('owner','supplier','recruiter') AND reason IS NOT NULL AND confidence IS NOT NULL AND confidence BETWEEN 0 AND 1 AND verified_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
