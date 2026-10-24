SELECT * FROM users WHERE cookie = {{cookie}} AND id = 10 AND login > now() - '24 hours'::interval
