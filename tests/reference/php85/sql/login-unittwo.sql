SELECT id,hash = crypt('unit-two-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-unit-two'
-- next
UPDATE users SET hash = crypt('unit-two-password', gen_salt('bf', 10)) WHERE id = 6 AND hash LIKE '$1$%'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 6 RETURNING login
