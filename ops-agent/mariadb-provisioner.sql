-- Execute once as a MariaDB DBA. Replace the password before running.
-- This account is deliberately not a MariaDB root account.
CREATE USER 'minipanel_provisioner'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
GRANT CREATE, DROP, ALTER, INDEX, REFERENCES ON *.* TO 'minipanel_provisioner'@'127.0.0.1';
GRANT CREATE USER ON *.* TO 'minipanel_provisioner'@'127.0.0.1';
GRANT GRANT OPTION ON *.* TO 'minipanel_provisioner'@'127.0.0.1';
FLUSH PRIVILEGES;
