CREATE TABLE api_clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_identifier CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    client_type VARCHAR(32) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY api_clients_public_identifier_unique (public_identifier),
    KEY api_clients_type_active (client_type, revoked_at),
    CONSTRAINT api_clients_creator_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT api_clients_type_valid CHECK (client_type IN ('runner', 'mcp', 'integration'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE api_access_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_client_id BIGINT UNSIGNED NOT NULL,
    token_prefix VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scopes_json JSON NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    last_used_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY api_access_tokens_hash_unique (token_hash),
    KEY api_access_tokens_client_active (api_client_id, revoked_at, expires_at),
    CONSTRAINT api_access_tokens_client_fk FOREIGN KEY (api_client_id) REFERENCES api_clients (id),
    CONSTRAINT api_access_tokens_creator_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
