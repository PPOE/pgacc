SELECT * FROM users WHERE cookie = {{cookie}} AND id = 3 AND login > now() - '24 hours'::interval
