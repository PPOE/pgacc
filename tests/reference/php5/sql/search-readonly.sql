SELECT * FROM users WHERE cookie = {{cookie}} AND id = 7 AND login > now() - '24 hours'::interval
