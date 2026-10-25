-- Data-free snapshot of the live pgacc schema observed on 2026-10-23.
--
-- This fixture deliberately keeps the live schema's nullable columns,
-- defaults, sequences, and limited constraints. The dated production backup
-- table is intentionally excluded. pgcrypto comes from
-- tests/fixtures/pgcrypto.sql.

CREATE SEQUENCE file_number;
CREATE SEQUENCE voucher_number;

CREATE TABLE balance (
  id serial NOT NULL,
  value integer,
  orga integer,
  date timestamp without time zone
);

CREATE TABLE donation_type (
  id serial NOT NULL,
  name text NOT NULL
);

CREATE TABLE import (
  line text UNIQUE
);

CREATE TABLE lo (
  id serial NOT NULL,
  name text NOT NULL
);

CREATE TABLE oo (
  id serial NOT NULL,
  name text NOT NULL,
  lo integer NOT NULL
);

CREATE TABLE person_type (
  id serial NOT NULL,
  name text,
  description text,
  used integer DEFAULT 0,
  public boolean DEFAULT true
);

CREATE TABLE type (
  id serial NOT NULL,
  name text NOT NULL,
  used integer NOT NULL DEFAULT 0,
  income integer,
  realtype integer
);

CREATE TABLE users (
  id serial NOT NULL,
  name text UNIQUE,
  hash text,
  login timestamp without time zone,
  cookie integer,
  rights text,
  hide text
);

CREATE TABLE vouchers (
  id serial NOT NULL,
  voucher_id integer,
  type integer,
  orga integer,
  member boolean,
  member_id integer,
  contra_account text,
  name text,
  street text,
  plz text,
  city text,
  amount integer,
  account text,
  comment text,
  committed boolean,
  receipt_received boolean DEFAULT false,
  deleted boolean NOT NULL DEFAULT false,
  date timestamp without time zone,
  ack1 text,
  ack2 text,
  person_type integer DEFAULT 2,
  file integer DEFAULT 0,
  save_date timestamp without time zone DEFAULT now(),
  save_user integer,
  commentgf text DEFAULT '',
  refund boolean DEFAULT false,
  vaccount text DEFAULT '',
  ack1_old text,
  ack2_old text,
  ack_rp1 text,
  ack_rp2 text,
  ack_rp1_old text,
  ack_rp2_old text
);
