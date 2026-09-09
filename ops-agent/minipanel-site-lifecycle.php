<?php

declare(strict_types=1);

/** Root-only lifecycle executor. All targets are derived from a validated namespace, never from .env. */
final class MiniPanelSiteLifecycle
{
    private string $directory;

    private string $manifest;

    private string $siteRoot;

    private string $user;

    private array $state = [];

    private ?PDO $database = null;

    public function __construct(private string $domain)
    {
        self::validateDomain($domain);
        $this->directory = '/var/lib/minipanel/site-trash/'.$domain;
        $this->manifest = $this->directory.'/manifest.json';
        $this->siteRoot = '/var/www/'.$domain;
        $this->user = 'mp_'.str_replace('.', '_', $domain);
        if (is_file($this->manifest)) {
            $this->state = json_decode(file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);
        }
    }

    public static function validateDomain(string $domain): void
    {
        if (strlen($domain) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/D', $domain)) {
            throw new RuntimeException('Invalid domain');
        }
    }

    private function run(array $arguments, bool $allowFailure = false): string
    {
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start server operation');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 && ! $allowFailure) {
            throw new RuntimeException('Server operation failed: '.basename($arguments[0]));
        }

        return trim($output);
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path.'.tmp', $contents, LOCK_EX) === false || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Could not persist lifecycle state');
        }
    }

    private function save(): void
    {
        $this->write($this->manifest, json_encode($this->state, JSON_THROW_ON_ERROR));
    }

    private function pdo(): PDO
    {
        if ($this->database === null) {
            $this->database = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->database->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
            if ($this->database->query("SELECT GET_LOCK('minipanel-database-sync', 5)")->fetchColumn() != 1) {
                throw new RuntimeException('Database is busy');
            }
        }

        return $this->database;
    }

    private function ownedDatabases(): array
    {
        $registry = '/etc/minipanel/database-state/'.$this->domain.'.json';
        $state = is_file($registry) ? json_decode(file_get_contents($registry), true, flags: JSON_THROW_ON_ERROR) : ['databases' => [], 'accounts' => []];
        foreach (glob('/etc/minipanel/database-state/*.json') ?: [] as $otherPath) {
            if ($otherPath === $registry) {
                continue;
            }
            $other = json_decode(file_get_contents($otherPath), true, flags: JSON_THROW_ON_ERROR);
            if (array_intersect($state['databases'], $other['databases']) || array_intersect($state['accounts'], $other['accounts'])) {
                throw new RuntimeException('Shared database resources require manual review');
            }
        }
        foreach ($state['databases'] as $name) {
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) || in_array(strtolower($name), ['mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
                throw new RuntimeException('Unsafe database registry');
            }
            $grants = $this->pdo()->prepare('SELECT User, Host FROM mysql.db WHERE ? LIKE Db');
            $grants->execute([$name]);
            foreach ($grants->fetchAll(PDO::FETCH_ASSOC) as $grant) {
                if (! in_array($grant['User'].'@'.$grant['Host'], $state['accounts'], true)) {
                    throw new RuntimeException('A database is used by an unmanaged account; review ownership first');
                }
            }
        }
        foreach ($state['accounts'] as $account) {
            [$user, $host] = explode('@', $account, 2);
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $user) || in_array(strtolower($user), ['root', 'mysql', 'mariadb'], true)) {
                throw new RuntimeException('Unsafe account registry');
            }
            $grants = $this->pdo()->prepare('SELECT Db FROM mysql.db WHERE User = ? AND Host = ?');
            $grants->execute([$user, $host]);
            foreach ($grants->fetchAll(PDO::FETCH_COLUMN) as $db) {
                if (! in_array(str_replace(['\\_', '\\%'], ['_', '%'], $db), $state['databases'], true)) {
                    throw new RuntimeException('Account has grants outside this domain');
                }
            }
            $accounts = $this->pdo()->prepare('SELECT Host FROM mysql.user WHERE User = ?');
            $accounts->execute([$user]);
            foreach ($accounts->fetchAll(PDO::FETCH_COLUMN) as $otherHost) {
                if (! in_array($user.'@'.$otherHost, $state['accounts'], true)) {
                    throw new RuntimeException('Account identity is shared outside this domain');
                }
            }
            $exists = $this->pdo()->prepare('SELECT 1 FROM mysql.user WHERE User = ? AND Host = ?');
            $exists->execute([$user, $host]);
            if ($exists->fetchColumn()) {
                foreach ($this->pdo()->query('SHOW GRANTS FOR '.$this->accountSql($account))->fetchAll(PDO::FETCH_COLUMN) as $grant) {
                    if (str_contains($grant, ' ON *.* ') && ! str_starts_with($grant, 'GRANT USAGE ON *.* ')) {
                        throw new RuntimeException('Account has server-wide privileges; review ownership first');
                    }
                }
            }
        }

        return $state;
    }

    private function accountSql(string $key): string
    {
        [$user, $host] = explode('@', $key, 2);

        return $this->pdo()->quote($user).'@'.$this->pdo()->quote($host);
    }

    private function resources(): array
    {
        $domain = $this->domain;
        $paths = [$this->siteRoot, '/etc/nginx/sites-enabled/'.$domain, '/etc/nginx/sites-available/'.$domain,
            '/etc/nginx/snippets/minipanel-'.$domain.'.conf', '/etc/minipanel/aliases/'.$domain,
            '/etc/minipanel/deploy-keys/'.$domain, '/etc/minipanel/deploy-keys/'.$domain.'.pub',
            '/var/lib/minipanel/suspended/'.$domain, '/var/spool/cron/crontabs/'.$this->user];
        foreach (['8.2', '8.3', '8.4'] as $version) {
            $pool = '/etc/php/'.$version.'/fpm/pool.d/minipanel-'.$domain.'.conf';
            if (file_exists($pool)) {
                if (is_link($pool) || fileowner($pool) !== 0) {
                    throw new RuntimeException('Unexpected PHP pool ownership');
                }
                $paths[] = $pool;
            }
        }
        foreach (glob('/etc/systemd/system/minipanel-'.$domain.'*') ?: [] as $path) {
            $name = basename($path);
            if (in_array($name, ['minipanel-'.$domain.'.service', 'minipanel-'.$domain.'.timer', 'minipanel-'.$domain.'-schedule.service'], true) ||
                preg_match('/^minipanel-'.preg_quote($domain, '/').'-queue-[a-zA-Z0-9_-]+-\d+\.(?:service|timer)$/D', $name)) {
                if (is_link($path) || ! is_file($path) || fileowner($path) !== 0) {
                    throw new RuntimeException('Unexpected service ownership');
                }
                $paths[] = $path;
            }
        }
        foreach (glob('/var/backups/minipanel/'.$domain.'-*') ?: [] as $path) {
            if (preg_match('/^'.preg_quote($domain, '/').'-(?:pre-restore-)?\d{8}-\d{6}(?:\.tar\.gz)?$/D', basename($path))) {
                $paths[] = $path;
            }
        }
        $nginx = '/etc/nginx/sites-available/'.$domain;
        if (is_file($nginx)) {
            preg_match_all('~/etc/letsencrypt/live/([a-zA-Z0-9._-]+)/~', file_get_contents($nginx), $matches);
            foreach (array_unique($matches[1]) as $certificate) {
                if (in_array($certificate, ['.', '..'], true)) {
                    throw new RuntimeException('Invalid certificate path');
                }
                $shared = false;
                foreach (array_merge(glob('/etc/nginx/sites-available/*') ?: [], glob('/etc/nginx/conf.d/*') ?: [], glob('/var/lib/minipanel/suspended/*/nginx') ?: []) as $other) {
                    if ($other === '/var/lib/minipanel/suspended/'.$domain.'/nginx') {
                        continue;
                    }
                    if ($other !== $nginx && is_file($other) && str_contains(file_get_contents($other), '/etc/letsencrypt/live/'.$certificate.'/')) {
                        $shared = true;
                    }
                }
                if (! $shared) {
                    $paths[] = '/etc/letsencrypt/renewal/'.$certificate.'.conf';
                    $paths[] = '/etc/letsencrypt/live/'.$certificate;
                    $paths[] = '/etc/letsencrypt/archive/'.$certificate;
                }
            }
        }

        return array_values(array_filter(array_unique($paths), static fn (string $path): bool => file_exists($path) || is_link($path)));
    }

    private function prepare(): void
    {
        if ($this->state !== []) {
            return;
        }
        $nginx = '/etc/nginx/sites-available/'.$this->domain;
        $userExists = (bool) posix_getpwnam($this->user);
        $emptySite = ! file_exists($this->siteRoot) && ! is_link($this->siteRoot) && ! file_exists($nginx) && ! is_link($nginx) && ! $userExists;
        if (! $emptySite && (is_link($this->siteRoot) || is_link($nginx) || ! is_file($nginx) || fileowner($nginx) !== 0 || ! $userExists)) {
            throw new RuntimeException('Unknown or unsafe site; provisioning must finish first');
        }
        $databases = $this->ownedDatabases();
        $paths = $this->resources();
        $services = [];
        foreach ($paths as $path) {
            if (str_starts_with($path, '/etc/systemd/system/')) {
                $unit = basename($path);
                $services[$unit] = ['enabled' => $this->run(['/usr/bin/systemctl', 'is-enabled', $unit], true) === 'enabled',
                    'active' => $this->run(['/usr/bin/systemctl', 'is-active', $unit], true) === 'active'];
            }
        }
        $accounts = [];
        foreach ($databases['accounts'] as $key) {
            $row = $this->pdo()->query('SHOW CREATE USER '.$this->accountSql($key))->fetch(PDO::FETCH_NUM);
            $accounts[$key] = str_contains($row[0], 'ACCOUNT LOCK');
        }
        $events = [];
        foreach ($databases['databases'] as $name) {
            $query = $this->pdo()->prepare("SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? AND STATUS = 'ENABLED'");
            $query->execute([$name]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $event) {
                $events[] = [$name, $event];
            }
        }
        $shadow = $userExists ? explode(':', $this->run(['/usr/bin/getent', 'shadow', $this->user])) : array_fill(0, 9, '');
        if (count($shadow) < 8) {
            throw new RuntimeException('Account state unavailable');
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true)) {
            throw new RuntimeException('Trash unavailable');
        }
        chmod(dirname($this->directory), 0700);
        $this->state = ['phase' => 'staging', 'purge_after' => time() + 5 * 86400, 'paths' => $paths, 'services' => $services,
            'databases' => $databases, 'accounts' => $accounts, 'events' => $events, 'expiry' => $shadow[7], 'user_exists' => $userExists];
        $this->save();
    }

    private function eventSql(array $event): string
    {
        return '`'.str_replace('`', '``', $event[0]).'`.`'.str_replace('`', '``', $event[1]).'`';
    }

    private function stage(): void
    {
        $this->prepare();
        if (in_array($this->state['phase'], ['trashed', 'purging', 'purged'], true)) {
            return;
        }
        if ($this->state['phase'] !== 'staging') {
            throw new RuntimeException('Restore must finish before deletion');
        }
        if ($this->state['user_exists']) {
            $this->run(['/usr/sbin/usermod', '--expiredate', '1', $this->user]);
        }
        foreach ($this->state['services'] as $unit => $settings) {
            $this->run(['/usr/bin/systemctl', 'disable', '--now', $unit]);
        }
        if ($this->state['user_exists']) {
            $this->run(['/usr/bin/pkill', '-KILL', '-u', $this->user], true);
        }
        foreach ($this->state['accounts'] as $key => $locked) {
            $this->pdo()->exec('ALTER USER '.$this->accountSql($key).' ACCOUNT LOCK');
            $username = explode('@', $key, 2)[0];
            $sessions = $this->pdo()->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE USER = ?');
            $sessions->execute([$username]);
            foreach ($sessions->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    $this->pdo()->exec('KILL '.(int) $id);
                } catch (PDOException) {
                }
            }
        }
        foreach ($this->state['events'] as $event) {
            $this->pdo()->exec('ALTER EVENT '.$this->eventSql($event).' DISABLE');
        }
        foreach ($this->state['paths'] as $index => $path) {
            $target = $this->directory.'/resource-'.$index;
            if (file_exists($target) || is_link($target)) {
                continue;
            }
            if ((file_exists($path) || is_link($path)) && ! rename($path, $target)) {
                throw new RuntimeException('Could not quarantine a site resource');
            }
        }
        $this->reloadPools();
        $this->run(['/usr/sbin/nginx', '-t']);
        $this->run(['/usr/bin/systemctl', 'reload', 'nginx']);
        $this->run(['/usr/bin/systemctl', 'daemon-reload']);
        $this->state['phase'] = 'trashed';
        $this->save();
    }

    private function reloadPools(): void
    {
        foreach (['8.2', '8.3', '8.4'] as $version) {
            if (in_array('/etc/php/'.$version.'/fpm/pool.d/minipanel-'.$this->domain.'.conf', $this->state['paths'], true)) {
                $this->run(['/usr/sbin/php-fpm'.$version, '-t']);
                $this->run(['/usr/bin/systemctl', 'reload', 'php'.$version.'-fpm']);
            }
        }
    }

    private function restore(): void
    {
        if (! in_array($this->state['phase'] ?? '', ['trashed', 'restoring'], true) || $this->state['purge_after'] <= time()) {
            throw new RuntimeException('Trash expired or unavailable');
        }
        $this->ownedDatabases();
        $nginxPath = '/etc/nginx/sites-available/'.$this->domain;
        $nginxIndex = array_search($nginxPath, $this->state['paths'], true);
        if ($nginxIndex !== false) {
            $quarantined = $this->directory.'/resource-'.$nginxIndex;
            $source = is_file($quarantined) ? $quarantined : $nginxPath;
            preg_match_all('/^[ \t]*server_name\s+([^;]+);/m', file_get_contents($source), $matches);
            $names = preg_split('/\s+/', trim(implode(' ', $matches[1])));
            foreach (glob('/etc/nginx/sites-available/*') ?: [] as $other) {
                if ($other === $nginxPath || ! is_file($other)) {
                    continue;
                }
                preg_match_all('/^[ \t]*server_name\s+([^;]+);/m', file_get_contents($other), $otherMatches);
                if (array_intersect($names, preg_split('/\s+/', trim(implode(' ', $otherMatches[1]))))) {
                    throw new RuntimeException('A hostname is already used by another site; restore stopped');
                }
            }
        }
        foreach ($this->state['paths'] as $index => $path) {
            $source = $this->directory.'/resource-'.$index;
            if ((file_exists($source) || is_link($source)) && (file_exists($path) || is_link($path))) {
                throw new RuntimeException('Restore target already exists; no files overwritten');
            }
        }
        $this->state['phase'] = 'restoring';
        $this->save();
        foreach ($this->state['paths'] as $index => $path) {
            $source = $this->directory.'/resource-'.$index;
            if ((file_exists($source) || is_link($source)) && ! rename($source, $path)) {
                throw new RuntimeException('Could not restore site resource');
            }
        }
        $this->reloadPools();
        $this->run(['/usr/sbin/nginx', '-t']);
        $this->run(['/usr/bin/systemctl', 'reload', 'nginx']);
        foreach ($this->state['accounts'] as $key => $locked) {
            if (! $locked) {
                $this->pdo()->exec('ALTER USER '.$this->accountSql($key).' ACCOUNT UNLOCK');
            }
        }
        foreach ($this->state['events'] as $event) {
            $this->pdo()->exec('ALTER EVENT '.$this->eventSql($event).' ENABLE');
        }
        if ($this->state['user_exists']) {
            $this->run(['/usr/sbin/usermod', '--expiredate', $this->state['expiry'], $this->user]);
        }
        $this->run(['/usr/bin/systemctl', 'daemon-reload']);
        foreach ($this->state['services'] as $unit => $settings) {
            if ($settings['enabled']) {
                $this->run(['/usr/bin/systemctl', 'enable', $unit]);
            }
            if ($settings['active']) {
                $this->run(['/usr/bin/systemctl', 'start', $unit]);
            }
        }
        $this->state['phase'] = 'restored';
        $this->save();
    }

    private function purge(): void
    {
        if (($this->state['phase'] ?? '') === 'purged') {
            return;
        }
        $this->stage();
        $this->ownedDatabases();
        $this->state['phase'] = 'purging';
        $this->save();
        foreach ($this->state['databases']['databases'] as $name) {
            $this->pdo()->exec('DROP DATABASE IF EXISTS `'.$name.'`');
        }
        foreach ($this->state['databases']['accounts'] as $key) {
            $this->pdo()->exec('DROP USER IF EXISTS '.$this->accountSql($key));
        }
        foreach ($this->state['paths'] as $index => $path) {
            $target = $this->directory.'/resource-'.$index;
            if (file_exists($target) || is_link($target)) {
                $this->run(['/usr/bin/rm', '-rf', '--one-file-system', '--', $target]);
                clearstatcache(true, $target);
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('Resource cleanup incomplete');
                }
            }
        }
        if (posix_getpwnam($this->user)) {
            $this->run(['/usr/sbin/userdel', $this->user]);
        }
        foreach (['/etc/minipanel/database-state/'.$this->domain.'.json', '/etc/minipanel/domain-names/'.$this->domain] as $file) {
            if (is_file($file) && ! unlink($file)) {
                throw new RuntimeException('Registry cleanup incomplete');
            }
        }
        $this->state = ['phase' => 'purged'];
        $this->save();
    }

    private function renameDomain(string $newDomain): void
    {
        self::validateDomain($newDomain);
        if ($this->state !== [] && ! in_array($this->state['phase'], ['restored'], true)) {
            throw new RuntimeException('Domain lifecycle operation in progress');
        }
        $mapping = '/etc/minipanel/domain-names/'.$this->domain;
        $oldDomain = is_file($mapping) ? trim(file_get_contents($mapping)) : $this->domain;
        if ($oldDomain === $newDomain) {
            return;
        }
        $nginx = '/etc/nginx/sites-available/'.$this->domain;
        if (is_link($nginx) || ! is_file($nginx) || fileowner($nginx) !== 0) {
            throw new RuntimeException('Unknown site');
        }
        foreach (glob('/etc/nginx/sites-available/*') ?: [] as $other) {
            if ($other !== $nginx && is_file($other) && preg_match('/(?<![a-z0-9.-])'.preg_quote($newDomain, '/').'(?![a-z0-9.-])/i', file_get_contents($other))) {
                throw new RuntimeException('New domain already exists in Nginx');
            }
        }
        if (is_dir('/var/lib/minipanel/site-trash/'.$newDomain) || is_dir('/var/www/'.$newDomain)) {
            throw new RuntimeException('New domain namespace is reserved');
        }
        $files = [$nginx];
        $suspended = '/var/lib/minipanel/suspended/'.$this->domain.'/nginx';
        if (is_file($suspended)) {
            $files[] = $suspended;
        }
        $originals = [];
        foreach ($files as $path) {
            $originals[$path] = file_get_contents($path);
            $contents = preg_replace_callback('/^([ \t]*server_name\s+)([^;]+);/m', static function (array $match) use ($oldDomain, $newDomain): string {
                $names = preg_split('/\s+/', trim($match[2]));

                return $match[1].implode(' ', array_map(static fn (string $name): string => $name === $oldDomain ? $newDomain : $name, $names)).';';
            }, $originals[$path]);
            $contents = str_replace('if ($host = '.$oldDomain.')', 'if ($host = '.$newDomain.')', $contents);
            $this->write($path, $contents);
        }
        try {
            $this->run(['/usr/sbin/nginx', '-t']);
            $this->run(['/usr/bin/systemctl', 'reload', 'nginx']);
            if (! is_dir(dirname($mapping))) {
                mkdir(dirname($mapping), 0700, true);
            }
            $this->write($mapping, $newDomain);
        } catch (Throwable $exception) {
            foreach ($originals as $path => $contents) {
                $this->write($path, $contents);
            }
            $this->run(['/usr/bin/systemctl', 'reload', 'nginx'], true);
            throw $exception;
        }
    }

    public function execute(string $action, array $input): array
    {
        $token = $input['operation_token'] ?? '';
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{32}$/D', $token)) {
            throw new RuntimeException('Invalid operation token');
        }
        $receiptDirectory = '/var/lib/minipanel/lifecycle-receipts';
        if (! is_dir($receiptDirectory) && ! mkdir($receiptDirectory, 0700, true)) {
            throw new RuntimeException('Receipt storage unavailable');
        }
        $receiptFile = $receiptDirectory.'/'.$token.'.json';
        if (is_file($receiptFile)) {
            $receipt = json_decode(file_get_contents($receiptFile), true, flags: JSON_THROW_ON_ERROR);
            if ($receipt['domain'] !== $this->domain || $receipt['action'] !== $action) {
                throw new RuntimeException('Operation token belongs to another request');
            }

            return $receipt;
        }
        if (in_array($this->state['phase'] ?? '', ['restored'], true) && $action !== 'restore') {
            unlink($this->manifest);
            $this->state = [];
        }
        match ($action) {
            'rename' => $this->renameDomain($input['new_domain'] ?? ''),
            'trash' => $this->stage(),
            'purge' => $this->purge(),
            'restore' => ($this->state['phase'] ?? '') === 'restored' ? null : $this->restore(),
            default => throw new RuntimeException('Invalid lifecycle action'),
        };

        $receipt = ['action' => $action, 'domain' => $this->domain, 'purge_after' => $this->state['purge_after'] ?? null];
        $this->write($receiptFile, json_encode($receipt, JSON_THROW_ON_ERROR));

        return $receipt;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (posix_geteuid() !== 0) {
            throw new RuntimeException('Root required');
        }
        $executor = new MiniPanelSiteLifecycle($argv[1] ?? '');
        $input = json_decode(stream_get_contents(STDIN, 8192), true, flags: JSON_THROW_ON_ERROR);
        echo json_encode($executor->execute($argv[2] ?? '', $input), JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
}
