-- Representative SQL contracts copied from the current application.
-- Any failed assertion aborts the test run. Comparisons use IS DISTINCT FROM,
-- so an unexpected NULL fails instead of passing silently.
-- Each check is a temporary plpgsql function.

CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  actual_tables text[];
  actual_sequences text[];
  actual_constraints text[];
  actual_not_null text[];
BEGIN
  actual_tables := ARRAY(
    SELECT table_name::text
    FROM information_schema.tables
    WHERE table_schema = 'public'
      AND table_type = 'BASE TABLE'
    ORDER BY table_name
  );

  IF actual_tables IS DISTINCT FROM ARRAY[
    'balance',
    'donation_type',
    'import',
    'lo',
    'oo',
    'person_type',
    'type',
    'users',
    'vouchers'
  ]::text[] THEN
    RAISE EXCEPTION 'Unexpected application tables: %', actual_tables;
  END IF;

  actual_sequences := ARRAY(
    SELECT sequence_name::text
    FROM information_schema.sequences
    WHERE sequence_schema = 'public'
    ORDER BY sequence_name
  );

  IF actual_sequences IS DISTINCT FROM ARRAY[
    'balance_id_seq',
    'donation_type_id_seq',
    'file_number',
    'lo_id_seq',
    'oo_id_seq',
    'person_type_id_seq',
    'type_id_seq',
    'users_id_seq',
    'voucher_number',
    'vouchers_id_seq'
  ]::text[] THEN
    RAISE EXCEPTION 'Unexpected application sequences: %', actual_sequences;
  END IF;

  actual_constraints := ARRAY(
    SELECT conname::text
    FROM pg_constraint
    -- PostgreSQL 18 also records NOT NULL as constraints (contype 'n'); they
    -- are checked from attnotnull below.
    WHERE connamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
      AND contype <> 'n'
    ORDER BY conname
  );

  IF actual_constraints IS DISTINCT FROM ARRAY['import_line_key', 'users_name_key']::text[] THEN
    RAISE EXCEPTION 'Unexpected application constraints: %', actual_constraints;
  END IF;

  -- NOT NULL columns, read from attnotnull.
  actual_not_null := ARRAY(
    SELECT (c.relname || '.' || a.attname)::text
    FROM pg_attribute AS a
    JOIN pg_class AS c ON c.oid = a.attrelid
    WHERE c.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
      AND c.relkind = 'r'
      AND a.attnum > 0
      AND NOT a.attisdropped
      AND a.attnotnull
    ORDER BY c.relname, a.attname
  );

  IF actual_not_null IS DISTINCT FROM ARRAY[
    'balance.id',
    'donation_type.id',
    'donation_type.name',
    'lo.id',
    'lo.name',
    'oo.id',
    'oo.lo',
    'oo.name',
    'person_type.id',
    'type.id',
    'type.name',
    'type.used',
    'users.id',
    'vouchers.deleted',
    'vouchers.id'
  ]::text[] THEN
    RAISE EXCEPTION 'Unexpected NOT NULL columns: %', actual_not_null;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- The insert paths in new.php and import.php rely on these database defaults.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  probe vouchers%ROWTYPE;
BEGIN
  INSERT INTO vouchers (voucher_id)
  VALUES (1999)
  RETURNING * INTO probe;

  IF probe.receipt_received IS DISTINCT FROM false
     OR probe.deleted IS DISTINCT FROM false
     OR probe.person_type IS DISTINCT FROM 2
     OR probe.file IS DISTINCT FROM 0
     OR probe.save_date IS NULL
     OR probe.commentgf IS DISTINCT FROM ''
     OR probe.refund IS DISTINCT FROM false
     OR probe.vaccount IS DISTINCT FROM '' THEN
    RAISE EXCEPTION 'Unexpected voucher defaults: %', probe;
  END IF;

  DELETE FROM vouchers WHERE id = probe.id;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- index.php login() and accounts.php password generation.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  correct_password boolean;
  wrong_password boolean;
BEGIN
  SELECT
    hash = crypt('correct-password', hash),
    hash = crypt('wrong-password', hash)
  INTO correct_password, wrong_password
  FROM users
  WHERE name = 'synthetic-admin';

  IF correct_password IS DISTINCT FROM true OR wrong_password IS DISTINCT FROM false THEN
    RAISE EXCEPTION 'Password hash characterization failed';
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- open.php: partially reviewed and unreviewed rows remain open.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  actual_ids integer[];
BEGIN
  actual_ids := ARRAY(
    SELECT id
    FROM (
      SELECT vouchers.id
      FROM vouchers
      LEFT JOIN lo ON orga = lo.id
      LEFT JOIN type ON type = type.id
      WHERE NOT deleted
        AND (ack1 IS NULL OR ack2 IS NULL)
    ) AS open_vouchers
    ORDER BY id
  );

  IF actual_ids IS DISTINCT FROM ARRAY[102, 105, 109, 110] THEN
    RAISE EXCEPTION 'Unexpected open voucher IDs: %', actual_ids;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- closed.php: an attachment on one row marks every closed row of that voucher.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  actual_rows text[];
