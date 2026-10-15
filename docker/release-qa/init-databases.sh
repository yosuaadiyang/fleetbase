#!/bin/sh
set -eu
# This initializer is only for the dedicated QA database container.
[ "${MYSQL_DATABASE:-}" = contrust_release_qa ] || exit 1
[ "${MYSQL_USER:-}" = contrust_qa ] || exit 1
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
for suffix in '' _sandbox _storefront _storefront_sandbox _ledger _ledger_sandbox; do
  qa_db="contrust_release_qa${suffix}"
  printf 'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON `%s`.* TO '\''contrust_qa'\''@'\''%%'\'';\n' "$qa_db" "$qa_db" | mysql -u root
 done
unset MYSQL_PWD
