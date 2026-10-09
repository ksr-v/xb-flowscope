#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
: "${XBOARD_PATH:?Set XBOARD_PATH to an audited Xboard checkout with vendor dependencies}"
find FlowScope -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/backend.php
php tests/integration.php
php tests/install.php
php tests/upgrade-install.php
php tests/upgrade-install.php 2.1.1
php tests/upgrade-install.php 2.3.0
php tests/ipv4.php
node --check FlowScope/resources/assets/flowscope.js
python3 tests/collector_test.py
python3 tests/bootstrap_test.py
python3 tests/protocol-e2e.py
