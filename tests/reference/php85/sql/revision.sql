SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2012-01-01' AND date < '2013-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2013-01-01' AND date < '2014-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2014-01-01' AND date < '2015-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2015-01-01' AND date < '2016-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2016-01-01' AND date < '2017-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2017-01-01' AND date < '2018-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2018-01-01' AND date < '2019-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2019-01-01' AND date < '2020-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2020-01-01' AND date < '2021-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2021-01-01' AND date < '2022-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2022-01-01' AND date < '2023-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2023-01-01' AND date < '2024-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2024-01-01' AND date < '2025-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2025-01-01' AND date < '2026-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2026-01-01' AND date < '2027-01-01' AND NOT deleted;
-- next
SELECT COUNT(*) AS c,COUNT(ack1) AS b,COUNT(ack2) AS a FROM vouchers WHERE date >= '2027-01-01' AND date < '2028-01-01' AND NOT deleted;
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT id, file, receipt_received, (TRUE ) AS mine FROM vouchers WHERE NOT deleted AND voucher_id = 1004 ORDER BY id;
-- next
BEGIN;
-- next
CREATE TEMP TABLE IF NOT EXISTS ppmembers (id integer, uuid text, name text, lo integer, paid integer) ON COMMIT PRESERVE ROWS;
-- next
DELETE FROM pg_temp.ppmembers;
-- next
INSERT INTO pg_temp.ppmembers (id, uuid, name, lo, paid) VALUES (9001,'00000000-0000-4000-8000-000000009001','Anna Synthetisch-Müller',9,1),(9002,'00000000-0000-4000-8000-000000009002','Bernd Synthetisch',1,0),(9003,'00000000-0000-4000-8000-000000009003','Carla Synthetisch',3,0),(9004,'00000000-0000-4000-8000-000000009004','Dieter Synthetisch',2,1),(9005,'00000000-0000-4000-8000-000000009005','Emma Synthetisch',4,0),(9006,'00000000-0000-4000-8000-000000009006','Franz Synthetisch-Müller',5,0),(9007,'00000000-0000-4000-8000-000000009007','Gerda Synthetisch',6,1),(9008,'00000000-0000-4000-8000-000000009008','Hannes Synthetisch',10,0),(9009,'00000000-0000-4000-8000-000000009009','Iris Synthetisch',8,0),(9010,'00000000-0000-4000-8000-000000009010','Jörg Synthetisch',10,1),(9011,'00000000-0000-4000-8000-000000009011','Käthe Synthetisch-Müller',9,0),(9012,'00000000-0000-4000-8000-000000009012','Lukas Synthetisch',1,0),(9013,'00000000-0000-4000-8000-000000009013','Maria Synthetisch',3,1),(9014,'00000000-0000-4000-8000-000000009014','Norbert Synthetisch',2,0),(9015,'00000000-0000-4000-8000-000000009015','Olga Synthetisch',4,0),(9016,'00000000-0000-4000-8000-000000009016','Paul Synthetisch-Müller',5,1),(9017,'00000000-0000-4000-8000-000000009017','Rosa Synthetisch',6,0),(9018,'00000000-0000-4000-8000-000000009018','Stefan Synthetisch',10,0),(9019,'00000000-0000-4000-8000-000000009019','Theresa Synthetisch',8,1),(9020,'00000000-0000-4000-8000-000000009020','Ulrich Synthetisch',10,0),(9021,'00000000-0000-4000-8000-000000009021','Vera Synthetisch-Müller',9,0),(9022,'00000000-0000-4000-8000-000000009022','Walter Synthetisch',1,1),(9023,'00000000-0000-4000-8000-000000009023','Xaver Synthetisch',3,0),(9024,'00000000-0000-4000-8000-000000009024','Yvonne Synthetisch',2,0),(9025,'00000000-0000-4000-8000-000000009025','Zoë Synthetisch',4,1),(9026,'00000000-0000-4000-8000-000000009026','Ömer Synthetisch-Müller',5,0),(9027,'00000000-0000-4000-8000-000000009027','Ännchen Synthetisch',6,0),(9028,'00000000-0000-4000-8000-000000009028','Ludwig Synthetisch',10,1),(9029,'00000000-0000-4000-8000-000000009029','Sophie Synthetisch',8,0),(9030,'00000000-0000-4000-8000-000000009030','Max Synthetisch',10,0),(9031,'00000000-0000-4000-8000-000000009031','Testa Mitglied',2,1),(9032,'00000000-0000-4000-8000-000000009032','Mitglied Zweitname',1,0),(9033,'00000000-0000-4000-8000-000000009033','O''Brien Synthetisch',8,1),(9034,'00000000-0000-4000-8000-000000009034','Gerlinde Titelträgerin',3,0),(9035,'00000000-0000-4000-8000-000000009035','Synthetica',5,1);
-- next
COMMIT;
-- next
SELECT id, uuid, name FROM pg_temp.ppmembers;
-- next
BEGIN;
-- next
SELECT pg_advisory_xact_lock(1, 1004);
-- next
SELECT id, file, receipt_received, (TRUE ) AS mine FROM vouchers WHERE NOT deleted AND voucher_id = 1004 ORDER BY id FOR UPDATE;
-- next

    UPDATE vouchers
    SET
      ack1_old = ack1,
      ack2_old = ack2,
      ack1 = NULL,
      ack2 = NULL
    WHERE
      NOT deleted AND
      (ack1 IS NOT NULL OR ack2 IS NOT NULL) AND
      voucher_id = 1004 ;
  
-- next
SELECT ack1_old, ack2_old FROM vouchers WHERE NOT deleted AND voucher_id = 1004  ORDER BY id ASC LIMIT 1;
-- next
UPDATE vouchers SET deleted = true WHERE NOT deleted AND voucher_id = 1004 AND id IN (105);
-- next

INSERT INTO vouchers
(voucher_id, date, type, person_type, orga, member,
member_id, contra_account, name, street, plz, city,
amount, account, vaccount, comment, commentgf,
committed, file, refund, ack1_old, ack2_old)
VALUES
(1004, '2024-09-15', 20,1,2,false,
  0,'SYNTHETICREF',
  'Synthetic Reference Supplier','Test Street 1','0000',
  'Test City',-3000,'TESTACCOUNT2',
  'TESTVIRTUAL2','Reference revised draft','Reference workflow',
  false,0,false,NULL,NULL);
-- next
COMMIT;
