#!/bin/sh
set -eu

php tests/http-smoke.php
php tests/write-workflows.php
