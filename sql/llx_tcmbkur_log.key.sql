-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.

ALTER TABLE llx_tcmbkur_log ADD INDEX idx_tcmbkur_log_entity_date (entity, date_run);
ALTER TABLE llx_tcmbkur_log ADD INDEX idx_tcmbkur_log_rate_date (rate_date);
