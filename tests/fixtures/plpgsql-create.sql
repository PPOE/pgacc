-- PostgreSQL before 9.0 only (tests/database-smoke.sh): plpgsql is not
-- installed by default there, and production's database does not need it.
-- tests/database-characterization.sql needs it; plpgsql-drop.sql removes it
-- again in the same transaction.
CREATE LANGUAGE plpgsql;
