-- Reference runs only: log every statement's text, with the client's
-- application_name, as CSV to the shared pglog volume. The application
-- server sets application_name per HTTP request (runtime/prepend.php), so the
-- recorder can attribute each statement to its request. Runs from
-- /docker-entrypoint-initdb.d; the settings apply from the server restart
-- that ends initialisation. ALTER SYSTEM exists from PostgreSQL 9.4 on.
ALTER SYSTEM SET logging_collector = on;
ALTER SYSTEM SET log_destination = 'csvlog';
ALTER SYSTEM SET log_directory = '/pglog';
ALTER SYSTEM SET log_filename = 'statements.log';
ALTER SYSTEM SET log_file_mode = '0644';
ALTER SYSTEM SET log_rotation_age = 0;
ALTER SYSTEM SET log_rotation_size = 0;
ALTER SYSTEM SET log_statement = 'all';
