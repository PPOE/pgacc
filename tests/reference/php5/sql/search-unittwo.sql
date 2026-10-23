SELECT * FROM users WHERE cookie = {{cookie}} AND id = 6 AND login > now() - '24 hours'::interval
