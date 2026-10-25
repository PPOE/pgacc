<?php

// Connection string of the synthetic test database (PG* variables of the
// test containers), shared by the HTTP tests and the PHPUnit bootstrap.

function pgacc_test_connection_string()
{
    return sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        getenv('PGHOST'),
        getenv('PGPORT'),
        getenv('PGDATABASE'),
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    );
}
