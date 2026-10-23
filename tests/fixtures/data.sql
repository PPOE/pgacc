-- Entirely synthetic records exercising pgacc's observable query states.

INSERT INTO donation_type (id, name)
VALUES (1, 'Synthetic monetary donation');

INSERT INTO lo (id, name)
VALUES
  (1, 'Synthetic National Unit'),
  (2, 'Synthetic Regional Unit');

INSERT INTO oo (id, name, lo)
VALUES (1, 'Synthetic Local Unit', 2);

INSERT INTO person_type (id, name, description, used, public)
VALUES
  (1, 'Synthetic organization', 'Test-only legal entity', 1, true),
  (2, 'Synthetic person', 'Test-only private person', 2, false);

INSERT INTO type (id, name, used, income, realtype)
VALUES
  (8, 'Synthetic donation', 10, 1, 8),
  (11, 'Synthetic in-kind donation', 1, 1, 11),
  (18, 'Synthetic legacy donation', 5, 1, 8),
  (20, 'Synthetic expense', 10, -1, 20),
  (21, 'Synthetic legacy expense', 5, -1, 20);

INSERT INTO users (id, name, hash, login, cookie, rights, hide)
VALUES (
  1,
  'synthetic-admin',
  crypt('correct-password', gen_salt('md5')),
  timestamp '2024-01-01 12:00:00',
  123456,
  'root',
  ''
);

INSERT INTO balance (id, value, orga, date)
VALUES
  (1, 100000, 1, timestamp '2023-12-31 00:00:00'),
  (2, 109500, 1, timestamp '2024-12-31 00:00:00');

INSERT INTO import (line)
VALUES ('synthetic-import-line');

INSERT INTO vouchers (
  id,
  voucher_id,
  type,
  orga,
  member,
  member_id,
  contra_account,
  name,
  amount,
  account,
  comment,
  committed,
  receipt_received,
  deleted,
  date,
  ack1,
  ack2,
  person_type,
  file,
  save_user,
  vaccount
)
VALUES
  (101, 1001, 8, 1, false, NULL, 'SYNTHETIC-001', 'Synthetic Donor',
   12000, 'TEST-ACCOUNT-1', 'Closed donation', true, true, false,
   timestamp '2024-02-01 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1,
   'TEST-VIRTUAL-1'),
  (102, 1002, 18, 1, false, NULL, 'SYNTHETIC-002', 'Synthetic Donor',
   3000, 'TEST-ACCOUNT-1', 'Partially reviewed legacy donation', true, true,
   false, timestamp '2024-03-01 00:00:00', 'reviewer-a', NULL, 2, 0, 1,
   'TEST-VIRTUAL-1'),
  (103, 1003, 20, 2, false, NULL, 'SYNTHETIC-003', 'Synthetic Supplier',
   -5000, 'TEST-ACCOUNT-2', 'Closed expense with attachment', true, true,
   false, timestamp '2024-04-01 00:00:00', 'reviewer-a', 'reviewer-b', 1,
   42, 1, 'TEST-VIRTUAL-2'),
  (104, 1003, 21, 2, false, NULL, 'SYNTHETIC-004', 'Synthetic Supplier',
   -1500, 'TEST-ACCOUNT-2', 'Second row of attached voucher', true, true,
   false, timestamp '2024-04-01 00:00:00', 'reviewer-a', 'reviewer-b', 1,
   0, 1, 'TEST-VIRTUAL-2'),
  (105, 1004, 20, 2, false, NULL, 'SYNTHETIC-005', 'Synthetic Draft',
   -2500, 'TEST-ACCOUNT-2', 'Unreviewed expense', true, false, false,
   timestamp '2024-05-01 00:00:00', NULL, NULL, 1, 0, 1,
   'TEST-VIRTUAL-2'),
  (106, 1005, 8, 1, false, NULL, 'SYNTHETIC-006', 'Synthetic Donor',
   9000, 'TEST-ACCOUNT-1', 'Deleted donation', true, true, true,
   timestamp '2024-06-01 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1,
   'TEST-VIRTUAL-1'),
  (107, 1006, 8, 1, false, NULL, 'SYNTHETIC-007', 'Synthetic Donor',
   0, 'TEST-ACCOUNT-1', 'Zero-value closed voucher', true, true, false,
   timestamp '2024-07-01 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1,
   'TEST-VIRTUAL-1'),
  (108, 1007, 8, 1, false, NULL, 'SYNTHETIC-008', 'Synthetic Donor',
   5000, 'TEST-ACCOUNT-1', 'Voucher before public transaction cutoff', true,
   true, false, timestamp '2011-12-31 00:00:00', 'reviewer-a', 'reviewer-b',
   2, 0, 1, 'TEST-VIRTUAL-1'),
  (109, 1008, 8, 1, false, NULL, 'SYNTHETIC-009', 'Synthetic Future Donor',
   2500, 'TEST-ACCOUNT-1', 'Different reporting year', true, true, false,
   timestamp '2025-01-01 00:00:00', 'reviewer-a', NULL, 2, 0, 1,
   'TEST-VIRTUAL-1'),
  (110, 1009, 8, 1, false, NULL, 'SYNTHETIC-010', 'Synthetic Donor',
   1000, 'TEST-ACCOUNT-1', 'Partially reviewed donation', true, true, false,
   timestamp '2024-08-01 00:00:00', 'reviewer-a', NULL, 2, 0, 1,
   'TEST-VIRTUAL-1');
