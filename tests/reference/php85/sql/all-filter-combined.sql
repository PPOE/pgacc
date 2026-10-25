SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE NOT deleted   AND date >= '2024-01-01' AND date <= '2024-12-31'  AND orga = 1 AND lower(lo.name) LIKE lower('%1%') AND amount >000 AND lower(comment) LIKE lower('%donation%') ORDER BY date ASC, voucher_id DESC, vouchers.id ASC
