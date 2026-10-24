SELECT id,hash = crypt('hide-odd-password',hash) AS success,rights FROM users WHERE name = 'synthetic-hide-odd'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 4
