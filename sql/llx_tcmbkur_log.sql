-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.

CREATE TABLE llx_tcmbkur_log (
  rowid          integer AUTO_INCREMENT PRIMARY KEY,
  entity         integer DEFAULT 1 NOT NULL,
  date_run       datetime NOT NULL,
  mode           varchar(16) NOT NULL,            -- sync | backfill | cron | mcp
  bulletin_date  date DEFAULT NULL,               -- TCMB bulletin date
  bulletin_no    varchar(16) DEFAULT NULL,
  rate_date      date DEFAULT NULL,               -- date written to llx_multicurrency_rate
  status         varchar(8) NOT NULL,             -- ok | skip | error
  written        integer DEFAULT 0,               -- rates inserted
  skipped        integer DEFAULT 0,               -- rates already present
  detail         text DEFAULT NULL,               -- "USD 48.5873 → 0.02058 ..." or the error
  fk_user        integer DEFAULT NULL
) ENGINE=innodb;
