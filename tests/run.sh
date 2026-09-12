#!/usr/bin/env bash
set -euo pipefail
php -l crosspost-to-loops.php
php -l includes/class-rabbit-cast.php
php tests/rabbit-cast-test.php
