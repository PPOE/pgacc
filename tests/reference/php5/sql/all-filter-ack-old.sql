SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted   AND (ack1_old LIKE '%%' OR ack2_old LIKE '%%')  ORDER BY date ASC, voucher_id DESC, vouchers.id ASC
