SELECT id,hash = crypt('correct-password',hash) AS success,rights FROM users WHERE name = 'synthetic-admin'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 1
