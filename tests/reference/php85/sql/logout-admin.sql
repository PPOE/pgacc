SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
-- next
UPDATE users SET cookie = NULL WHERE id = 1
