SELECT * FROM users WHERE cookie = {{cookie}} AND id = 2 AND login > now() - '24 hours'::interval
