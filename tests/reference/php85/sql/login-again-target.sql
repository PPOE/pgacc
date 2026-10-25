SELECT * FROM users WHERE id = 1 AND login > now() - '24 hours'::interval
