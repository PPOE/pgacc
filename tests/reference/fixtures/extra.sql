-- Reference runs only: synthetic records on top of tests/fixtures/data.sql
-- that reach more report branches, number formats and PHP 8 sensitive
-- values (umlauts, quotes, line breaks, odd percentages, int4 limits,
-- membership fees for the stub's member ids, election-campaign ranges, in-kind
-- donations). Loaded by tests/reference/scenario.php before the first request.
-- Sequences are reset so that later ids do not depend on other fixtures.

INSERT INTO type (id, name, used, income, realtype)
VALUES
  (1, 'Synthetic membership fee', 20, 1, 1),
  (12, 'Synthetic in-kind service', 1, 1, 12),
  (13, 'Synthetic member credit', 0, 1, 13),
  (14, 'Synthetic other income', 3, 1, 14),
  (23, 'Synthetic member credit offset', 0, -1, 23),
  (28, 'Synthetic bank charges', 2, -1, 28),
  (29, 'Synthetic internal transfer in', 0, 1, 29),
  (30, 'Synthetic internal transfer out', 0, -1, 30),
  (1029, 'Synthetic private refund', 0, -1, 20);

INSERT INTO lo (id, name)
VALUES
  (3, 'Synthetic Unit Three'),
  (4, 'Synthetic Ünit Vier'),
  (5, 'Synthetic Unit Five'),
  (6, 'Synthetic Piratenpartei Six'),
  (8, 'Synthetic Unit Eight'),
  (9, 'Synthetic Unit Nine'),
  (10, 'Synthetic Federal Unit');

INSERT INTO oo (id, name, lo)
VALUES (2, 'Synthetic Piratenpartei Local', 6);

-- Ids 1 to 9 are in data.sql (users.id has no unique constraint, so a
-- duplicate id would silently log in as the other user);
-- synthetic-norights differs from synthetic-no-rights by its NULL hide.
INSERT INTO users (id, name, hash, login, cookie, rights, hide)
VALUES
  (10, 'synthetic-readonly',
   crypt('readonly-password', gen_salt('md5')),
   timestamp '2024-01-01 12:00:00', 101234, 'Rbgf', ''),
  (11, 'synthetic-norights',
   crypt('norights-password', gen_salt('md5')),
   timestamp '2024-01-01 12:00:00', 111234, '', NULL);

INSERT INTO balance (id, value, orga, date)
VALUES (3, -2147483648, 2, timestamp '2025-12-31 00:00:00');

