SELECT id,hash = crypt('reviewer-password',hash) AS success,hash LIKE '$1$%' AS md5,rights FROM users WHERE name = 'synthetic-reviewer'
-- next
UPDATE users SET hash = crypt('reviewer-password', gen_salt('bf', 10)) WHERE id = 2 AND hash LIKE '$1$%'
-- next
UPDATE users SET cookie = {{cookie}}, login = now() WHERE id = 2 RETURNING login
