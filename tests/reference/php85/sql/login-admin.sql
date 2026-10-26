SELECT id,hash = crypt('correct-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-admin'
-- next
UPDATE users SET hash = crypt('correct-password', gen_salt('bf', 10)) WHERE id = 1 AND hash LIKE '$1$%'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 1 RETURNING login
