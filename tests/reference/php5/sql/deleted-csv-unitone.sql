SELECT * FROM users WHERE cookie = {{cookie}} AND id = 3 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE cookie = {{cookie}} AND id = 3 AND login > now() - '24 hours'::interval
-- next
SELECT vouchers.*,lo.name AS lo_name,type.name AS type_name FROM vouchers LEFT JOIN lo ON orga = lo.id LEFT JOIN type ON type = type.id WHERE deleted AND ack1 IS NULL AND ack2 IS NULL  AND orga IN ('1')   ORDER BY date ASC, voucher_id DESC, vouchers.id ASC
