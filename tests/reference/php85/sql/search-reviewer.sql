SELECT * FROM users WHERE id = 2 AND login > now() - '24 hours'::interval
