SELECT id,hash = crypt('unit-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-unit-one'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 3 RETURNING login
