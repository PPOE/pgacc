SELECT id,hash = crypt('unit-two-password',hash) AS success,rights FROM users WHERE name = 'synthetic-unit-two'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 6 RETURNING login
