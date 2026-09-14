<?php

declare(strict_types=1);

try {
    $data = json_decode(fgets(STDIN, 1048576), true, flags: JSON_THROW_ON_ERROR);
    $users = $data['users'] ?? null;
    if (! is_array($users) || count($users) > 20) {
        throw new RuntimeException('Invalid users');
    }

    $names = [];
    foreach ($users as $user) {
        if (! is_array($user)
            || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $user['name'] ?? '')
            || in_array(strtolower($user['name']), ['root', 'mysql', 'mariadb', 'debian_sys_maint'], true)
            || ! is_string($user['password'] ?? null)
            || strlen($user['password']) < 12
            || strlen($user['password']) > 128
            || in_array($user['name'], $names, true)) {
            throw new RuntimeException('Invalid user');
        }
        $names[] = $user['name'];
    }

    $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query("SELECT GET_LOCK('minipanel-database-sync', 10)")->fetchColumn() != 1) {
        throw new RuntimeException('Busy');
    }
    $directory = '/etc/minipanel/database-state';
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException('Registry unavailable');
    }
    $registry = $directory.'/global-dba.json';
    $state = is_file($registry) ? json_decode(file_get_contents($registry), true, flags: JSON_THROW_ON_ERROR) : ['accounts' => []];
    foreach (glob($directory.'/*.json') as $file) {
        if ($file === $registry) {
            continue;
        }
        $other = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $otherNames = array_map(static fn (string $account): string => explode('@', $account, 2)[0], $other['accounts'] ?? []);
        if (array_intersect($names, $otherNames)) {
            throw new RuntimeException('Name belongs to a site');
        }
    }
    foreach ($users as $user) {
        $account = $user['name'].'@localhost';
        if (! in_array($account, $state['accounts'], true)) {
            $check = $pdo->prepare('SELECT 1 FROM mysql.user WHERE User = ? AND Host = ?');
            $check->execute([$user['name'], 'localhost']);
            if ($check->fetchColumn()) {
                throw new RuntimeException('Unmanaged account exists');
            }
            $state['accounts'][] = $account;
        }
        $quoted = $pdo->quote($user['name'])."@'localhost'";
        $password = $pdo->quote($user['password']);
        $pdo->exec("CREATE USER IF NOT EXISTS $quoted IDENTIFIED BY $password");
        $pdo->exec("ALTER USER $quoted IDENTIFIED BY $password ACCOUNT UNLOCK");
        $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $quoted");
        $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO $quoted WITH GRANT OPTION");
    }
    foreach ($state['accounts'] as $account) {
        [$name, $host] = explode('@', $account, 2);
        if (in_array($name, $names, true)) {
            continue;
        }
        $quoted = $pdo->quote($name).'@'.$pdo->quote($host);
        $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $quoted");
        $pdo->exec("ALTER USER $quoted ACCOUNT LOCK");
    }
    if (file_put_contents($registry.'.tmp', json_encode(['accounts' => $state['accounts']], JSON_THROW_ON_ERROR), LOCK_EX) === false
        || ! rename($registry.'.tmp', $registry)) {
        throw new RuntimeException('Registry write failed');
    }
    chmod($registry, 0600);
    echo "Global DBA accounts applied.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Global DBA administration failed.\n");
    exit(1);
}
