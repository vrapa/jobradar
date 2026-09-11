ALTER TABLE action_items DROP CHECK action_items_type_valid;
ALTER TABLE action_items ADD CONSTRAINT action_items_type_valid CHECK (action_type IN ('review_opportunities', 'verify_attachment', 'verify_terms', 'review_application', 'reply', 'follow_up', 'login', 'other'));
ALTER TABLE search_runs ADD review_action_processed_at DATETIME(6) NULL;
