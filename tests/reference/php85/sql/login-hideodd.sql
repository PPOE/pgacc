SELECT id,hash = crypt('hide-odd-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-hide-odd'
-- next
UPDATE users SET hash = crypt('hide-odd-password', gen_salt('bf', 10)) WHERE id = 4 AND hash LIKE '$1$%'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 4 RETURNING login
