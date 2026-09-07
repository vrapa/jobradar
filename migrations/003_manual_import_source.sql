INSERT INTO sources (
    name,
    url,
    market_code,
    source_type,
    priority,
    recommended_frequency_hours,
    active,
    access_requirement,
    adapter_capabilities,
    created_at,
    updated_at
) VALUES (
    'Ruční import',
    'internal://manual-import',
    NULL,
    'manual',
    'A',
    NULL,
    1,
    'public',
    JSON_OBJECT('manualImport', TRUE),
    UTC_TIMESTAMP(6),
    UTC_TIMESTAMP(6)
)
ON DUPLICATE KEY UPDATE updated_at = updated_at;
