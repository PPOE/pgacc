# Reference runs only: log every statement's text as CSV to the shared pglog
# volume, with the client's application_name. The application server sets
# application_name per HTTP request (runtime/prepend.php), so the recorder can
# attribute each statement to its request
# (ref_statement_log_until_marker() in tests/reference/lib.php).
# Sourced from /docker-entrypoint-initdb.d by the official image's entrypoint
# with PGDATA set; the settings apply from the server restart that ends
# initialisation.
cat >>"$PGDATA/postgresql.conf" <<'CONF'

# pgacc reference statement log (tests/reference/runtime/postgres-statement-log.sh)
logging_collector = on
log_destination = 'csvlog'
log_directory = '/pglog'
log_filename = 'statements.log'
log_rotation_age = 0
log_rotation_size = 0
log_statement = 'all'
CONF
printf "log_file_mode = 0644\n" >>"$PGDATA/postgresql.conf"
