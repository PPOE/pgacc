# Reference runs only: log every statement's text as CSV to the shared pglog
# volume, with the client's application_name where the server has one (9.0+).
# The application server sets application_name per HTTP request
# (runtime/prepend.php), so the recorder can attribute each statement to its
# request; on 8.4 it attributes by backend process instead
# (ref_statement_log_until_marker() in tests/reference/lib.php).
# Sourced from /docker-entrypoint-initdb.d by the server image's entrypoint
# (the official images and tests/postgres84/entrypoint.sh) with PGDATA set;
# the settings apply from the server restart that ends initialisation.
# postgresql.conf instead of ALTER SYSTEM (9.4+), so that 8.4 works too.
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
# log_file_mode exists from 9.1; 8.4 writes the log with mode 0600, which the
# recorder (root in its container) can read as well.
if [ "$(cat "$PGDATA/PG_VERSION")" != 8.4 ]; then
  printf "log_file_mode = 0644\n" >>"$PGDATA/postgresql.conf"
fi
