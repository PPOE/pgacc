SELECT id,hash = crypt('norights-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-norights'
-- next
UPDATE users SET hash = crypt('norights-password', gen_salt('bf', 10)) WHERE id = 11 AND hash LIKE '$1$%'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 11 RETURNING login
