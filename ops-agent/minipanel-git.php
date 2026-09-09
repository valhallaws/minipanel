<?php

declare(strict_types=1);

/** All repository content and commands run as the isolated site identity, never root. */
final class MiniPanelGit
{
    private string $private;

    private string $step = '';

    private array $environment;

    private string $npmBinary;

    public function __construct(private string $root, private string $phpVersion)
    {
        if (posix_geteuid() === 0 || ! is_dir($root) || is_link($root) || ! preg_match('/^8\.[0-9]{1,2}$/D', $phpVersion)) {
            throw new RuntimeException('Se requiere el usuario aislado del dominio.');
        }
        $this->root = realpath($root);
        $this->npmBinary = getenv('MINIPANEL_NPM_BINARY') ?: '/usr/bin/npm';
        $this->private = $this->root.'/.ssh/minipanel';
        foreach ([$this->root.'/.ssh', $this->private] as $path) {
            if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
                throw new RuntimeException('Ubicación privada no válida.');
            }
            if (! is_dir($path) && ! mkdir($path, 0700)) {
                throw new RuntimeException('No se pudo preparar el almacenamiento privado.');
            }
            chmod($path, 0700);
        }
        $this->environment = ['HOME' => $this->root, 'PUPPETEER_CACHE_DIR' => $this->root.'/.cache/puppeteer', 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null'];
    }

