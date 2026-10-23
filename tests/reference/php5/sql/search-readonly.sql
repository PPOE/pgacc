SELECT * FROM users WHERE cookie = {{cookie}} AND id = 8 AND login > now() - '24 hours'::interval
