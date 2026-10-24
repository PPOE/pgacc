SELECT * FROM users WHERE cookie = {{cookie}} AND id = 11 AND login > now() - '24 hours'::interval
