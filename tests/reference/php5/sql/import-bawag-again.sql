SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
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
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
DROP TABLE IF EXISTS ppmembers;
-- next
CREATE TABLE ppmembers (id integer, name text, lo integer, paid integer);
-- next
INSERT INTO ppmembers (id, name, lo, paid) VALUES (9001,'Anna Synthetisch-Müller',9,1),(9002,'Bernd Synthetisch',1,0),(9003,'Carla Synthetisch',3,0),(9004,'Dieter Synthetisch',2,1),(9005,'Emma Synthetisch',4,0),(9006,'Franz Synthetisch-Müller',5,0),(9007,'Gerda Synthetisch',6,1),(9008,'Hannes Synthetisch',10,0),(9009,'Iris Synthetisch',8,0),(9010,'Jörg Synthetisch',10,1),(9011,'Käthe Synthetisch-Müller',9,0),(9012,'Lukas Synthetisch',1,0),(9013,'Maria Synthetisch',3,1),(9014,'Norbert Synthetisch',2,0),(9015,'Olga Synthetisch',4,0),(9016,'Paul Synthetisch-Müller',5,1),(9017,'Rosa Synthetisch',6,0),(9018,'Stefan Synthetisch',10,0),(9019,'Theresa Synthetisch',8,1),(9020,'Ulrich Synthetisch',10,0),(9021,'Vera Synthetisch-Müller',9,0),(9022,'Walter Synthetisch',1,1),(9023,'Xaver Synthetisch',3,0),(9024,'Yvonne Synthetisch',2,0),(9025,'Zoë Synthetisch',4,1),(9026,'Ömer Synthetisch-Müller',5,0),(9027,'Ännchen Synthetisch',6,0),(9028,'Ludwig Synthetisch',10,1),(9029,'Sophie Synthetisch',8,0),(9030,'Max Synthetisch',10,0),(9031,'Testa Mitglied',2,1),(9032,'Mitglied Zweitname',1,0),(9033,'O''Brien Synthetisch',8,1),(9034,'Gerlinde Titelträgerin',3,0),(9035,'Synthetica',5,1);
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;SpendeBAWAATWWAT611904300234573201SyntheticSpender;02.01.2024;02.01.2024;+50,00;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;MB2024OPSKATWWAT111111111111111111TestaMitglied;03.01.2024;03.01.2024;+24,00;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;Rechnung4711BAWAATWWAT222222222222222222SyntheticLieferantGmbH;04.01.2024;04.01.2024;-1.234,56;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT916000050110110437;MitgliedsbeitragGerlindeTitelträgerinAT333333;05.01.2024;05.01.2024;+20,00;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT596000050110117318;SpendeBAWAATWWAT444444444444444444Dr.GerlindeTitelträgerin;06.01.2024;06.01.2024;+15,00;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT376000050110117326;ZinsenBG/000000301;31.03.2024;31.03.2024;+0,05;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT686000050110117350;Dauerauftrag1234567890MariaSynthetisch;07.01.2024;07.01.2024;+1.000.000,00;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT406000050110117369;Spende1e3BAWAATWWAT555555555555555555SyntheticO''Quote;08.01.2024;08.01.2024;+0,10;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT716000050110117393;Bargeld;09.01.2024;09.01.2024;-0,01;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT856000010110123642;SpendeohneGegenkontoSynthetica;10.01.2024;10.01.2024;+3,00;EUR';
-- next
SELECT 1 FROM import WHERE line = 'AT606000050110117300;NachdemAbbruch;11.01.2024;11.01.2024;+4,00;EUR';
-- next
DROP TABLE IF EXISTS ppmembers;
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
