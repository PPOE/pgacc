SELECT id,hash = crypt('unit-password',hash) AS success,rights FROM users WHERE name = 'synthetic-unit-one'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 3
