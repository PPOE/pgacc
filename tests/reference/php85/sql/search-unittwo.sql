SELECT * FROM users WHERE id = 6 AND login > now() - '24 hours'::interval
