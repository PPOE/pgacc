SELECT id,hash = crypt('reviewer-password',hash) AS success,rights FROM users WHERE name = 'synthetic-reviewer'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 2
