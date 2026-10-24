SELECT * FROM users WHERE cookie = {{cookie}} AND id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT file,voucher_id FROM vouchers WHERE NOT deleted AND id = 1001  ORDER BY id ASC;
