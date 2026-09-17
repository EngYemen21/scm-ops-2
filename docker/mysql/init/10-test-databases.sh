# Sourced by the MySQL image on FIRST start only (empty data volume).
# The test suite uses scm_ops_test, and scm_ops_test_<domain> when suites run in parallel — see docs/CONVENTIONS.md.
docker_process_sql --database=mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`scm_ops_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`scm\_ops\_test%\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
