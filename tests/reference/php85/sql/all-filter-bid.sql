SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted   AND id = 103 ORDER BY date ASC, voucher_id DESC, vouchers.id ASC
-- next
-- ERROR 42702: column reference "id" is ambiguous
-- in: SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted   AND id = 103 ORDER BY date ASC, voucher_id DESC, vouchers.id ASC
