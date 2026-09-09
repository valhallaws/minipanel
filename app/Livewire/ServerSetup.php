<?php

namespace App\Livewire;

use App\Models\DnsSetting;
use App\Models\ServerSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ServerSetup extends Component
{
    public string $serverSection = 'overview';

    public array $serverStatus = [];

    public array $securityStatus = [];

    public string $serverOutput = '';

    public string $serverTimezone = 'America/Mexico_City';

    public string $serverHostname = '';

    public bool $confirmServerAction = false;

    public string $serverAction = '';

    public string $serverService = '';

    public string $rebootConfirmation = '';

    public string $hostnameConfirmation = '';

    public string $updateConfirmation = '';

    public string $firewallPort = '';

    public string $firewallProtocol = 'tcp';

    public string $firewallSource = '';

    public string $firewallRuleNumber = '';

    public string $firewallConfirmation = '';

    public string $fail2banJail = '';

    public string $fail2banIp = '';

    public string $fail2banConfirmation = '';

    public string $sshPublicKey = '';

    public string $sshKeyNumber = '';

    public string $sshConfirmation = '';

    public array $panelUpdateStatus = [];

    public function selectServerSection(string $section): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(in_array($section, ['overview', 'security', 'services', 'applications', 'dns', 'maintenance'], true), 422);
        $this->serverSection = $section;
        if ($section === 'maintenance') {
            $this->refreshPanelUpdateStatus();
        }
    }

    public function refreshPanelUpdateStatus(): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetErrorBag('panelUpdate');

        try {
            $result = Process::timeout(15)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-panel-update-status']);
            if (! $result->successful()) {
                $this->addError('panelUpdate', 'No se pudo leer la versión instalada de Freyja.');

                return;
            }
            $this->panelUpdateStatus = collect(preg_split('/\R/', $result->output()))->filter()->mapWithKeys(function (string $line): array {
                [$key, $value] = array_pad(explode("\t", $line, 2), 2, '');

                return [$key => $value];
            })->all();
        } catch (\Throwable) {
            $this->addError('panelUpdate', 'No se pudo contactar al agente del VPS.');
        }
    }

    public function refreshServerStatus(): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetErrorBag('server');

        try {
            $result = Process::timeout(15)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-status']);
            $this->serverOutput = trim($result->output()."\n".$result->errorOutput());
            if (! $result->successful()) {
                $this->addError('server', 'No se pudo leer el estado del VPS.');

                return;
            }
            $this->serverStatus = collect(preg_split('/\R/', $result->output()))->filter()->mapWithKeys(function (string $line): array {
                [$key, $value] = array_pad(explode("\t", $line, 2), 2, '');

                return [$key => $value];
            })->all();
            $timezone = $this->serverStatus['Zona horaria'] ?? null;
            if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
                $this->serverTimezone = $timezone;
                ServerSetting::query()->first()?->update(['timezone' => $timezone]);
            }
            $this->serverHostname = $this->serverStatus['Hostname'] ?? $this->serverHostname;
            $this->refreshSecurityStatus();
        } catch (\Throwable) {
            $this->addError('server', 'No se pudo contactar al agente del VPS.');
        }
    }

    public function refreshSecurityStatus(): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetErrorBag('security');

        try {
            $result = Process::timeout(20)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-security-status']);
            if (! $result->successful()) {
                $this->addError('security', 'No se pudo leer el estado de seguridad del VPS.');

                return;
            }
            $this->securityStatus = collect(preg_split('/\R/', $result->output()))->filter()->mapWithKeys(function (string $line): array {
                [$key, $value] = array_pad(explode("\t", $line, 2), 2, '');

                return [$key => $value];
            })->all();
        } catch (\Throwable) {
            $this->addError('security', 'No se pudo contactar al agente de seguridad.');
        }
    }

    public function loadServiceLogs(string $service): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(in_array($service, $this->managedServices(), true), 422);
        $this->resetErrorBag('server');

        try {
            $result = Process::timeout(15)->run(['sudo', '/usr/local/bin/minipanel-agent', 'server-service-log', $service]);
            $this->serverOutput = trim($result->output()."\n".$result->errorOutput());
            if (! $result->successful()) {
                $this->addError('server', 'No se pudo leer el registro del servicio.');
            }
        } catch (\Throwable) {
            $this->addError('server', 'No se pudo contactar al agente del VPS.');
        }
    }

    public function prepareServerAction(string $action, string $service = ''): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(in_array($action, ['hostname', 'timezone', 'sync-clock', 'restart-service', 'firewall-reload', 'fail2ban-reload', 'system-update', 'panel-update', 'reboot'], true), 422);
        abort_unless($action !== 'restart-service' || in_array($service, $this->managedServices(), true), 422);
        $this->serverAction = $action;
        $this->serverService = $service;
        $this->rebootConfirmation = '';
        $this->hostnameConfirmation = '';
        $this->updateConfirmation = '';
        $this->firewallConfirmation = '';
        $this->confirmServerAction = true;
    }

    public function prepareFirewallAllow(): void
    {
        abort_unless(Auth::check(), 403);
        $this->firewallSource = trim($this->firewallSource);
        $this->validate([
            'firewallPort' => ['required', 'integer', 'between:1,65535'],
            'firewallProtocol' => [Rule::in(['tcp', 'udp'])],
            'firewallSource' => ['nullable', 'regex:/^(?:[0-9.]+|[0-9a-fA-F:]+)(?:\/[0-9]{1,3})?$/'],
        ], ['firewallSource.regex' => 'Escribe una IP o red CIDR válida, o déjalo vacío para cualquier origen.']);
        $this->serverAction = 'firewall-allow';
        $this->firewallConfirmation = '';
        $this->confirmServerAction = true;
    }

    public function prepareFirewallDelete(): void
    {
        abort_unless(Auth::check(), 403);
        $this->validate(['firewallRuleNumber' => ['required', 'integer', 'between:1,999']]);
        $this->serverAction = 'firewall-delete';
        $this->firewallConfirmation = '';
        $this->confirmServerAction = true;
    }

    public function fail2banJails(): array
    {
        return collect(array_keys($this->securityStatus))
            ->filter(fn (string $key): bool => str_starts_with($key, 'Bloqueados:'))
            ->map(fn (string $key): string => substr($key, strlen('Bloqueados:')))
            ->values()
            ->all();
    }

    public function prepareFail2banUnban(): void
    {
        abort_unless(Auth::check(), 403);
        $this->validate([
            'fail2banJail' => [Rule::in($this->fail2banJails())],
            'fail2banIp' => ['required', 'ip'],
        ]);
        $this->serverAction = 'fail2ban-unban';
        $this->fail2banConfirmation = '';
        $this->confirmServerAction = true;
    }

    public function sshKeys(): array
    {
        return collect($this->securityStatus)
            ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'Llave SSH:'))
            ->mapWithKeys(fn (mixed $value, string $key): array => [substr($key, strlen('Llave SSH:')) => $value])
            ->all();
    }

    public function prepareSshKeyAdd(): void
    {
        abort_unless(Auth::check(), 403);
        $this->sshPublicKey = trim($this->sshPublicKey);
        $this->validate([
            'sshPublicKey' => ['required', 'string', 'max:8192', 'regex:/^(?:ssh-(?:ed25519|rsa)|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/]+=*(?: [^\r\n]+)?$/'],
        ], ['sshPublicKey.regex' => 'Pega una llave pública OpenSSH válida.']);
        $this->serverAction = 'ssh-key-add';
        $this->sshConfirmation = '';
        $this->confirmServerAction = true;
    }

    public function prepareSshKeyDelete(): void
    {
        abort_unless(Auth::check(), 403);
        $this->validate(['sshKeyNumber' => ['required', 'integer', Rule::in(array_map('intval', array_keys($this->sshKeys())))]]);
        $this->serverAction = 'ssh-key-delete';
        $this->sshConfirmation = '';
        $this->confirmServerAction = true;
    }

    public function runServerAction(): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(config('minipanel.execution_enabled'), 422, 'La ejecución está desactivada en este equipo.');
        $commands = [
            'hostname' => ['server-hostname', $this->serverHostname],
            'timezone' => ['server-timezone', $this->serverTimezone],
            'sync-clock' => ['server-sync-clock'],
            'restart-service' => ['server-restart-service', $this->serverService],
            'firewall-reload' => ['server-firewall-reload'],
            'fail2ban-reload' => ['server-fail2ban-reload'],
            'system-update' => ['server-system-update-start'],
            'panel-update' => ['server-panel-update-start'],
            'firewall-allow' => ['server-firewall-allow', $this->firewallPort, $this->firewallProtocol, $this->firewallSource ?: 'any'],
            'firewall-delete' => ['server-firewall-delete', $this->firewallRuleNumber],
            'fail2ban-unban' => ['server-fail2ban-unban', $this->fail2banJail, $this->fail2banIp],
            'ssh-key-add' => ['server-ssh-add-key', $this->sshPublicKey],
            'ssh-key-delete' => ['server-ssh-delete-key', $this->sshKeyNumber],
            'reboot' => ['server-reboot'],
        ];
        abort_unless(isset($commands[$this->serverAction]), 422);
        if ($this->serverAction === 'reboot') {
            $this->validate(['rebootConfirmation' => ['required', 'in:REINICIAR']]);
        }
        if ($this->serverAction === 'hostname') {
            $this->serverHostname = strtolower(trim($this->serverHostname));
            $this->validate([
                'serverHostname' => ['required', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,}$/'],
                'hostnameConfirmation' => ['required', 'same:serverHostname'],
            ], [
                'serverHostname.regex' => 'Escribe un hostname completo, por ejemplo vps.ejemplo.com.',
                'hostnameConfirmation.same' => 'Escribe exactamente el hostname nuevo para confirmar.',
            ]);
            $commands['hostname'] = ['server-hostname', $this->serverHostname];
        }
        if ($this->serverAction === 'system-update') {
            $this->validate(['updateConfirmation' => ['required', 'in:ACTUALIZAR']]);
        }
        if ($this->serverAction === 'firewall-allow') {
            $this->validate(['firewallConfirmation' => ['required', 'in:APLICAR']]);
        }
        if ($this->serverAction === 'firewall-delete') {
            $this->validate(['firewallConfirmation' => ['required', 'in:ELIMINAR']]);
        }
        if ($this->serverAction === 'fail2ban-unban') {
            $this->validate(['fail2banConfirmation' => ['required', 'in:DESBLOQUEAR']]);
        }
        if ($this->serverAction === 'ssh-key-add') {
            $this->validate(['sshConfirmation' => ['required', 'in:AGREGAR']]);
        }
        if ($this->serverAction === 'ssh-key-delete') {
            $this->validate(['sshConfirmation' => ['required', 'in:ELIMINAR']]);
        }
        try {
            $result = Process::timeout($this->serverAction === 'reboot' ? 8 : 30)->run(['sudo', '/usr/local/bin/minipanel-agent', ...$commands[$this->serverAction]]);
            $this->serverOutput = trim($result->output()."\n".$result->errorOutput());
            if (! $result->successful()) {
                $this->addError('server', 'La operación no se completó. Revisa el resultado.');

                return;
            }
            $this->confirmServerAction = false;
            if ($this->serverAction !== 'reboot') {
                $this->refreshServerStatus();
            }
        } catch (\Throwable) {
            $this->addError('server', 'El VPS no confirmó la operación.');
        }
    }

    public function managedServices(): array
    {
        return ['nginx', $this->panelPhpFpmService(), 'mariadb', 'redis-server', 'minipanel-worker', 'minipanel-root-terminal'];
    }

    public function serviceLabel(string $service): string
    {
        return [
            'nginx' => 'Nginx',
            $this->panelPhpFpmService() => 'PHP-FPM '.$this->panelPhpVersion(),
            'mariadb' => 'MariaDB',
            'redis-server' => 'Redis',
            'minipanel-worker' => 'Workers del panel',
            'minipanel-root-terminal' => 'Consola root',
        ][$service] ?? $service;
    }

    public function panelPhpVersion(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    public function panelPhpFpmService(): string
    {
        return 'php'.$this->panelPhpVersion().'-fpm';
    }

    public string $dnsDomain = '';

    public array $dnsNameservers = [['hostname' => '', 'ip' => ''], ['hostname' => '', 'ip' => '']];

    public function saveDnsSettings(): void
    {
        abort_unless(Auth::check(), 403);
        $this->dnsDomain = strtolower(trim($this->dnsDomain));
        foreach ($this->dnsNameservers as &$server) {
            if (is_array($server) && is_string($server['hostname'] ?? null)) {
                $server['hostname'] = strtolower(trim($server['hostname']));
            }
        }
        unset($server);
        $hostnameRules = ['nullable', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/'];
        $data = $this->validate([
            'dnsDomain' => $hostnameRules,
            'dnsNameservers' => ['required', 'array', 'list', 'size:2'],
            'dnsNameservers.*' => ['required', 'array:hostname,ip'],
            'dnsNameservers.*.hostname' => [...$hostnameRules, 'distinct:ignore_case'],
            'dnsNameservers.*.ip' => ['nullable', 'ip'],
        ], [
            'dnsDomain.regex' => 'Escribe un dominio sin https://, rutas ni comodines.',
            'dnsNameservers.*.hostname.regex' => 'Escribe el nombre completo del nameserver, por ejemplo ns1.ejemplo.com.',
            'dnsNameservers.*.hostname.distinct' => 'Cada nameserver debe tener un nombre distinto.',
            'dnsNameservers.*.ip.ip' => 'Escribe una dirección IPv4 o IPv6 válida.',
        ]);
        DnsSetting::updateOrCreate(['id' => 1], ['infrastructure_domain' => $data['dnsDomain'] ?: null, 'nameservers' => $data['dnsNameservers']]);
        session()->flash('dnsNotice', 'Configuración DNS guardada como borrador. No se han cambiado servicios, delegación ni certificados.');
    }

    public bool $confirmTimezones = false;

    public string $timezoneOutput = '';

    public function importTimezones(): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless($this->confirmTimezones, 422);
        if (! config('minipanel.execution_enabled')) {
            $this->addError('timezones', 'La ejecución está desactivada en este equipo.');

            return;
        }
        $this->resetErrorBag('timezones');
        try {
            $result = Process::timeout(90)->run(['sudo', '/usr/local/bin/minipanel-agent', 'import-mariadb-timezones']);
            $this->timezoneOutput = mb_substr(trim($result->output()."\n".$result->errorOutput()), 0, 8000);
            if (! $result->successful()) {
                $this->addError('timezones', 'No se completó la carga. Revisa el resultado antes de reintentar.');
            }
        } catch (\Throwable) {
            $this->addError('timezones', 'No se pudo confirmar el resultado del agente. Revisa el servidor antes de reintentar.');
        } finally {
            $this->confirmTimezones = false;
        }
    }

    public string $panelPath = '/var/www/minipanel';

    public string $panelUser = 'www-data';

    public string $panelPhpVersion = '8.3';

    public string $publicIp = '';

    public string $host = '127.0.0.1';

    public string $port = '3306';

    public string $username = '';

    public string $password = '';

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        if ($dns = DnsSetting::find(1)) {
            $this->dnsDomain = $dns->infrastructure_domain ?? '';
            $this->dnsNameservers = $dns->nameservers;
        }
        if ($settings = ServerSetting::first()) {
            $this->panelPath = $settings->panel_path ?? $this->panelPath;
            $this->panelUser = $settings->panel_user ?? $this->panelUser;
            $this->panelPhpVersion = $settings->panel_php_version ?? $this->panelPhpVersion;
            $this->publicIp = $settings->public_ip ?? '';
            $this->host = $settings->db_host;
            $this->port = (string) $settings->db_port;
            $this->username = $settings->db_username;
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'panelPath' => ['required', 'regex:#^/var/www/[A-Za-z0-9._-]+$#'],
            'panelUser' => ['required', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'panelPhpVersion' => ['required', 'regex:/^8\.[0-9]{1,2}$/'],
            'publicIp' => ['nullable', 'ip'],
            'host' => ['required', 'string', 'max:255'], 'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:1000'],
        ]);
        ServerSetting::updateOrCreate(['id' => 1], [
            'panel_domain' => null,
            'panel_path' => $data['panelPath'], 'panel_user' => $data['panelUser'], 'panel_php_version' => $data['panelPhpVersion'], 'public_ip' => filled($data['publicIp']) ? $data['publicIp'] : null,
            'db_host' => $data['host'], 'db_port' => $data['port'], 'db_username' => $data['username'], 'db_password' => $data['password'], 'applied_at' => null,
        ]);
        session()->flash('notice', 'Configuración guardada cifrada. Se aplicará al VPS cuando habilites la ejecución y ejecutes el instalador del agente.');
    }

    public function render()
    {
        return view('livewire.server-setup', [
            'dnsConfiguration' => DnsSetting::find(1),
            'panelUpdateWebhookUrl' => route('webhooks.panel-update'),
            'panelUpdateWebhookConfigured' => filled(config('minipanel.panel_update_webhook_secret')),
            'panelUpdateBranch' => (string) config('minipanel.panel_update_branch'),
        ])->layout('components.layouts.app');
    }
}
