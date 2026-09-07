ALTER TABLE runner_devices
    ADD COLUMN api_client_id BIGINT UNSIGNED NULL AFTER id,
    ADD UNIQUE KEY runner_devices_api_client_unique (api_client_id),
    ADD CONSTRAINT runner_devices_api_client_fk FOREIGN KEY (api_client_id) REFERENCES api_clients (id);
