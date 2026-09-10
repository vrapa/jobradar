ALTER TABLE action_items DROP CHECK action_items_type_valid;
ALTER TABLE action_items ADD CONSTRAINT action_items_type_valid CHECK (action_type IN ('verify_attachment', 'verify_terms', 'review_application', 'reply', 'follow_up', 'login', 'other'));

CREATE TABLE attachment_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    source_version_id BIGINT UNSIGNED NOT NULL,
    attachment_key VARCHAR(160) COLLATE utf8mb4_bin NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    reason TEXT NOT NULL,
    questions TEXT NOT NULL,
    findings TEXT NOT NULL,
    evidence TEXT NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    action_item_id BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY attachment_identity (user_id, opportunity_id, attachment_key),
    CONSTRAINT attachment_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT attachment_opportunity_fk FOREIGN KEY (opportunity_id) REFERENCES opportunities(id),
    CONSTRAINT attachment_version_fk FOREIGN KEY (source_version_id) REFERENCES source_versions(id),
    CONSTRAINT attachment_action_fk FOREIGN KEY (action_item_id) REFERENCES action_items(id),
    CONSTRAINT attachment_status_valid CHECK (status IN ('pending', 'reviewed', 'not_needed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
