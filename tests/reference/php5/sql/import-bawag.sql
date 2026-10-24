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
DROP TABLE IF EXISTS ppmembers;
-- next
CREATE TABLE ppmembers (id integer, name text, lo integer, paid integer);
-- next
INSERT INTO ppmembers (id, name, lo, paid) VALUES (9001,'Anna Synthetisch-Müller',9,1),(9002,'Bernd Synthetisch',1,0),(9003,'Carla Synthetisch',3,0),(9004,'Dieter Synthetisch',2,1),(9005,'Emma Synthetisch',4,0),(9006,'Franz Synthetisch-Müller',5,0),(9007,'Gerda Synthetisch',6,1),(9008,'Hannes Synthetisch',10,0),(9009,'Iris Synthetisch',8,0),(9010,'Jörg Synthetisch',10,1),(9011,'Käthe Synthetisch-Müller',9,0),(9012,'Lukas Synthetisch',1,0),(9013,'Maria Synthetisch',3,1),(9014,'Norbert Synthetisch',2,0),(9015,'Olga Synthetisch',4,0),(9016,'Paul Synthetisch-Müller',5,1),(9017,'Rosa Synthetisch',6,0),(9018,'Stefan Synthetisch',10,0),(9019,'Theresa Synthetisch',8,1),(9020,'Ulrich Synthetisch',10,0),(9021,'Vera Synthetisch-Müller',9,0),(9022,'Walter Synthetisch',1,1),(9023,'Xaver Synthetisch',3,0),(9024,'Yvonne Synthetisch',2,0),(9025,'Zoë Synthetisch',4,1),(9026,'Ömer Synthetisch-Müller',5,0),(9027,'Ännchen Synthetisch',6,0),(9028,'Ludwig Synthetisch',10,1),(9029,'Sophie Synthetisch',8,0),(9030,'Max Synthetisch',10,0),(9031,'Testa Mitglied',2,1),(9032,'Mitglied Zweitname',1,0),(9033,'O''Brien Synthetisch',8,1),(9034,'Gerlinde Titelträgerin',3,0),(9035,'Synthetica',5,1);
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;SpendeBAWAATWWAT611904300234573201SyntheticSpender;02.01.2024;02.01.2024;+50,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Synthetic Spender');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Spender Synthetic');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5007, '2024-01-02', 8,2,false,0,'BAWAATWW AT611904300234573201','Synthetic Spender','','','',5000,'AT606000050110117300','Spende
BAWAATWW AT611904300234573201 Synthetic Spender',false,false)
-- next
INSERT INTO import (line) VALUES ('AT606000050110117300;SpendeBAWAATWWAT611904300234573201SyntheticSpender;02.01.2024;02.01.2024;+50,00;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;MB2024OPSKATWWAT111111111111111111TestaMitglied;03.01.2024;03.01.2024;+24,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Testa Mitglied');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5008, '2024-01-03', 1,2,true,9031,'OPSKATWW AT111111111111111111','Testa Mitglied','','','',2400,'AT606000050110117300','MB 2024
OPSKATWW AT111111111111111111 Testa Mitglied',false,false)
-- next
INSERT INTO import (line) VALUES ('AT606000050110117300;MB2024OPSKATWWAT111111111111111111TestaMitglied;03.01.2024;03.01.2024;+24,00;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;Rechnung4711BAWAATWWAT222222222222222222SyntheticLieferantGmbH;04.01.2024;04.01.2024;-1.234,56;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Synthetic Lieferant GmbH');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Lieferant GmbH Synthetic');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5009, '2024-01-04', 28,2,false,0,'BAWAATWW AT222222222222222222','Synthetic Lieferant GmbH','','','',-123456,'AT606000050110117300','Rechnung 4711
BAWAATWW AT222222222222222222 Synthetic Lieferant GmbH',false,false)
-- next
INSERT INTO import (line) VALUES ('AT606000050110117300;Rechnung4711BAWAATWWAT222222222222222222SyntheticLieferantGmbH;04.01.2024;04.01.2024;-1.234,56;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT916000050110110437;MitgliedsbeitragGerlindeTitelträgerinAT333333;05.01.2024;05.01.2024;+20,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE name <> '' AND 'AT916000050110110437;MitgliedsbeitragGerlindeTitelträgerinAT333333;05.01.2024;05.01.2024;+20,00;EUR' ILIKE '%' || replace(replace(replace(name, E'\\', E'\\\\'), '%', E'\\%'), '_', E'\\_') || '%' ESCAPE E'\\'
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5010, '2024-01-05', 1,10,true,0,'','','','','',2000,'AT916000050110110437','Mitgliedsbeitrag
Gerlinde Titelträgerin AT33 3333',false,false)
-- next
INSERT INTO import (line) VALUES ('AT916000050110110437;MitgliedsbeitragGerlindeTitelträgerinAT333333;05.01.2024;05.01.2024;+20,00;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT596000050110117318;SpendeBAWAATWWAT444444444444444444Dr.GerlindeTitelträgerin;06.01.2024;06.01.2024;+15,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Dr. Gerlinde Titelträgerin');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Gerlinde Titelträgerin Dr.');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Gerlinde Titelträgerin');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5011, '2024-01-06', 1,3,true,9034,'BAWAATWW AT444444444444444444','Dr. Gerlinde Titelträgerin','','','',1500,'AT596000050110117318','Spende
BAWAATWW AT444444444444444444 Dr. Gerlinde Titelträgerin',false,false)
-- next
INSERT INTO import (line) VALUES ('AT596000050110117318;SpendeBAWAATWWAT444444444444444444Dr.GerlindeTitelträgerin;06.01.2024;06.01.2024;+15,00;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT376000050110117326;ZinsenBG/000000301;31.03.2024;31.03.2024;+0,05;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE name <> '' AND 'AT376000050110117326;ZinsenBG/000000301;31.03.2024;31.03.2024;+0,05;EUR' ILIKE '%' || replace(replace(replace(name, E'\\', E'\\\\'), '%', E'\\%'), '_', E'\\_') || '%' ESCAPE E'\\'
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5012, '2024-03-31', 14,4,false,0,'','','','','',5,'AT376000050110117326','Zinsen BG/000000301',false,false)
-- next
INSERT INTO import (line) VALUES ('AT376000050110117326;ZinsenBG/000000301;31.03.2024;31.03.2024;+0,05;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT686000050110117350;Dauerauftrag1234567890MariaSynthetisch;07.01.2024;07.01.2024;+1.000.000,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Maria Synthetisch');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5013, '2024-01-07', 1,5,true,9013,'12345 67890','Maria Synthetisch','','','',100000000,'AT686000050110117350','Dauerauftrag
12345 67890 Maria Synthetisch',false,false)
-- next
INSERT INTO import (line) VALUES ('AT686000050110117350;Dauerauftrag1234567890MariaSynthetisch;07.01.2024;07.01.2024;+1.000.000,00;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT406000050110117369;Spende1e3BAWAATWWAT555555555555555555SyntheticO''Quote;08.01.2024;08.01.2024;+0,10;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('Synthetic O''Quote');
-- next
SELECT * FROM ppmembers WHERE lower(name) = lower('O''Quote Synthetic');
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5014, '2024-01-08', 8,6,false,0,'BAWAATWW AT555555555555555555','Synthetic O''Quote','','','',10,'AT406000050110117369','Spende 1e3
BAWAATWW AT555555555555555555 Synthetic O''Quote',false,false)
-- next
INSERT INTO import (line) VALUES ('AT406000050110117369;Spende1e3BAWAATWWAT555555555555555555SyntheticO''Quote;08.01.2024;08.01.2024;+0,10;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT716000050110117393;Bargeld;09.01.2024;09.01.2024;-0,01;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE name <> '' AND 'AT716000050110117393;Bargeld;09.01.2024;09.01.2024;-0,01;EUR' ILIKE '%' || replace(replace(replace(name, E'\\', E'\\\\'), '%', E'\\%'), '_', E'\\_') || '%' ESCAPE E'\\'
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5015, '2024-01-09', 28,8,false,0,'','','','','',-1,'AT716000050110117393','Bargeld
',false,false)
-- next
INSERT INTO import (line) VALUES ('AT716000050110117393;Bargeld;09.01.2024;09.01.2024;-0,01;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT856000010110123642;SpendeohneGegenkontoSynthetica;10.01.2024;10.01.2024;+3,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE name <> '' AND 'AT856000010110123642;SpendeohneGegenkontoSynthetica;10.01.2024;10.01.2024;+3,00;EUR' ILIKE '%' || replace(replace(replace(name, E'\\', E'\\\\'), '%', E'\\%'), '_', E'\\_') || '%' ESCAPE E'\\'
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5016, '2024-01-10', 1,9,true,9035,'','Synthetica','','','',300,'AT856000010110123642','Spende ohne Gegenkonto Synthetica',false,false)
-- next
INSERT INTO import (line) VALUES ('AT856000010110123642;SpendeohneGegenkontoSynthetica;10.01.2024;10.01.2024;+3,00;EUR');
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;NachdemAbbruch;11.01.2024;11.01.2024;+4,00;EUR';
-- next
SELECT nextval('voucher_number') AS num;
-- next
SELECT * FROM ppmembers WHERE name <> '' AND 'AT606000050110117300;NachdemAbbruch;11.01.2024;11.01.2024;+4,00;EUR' ILIKE '%' || replace(replace(replace(name, E'\\', E'\\\\'), '%', E'\\%'), '_', E'\\_') || '%' ESCAPE E'\\'
-- next
INSERT INTO vouchers (voucher_id, date, type, orga, member, member_id, contra_account, name, street, plz, city, amount, account, comment, committed, receipt_received) VALUES (5017, '2024-01-11', 14,2,false,0,'','','','','',400,'AT606000050110117300','Nach dem Abbruch',false,false)
-- next
INSERT INTO import (line) VALUES ('AT606000050110117300;NachdemAbbruch;11.01.2024;11.01.2024;+4,00;EUR');
-- next
DROP TABLE IF EXISTS ppmembers;
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
