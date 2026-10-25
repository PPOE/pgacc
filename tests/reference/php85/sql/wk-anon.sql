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
SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 1  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 8  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 14  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 11  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 12  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 13  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 29  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 20  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 28  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 23  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 30  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date < '2013-01-29' AND date >= '2012-09-28' 
-- next
SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 1  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 8  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 14  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 11  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 12  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 13  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 29  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 20  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 28  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 23  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 30  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date < '2013-03-06' AND date >= '2012-12-28' 
-- next
SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 1  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 8  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 14  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 11  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 12  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 13  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 29  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 20  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 28  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 23  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype = 30  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 3 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date < '2013-03-06' AND date >= '2013-01-17' 
-- next
SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 1  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 8  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 14  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 11  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 12  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 13  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 29  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 20  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 28  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 23  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype = 30  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 1  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 8  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 14  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 11  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 12  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 13  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 29  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 20  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 28  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 23  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype = 30  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 10 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 1  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 8  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 14  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 11  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 12  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 13  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 29  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 20  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 28  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 23  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype = 30  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT id,name FROM oo WHERE lo = 6 AND name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT id,name FROM type WHERE income = 1 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 1  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 8  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 14  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 11  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 12  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 13  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 29  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT id,name FROM type WHERE income <= 0 AND realtype = id ORDER BY used DESC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 20  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 28  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 23  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype = 30  AND date < '2013-11-01' AND date >= '2013-06-13' 
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted  AND orga = 2 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date < '2013-11-01' AND date >= '2013-06-13' 
