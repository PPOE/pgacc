SELECT id,hash = crypt('norights-password',hash) AS success,rights FROM users WHERE name = 'synthetic-norights'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 9
