SELECT * FROM users WHERE id = 11 AND login > now() - '24 hours'::interval
