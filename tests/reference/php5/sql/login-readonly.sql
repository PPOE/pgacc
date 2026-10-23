SELECT id,hash = crypt('readonly-password',hash) AS success,rights FROM users WHERE name = 'synthetic-readonly'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 10
