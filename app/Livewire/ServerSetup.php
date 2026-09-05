<?php

namespace App\Livewire;

use App\Models\ServerSetting;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ServerSetup extends Component
{
    public string $panelDomain = '';

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
        if ($settings = ServerSetting::first()) {
            $this->panelDomain = $settings->panel_domain ?? '';
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
            'panelDomain' => ['nullable', 'lowercase', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/'],
            'panelPath' => ['required', 'regex:#^/var/www/[A-Za-z0-9._-]+$#'],
            'panelUser' => ['required', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/'],
            'panelPhpVersion' => [Rule::in(['8.2', '8.3', '8.4'])],
            'publicIp' => ['nullable', 'ip'],
            'host' => ['required', 'string', 'max:255'], 'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:1000'],
        ]);
        ServerSetting::updateOrCreate(['id' => 1], [
            'panel_domain' => filled($data['panelDomain']) ? $data['panelDomain'] : null,
            'panel_path' => $data['panelPath'], 'panel_user' => $data['panelUser'], 'panel_php_version' => $data['panelPhpVersion'], 'public_ip' => filled($data['publicIp']) ? $data['publicIp'] : null,
            'db_host' => $data['host'], 'db_port' => $data['port'], 'db_username' => $data['username'], 'db_password' => $data['password'], 'applied_at' => null,
        ]);
        session()->flash('notice', 'Configuración guardada cifrada. Se aplicará al VPS cuando habilites la ejecución y ejecutes el instalador del agente.');
    }

    public function render()
    {
        return view('livewire.server-setup')->layout('components.layouts.app');
    }
}
