SELECT id,hash = crypt('readonly-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-readonly'
-- next
UPDATE users SET hash = crypt('readonly-password', gen_salt('bf', 10)) WHERE id = 10 AND hash LIKE '$1$%'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 10 RETURNING login