    public function execute(string $action, array $data): array
    {
        $lock = fopen($this->private.'/operation.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Otra operación Git está en curso.');
        }
        try {
            if (str_starts_with($action, 'laravel-')) {
                return $this->laravel($action, $data);
            }
            if ($action === 'folders') {
                $path = $this->path($data['directory'] ?? '.');
                $folders = [];
                foreach (scandir($path) ?: [] as $name) {
                    if ($name[0] === '.' || ! is_dir($path.'/'.$name) || is_link($path.'/'.$name)) {
                        continue;
                    }
                    $folders[] = ['name' => $name, 'path' => ltrim(substr($path.'/'.$name, strlen($this->root)), '/')];
                }

                return ['folders' => $folders];
            }
            if ($action === 'mkdir') {
                $path = $this->path($data['directory'] ?? '', false);
                if (file_exists($path) || ! mkdir($path, 0755)) {
                    throw new RuntimeException('La carpeta ya existe o no se pudo crear.');
                }

                return ['created' => true];
            }
            $id = $data['id'] ?? '';
            if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/D', $id)) {
                throw new RuntimeException('Identificador no válido.');
            }
            $key = $this->private.'/'.$id;
            if (is_link($key) || is_link($key.'.pub')) {
                throw new RuntimeException('Clave no válida.');
            }
            if ($action === 'key') {
                if (! file_exists($key)) {
                    $this->run(['/usr/bin/ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'minipanel-'.$id, '-f', $key], $this->root);
                }
                chmod($key, 0600);

                return ['public_key' => trim(file_get_contents($key.'.pub'))];
            }
            if ($action !== 'sync' || ! is_file($key)) {
                throw new RuntimeException('Operación no válida o clave pendiente de generar.');
            }
            $url = $data['url'] ?? '';
            if (! is_string($url) || strlen($url) > 512 || ! preg_match('~^(?:git@[A-Za-z0-9][A-Za-z0-9.-]*:[A-Za-z0-9_][A-Za-z0-9._/-]*|ssh://git@[A-Za-z0-9][A-Za-z0-9.-]*(?::[0-9]{1,5})?/[A-Za-z0-9_][A-Za-z0-9._/-]*)$~D', $url)) {
                throw new RuntimeException('Solo se permiten repositorios SSH.');
            }
            $target = $this->path($data['directory'] ?? '', false);
            $record = $key.'.json';
            if (is_link($record)) {
                throw new RuntimeException('Registro no válido.');
            }
            foreach (glob($this->private.'/*.json') ?: [] as $otherFile) {
                if ($otherFile === $record) {
                    continue;
                }
                $other = json_decode(file_get_contents($otherFile), true, flags: JSON_THROW_ON_ERROR);
                if (str_starts_with($target.'/', $other['path'].'/') || str_starts_with($other['path'].'/', $target.'/')) {
                    throw new RuntimeException('El destino se cruza con otro repositorio.');
                }
            }
            $known = is_file($record) ? json_decode(file_get_contents($record), true, flags: JSON_THROW_ON_ERROR) : null;
            if ($known && ($known['path'] !== $target || $known['url'] !== $url)) {
                throw new RuntimeException('El destino o remoto no coincide con el registro del repositorio.');
            }
            $this->environment['GIT_SSH_COMMAND'] = 'ssh -F /dev/null -i '.escapeshellarg($key).' -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -o UserKnownHostsFile='.escapeshellarg($this->private.'/known_hosts');
            $this->step('Validando clave y conexión');
            $this->git(['ls-remote', '--symref', $url, 'HEAD'], $this->root);
            $this->done();
            $maintenanceStarted = false;
            if (is_dir($target.'/.git') && $this->isLaravelProject($target) && ! is_file($target.'/storage/framework/down')) {
                $this->step('Activando mantenimiento Laravel');
                $this->run(['/usr/bin/php'.$this->phpVersion, 'artisan', 'down', '--no-interaction'], $target);
                $this->done();
                $maintenanceStarted = true;
            }
            if (! is_dir($target.'/.git')) {
                if (is_dir($target) && count(scandir($target)) > 2) {
                    throw new RuntimeException('La carpeta destino no está vacía. No se sobrescribió ningún archivo.');
                }
                $this->step('Obteniendo archivos');
                $this->git(['clone', '--progress', '--', $url, $target], $this->root);
                file_put_contents($record, json_encode(['path' => $target, 'url' => $url], JSON_THROW_ON_ERROR), LOCK_EX);
                chmod($record, 0600);
                $this->done();
            } else {
                if (! $known || is_link($target.'/.git')) {
                    throw new RuntimeException('Ya existe un repositorio no registrado en esa carpeta; no se modificó.');
                }
                if (trim($this->git(['remote', 'get-url', 'origin'], $target)) !== $url) {
                    throw new RuntimeException('El remoto fue modificado fuera del panel.');
                }
                $this->step('Obteniendo archivos');
                $this->git(['fetch', '--progress', '--prune', 'origin'], $target);
                $this->done();
            }
            $branches = array_values(array_filter(explode("\n", trim($this->git(['for-each-ref', '--format=%(refname:strip=3)', 'refs/remotes/origin'], $target))), static fn (string $ref): bool => $ref !== 'HEAD' && $ref !== ''));
            $branch = $data['branch'] ?? trim($this->git(['symbolic-ref', '--short', 'HEAD'], $target));
            if (! in_array($branch, $branches, true) || str_starts_with($branch, '-')) {
                throw new RuntimeException('La rama seleccionada ya no existe en el remoto.');
            }
            $this->step('Actualizando archivos');
            $this->git(['checkout', '--force', '-B', $branch, 'refs/remotes/origin/'.$branch], $target);
            $this->git(['reset', '--hard', 'refs/remotes/origin/'.$branch], $target);
            $this->done('Los cambios rastreados locales fueron reemplazados por la rama remota. Los archivos no versionados, incluido .env, se conservaron.');
            $this->step('Detectando el proyecto');
            $composer = $this->jsonFile($target.'/composer.json');
            $package = $this->jsonFile($target.'/package.json');
            $laravel = is_file($target.'/artisan') && isset($composer['require']['laravel/framework']);
            $dependencies = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);
            $frontend = isset($dependencies['vite']) || isset($dependencies['laravel-mix']);
            $projectType = $laravel ? 'Laravel' : ($frontend ? (isset($dependencies['vite']) ? 'Vite' : 'Mix') : 'Web');
            $this->done($projectType);
            if (($data['prepare'] ?? false) === true) {
                $this->step('Preparando el proyecto');
                if ($laravel) {
                    if (is_link($target.'/.env') || is_link($target.'/.env.example')) {
                        throw new RuntimeException('No se modifica un .env enlazado.');
                    }
                    if (! file_exists($target.'/.env') && is_file($target.'/.env.example')) {
                        copy($target.'/.env.example', $target.'/.env');
                        $environment = file_get_contents($target.'/.env');
                        $environment = preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=production', $environment);
                        $environment = preg_replace('/^APP_DEBUG=.*$/m', 'APP_DEBUG=false', $environment);
                        file_put_contents($target.'/.env', $environment);
                        chmod($target.'/.env', 0600);
                    }
                    $this->run(['/usr/bin/php'.$this->phpVersion, '/usr/bin/composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'], $target);
                    if (is_file($target.'/.env') && (! preg_match('/^APP_KEY\s*=\s*(.*)$/m', file_get_contents($target.'/.env'), $match) || trim($match[1], " \t\r\n\"'") === '')) {
                        $this->run(['/usr/bin/php'.$this->phpVersion, 'artisan', 'key:generate', '--force', '--no-interaction'], $target, false, null, 'No se pudo preparar APP_KEY. Revisa el entorno del proyecto.');
                    }
                }
                if ($frontend) {
                    $hasLockFile = is_file($target.'/package-lock.json');
                    try {
                        $this->run($this->npmCommand($hasLockFile ? 'ci' : 'install', '--no-audit', '--no-fund'), $target);
                    } catch (RuntimeException $exception) {
                        if (! $hasLockFile || ! str_contains($exception->getMessage(), 'EUSAGE') || ! str_contains($exception->getMessage(), 'package-lock.json')) {
                            throw $exception;
                        }
                        $this->run($this->npmCommand('install', '--no-audit', '--no-fund'), $target);
                    }
                    $script = isset($package['scripts']['build']) ? 'build' : (isset($package['scripts']['production']) ? 'production' : null);
                    if (! $script) {
                        throw new RuntimeException('Vite/Mix detectado, pero falta un script build o production en package.json.');
                    }
                    $this->run($this->npmCommand('run', $script), $target);
                }
                $this->done();
            }
            $this->runDeployCommands($data['commands'] ?? '', $target);
            if ($maintenanceStarted) {
                $this->step('Reanudando Laravel');
                $this->run(['/usr/bin/php'.$this->phpVersion, 'artisan', 'up', '--no-interaction'], $target);
                $this->done();
            }
            $commits = [];
            foreach (array_filter(explode("\n", trim($this->git(['log', '-5', '--format=%H%x09%s'], $target)))) as $line) {
                [$hash, $subject] = array_pad(explode("\t", $line, 2), 2, '');
                $commits[] = ['hash' => $hash, 'subject' => $subject];
            }

            return ['result' => ['branch' => $branch, 'branches' => $branches, 'commits' => $commits, 'project_type' => $projectType]];
        } catch (Throwable $exception) {
            if ($this->step !== '') {
                $this->emit(['step' => $this->step, 'status' => 'failed', 'detail' => $exception->getMessage()]);
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function laravel(string $action, array $data): array
    {
        $target = $this->path($data['directory'] ?? '.');
        if (! is_file($target.'/artisan') || is_link($target.'/artisan') || ! isset($this->jsonFile($target.'/composer.json')['require']['laravel/framework'])) {
            throw new RuntimeException('No se detectó Laravel en la carpeta seleccionada.');
        }
        $env = $target.'/.env';
        if (is_link($env)) {
            throw new RuntimeException('No se permite un .env enlazado.');
        }
        if ($action === 'laravel-state') {
            try {
                $script = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class); $kernel->bootstrap(); $commands = []; foreach ($kernel->all() as $name => $command) { if (!$command->isHidden()) { $commands[$name] = ["name" => $name, "description" => $command->getDescription()]; } } ksort($commands); echo json_encode(["maintenance" => $app->isDownForMaintenance(), "commands" => array_values($commands)], JSON_THROW_ON_ERROR);';
                $state = json_decode(trim($this->run(['/usr/bin/php'.$this->phpVersion, '-r', $script], $target, false)), true, 512, JSON_THROW_ON_ERROR);
                if (! is_bool($state['maintenance'] ?? null) || ! is_array($state['commands'] ?? null)) {
                    throw new RuntimeException('Unexpected maintenance state');
                }
            } catch (Throwable) {
                throw new RuntimeException('No se pudo consultar Laravel. Comprueba sus dependencias y configuración.');
            }

            return [...$state, 'env_exists' => is_file($env)];
        }
        if (in_array($action, ['laravel-env-read', 'laravel-env-write'], true)) {
            if (! is_file($env) || filesize($env) > 1048576) {
                throw new RuntimeException('El .env no existe o supera 1 MB.');
            }
            $contents = file_get_contents($env);
            if ($action === 'laravel-env-read') {
                return ['contents' => $contents, 'hash' => hash('sha256', $contents)];
            }
            if (! hash_equals(hash('sha256', $contents), (string) ($data['hash'] ?? ''))) {
                throw new RuntimeException('El .env cambió desde que lo abriste. Vuelve a cargarlo antes de guardar.');
            }
            $new = $data['contents'] ?? null;
            if (! is_string($new) || strlen($new) > 1048576 || str_contains($new, "\0")) {
                throw new RuntimeException('Contenido del .env no válido.');
            }
            $temporary = tempnam($target, '.env-edit-');
            try {
                chmod($temporary, 0600);
                if (file_put_contents($temporary, $new) !== strlen($new) || ! rename($temporary, $env)) {
                    throw new RuntimeException('No se pudo guardar el .env.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return ['saved' => true, 'hash' => hash('sha256', $new)];
        }
        if ($action === 'laravel-maintenance') {
            $secret = $data['secret'] ?? '';
            if (! is_string($secret) || ($secret !== '' && ! preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $secret))) {
                throw new RuntimeException('Secret no válido: usa de 8 a 128 letras, números, guion o guion bajo.');
            }
            if (! is_bool($data['enabled'] ?? null)) {
                throw new RuntimeException('Estado de mantenimiento no válido.');
            }
            try {
                $script = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class); $data = json_decode(stream_get_contents(STDIN), true); $options = ["--no-interaction" => true]; if ($data["enabled"] && $data["secret"] !== "") { $options["--secret"] = $data["secret"]; } exit($kernel->call($data["enabled"] ? "down" : "up", $options, new Symfony\\Component\\Console\\Output\\NullOutput));';
                $this->run(['/usr/bin/php'.$this->phpVersion, '-r', $script], $target, false, json_encode(['enabled' => $data['enabled'], 'secret' => $secret], JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                throw new RuntimeException('No se pudo cambiar el mantenimiento. Revisa el log de Laravel del proyecto.');
            }

            return ['maintenance' => $data['enabled']];
        }
        if ($action === 'laravel-puppeteer') {
            if (! is_file($target.'/package.json')) {
                throw new RuntimeException('El proyecto no tiene package.json. Inicializa Node.js antes de instalar Puppeteer.');
            }
            $this->step('Instalando Puppeteer para este proyecto');
            $this->run($this->npmCommand('install', 'puppeteer', '--save', '--no-audit', '--no-fund'), $target);
            $this->done();
            $this->step('Descargando Google Chrome');
            $this->run($this->npmCommand('exec', 'puppeteer', 'browsers', 'install', 'chrome'), $target);
            $node = $this->nodeCommand('-e', 'const puppeteer = require("puppeteer"); Promise.resolve(puppeteer.executablePath()).then((path) => process.stdout.write(path)).catch((error) => { console.error(error); process.exitCode = 1; });')[0];
            $browser = trim($this->run([$node, '-e', 'const puppeteer = require("puppeteer"); Promise.resolve(puppeteer.executablePath()).then((path) => process.stdout.write(path)).catch((error) => { console.error(error); process.exitCode = 1; });'], $target));
            if ($browser === '' || ! str_starts_with($browser, $this->root.'/')) {
                throw new RuntimeException('No se pudo localizar el navegador instalado para este sitio.');
            }
            $cache = $this->environment['PUPPETEER_CACHE_DIR'];
            $this->done("Listo. Agrega al .env:\nNODE_BINARY={$node}\nPUPPETEER_CACHE_DIR={$cache}\nPUPPETEER_EXECUTABLE_PATH={$browser}");

            return ['browser_path' => $browser, 'cache_path' => $cache];
        }
        if ($action !== 'laravel-command') {
            throw new RuntimeException('Herramienta Laravel no válida.');
        }
        $tool = $data['tool'] ?? '';
        $arguments = $data['arguments'] ?? [];
        if (! is_array($arguments) || ! array_is_list($arguments) || count($arguments) < 1 || count($arguments) > 50) {
            throw new RuntimeException('Argumentos no válidos.');
        }
        foreach ($arguments as $argument) {
            if (! is_string($argument) || ! preg_match('~^[A-Za-z0-9_.,:@=+/-]{1,1000}$~D', $argument)) {
                throw new RuntimeException('No se permiten operadores de shell en los argumentos.');
            }
        }
        if (str_starts_with($arguments[0], '-') || ($tool === 'artisan' && in_array($arguments[0], ['serve', 'tinker', 'queue:work', 'queue:listen', 'schedule:work'], true))) {
            throw new RuntimeException('Usa las herramientas de servicios para procesos permanentes.');
        }
        $command = match ($tool) {
            'artisan' => ['/usr/bin/php'.$this->phpVersion, 'artisan', ...$arguments, '--no-interaction', '--no-ansi'],
            'composer' => ['/usr/bin/php'.$this->phpVersion, '/usr/bin/composer', ...$arguments, '--no-interaction', '--no-ansi'],
            'npm' => $this->npmCommand(...$arguments),
            default => throw new RuntimeException('Herramienta no válida.'),
        };
        $this->step('Ejecutando '.$tool);
        $output = $this->run($command, $target);
        $this->done();

        return ['output' => substr($output, -16000)];
    }

    private function isLaravelProject(string $target): bool
    {
        return is_file($target.'/artisan') && isset($this->jsonFile($target.'/composer.json')['require']['laravel/framework']);
    }

    private function runDeployCommands(mixed $commands, string $target): void
    {
        if (! is_string($commands) || strlen($commands) > 50000 || str_contains($commands, "\0")) {
            throw new RuntimeException('El script post-deploy no es válido.');
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $commands) ?: []), static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
        if ($lines === []) {
            return;
        }

        $this->step('Ejecutando script de deploy');
        foreach ($lines as $line) {
            if (! preg_match('/^(?:php artisan|composer|npm)(?:\s+[A-Za-z0-9_.,:@=+\/-]+)*$/D', $line)) {
                throw new RuntimeException('El script post-deploy contiene un comando no permitido.');
            }
            $arguments = preg_split('/\s+/', $line) ?: [];
            if ($arguments[0] === 'php') {
                $command = ['/usr/bin/php'.$this->phpVersion, 'artisan', ...array_slice($arguments, 2), '--no-interaction', '--no-ansi'];
            } elseif ($arguments[0] === 'composer') {
                $command = ['/usr/bin/php'.$this->phpVersion, '/usr/bin/composer', ...array_slice($arguments, 1), '--no-interaction', '--no-ansi'];
            } else {
                $command = $this->npmCommand(...array_slice($arguments, 1));
            }
            try {
                $this->run($command, $target);
            } catch (Throwable $exception) {
                throw new RuntimeException('Falló el comando “'.$line."”.\n".$exception->getMessage(), previous: $exception);
            }
        }
        $this->done();
    }

    private function npmCommand(string ...$arguments): array
    {
        if (! preg_match('~^/opt/minipanel/nvm/versions/node/v[0-9]+\.[0-9]+\.[0-9]+/bin/npm$|^/usr/bin/npm$~D', $this->npmBinary) || ! is_executable($this->npmBinary)) {
            throw new RuntimeException('El runtime Node.js seleccionado no está disponible.');
        }

        return [$this->npmBinary, ...$arguments];
    }

    private function nodeCommand(string ...$arguments): array
    {
        $this->npmCommand();
        $node = dirname($this->npmBinary).'/node';
        if (! is_executable($node)) {
            throw new RuntimeException('El runtime Node.js seleccionado no está disponible.');
        }

        return [$node, ...$arguments];
    }

    public function path(string $relative, bool $allowRoot = true): string
    {
        if ($allowRoot && $relative === '.') {
            return $this->root;
        }
        if (strlen($relative) > 255 || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9._ -]*(?:/[A-Za-z0-9][A-Za-z0-9._ -]*)*$~D', $relative)) {
            throw new RuntimeException('Ruta no válida dentro del dominio.');
        }
        $path = $this->root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('No se permiten enlaces simbólicos en la carpeta destino.');
            }
        }

        return $path;
    }

