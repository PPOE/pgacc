SELECT * FROM users WHERE id = 3 AND login > now() - '24 hours'::interval
