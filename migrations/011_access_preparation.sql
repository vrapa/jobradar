ALTER TABLE search_requests
    ADD prepare_access TINYINT(1) NOT NULL DEFAULT 0,
    ADD access_confirmed_at DATETIME(6) NULL;
CREATE TABLE search_access_preparations (
    search_request_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    access_status VARCHAR(32) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    PRIMARY KEY(search_request_id, source_id),
    FOREIGN KEY(search_request_id,source_id) REFERENCES search_request_sources(search_request_id,source_id),
    CHECK(access_status IN ('available','login_required','blocked','error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