    private function jsonFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        if (is_link($path) || filesize($path) > 1048576) {
            throw new RuntimeException('Manifiesto del proyecto no válido.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function git(array $arguments, string $directory): string
    {
        return $this->run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'protocol.file.allow=never', ...$arguments], $directory);
    }

    private function run(array $command, string $directory, bool $showOutput = true, ?string $input = null, ?string $failureMessage = null): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $this->environment);
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el proceso.');
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $detail = '';
        $last = 0;
        do {
            $out = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            $stdout .= $out;
            $detail = substr($detail.$out.$error, -3000);
            if ($showOutput && $this->step !== '' && ($out !== '' || $error !== '') && microtime(true) - $last > 0.5) {
                $this->emit(['step' => $this->step, 'status' => 'running', 'detail' => $detail]);
                $last = microtime(true);
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(50000);
            }
        } while ($status['running']);
        $stdout .= stream_get_contents($pipes[1]);
        $detail = substr($detail.stream_get_contents($pipes[2]), -3000);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if (($status['exitcode'] >= 0 ? $status['exitcode'] : $exit) !== 0) {
            throw new RuntimeException($showOutput ? ($detail ?: 'El comando terminó con error.') : ($failureMessage ?: 'La operación interna no pudo completarse.'));
        }

        return $stdout;
    }

    private function step(string $name): void
    {
        $this->step = $name;
        $this->emit(['step' => $name, 'status' => 'running']);
    }

    private function done(string $detail = ''): void
    {
        $this->emit(['step' => $this->step, 'status' => 'done', 'detail' => $detail]);
        $this->step = '';
    }

    private function emit(array $event): void
    {
        echo json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        flush();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $payload = json_decode(stream_get_contents(STDIN, 2097152), true, flags: JSON_THROW_ON_ERROR);
        $result = (new MiniPanelGit($argv[1], $argv[2]))->execute($argv[3], $payload);
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
}
