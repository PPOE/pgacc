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
SELECT 1 FROM import WHERE line = 'AT716000020810070316;SpendefürBüroBAWAATWWAT666666666666666666JörgÖsterreicher;12.01.2024;12.01.2024;+12,34;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Jörg Österreicher');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Österreicher Jörg');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5016, '2024-01-12', 8,6,false,0,'BAWAATWW AT666666666666666666','Jörg Österreicher','','','',1234,'AT716000020810070316','Spende für Büro
BAWAATWW AT666666666666666666 Jörg Österreicher',false,false)
-- next
INSERT INTO import (line) VALUES ('AT716000020810070316;SpendefürBüroBAWAATWWAT666666666666666666JörgÖsterreicher;12.01.2024;12.01.2024;+12,34;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT496000020810070324;Rückzahlung€BAWAATWWAT777777777777777777ZoëSynthetisch;13.01.2024;13.01.2024;-5,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Zoë Synthetisch');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5017, '2024-01-13', 28,6,true,9025,'BAWAATWW AT777777777777777777','Zoë Synthetisch','','','',-500,'AT496000020810070324','Rückzahlung €
BAWAATWW AT777777777777777777 Zoë Synthetisch',false,false)
-- next
INSERT INTO import (line) VALUES ('AT496000020810070324;Rückzahlung€BAWAATWWAT777777777777777777ZoëSynthetisch;13.01.2024;13.01.2024;-5,00;EUR');
-- next
SELECT 1 FROM import WHERE line = '15.02.2024;12,50;Handkassa;KaffeeMilch;GFKommentar;FREMD-1;Ja;Nein;SyntheticKassa;0;2;Weg1;1010;Wien';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Synthetic Kassa');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Kassa Synthetic');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5018, '2024-02-15', 8,2,false,0,'FREMD-1','Synthetic Kassa','','','',1250,'Handkassa','Kaffee
Milch',false,false)
-- next
INSERT INTO import (line) VALUES ('15.02.2024;12,50;Handkassa;KaffeeMilch;GFKommentar;FREMD-1;Ja;Nein;SyntheticKassa;0;2;Weg1;1010;Wien');
-- next
SELECT 1 FROM import WHERE line = '16.02.2024;7;Handkassa;Porto;;;Nein;Ja;TestaMitglied;9031;0;;;';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Testa Mitglied');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5019, '2024-02-16', 1,10,true,9031,'','Testa Mitglied','','','',700,'Handkassa','Porto',false,false)
-- next
INSERT INTO import (line) VALUES ('16.02.2024;7;Handkassa;Porto;;;Nein;Ja;TestaMitglied;9031;0;;;');
-- next
SELECT 1 FROM import WHERE line = '17.02.2024;0;Handkassa;Nullbuchung;;;;;;;;;;';
-- next
SELECT 1 FROM import WHERE line = '2024-02-18;-3.5;Handkassa;ISODatum;;;1;Wahr;SyntheticKassa;abc;99;;;';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Synthetic Kassa');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Kassa Synthetic');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5020, '2024-02-18', 28,99,false,0,'','Synthetic Kassa','','','',-350,'Handkassa','ISO Datum',false,false)
-- next
INSERT INTO import (line) VALUES ('2024-02-18;-3.5;Handkassa;ISODatum;;;1;Wahr;SyntheticKassa;abc;99;;;');
-- next
DROP TABLE IF EXISTS ppmembers;
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
