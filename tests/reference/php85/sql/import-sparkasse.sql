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
BEGIN;
-- next
DROP TABLE IF EXISTS ppmembers;
-- next
CREATE TABLE ppmembers (id integer, name text, lo integer, paid integer);
-- next
INSERT INTO ppmembers (id, name, lo, paid) VALUES (9001,'Anna Synthetisch-Müller',9,1),(9002,'Bernd Synthetisch',1,0),(9003,'Carla Synthetisch',3,0),(9004,'Dieter Synthetisch',2,1),(9005,'Emma Synthetisch',4,0),(9006,'Franz Synthetisch-Müller',5,0),(9007,'Gerda Synthetisch',6,1),(9008,'Hannes Synthetisch',10,0),(9009,'Iris Synthetisch',8,0),(9010,'Jörg Synthetisch',10,1),(9011,'Käthe Synthetisch-Müller',9,0),(9012,'Lukas Synthetisch',1,0),(9013,'Maria Synthetisch',3,1),(9014,'Norbert Synthetisch',2,0),(9015,'Olga Synthetisch',4,0),(9016,'Paul Synthetisch-Müller',5,1),(9017,'Rosa Synthetisch',6,0),(9018,'Stefan Synthetisch',10,0),(9019,'Theresa Synthetisch',8,1),(9020,'Ulrich Synthetisch',10,0),(9021,'Vera Synthetisch-Müller',9,0),(9022,'Walter Synthetisch',1,1),(9023,'Xaver Synthetisch',3,0),(9024,'Yvonne Synthetisch',2,0),(9025,'Zoë Synthetisch',4,1),(9026,'Ömer Synthetisch-Müller',5,0),(9027,'Ännchen Synthetisch',6,0),(9028,'Ludwig Synthetisch',10,1),(9029,'Sophie Synthetisch',8,0),(9030,'Max Synthetisch',10,0),(9031,'Testa Mitglied',2,1),(9032,'Mitglied Zweitname',1,0),(9033,'O''Brien Synthetisch',8,1),(9034,'Gerlinde Titelträgerin',3,0),(9035,'Synthetica',5,1);
-- next
COMMIT;
-- next
SELECT 1 FROM import WHERE line = 'Bezeichnung;Valutadatum;Betrag;Währung;Buchungsdatum;Umsatzzeile1;Zusatztext;Auftraggeber;Kundendaten/Zahlungsreferenz;Umsatzzeile2;Ersterfassungsreferenz;AuftraggeberKontonr./IBAN;AuftraggeberBLZ/BIC;Partnername;PartnerKontonr./IBAN;PartnerBLZ/BIC';
-- next
SELECT 1 FROM import WHERE line = 'Giro;20.02.2024;+99,99;EUR;20.02.2024;Gutschrift;Spende;SyntheticSparer;REF1;;ER1;AT00;STSPAT2G;SyntheticSparer;AT999999999999999999;STSPAT2GXXX';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Synthetic Sparer');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Sparer Synthetic');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5023, '2024-02-20', 8,6,false,0,'AT999999999999999999 STSPAT2GXXX','Synthetic Sparer','','','',9999,'20815 6659403','Gutschrift; Spende; Synthetic Sparer',false,false)
-- next
INSERT INTO import (line) VALUES ('Giro;20.02.2024;+99,99;EUR;20.02.2024;Gutschrift;Spende;SyntheticSparer;REF1;;ER1;AT00;STSPAT2G;SyntheticSparer;AT999999999999999999;STSPAT2GXXX');
-- next
SELECT 1 FROM import WHERE line = 'Giro;21.02.2024;0,00;EUR;21.02.2024;Null;;;;;;;;;;';
-- next
SELECT 1 FROM import WHERE line = 'Giro;22.02.2024;-10,00;EUR;22.02.2024;Lastschrift;Gebühr;;;;;;;SyntheticBank;;';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Synthetic Bank');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Bank Synthetic');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5024, '2024-02-22', 28,6,false,0,' ','Synthetic Bank','','','',-1000,'20815 6659403','Lastschrift; Gebühr; ',false,false)
-- next
INSERT INTO import (line) VALUES ('Giro;22.02.2024;-10,00;EUR;22.02.2024;Lastschrift;Gebühr;;;;;;;SyntheticBank;;');
-- next
DROP TABLE IF EXISTS ppmembers;
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
