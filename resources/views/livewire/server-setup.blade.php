<div wire:init="refreshServerStatus">
    <section class="hero"><div><p class="eyebrow">CENTRO DE CONTROL DEL VPS</p><h1>Servidor</h1><p class="muted">Operación diaria del host, organizada por área.</p></div><a class="ghost" href="{{ route('server.terminal') }}">Abrir consola root ↗</a></section>
    <nav class="server-index" aria-label="Áreas de administración del servidor">
        <button wire:click="selectServerSection('overview')" @class(['selected' => $serverSection === 'overview'])><strong>General</strong><span>Hostname, hora y zona</span></button>
        <button wire:click="selectServerSection('security')" @class(['selected' => $serverSection === 'security'])><strong>Seguridad</strong><span>Firewall, Fail2ban y SSH</span></button>
        <button wire:click="selectServerSection('services')" @class(['selected' => $serverSection === 'services'])><strong>Servicios y recursos</strong><span>Nginx, PHP, MariaDB y workers</span></button>
        <button wire:click="selectServerSection('applications')" @class(['selected' => $serverSection === 'applications'])><strong>Aplicaciones y bases</strong><span>Freyja y MariaDB</span></button>
        <button wire:click="selectServerSection('dns')" @class(['selected' => $serverSection === 'dns'])><strong>DNS y certificados</strong><span>Infraestructura y wildcard</span></button>
        <button wire:click="selectServerSection('maintenance')" @class(['selected' => $serverSection === 'maintenance'])><strong>Mantenimiento</strong><span>Consola y reinicio del VPS</span></button>
    </nav>
    @error('server')<p class="error" role="alert">{{ $message }}</p>@enderror
    @if($serverSection === 'overview')
    <section class="panel"><div class="section-title"><div><h2>Estado del VPS</h2><p wire:loading.remove wire:target="refreshServerStatus">Actualización manual disponible.</p><p wire:loading wire:target="refreshServerStatus">Leyendo el VPS…</p></div><button class="secondary" wire:click="refreshServerStatus">Actualizar</button></div>
        @if($serverStatus)<div class="server-facts">@foreach($serverStatus as $name => $value)@if(!str_starts_with($name, 'Servicio:'))<div><small>{{ $name }}</small><strong>{{ $value }}</strong></div>@endif@endforeach</div>@else<p class="muted">Cargando inventario del servidor…</p>@endif
    </section>
    <section class="panel form-grid" id="general"><div><h2>Identidad, hora y zona horaria</h2><p class="muted">El hostname identifica al VPS en la red y en los registros. Usa un nombre completo que controles, por ejemplo <code>vps.ejemplo.com</code>.</p></div><div class="stack"><label>Hostname del VPS<input wire:model="serverHostname" placeholder="vps.ejemplo.com" autocomplete="off" autocapitalize="none" spellcheck="false"></label>@error('serverHostname')<p class="error">{{ $message }}</p>@enderror<div class="button-row"><button class="secondary" wire:click="prepareServerAction('hostname')">Cambiar hostname</button></div></div><div><p class="muted">Hora actual: {{ $serverStatus['Hora'] ?? '—' }} · NTP: {{ $serverStatus['NTP'] ?? '—' }}</p></div><div class="stack"><label>Zona horaria<select wire:model="serverTimezone"><option>America/Mexico_City</option><option>America/Tijuana</option><option>America/Cancun</option><option>America/Monterrey</option><option>Etc/UTC</option><option>America/New_York</option><option>Europe/Madrid</option></select></label><div class="button-row"><button class="secondary" wire:click="prepareServerAction('timezone')">Cambiar zona</button><button class="ghost" wire:click="prepareServerAction('sync-clock')">Sincronizar NTP</button></div></div></section>
    @endif
    @if($serverSection === 'security')
    <section class="panel" id="security"><div class="section-title"><div><h2>Seguridad y acceso</h2><p>Controles del perímetro del VPS. Las reglas existentes no se modifican automáticamente.</p></div><button class="secondary" wire:click="refreshSecurityStatus">Actualizar</button></div>
        @error('security')<p class="error" role="alert">{{ $message }}</p>@enderror
        @if($securityStatus)
            <div class="security-overview">
                <article class="security-card security-card-wide"><header><div><h3>Firewall UFW</h3><p>Estado: <strong>{{ $securityStatus['Firewall'] ?? '—' }}</strong></p></div><span class="security-state">{{ $securityStatus['Firewall'] ?? '—' }}</span></header><details><summary>Ver reglas públicas</summary><pre>{{ str_replace(';', "\n", $securityStatus['Reglas públicas'] ?? 'Sin reglas públicas.') }}</pre></details></article>
                <article class="security-card"><header><div><h3>Fail2ban</h3><p>Protección contra intentos repetidos.</p></div><span class="security-state">{{ $securityStatus['Fail2ban'] ?? '—' }}</span></header><dl><div><dt>Jails activos</dt><dd>{{ $securityStatus['Jails Fail2ban'] ?? '—' }}</dd></div>@foreach($this->fail2banJails() as $jail)<div wire:key="jail-{{ $jail }}"><dt>{{ $jail }}</dt><dd>{{ $securityStatus['Bloqueados:'.$jail] ?? 'ninguno' }}</dd></div>@endforeach</dl></article>
                <article class="security-card"><header><div><h3>Acceso SSH</h3><p>Configuración efectiva del demonio.</p></div></header><dl><div><dt>Puerto</dt><dd>{{ $securityStatus['SSH puerto'] ?? '—' }}</dd></div><div><dt>Contraseña</dt><dd>{{ $securityStatus['SSH contraseña'] ?? '—' }}</dd></div><div><dt>Root</dt><dd>{{ $securityStatus['SSH root'] ?? '—' }}</dd></div><div><dt>Usuario admin</dt><dd>{{ $securityStatus['SSH usuario'] ?? '—' }}</dd></div><div><dt>Llaves</dt><dd>{{ $securityStatus['Llaves SSH'] ?? '—' }}</dd></div></dl></article>
                <article class="security-card"><header><div><h3>Actualizaciones</h3><p>Se instalan en segundo plano.</p></div><span class="security-state">{{ $securityStatus['Actualizaciones pendientes'] ?? '—' }} pendientes</span></header><dl><div><dt>Proceso</dt><dd>{{ $securityStatus['Actualización del sistema'] ?? '—' }}</dd></div></dl></article>
            </div>
        @else<p class="muted">Leyendo configuración de seguridad…</p>@endif
        <section class="security-rules-form"><div><h3>Permitir acceso</h3><p class="muted">Abre un puerto. Deja el origen vacío sólo si el servicio debe ser público.</p></div><form wire:submit="prepareFirewallAllow" class="form-grid"><label>Puerto<input wire:model="firewallPort" type="number" min="1" max="65535" placeholder="3306"></label><label>Protocolo<select wire:model="firewallProtocol"><option value="tcp">TCP</option><option value="udp">UDP</option></select></label><label>Origen <small>(IP o CIDR)</small><input wire:model="firewallSource" placeholder="203.0.113.10 o 203.0.113.0/24"></label><div class="form-actions"><button class="secondary">Preparar regla</button></div></form>@error('firewallPort')<p class="error">{{ $message }}</p>@enderror @error('firewallSource')<p class="error">{{ $message }}</p>@enderror</section>
        <section class="security-rules-form security-delete"><div><h3>Eliminar regla</h3><p class="muted">Usa el número que aparece en “Ver reglas públicas”.</p></div><form wire:submit="prepareFirewallDelete" class="form-grid"><label>Número de regla<input wire:model="firewallRuleNumber" type="number" min="1" max="999" placeholder="3"></label><div class="form-actions"><button class="ghost">Preparar eliminación</button></div></form>@error('firewallRuleNumber')<p class="error">{{ $message }}</p>@enderror</section>
        <section class="security-rules-form security-delete"><div><h3>Desbloquear IP</h3><p class="muted">Quita una IP bloqueada de un jail activo de Fail2ban.</p></div><form wire:submit="prepareFail2banUnban" class="form-grid"><label>Jail<select wire:model="fail2banJail"><option value="">Selecciona…</option>@foreach($this->fail2banJails() as $jail)<option value="{{ $jail }}">{{ $jail }}</option>@endforeach</select></label><label>IP<input wire:model="fail2banIp" placeholder="203.0.113.10"></label><div class="form-actions"><button class="ghost">Preparar desbloqueo</button></div></form>@error('fail2banJail')<p class="error">{{ $message }}</p>@enderror @error('fail2banIp')<p class="error">{{ $message }}</p>@enderror</section>
        <section class="security-rules-form security-ssh-keys"><div><h3>Llaves SSH autorizadas</h3><p class="muted">Se administran las llaves del usuario {{ $securityStatus['SSH usuario'] ?? 'administrador' }}. La última llave no se puede eliminar.</p>@if($this->sshKeys())<details><summary>Ver huellas</summary><dl>@foreach($this->sshKeys() as $number => $fingerprint)<div wire:key="ssh-key-{{ $number }}"><dt>#{{ $number }}</dt><dd>{{ $fingerprint }}</dd></div>@endforeach</dl></details>@endif</div><form wire:submit="prepareSshKeyAdd" class="stack"><label>Llave pública<textarea wire:model="sshPublicKey" rows="3" placeholder="ssh-ed25519 AAAA… nombre@equipo"></textarea></label><div class="button-row"><button class="secondary">Preparar llave</button></div></form>@error('sshPublicKey')<p class="error">{{ $message }}</p>@enderror</section>
        @if($this->sshKeys())<section class="security-rules-form security-delete"><div><h3>Eliminar llave SSH</h3><p class="muted">La última llave siempre queda protegida para no perder acceso.</p></div><form wire:submit="prepareSshKeyDelete" class="form-grid"><label>Número de llave<select wire:model="sshKeyNumber"><option value="">Selecciona…</option>@foreach($this->sshKeys() as $number => $fingerprint)<option value="{{ $number }}">#{{ $number }}</option>@endforeach</select></label><div class="form-actions"><button class="ghost">Preparar eliminación</button></div></form>@error('sshKeyNumber')<p class="error">{{ $message }}</p>@enderror</section>@endif
        <div class="button-row security-actions"><button class="ghost" wire:click="prepareServerAction('firewall-reload')">Recargar UFW</button><button class="ghost" wire:click="prepareServerAction('fail2ban-reload')">Recargar Fail2ban</button><button class="secondary" wire:click="prepareServerAction('system-update')">Instalar actualizaciones</button></div>
        <p class="muted">Las actualizaciones se ejecutan en segundo plano para no cortar esta sesión. Si el sistema indica que requiere reinicio, podrás hacerlo desde Mantenimiento.</p>
    </section>
    @endif
    @if($serverSection === 'services')
    <section class="panel service-dashboard" id="services">
        <div class="section-title"><div><h2>Servicios y recursos</h2><p>Estado operativo, capacidad disponible y registro reciente de cada servicio.</p></div><button class="secondary" wire:click="refreshServerStatus" wire:loading.attr="disabled" wire:target="refreshServerStatus">Actualizar</button></div>
        <div class="resource-strip" aria-label="Recursos actuales del VPS">
            <div><small>CPU</small><strong>{{ $serverStatus['CPU'] ?? '—' }}</strong></div>
            <div><small>Carga · 1 / 5 / 15 min</small><strong>{{ $serverStatus['Carga'] ?? '—' }}</strong></div>
            <div><small>Memoria</small><strong>{{ $serverStatus['Memoria'] ?? '—' }}</strong><span>Disponible: {{ $serverStatus['Memoria disponible'] ?? '—' }}</span></div>
            <div><small>Disco raíz</small><strong>{{ $serverStatus['Disco /'] ?? '—' }}</strong><span>Inodos: {{ $serverStatus['Inodos /'] ?? '—' }}</span></div>
        </div>
        <div class="service-list" role="list">
            @foreach($this->managedServices() as $service)
                @php($serviceStatus = $serverStatus['Servicio:'.$service] ?? 'sin comprobar')
                <article wire:key="service-{{ $service }}" role="listitem">
                    <div class="service-name"><span class="dot {{ $serviceStatus === 'active' ? 'finished' : 'failed' }}"></span><div><strong>{{ $this->serviceLabel($service) }}</strong><small>{{ $service }}</small></div></div>
                    <span @class(['service-state', 'is-active' => $serviceStatus === 'active', 'is-inactive' => $serviceStatus !== 'active'])>{{ $serviceStatus }}</span>
                    <p>PID {{ $serverStatus['ServicioPID:'.$service] ?? '—' }} · {{ $serverStatus['ServicioInicio:'.$service] ?? 'sin inicio registrado' }}</p>
                    <div class="service-actions"><button class="ghost" wire:click="loadServiceLogs('{{ $service }}')" wire:loading.attr="disabled" wire:target="loadServiceLogs">Ver registro</button><button class="secondary" wire:click="prepareServerAction('restart-service', '{{ $service }}')">Reiniciar</button></div>
                </article>
            @endforeach
        </div>
    </section>
    @endif
    @if($serverSection === 'maintenance')
    <section class="panel" id="maintenance">
        <div class="section-title"><div><h2>Actualizar Freyja</h2><p class="muted">Descarga la rama actual del panel, reinstala dependencias, ejecuta migraciones y recompila la interfaz. Los sitios hospedados no se modifican.</p></div><div class="button-row"><button class="ghost" wire:click="refreshPanelUpdateStatus" wire:loading.attr="disabled" wire:target="refreshPanelUpdateStatus">Actualizar estado</button><button class="secondary" wire:click="prepareServerAction('panel-update')">Actualizar desde Git</button></div></div>
        <dl class="mt-5 grid gap-3 sm:grid-cols-3">
            <div><dt class="muted">Versión instalada</dt><dd class="mt-1 font-mono">{{ $panelUpdateStatus['Commit'] ?? '—' }}</dd></div>
            <div><dt class="muted">Rama instalada</dt><dd class="mt-1 font-mono">{{ $panelUpdateStatus['Rama'] ?? '—' }}</dd></div>
            <div><dt class="muted">Fecha del commit</dt><dd class="mt-1">{{ $panelUpdateStatus['Fecha'] ?? '—' }}</dd></div>
        </dl>
        @error('panelUpdate')<p class="error mt-4">{{ $message }}</p>@enderror
        <div class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><strong>Webhook de actualización</strong><p class="muted">GitHub debe enviar <code>push</code> a esta ruta para la rama <code>{{ $panelUpdateBranch }}</code>.</p></div><span class="security-state">{{ $panelUpdateWebhookConfigured ? 'Secreto listo' : 'Falta secreto' }}</span></div>
            <code class="mt-3 block break-all text-sm text-sky-700 dark:text-sky-300">{{ $panelUpdateWebhookUrl }}</code>
            @if(! $panelUpdateWebhookConfigured)<p class="error mt-3">Define <code>PANEL_UPDATE_WEBHOOK_SECRET</code> en el <code>.env</code> antes de registrarlo en GitHub.</p>@endif
        </div>
        <div class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><strong>Configuración de Freyja</strong><p class="muted">Edita el <code>.env</code> del panel. Al guardar se limpia y reconstruye la caché; PHP-FPM y los workers se recargan.</p></div><button class="secondary" wire:click="openPanelEnvironmentEditor" wire:loading.attr="disabled" wire:target="openPanelEnvironmentEditor">Editar .env</button></div>
            @if(session('panelEnvironmentNotice'))<p class="notice mt-3" role="status">{{ session('panelEnvironmentNotice') }}</p>@endif
            @error('panelEnvironment')<p class="error mt-3" role="alert">{{ $message }}</p>@enderror
        </div>
    </section>
    <section class="panel danger-zone"><div class="section-title"><div><h2>Reiniciar VPS</h2><p class="muted">Corta brevemente todos los sitios, conexiones y colas. Freyja volverá al terminar el arranque.</p></div><button class="danger-button" wire:click="prepareServerAction('reboot')">Reiniciar VPS</button></div></section>
    @endif
    @if($serverOutput)<section class="panel"><h2>Resultado</h2><pre class="laravel-console">{{ $serverOutput }}</pre></section>@endif
    @if($confirmServerAction)
        <dialog class="git-dialog" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.set('confirmServerAction', false)">
            <h2>
                @if($serverAction === 'reboot')
                    Confirmar reinicio del VPS
                @elseif($serverAction === 'restart-service')
                    Reiniciar {{ $serverService }}
                @elseif($serverAction === 'hostname')
                    Cambiar hostname del VPS
                @elseif($serverAction === 'firewall-reload')
                    Recargar reglas de UFW
                @elseif($serverAction === 'firewall-allow')
                    Permitir acceso en UFW
                @elseif($serverAction === 'firewall-delete')
                    Eliminar regla de UFW
                @elseif($serverAction === 'fail2ban-unban')
                    Desbloquear IP de Fail2ban
                @elseif($serverAction === 'ssh-key-add')
                    Agregar llave SSH
                @elseif($serverAction === 'ssh-key-delete')
                    Eliminar llave SSH
                @elseif($serverAction === 'fail2ban-reload')
                    Recargar Fail2ban
                @elseif($serverAction === 'system-update')
                    Instalar actualizaciones del sistema
                @elseif($serverAction === 'panel-update')
                    Actualizar Freyja desde Git
                @elseif($serverAction === 'timezone')
                    Cambiar zona horaria
                @else
                    Sincronizar reloj
                @endif
            </h2>
            <p>@if($serverAction === 'reboot') Escribe <code>REINICIAR</code> para reiniciar el VPS ahora. @elseif($serverAction === 'hostname') Escribe el hostname nuevo para confirmar el cambio del sistema. @elseif($serverAction === 'system-update') Escribe <code>ACTUALIZAR</code> para iniciar <code>apt update</code> y <code>apt upgrade</code> en segundo plano. @elseif($serverAction === 'panel-update') Descargará la rama actual de Freyja y el panel se recargará al terminar. @elseif($serverAction === 'firewall-allow') Escribe <code>APLICAR</code> para permitir {{ $firewallPort }}/{{ $firewallProtocol }} desde {{ $firewallSource ?: 'cualquier origen' }}. @elseif($serverAction === 'firewall-delete') Escribe <code>ELIMINAR</code> para quitar la regla UFW #{{ $firewallRuleNumber }}. @elseif($serverAction === 'fail2ban-unban') Escribe <code>DESBLOQUEAR</code> para retirar {{ $fail2banIp }} del jail {{ $fail2banJail }}. @elseif($serverAction === 'ssh-key-add') Escribe <code>AGREGAR</code> para autorizar esta llave pública. @elseif($serverAction === 'ssh-key-delete') Escribe <code>ELIMINAR</code> para borrar la llave #{{ $sshKeyNumber }}. @else Esta acción se ejecutará directamente en el VPS. @endif</p>
            @if($serverAction === 'reboot')<label>Confirmación<input wire:model="rebootConfirmation" autocomplete="off"></label>@endif
            @if($serverAction === 'hostname')<label>Confirmación<input wire:model="hostnameConfirmation" placeholder="{{ $serverHostname }}" autocomplete="off" autocapitalize="none" spellcheck="false"></label>@error('hostnameConfirmation')<p class="error">{{ $message }}</p>@enderror@endif
            @if($serverAction === 'system-update')<label>Confirmación<input wire:model="updateConfirmation" autocomplete="off"></label>@error('updateConfirmation')<p class="error">{{ $message }}</p>@enderror@endif
            @if(in_array($serverAction, ['firewall-allow', 'firewall-delete'], true))<label>Confirmación<input wire:model="firewallConfirmation" autocomplete="off"></label>@error('firewallConfirmation')<p class="error">{{ $message }}</p>@enderror@endif
            @if($serverAction === 'fail2ban-unban')<label>Confirmación<input wire:model="fail2banConfirmation" autocomplete="off"></label>@error('fail2banConfirmation')<p class="error">{{ $message }}</p>@enderror@endif
            @if(in_array($serverAction, ['ssh-key-add', 'ssh-key-delete'], true))<label>Confirmación<input wire:model="sshConfirmation" autocomplete="off"></label>@error('sshConfirmation')<p class="error">{{ $message }}</p>@enderror@endif
            <footer><button class="primary" wire:click="runServerAction" wire:loading.attr="disabled">Confirmar</button><button class="secondary" wire:click="$set('confirmServerAction', false)">Cancelar</button></footer>
        </dialog>
    @endif
    @if($panelEnvironmentEditorOpen)
        <dialog class="git-dialog" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.set('panelEnvironmentEditorOpen', false)" aria-label="Editar configuración de Freyja">
            <h2>Editar .env de Freyja</h2>
            <p>Este archivo contiene secretos del panel. Al guardar se validará el formato, se reconstruirá la caché y se reiniciarán PHP-FPM y los workers.</p>
            <form wire:submit="savePanelEnvironment" class="stack">
                <label>Configuración<textarea wire:model="panelEnvironment" rows="20" class="font-mono text-sm" spellcheck="false" autocapitalize="none" autocomplete="off"></textarea></label>
                @error('panelEnvironment')<p class="error" role="alert">{{ $message }}</p>@enderror
                @if($panelEnvironmentOutput)<pre class="laravel-console">{{ $panelEnvironmentOutput }}</pre>@endif
                <footer><button class="primary" wire:loading.attr="disabled" wire:target="savePanelEnvironment">Guardar y recachear</button><button type="button" class="secondary" wire:click="$set('panelEnvironmentEditorOpen', false)" wire:loading.attr="disabled" wire:target="savePanelEnvironment">Cancelar</button></footer>
                <p class="muted" wire:loading wire:target="savePanelEnvironment">Aplicando configuración y reconstruyendo cachés…</p>
            </form>
        </dialog>
    @endif
    @if($serverSection === 'dns')
    <section class="panel" id="dns-configuration">
        <h2>DNS del servidor</h2>
        <p class="muted">Puedes dejar estos datos pendientes o guardarlos parcialmente hasta tener un dominio propio. Esta configuración es independiente del dominio de acceso al panel y de MariaDB.</p>
        <p role="status">Configuración: {{ $dnsConfiguration?->hasCompleteConfiguration() ? 'Datos completos · borrador guardado' : 'Pendiente de completar' }} · Delegación: no verificada</p>
        @if(session('dnsNotice'))<p class="notice" role="status">{{ session('dnsNotice') }}</p>@endif
        <form wire:submit="saveDnsSettings" class="stack">
            <label>Dominio de infraestructura <small>(opcional por ahora)</small><input wire:model="dnsDomain" placeholder="ejemplo.com" autocomplete="off" spellcheck="false"></label>
            @error('dnsDomain')<p class="error">{{ $message }}</p>@enderror
            <div class="form-grid">
                @foreach([0, 1] as $index)
                    <div wire:key="dns-nameserver-{{ $index }}">
                        <label>Nameserver {{ $index + 1 }}<input wire:model="dnsNameservers.{{ $index }}.hostname" placeholder="ns{{ $index + 1 }}.ejemplo.com" autocomplete="off" spellcheck="false"></label>
                        @error('dnsNameservers.'.$index.'.hostname')<p class="error">{{ $message }}</p>@enderror
                        <label>IP del nameserver {{ $index + 1 }}<input wire:model="dnsNameservers.{{ $index }}.ip" placeholder="IPv4 o IPv6" autocomplete="off" spellcheck="false"></label>
                        @error('dnsNameservers.'.$index.'.ip')<p class="error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
            @error('dnsNameservers')<p class="error">{{ $message }}</p>@enderror
            <div><button class="secondary" wire:loading.attr="disabled" wire:target="saveDnsSettings">Guardar configuración DNS</button></div>
        </form>
        <p class="muted">Servicio DNS autoritativo: pendiente de implementar. Guardar estos datos no instala ni activa el servicio. Después será necesario configurar la delegación y comprobarla antes de habilitar wildcard.</p>
        <p class="muted">SSL individual sigue disponible. Wildcard y su renovación automática permanecen pendientes de DNS operativo y validado.</p>
    </section>
    @endif
    @if($serverSection === 'applications')
    <section class="panel" id="applications">
        <h2>Aplicaciones y bases de datos</h2>
        <h3>Zonas horarias de MariaDB</h3>
        <p>Carga o actualiza las tablas de zonas horarias de MariaDB en este VPS desde /usr/share/zoneinfo. No cambia la zona horaria predeterminada ni los datos de tus aplicaciones.</p>
        <button class="secondary" wire:click="$set('confirmTimezones', true)">Cargar / actualizar zonas horarias</button>
        @error('timezones')<p class="error" role="alert">{{ $message }}</p>@enderror
        @if($timezoneOutput)<pre class="laravel-console" role="status">{{ $timezoneOutput }}</pre>@endif
        @if($confirmTimezones)<dialog class="git-dialog" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.set('confirmTimezones', false)" aria-label="Confirmar carga de zonas horarias"><h2>Cargar zonas horarias</h2><p>Se respaldan y actualizan únicamente las cinco tablas mysql.time_zone* de MariaDB local. Esta acción afecta al catálogo compartido de todos los dominios.</p><p>No se reinicia MariaDB. Si ya tenía zonas horarias en caché, programa un reinicio para aplicar sus cambios.</p><footer><button class="primary" wire:click="importTimezones" wire:loading.attr="disabled">Confirmar carga</button><button class="secondary" wire:click="$set('confirmTimezones', false)" wire:loading.attr="disabled">Cancelar</button></footer><p wire:loading wire:target="importTimezones">Cargando zonas horarias…</p></dialog>@endif
    </section>
    @if(session('notice'))<div class="notice">{{ session('notice') }}</div>@endif
    <form wire:submit="save" class="panel form-grid" id="applications-settings">
        <h2>Freyja</h2>
        <p class="muted">Freyja se publica como control del VPS en <code>https://IP_DEL_VPS:8443</code>. No requiere un dominio de sitio.</p>
        <label>Ruta del panel<input wire:model="panelPath" placeholder="/var/www/minipanel"></label>
        <label>Usuario del panel<input wire:model="panelUser" placeholder="www-data"></label>
        <label>PHP del panel<select wire:model="panelPhpVersion"><option>8.2</option><option>8.3</option><option>8.4</option></select></label>
        <label>IP pública del VPS <small>(para SSL automático)</small><input wire:model="publicIp" placeholder="65.99.225.120"></label>
        <h2>MariaDB provisionador</h2>
        <label>Host<input wire:model="host" placeholder="127.0.0.1"></label>
        <label>Puerto<input wire:model="port" type="number"></label>
        <label>Usuario DBA<input wire:model="username" autocomplete="off"></label>
        <label>Contraseña DBA<input wire:model="password" type="password" autocomplete="new-password"></label>
        <div class="form-actions"><button class="primary">Guardar configuración</button></div>
        @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    </form>
    <section class="panel"><h2>Instalación en Ubuntu</h2><p class="muted">El instalador publica el panel en el puerto 8443, obtiene un certificado TLS para la IP pública y programa su renovación automática. Primero revisa el plan y luego ejecútalo explícitamente.</p><pre><code>sudo ./ops-agent/install-minipanel --app-path {{ $panelPath }} --app-user {{ $panelUser }} --php-version {{ $panelPhpVersion }} --panel-ip {{ $publicIp ?: 'IP_PUBLICA_DEL_VPS' }} --panel-port 8443 --certificate-email {{ auth()->user()->email }} --with-firewall
sudo ./ops-agent/install-minipanel --apply --app-path {{ $panelPath }} --app-user {{ $panelUser }} --php-version {{ $panelPhpVersion }} --panel-ip {{ $publicIp ?: 'IP_PUBLICA_DEL_VPS' }} --panel-port 8443 --certificate-email {{ auth()->user()->email }} --with-firewall
php artisan minipanel:doctor</code></pre></section>
    @endif
</div>
