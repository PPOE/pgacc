SELECT * FROM users WHERE cookie = {{cookie}} AND id = 9 AND login > now() - '24 hours'::interval