INSERT INTO vouchers (
  id, voucher_id, type, orga, member, member_id, contra_account, name, street,
  plz, city, amount, account, comment, committed, receipt_received, deleted,
  date, ack1, ack2, person_type, file, save_user, commentgf, refund, vaccount
)
VALUES
  -- Membership fees after the fixed "now" year's previous October (mb.php,
  -- mbapi.php): member ids from tests/reference/membership/adm_names.tsv.
  (201, 2001, 1, 2, true, 9001, 'AT000000000000009001', 'Anna Synthetisch-Müller', 'Teststraße 1',
   '1010', 'Wien', 2400, 'TEST-ACCOUNT-1', 'MB 2026', true, true, false,
   timestamp '2025-11-03 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1, '', false, ''),
  (202, 2002, 1, 2, true, 9004, '', 'Dieter Synthetisch', '', '', '', 1999,
   'TEST-ACCOUNT-1', 'Mitgliedsbeitrag knapp', true, true, false,
   timestamp '2026-01-15 00:00:00', 'reviewer-a', NULL, 2, 0, 1, '', false, ''),
  (203, 2003, 1, 10, true, 9031, '', 'Testa Mitglied', '', '', '', 1,
   'TEST-ACCOUNT-1', 'MB Teil 1', true, false, false,
   timestamp '2026-02-01 00:00:00', NULL, NULL, 2, 0, 1, '', false, ''),
  (204, 2003, 1, 10, true, 9031, '', 'Testa Mitglied', '', '', '', 1999,
   'TEST-ACCOUNT-1', 'MB Teil 2', true, false, false,
   timestamp '2026-02-01 00:00:00', NULL, NULL, 2, 0, 1, '', false, ''),
  -- Odd counts per year for the review percentages in the page header.
  (205, 2005, 8, 1, false, NULL, 'SYNTHETIC-205', 'Synthetic Donor 2012', '', '', '', 333,
   'TEST-ACCOUNT-1', 'Year 2012 a', true, true, false,
   timestamp '2012-03-01 00:00:00', 'reviewer-a', NULL, 2, 0, 1, '', false, 'TEST-VIRTUAL-1'),
  (206, 2006, 8, 1, false, NULL, 'SYNTHETIC-206', 'Synthetic Donor 2012', '', '', '', 667,
   'TEST-ACCOUNT-1', 'Year 2012 b', true, true, false,
   timestamp '2012-03-02 00:00:00', NULL, NULL, 2, 0, 1, '', false, 'TEST-VIRTUAL-1'),
  (207, 2007, 20, 1, false, NULL, 'SYNTHETIC-207', 'Synthetic Supplier 2012', '', '', '', -1,
   'TEST-ACCOUNT-1', 'Year 2012 c', true, true, false,
   timestamp '2012-12-31 00:00:00', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', false, 'TEST-VIRTUAL-1'),
  -- Election campaign ranges of wk.php (Graz 2012 on unit 6, national 2013).
  (208, 2008, 20, 6, false, NULL, 'SYNTHETIC-208', 'Synthetic Campaign Printer', '', '', '', -123456,
   'TEST-ACCOUNT-6', 'Plakate Graz', true, true, false,
   timestamp '2012-10-15 00:00:00', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', false, 'TEST-VIRTUAL-6'),
  (209, 2009, 8, 6, false, NULL, 'SYNTHETIC-209', 'Synthetic Campaign Donor', '', '', '', 50005,
   'TEST-ACCOUNT-6', 'Wahlkampfspende', true, true, false,
   timestamp '2012-11-01 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1, '', false, 'TEST-VIRTUAL-6'),
  (210, 2010, 21, 10, false, NULL, 'SYNTHETIC-210', 'Synthetic Agency', '', '', '', -2147483647,
   'TEST-ACCOUNT-10', 'NRW 2013 int4 limit', true, true, false,
   timestamp '2013-07-01 00:00:00', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', false, 'TEST-VIRTUAL-10'),
  (211, 2011, 11, 6, false, NULL, 'SYNTHETIC-211', 'Synthetic In-Kind Donor', '', '', '', 2147483647,
   'TEST-ACCOUNT-6', 'NRW 2013 Sachspende', true, true, false,
   timestamp '2013-08-01 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1, '', false, 'TEST-VIRTUAL-6'),
  -- In-kind donations (kdonations.php) with umlauts, quotes and a line break.
  (212, 2012, 11, 2, false, NULL, 'BG/123456789 12', 'Jörg "Quote" O''Brien', 'Gasse 2', '8010', 'Graz', 9999,
   'TEST-ACCOUNT-2', E'Sachspende Österreich\nzweite Zeile BG/123456789 12', true, true, false,
   timestamp '2024-10-01 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1, 'Kommentar <b>GF</b>', false, 'TEST-VIRTUAL-2'),
  (213, 2013, 12, 1, false, NULL, 'SYNTHETIC-213', 'Synthetic Service Donor', '', '', '', 5,
   'TEST-ACCOUNT-1', 'Dienstleistung', true, true, false,
   timestamp '2024-10-02 00:00:00', 'reviewer-a', NULL, 1, 0, 1, '', false, 'TEST-VIRTUAL-1'),
  -- Bank charges, refunds, transfers and the private-refund type.
  (214, 2014, 28, 2, false, NULL, '', 'Bank', '', '', '', -750,
   'TEST-ACCOUNT-2', 'Entgelt für Kontoführung', true, true, false,
   timestamp '2024-03-31 00:00:00', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', false, 'TEST-VIRTUAL-2'),
  (215, 2015, 1029, 2, false, NULL, 'AT000000000000000215', 'Private Person', '', '', '', -4200,
   'TEST-ACCOUNT-2', 'Rückerstattung', true, true, false,
   timestamp '2024-11-11 00:00:00', 'reviewer-a', 'reviewer-b', 2, 0, 1, '', true, 'TEST-VIRTUAL-2'),
  (216, 2016, 29, 1, false, NULL, '', 'Intern', '', '', '', 100000,
   'TEST-ACCOUNT-1', 'Umbuchung', true, true, false,
   timestamp '2024-12-01 00:00:00', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', false, ''),
  (217, 2016, 30, 2, false, NULL, '', 'Intern', '', '', '', -100000,
   'TEST-ACCOUNT-2', 'Umbuchung', true, true, false,
   timestamp '2024-12-01 00:00:00', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', false, ''),
  -- Current (fixed "now") year rows, one of them unreviewed.
  (218, 2018, 14, 4, false, NULL, 'SYNTHETIC-218', 'Synthetic Other Income', '', '', '', 10,
   'TEST-ACCOUNT-4', 'Zinsen', false, false, false,
   timestamp '2026-06-01 00:00:00', NULL, NULL, 1, 0, 1, '', false, 'TEST-VIRTUAL-4'),
  (219, 2019, 20, 4, false, NULL, 'SYNTHETIC-219', 'Synthetic Supplier', '', '', '', -99,
   'TEST-ACCOUNT-4', 'Büromaterial', true, true, false,
   timestamp '2026-05-31 00:00:00', 'reviewer-a', 'reviewer-b', 1, 0, 1, '', false, 'TEST-VIRTUAL-4'),
  -- Open vouchers for the PDF receipt import (import2.php): the id and date
  -- appear in tests/reference/fixtures/import/*.pdf.
  (220, 2020, 20, 2, false, NULL, 'SYNTHETIC-220', 'Synthetic Statement Supplier', '', '', '', -1234,
   'AT606000050110117300', 'Lastschrift BG/000000220', true, false, false,
   timestamp '2024-03-15 00:00:00', NULL, NULL, 1, 0, 1, '', false, ''),
  (221, 2021, 28, 2, false, NULL, '', 'Bank', '', '', '', -50,
   'AT606000050110117300', 'Zinsen BG/000000221', true, false, false,
   timestamp '2024-03-31 00:00:00', NULL, NULL, 1, 0, 1, '', false, ''),
  (222, 2022, 28, 2, false, NULL, '', 'Bank', '', '', '', -700,
   'AT606000050110117300', 'Entgelt für Kontoführung', true, false, false,
   timestamp '2024-06-30 00:00:00', NULL, NULL, 1, 0, 1, '', false, '');

SELECT setval('vouchers_id_seq', 1000, false);
SELECT setval('voucher_number', 5000, false);
SELECT setval('file_number', 100, false);
SELECT setval('users_id_seq', 100, false);
