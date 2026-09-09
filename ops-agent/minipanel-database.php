<?php

declare(strict_types=1);

try {
    $domain = $argv[1] ?? '';
    if (! preg_match('/^[a-z0-9.-]+$/D', $domain)) {
        throw new RuntimeException('Invalid domain');
    }
    $data = json_decode(fgets(STDIN, 1048576), true, flags: JSON_THROW_ON_ERROR);
    if (($data['statistics'] ?? false) === true) {
        $registry = '/etc/minipanel/database-state/'.$domain.'.json';
        $state = is_file($registry) ? json_decode(file_get_contents($registry), true, flags: JSON_THROW_ON_ERROR) : ['databases' => []];
        $names = $data['databases'] ?? null;
        if (! is_array($names) || count($names) > 100) {
            throw new RuntimeException('Invalid statistics request');
        }
        $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION max_statement_time = 10');
        $query = $pdo->prepare("SELECT COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) AS bytes, COUNT(t.TABLE_NAME) AS tables FROM information_schema.SCHEMATA s LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME AND t.TABLE_TYPE = 'BASE TABLE' WHERE s.SCHEMA_NAME = ? GROUP BY s.SCHEMA_NAME");
        $statistics = [];
        foreach ($names as $name) {
            if (! is_string($name) || ! in_array($name, $state['databases'], true)) {
                continue;
            }
            $query->execute([$name]);
            if ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $statistics[$name] = ['bytes' => (int) $row['bytes'], 'tables' => (int) $row['tables']];
            }
        }
        echo json_encode((object) $statistics, JSON_THROW_ON_ERROR);
        exit(0);
    }
    if (isset($data['operation'])) {
        require __DIR__.'/minipanel-database-operation.php';
        exit(0);
    }
    if (! is_array($data['databases'] ?? null) || ! is_array($data['users'] ?? null) || count($data['databases']) > 100 || count($data['users']) > 100) {
        throw new RuntimeException('Invalid resources');
    }
    $databaseNames = [];
    $databaseCollations = [];
    foreach ($data['databases'] as $database) {
        $name = $database['name'] ?? null;
        $collation = $database['collation'] ?? 'utf8mb4_spanish2_ci';
        if (! is_string($name) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $name) || in_array(strtolower($name), ['mysql', 'sys', 'information_schema', 'performance_schema'], true) || ! in_array($collation, ['utf8mb4_spanish2_ci', 'utf8mb4_unicode_ci'], true)) {
            throw new RuntimeException('Invalid namespace');
        }
        $databaseNames[] = $name;
        $databaseCollations[$name] = $collation;
    }
    foreach ($data['users'] as $user) {
        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $user['name'] ?? '') ||
            in_array(strtolower($user['name']), ['root', 'mysql', 'mariadb', 'debian_sys_maint'], true) ||
            ! in_array($user['access'] ?? '', ['local', 'ip', 'any'], true) ||
            ! in_array($user['permission'] ?? '', ['read', 'write', 'admin'], true) ||
            ! is_string($user['password'] ?? null) || strlen($user['password']) < 12 || strlen($user['password']) > 128 ||
            ! is_array($user['databases'] ?? null) || array_diff($user['databases'], $databaseNames) ||
            ($user['access'] === 'ip' && ! filter_var($user['ip'] ?? '', FILTER_VALIDATE_IP))) {
            throw new RuntimeException('Invalid user');
        }
    }
    $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    if ($pdo->query("SELECT GET_LOCK('minipanel-database-sync', 10)")->fetchColumn() != 1) {
        throw new RuntimeException('Busy');
    }
    $directory = '/etc/minipanel/database-state';
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException('Registry unavailable');
    }
    $file = $directory.'/'.$domain.'.json';
    $state = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : ['databases' => [], 'accounts' => []];
    foreach (glob($directory.'/*.json') as $registry) {
        if ($registry === $file) {
            continue;
        }
        $other = json_decode(file_get_contents($registry), true, flags: JSON_THROW_ON_ERROR);
        $otherUsers = array_map(static fn (string $account): string => explode('@', $account, 2)[0], $other['accounts']);
        if (array_intersect($databaseNames, $other['databases']) || array_intersect(array_column($data['users'], 'name'), $otherUsers)) {
            throw new RuntimeException('Resources belong to another domain');
        }
    }
    foreach ($data['users'] as $user) {
        $check = $pdo->prepare('SELECT Host FROM mysql.user WHERE User = ?');
        $check->execute([$user['name']]);
        foreach ($check->fetchAll(PDO::FETCH_COLUMN) as $host) {
            if (! in_array($user['name'].'@'.$host, $state['accounts'], true)) {
                throw new RuntimeException('Unmanaged account exists');
            }
        }
    }
    $save = static function () use (&$state, $file): void {
        if (file_put_contents($file.'.tmp', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Registry write failed');
        }
        chmod($file.'.tmp', 0600);
        if (! rename($file.'.tmp', $file)) {
            throw new RuntimeException('Registry commit failed');
        }
    };
    foreach ($databaseNames as $name) {
        if (! in_array($name, $state['databases'], true)) {
            $check = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $check->execute([$name]);
            if ($check->fetchColumn()) {
                throw new RuntimeException('Unmanaged database exists');
            }
            $state['databases'][] = $name;
            $save();
        }
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE ".$databaseCollations[$name]);
    }
    foreach ($data['users'] as $user) {
        $hosts = ['localhost'];
        if ($user['access'] !== 'local') {
            $hosts[] = $user['access'] === 'any' ? '%' : $user['ip'];
        }
        foreach ($hosts as $host) {
            $key = $user['name'].'@'.$host;
            $account = $pdo->quote($user['name']).'@'.$pdo->quote($host);
            if (! in_array($key, $state['accounts'], true)) {
                $check = $pdo->prepare('SELECT User FROM mysql.user WHERE User = ? AND Host = ?');
                $check->execute([$user['name'], $host]);
                if ($check->fetchColumn()) {
                    throw new RuntimeException('Unmanaged account exists');
                }
                $state['accounts'][] = $key;
                $save();
            }
            $password = $pdo->quote($user['password']);
            $tls = $host === 'localhost' ? 'NONE' : 'SSL';
            $pdo->exec("CREATE USER IF NOT EXISTS $account IDENTIFIED BY $password REQUIRE $tls");
            $pdo->exec("ALTER USER $account IDENTIFIED BY $password REQUIRE $tls ACCOUNT UNLOCK");
            $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");
            $privileges = match ($user['permission']) {
                'read' => 'SELECT, SHOW VIEW',
                'write' => 'SELECT, INSERT, UPDATE, DELETE, SHOW VIEW',
                'admin' => 'ALL PRIVILEGES',
            };
            foreach ($user['databases'] as $database) {
                $database = str_replace('_', '\\_', $database);
                $pdo->exec("GRANT $privileges ON `$database`.* TO $account");
            }
        }
        foreach ($state['accounts'] as $key) {
            [$name, $host] = explode('@', $key, 2);
            if ($name === $user['name'] && ! in_array($host, $hosts, true)) {
                $account = $pdo->quote($name).'@'.$pdo->quote($host);
                $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");
                $pdo->exec("ALTER USER $account ACCOUNT LOCK");
            }
        }
    }
    echo "Domain databases and grants applied.\n";
} catch (Throwable $exception) {
    // Do not expose PDO messages: they may contain credentials.
    fwrite(STDERR, "Database administration failed. Check MariaDB and the domain ownership registry.\n");
    exit(1);
}
