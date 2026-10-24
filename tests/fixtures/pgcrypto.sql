-- PostgreSQL 9.1+ only (CREATE EXTENSION); see tests/database-smoke.sh.
-- The 8.4 test server has pgcrypto from contrib in template1, like production.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