BEGIN
  actual_rows := ARRAY(
    SELECT (id::text || ':' || COALESCE(file, 0)::text)
    FROM (
      SELECT anyfile AS file, vouchers.id
      FROM vouchers
      LEFT JOIN lo ON vouchers.orga = lo.id
      LEFT JOIN type ON vouchers.type = type.id
      LEFT JOIN (
        SELECT voucher_id, 1 AS anyfile
        FROM vouchers AS attachment
        WHERE attachment.file != 0
          AND attachment.ack1 IS NOT NULL
          AND attachment.ack2 IS NOT NULL
        GROUP BY voucher_id
      ) AS attached ON attached.voucher_id = vouchers.voucher_id
      WHERE NOT deleted
        AND ack1 IS NOT NULL
        AND ack2 IS NOT NULL
    ) AS closed_vouchers
    ORDER BY id
  );

  IF actual_rows IS DISTINCT FROM ARRAY[
    '101:0',
    '103:1',
    '104:1',
    '107:0',
    '108:0'
  ]::text[] THEN
    RAISE EXCEPTION 'Unexpected closed voucher attachment rows: %', actual_rows;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- transactions.php: omit deleted, zero-value, and pre-2012 rows.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  actual_ids integer[];
BEGIN
  actual_ids := ARRAY(
    SELECT id
    FROM vouchers
    WHERE NOT deleted
      AND amount != 0
      AND date > timestamp '2011-12-31'
    ORDER BY id
  );

  IF actual_ids IS DISTINCT FROM ARRAY[101, 102, 103, 104, 105, 109, 110] THEN
    RAISE EXCEPTION 'Unexpected public transaction IDs: %', actual_ids;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- spendings.php with the default eyes() condition.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  actual_ids integer[];
BEGIN
  actual_ids := ARRAY(
    SELECT id
    FROM vouchers
    WHERE NOT deleted
      AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)
      AND amount < 0
    ORDER BY id
  );

  IF actual_ids IS DISTINCT FROM ARRAY[103, 104] THEN
    RAISE EXCEPTION 'Unexpected public spending IDs: %', actual_ids;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- donations.php: only direct type 8 rows contribute to the donor total.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  actual_total bigint;
BEGIN
  SELECT SUM(amount)
  INTO actual_total
  FROM vouchers
  WHERE NOT deleted
    AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)
    AND type = 8
    AND date >= timestamp '2024-01-01'
    AND date < timestamp '2025-01-01'
    AND name = 'Synthetic Donor';

  IF actual_total IS DISTINCT FROM 13000::bigint THEN
    RAISE EXCEPTION 'Unexpected 2024 synthetic donation total: %', actual_total;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- report.php: legacy types roll up through type.realtype.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  actual_total bigint;
BEGIN
  SELECT SUM(amount)
  INTO actual_total
  FROM vouchers
  LEFT JOIN type AS mapped_type ON mapped_type.id = type
  WHERE NOT deleted
    AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)
    AND realtype = 8
    AND date >= timestamp '2024-01-01'
    AND date < timestamp '2025-01-01';

  IF actual_total IS DISTINCT FROM 16000::bigint THEN
    RAISE EXCEPTION 'Unexpected mapped donation total: %', actual_total;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();

-- functions.php percent_of_bookings(): characterize workflow counts.
CREATE FUNCTION pg_temp.pgacc_check() RETURNS void AS $test$
DECLARE
  total_count bigint;
  first_review_count bigint;
  second_review_count bigint;
BEGIN
  SELECT COUNT(*), COUNT(ack1), COUNT(ack2)
  INTO total_count, first_review_count, second_review_count
  FROM vouchers
  WHERE date >= timestamp '2024-01-01'
    AND date < timestamp '2025-01-01'
    AND NOT deleted;

  IF (total_count, first_review_count, second_review_count)
     IS DISTINCT FROM (7::bigint, 6::bigint, 4::bigint) THEN
    RAISE EXCEPTION 'Unexpected workflow counts: %, %, %',
      total_count,
      first_review_count,
      second_review_count;
  END IF;
END
$test$ LANGUAGE plpgsql;
SELECT pg_temp.pgacc_check();
DROP FUNCTION pg_temp.pgacc_check();
