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
SELECT vaccount FROM vouchers WHERE NOT deleted AND amount != 0 GROUP BY vaccount ORDER BY vaccount
-- next
SELECT id,name FROM type WHERE income = 1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 1  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 8  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 18  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 11  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 12  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 13  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 14  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 29  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income = 1)  AND vaccount LIKE '%' 
-- next
SELECT id,name FROM type WHERE income <= 0  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 20  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 21  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 1029  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 23  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 28  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype = 30  AND vaccount LIKE '%' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND vaccount LIKE '%' 
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
