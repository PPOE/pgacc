SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted   ORDER BY amount ASC, date ASC, voucher_id DESC, vouchers.id ASC
-- next
SELECT name FROM type WHERE id = 21
-- next
SELECT name FROM lo WHERE id = 10
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 6
-- next
SELECT name FROM type WHERE id = 30
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 1029
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 21
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 28
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 28
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 4
-- next
SELECT name FROM type WHERE id = 28
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 20
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 1
-- next
SELECT name FROM lo WHERE id = 10
-- next
SELECT name FROM type WHERE id = 12
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 14
-- next
SELECT name FROM lo WHERE id = 4
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 1
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 1
-- next
SELECT name FROM lo WHERE id = 10
-- next
SELECT name FROM type WHERE id = 1
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 18
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 11
-- next
SELECT name FROM lo WHERE id = 2
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 8
-- next
SELECT name FROM lo WHERE id = 6
-- next
SELECT name FROM type WHERE id = 29
-- next
SELECT name FROM lo WHERE id = 1
-- next
SELECT name FROM type WHERE id = 11
-- next
SELECT name FROM lo WHERE id = 6
