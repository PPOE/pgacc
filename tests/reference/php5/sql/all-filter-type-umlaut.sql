SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted   AND lower(type.name) LIKE lower('%Spende ü%') ORDER BY date ASC, voucher_id DESC, vouchers.id ASC
