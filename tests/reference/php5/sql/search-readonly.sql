SELECT * FROM users WHERE id = 10 AND login > now() - '24 hours'::interval
