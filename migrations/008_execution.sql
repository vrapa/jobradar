ALTER TABLE search_run_sources ADD checkpoint_json JSON NULL, ADD checkpoint_at DATETIME(6) NULL;
CREATE TABLE execution_events (
    search_run_source_id BIGINT UNSIGNED NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    lease_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (search_run_source_id, key_hash),
    CONSTRAINT execution_events_source_fk FOREIGN KEY (search_run_source_id) REFERENCES search_run_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
