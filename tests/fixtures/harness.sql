CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE harness_probe (
  id integer PRIMARY KEY,
  label text NOT NULL
);

INSERT INTO harness_probe (id, label)
VALUES (1, 'synthetic test data');

DO $harness$
DECLARE
  generated_hash text;
BEGIN
  generated_hash := crypt('test-password', gen_salt('bf'));
  IF crypt('test-password', generated_hash) <> generated_hash THEN
    RAISE EXCEPTION 'pgcrypto password verification failed';
  END IF;
END
$harness$;
