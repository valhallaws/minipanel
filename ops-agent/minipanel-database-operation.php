<?php

declare(strict_types=1);

$action = $data['operation'];
$name = $data['name'] ?? '';
$token = $data['token'] ?? '';
if (! in_array($action, ['export', 'import', 'delete-database', 'delete-user'], true) ||
    ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $name) ||
    ! preg_match('/^[a-f0-9]{32}$/D', $token)) {
    throw new RuntimeException('Invalid operation');
}
$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
if ($pdo->query("SELECT GET_LOCK('minipanel-database-sync', 2)")->fetchColumn() != 1) {
    throw new RuntimeException('Busy');
}
$registry = '/etc/minipanel/database-state/'.$domain.'.json';
if (! is_file($registry)) {
    $statement = $action === 'delete-user'
        ? $pdo->prepare('SELECT 1 FROM mysql.user WHERE User = ? LIMIT 1')
        : $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
    $statement->execute([$name]);
    if ($statement->fetchColumn()) {
        throw new RuntimeException('Resource not owned');
    }

    echo "Pending resource was never provisioned; local record can be removed.\n";
    exit(0);
}
$state = json_decode(file_get_contents($registry), true, flags: JSON_THROW_ON_ERROR);
$accounts = array_values(array_filter($state['accounts'], static fn (string $account): bool => explode('@', $account, 2)[0] === $name));
if (($action === 'delete-user' && $accounts === []) || ($action !== 'delete-user' && ! in_array($name, $state['databases'], true))) {
    throw new RuntimeException('Resource not owned');
}
$save = static function () use (&$state, $registry): void {
    if (file_put_contents($registry.'.tmp', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Registry write failed');
    }
    chmod($registry.'.tmp', 0600);
    if (! rename($registry.'.tmp', $registry)) {
        throw new RuntimeException('Registry commit failed');
    }
};
$run = static function (array $command, mixed $input = null, string $output = '/dev/null', ?array $environment = null) use ($data): void {
    $process = proc_open(array_merge(['/usr/bin/timeout', '--kill-after=2', '7100'], $command), [
        0 => is_resource($input) ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
        1 => ['file', $output, 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes, null, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Database process failed');
    }
    if (is_resource($input)) {
        try {
            $copied = stream_copy_to_stream($input, $pipes[0], 1073741825);
        } finally {
            fclose($pipes[0]);
        }
    }
    $code = proc_close($process);
    if ($code !== 0 || (isset($copied) && ($copied === false || $copied !== ($data['size'] ?? null)))) {
        throw new RuntimeException('Database process failed');
    }
};
$grantName = str_replace('_', '\_', $name);
$importUser = 'mpi'.substr(hash('sha256', $domain.'|'.$name), 0, 24);
$importAccount = $pdo->quote($importUser)."@'localhost'";

if ($action === 'delete-user') {
    foreach ($accounts as $key) {
        [$user, $host] = explode('@', $key, 2);
        $pdo->exec('DROP USER IF EXISTS '.$pdo->quote($user).'@'.$pdo->quote($host));
    }
    $state['accounts'] = array_values(array_diff($state['accounts'], $accounts));
    $save();
} elseif ($action === 'delete-database') {
    foreach ($state['accounts'] as $key) {
        [$user, $host] = explode('@', $key, 2);
        $check = $pdo->prepare('SELECT Db FROM mysql.db WHERE User = ? AND Host = ? AND Db = ?');
        $check->execute([$user, $host, $grantName]);
        if ($check->fetchColumn()) {
            $pdo->exec("REVOKE ALL PRIVILEGES ON `$grantName`.* FROM ".$pdo->quote($user).'@'.$pdo->quote($host));
        }
    }
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    if (in_array($importUser.'@localhost', $state['accounts'], true)) {
        $pdo->exec("DROP USER IF EXISTS $importAccount");
        $state['accounts'] = array_values(array_diff($state['accounts'], [$importUser.'@localhost']));
    }
    $state['databases'] = array_values(array_diff($state['databases'], [$name]));
    $save();
} elseif ($action === 'import') {
    if (! is_int($data['size'] ?? null) || $data['size'] < 1 || $data['size'] > 1073741824) {
        throw new RuntimeException('Invalid SQL');
    }
    if (! in_array($importUser.'@localhost', $state['accounts'], true)) {
        $check = $pdo->prepare('SELECT User FROM mysql.user WHERE User = ?');
        $check->execute([$importUser]);
        if ($check->fetchColumn()) {
            throw new RuntimeException('Unmanaged import account');
        }
        $state['accounts'][] = $importUser.'@localhost';
        $save();
    }
    $password = bin2hex(random_bytes(32));
    $quotedPassword = $pdo->quote($password);
    $pdo->exec("CREATE USER IF NOT EXISTS $importAccount IDENTIFIED BY $quotedPassword ACCOUNT LOCK");
    $pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $importAccount");
    $pdo->exec("GRANT ALL PRIVILEGES ON `$grantName`.* TO $importAccount");
    try {
        $pdo->exec("ALTER USER $importAccount IDENTIFIED BY $quotedPassword ACCOUNT UNLOCK");
        $run(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/mariadb', '--no-defaults', '--protocol=socket', '--socket=/run/mysqld/mysqld.sock',
            '--user='.$importUser, '--database='.$name, '--binary-mode', '--batch', '--local-infile=0', '--skip-reconnect'],
            STDIN, '/dev/null', ['MYSQL_PWD' => $password, 'PATH' => '/usr/bin:/bin']);
    } finally {
        $pdo->exec("ALTER USER $importAccount ACCOUNT LOCK");
    }
} else {
    $panelUser = trim(file_get_contents('/etc/minipanel/panel-user'));
    $panelAccount = posix_getpwnam($panelUser);
    if (! $panelAccount || $panelUser === 'www-data' || $panelAccount['uid'] === 0) {
        throw new RuntimeException('Dedicated panel identity required');
    }
    $directory = '/var/lib/minipanel/database-exports';
    if (! is_dir($directory) && ! mkdir($directory, 0750, true)) {
        throw new RuntimeException('Export directory unavailable');
    }
    chgrp($directory, $panelAccount['gid']);
    $temporary = $directory.'/'.$token;
    if (! mkdir($temporary, 0700)) {
        throw new RuntimeException('Export already exists');
    }
    $dump = $temporary.'/'.$name.'.sql';
    $archive = $temporary.'/dump.zip';
    try {
        $run(['/usr/bin/mariadb-dump', '--no-defaults', '--protocol=socket', '--socket=/run/mysqld/mysqld.sock', '--user=root',
            '--single-transaction', '--routines', '--events', '--hex-blob', '--skip-add-locks', $name], null, $dump);
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true || ! $zip->addFile($dump, $name.'.sql') || ! $zip->close()) {
            throw new RuntimeException('ZIP failed');
        }
        chmod($archive, 0600);
        chown($archive, $panelUser);
        chown($temporary, $panelUser);
        $completed = true;
    } finally {
        foreach (! empty($completed) ? [$dump] : [$dump, $archive] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (empty($completed)) {
            rmdir($temporary);
        }
    }
}
echo "Database operation completed.\n";
