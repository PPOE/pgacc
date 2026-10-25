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
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12,29,30) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT COUNT(*) AS c FROM balance WHERE date = '2018-12-31'
-- next
SELECT date FROM balance ORDER BY date DESC LIMIT 1
-- next
SELECT COUNT(*) AS c FROM balance WHERE date = '2019-12-31'
-- next
SELECT date FROM balance ORDER BY date DESC LIMIT 1
-- next
SELECT SUM(value) AS value FROM balance WHERE date = '2025-12-31'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL) AND date < '2020-01-01'
-- next
SELECT SUM(value) AS value FROM balance WHERE date = '2025-12-31'
-- next
SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT SUM(value) AS value FROM balance WHERE orga = 6 AND date = '2025-12-31'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND realtype NOT IN (11,12) AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND orga = 6 AND date < '2020-01-01'
-- next
SELECT value FROM balance WHERE orga = 6 AND date = '2025-12-31'
-- next
SELECT id,name FROM type WHERE income = 1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 1 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 8 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 18 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 11 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 12 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 13 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 14 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 29 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM type WHERE income <= 0  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM type WHERE income = 1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 1 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 8 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 18 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 11 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 12 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 13 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 14 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 29 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM type WHERE income <= 0  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT id,name FROM type WHERE income = 1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 1 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 8 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 18 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 11 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 12 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 13 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 14 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 29 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM type WHERE income <= 0  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM oo WHERE lo = 6 AND name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT id,name FROM type WHERE income = 1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 1 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 8 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 18 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 11 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 12 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 13 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 14 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 29 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND realtype IN (SELECT id FROM type WHERE income = 1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM type WHERE income <= 0  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND realtype IN (SELECT id FROM type WHERE income <= 0)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM type WHERE income = -1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND realtype IN (SELECT id FROM type WHERE income = -1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM type WHERE income = -1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 10 AND realtype IN (SELECT id FROM type WHERE income = -1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM lo WHERE name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT id,name FROM type WHERE income = -1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 6 AND realtype IN (SELECT id FROM type WHERE income = -1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT id,name FROM oo WHERE lo = 6 AND name LIKE '%Piratenpartei%' ORDER BY id ASC
-- next
SELECT id,name FROM type WHERE income = -1  ORDER BY realtype ASC,id ASC
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 20 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 21 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 1029 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 23 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 28 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND type = 30 AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers LEFT JOIN type T ON T.id = type WHERE NOT deleted AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)   AND orga = 2 AND realtype IN (SELECT id FROM type WHERE income = -1)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT * FROM person_type WHERE public
-- next
SELECT SUM(amount) AS sum FROM vouchers WHERE NOT deleted AND type = 8 AND person_type = 3 AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers WHERE NOT deleted AND type = 8 AND person_type = 1 AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers WHERE NOT deleted AND type = 8 AND person_type = 5 AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND date >= '2019-01-01' AND date < '2020-01-01'
-- next
SELECT SUM(amount) AS sum FROM vouchers WHERE NOT deleted AND type = 8 AND person_type = 4 AND  NOT deleted AND (ack1 IS NOT NULL OR ack2 IS NOT NULL)  AND date >= '2019-01-01' AND date < '2020-01-01'
