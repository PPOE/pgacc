SELECT id,hash = crypt('unit-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-unit-one'
-- next
UPDATE users SET hash = crypt('unit-password', gen_salt('bf', 10)) WHERE id = 3 AND hash LIKE '$1$%'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 3 RETURNING login
