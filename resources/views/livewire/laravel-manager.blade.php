<div class="laravel-toolkit {{ $embedded ? 'laravel-toolkit-embedded' : '' }}" x-data="{ tab: 'dashboard', commands: { artisan: '', composer: '', npm: '' } }" wire:init="inspectLaravel" @if($activity->whereIn('status', ['queued', 'running'])->isNotEmpty()) wire:poll.1500ms @endif>
    @unless($embedded)
    <p class="breadcrumb"><a href="{{ route('dashboard') }}">Dominios</a> › <a href="{{ route('sites.show', $site) }}">{{ $site->domain }}</a> › Laravel</p>
    <header class="section-title"><h1>Laravel · {{ $site->domain }}</h1><a class="secondary" href="{{ route('sites.files', $site) }}">Archivos</a></header>
    @endunless
    @if($repositories->count() > 1 || $site->runtime === 'laravel')
    <label>Proyecto<select wire:model.live="repositoryId">@if($site->runtime === 'laravel')<option value="0">Raíz del dominio</option>@endif @foreach($repositories as $repository)<option wire:key="laravel-repo-{{ $repository->id }}" value="{{ $repository->id }}">{{ $repository->name }} · /{{ $repository->directory }}</option>@endforeach</select></label>
    @endif
    @if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
    @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <nav class="project-tabs" aria-label="Herramientas Laravel">
        @foreach(['dashboard' => 'Resumen', 'artisan' => 'Artisan', 'composer' => 'Composer', 'npm' => 'Node.js', 'schedule' => 'Schedule', 'queues' => 'Queues'] as $key => $label)<button type="button" @click="tab = '{{ $key }}'" :class="{ active: tab === '{{ $key }}' }">{{ $label }}</button>@endforeach
    </nav>
    <section class="panel" x-show="tab === 'dashboard'">
        @php($project = $repositories->firstWhere('id', $repositoryId))
        <h2>Información de la aplicación</h2>
        <dl class="laravel-info"><dt>Dominio</dt><dd>{{ $site->domain }}</dd><dt>Repositorio</dt><dd>{{ $project?->url ?? 'Sin repositorio asociado' }}</dd><dt>Carpeta</dt><dd><code>{{ $directory }}</code></dd><dt>PHP</dt><dd>{{ $site->php_version }}</dd><dt>Último commit</dt><dd><pre class="laravel-commit">{{ $project?->commits[0]['hash'] ?? 'Sin información' }}
{{ $project?->commits[0]['subject'] ?? '' }}</pre></dd></dl>
        <h2>Configuración del proyecto</h2>
        <button class="secondary" wire:click="openEnvironment" wire:loading.attr="disabled">Editar .env</button>
        <h3>Modo mantenimiento</h3>
        <div class="maintenance-row">
            <div class="maintenance-control">
                <button type="button" class="maintenance-switch" role="switch" aria-label="Modo mantenimiento" aria-checked="{{ $maintenance ? 'true' : 'false' }}" wire:click="setMaintenance({{ $maintenance ? 'false' : 'true' }})" wire:loading.attr="disabled" @disabled($maintenance === null)><span aria-hidden="true"></span></button>
                <span>{{ $maintenance === null ? 'Consultando…' : ($maintenance ? 'Activado' : 'Desactivado') }}</span>
            </div>
            <label class="maintenance-secret">Secret <span class="muted">(opcional)</span><input type="password" wire:model="maintenanceSecret" autocomplete="new-password" placeholder="Sin secret"></label>
            <button class="ghost" wire:click="inspectLaravel" wire:loading.attr="disabled">Actualizar estado</button>
        </div>
        <p class="muted">De 8 a 128 letras, números, guion o guion bajo. Conserva tu secret para acceder por /SECRET mientras el sitio está en mantenimiento. No se guarda en el historial del panel.</p>
    </section>
    <section class="panel" x-show="['artisan', 'composer', 'npm'].includes(tab)" x-cloak>
        <div x-show="tab === 'npm'" class="button-row">
            <div><strong>Puppeteer</strong><p class="muted">Se instala para este proyecto con su navegador Chrome aislado. Al terminar verás las rutas para tu archivo <code>.env</code>.</p></div>
            <button type="button" class="secondary" wire:click="installPuppeteer" wire:loading.attr="disabled">Instalar Puppeteer y Chrome</button>
        </div>
        <form class="laravel-command" @submit.prevent="$wire.set('tool', tab, false); $wire.set('command', commands[tab], false); $wire.runCommand()">
            <label for="laravel-command-{{ $site->id }}" x-text="tab === 'artisan' ? 'php artisan' : tab"></label><input id="laravel-command-{{ $site->id }}" x-model="commands[tab]" :list="tab === 'artisan' ? 'artisan-suggestions-{{ $site->id }}-{{ $repositoryId }}' : null" placeholder="Comando…" autocomplete="off" required>
            <datalist id="artisan-suggestions-{{ $site->id }}-{{ $repositoryId }}">
                @foreach($artisanSuggestions as $suggestion)
                    <option wire:key="artisan-suggestion-{{ $repositoryId }}-{{ $suggestion['name'] }}" value="{{ $suggestion['name'] }}" label="{{ $suggestion['description'] }}"></option>
                @endforeach
            </datalist>
            <button class="secondary" aria-label="Ejecutar comando" title="Ejecutar" wire:loading.attr="disabled">▷</button>
            <button type="button" class="ghost" x-show="tab === 'artisan'" wire:click="inspectLaravel" wire:loading.attr="disabled" title="Actualizar comandos registrados">↻ Comandos</button>
        </form>
        @foreach(['artisan', 'composer', 'npm'] as $console)
            @php($result = $activity->first(fn ($run) => ($run->parameters['tool'] ?? null) === $console || ($console === 'npm' && ($run->parameters['task'] ?? null) === 'puppeteer')))
            <div x-show="tab === '{{ $console }}'" wire:key="console-{{ $console }}-{{ $repositoryId }}"><small class="muted">{{ $result?->status }}</small><pre class="laravel-console">{{ $result?->output }}</pre></div>
        @endforeach
    </section>
    <section class="panel" x-show="tab === 'schedule'" x-cloak>
        @php($schedulerStatus = $site->scheduler_status ?: ($site->scheduler_enabled ? 'active' : 'inactive'))
        <div class="schedule-heading">
            <div><h2>Tareas programadas</h2><p class="muted">Laravel revisa las tareas cada minuto; aquí ves la última lectura de <code>schedule:list</code>.</p></div>
            <span class="schedule-state {{ $schedulerStatus === 'active' ? 'is-active' : 'is-inactive' }}">{{ match($schedulerStatus) { 'active' => 'Scheduler activo', 'failed' => 'Scheduler con error', 'unknown' => 'Scheduler sin verificar', default => 'Scheduler detenido' } }}</span>
        </div>
        <div class="button-row"><button class="secondary" wire:click="scheduleList">Actualizar tareas</button><button class="primary" wire:click="scheduler(true)">Activar scheduler</button><button class="secondary" wire:click="scheduler(false)">Detener scheduler</button><button class="ghost" wire:click="serviceStatus">Ver servicios</button></div>
        @if($scheduleInspection)
            <p class="schedule-inspected">Última consulta: {{ $scheduleInspection->created_at->format('d/m/Y H:i') }}</p>
            @if(count($scheduledTasks))
                <div class="schedule-list" role="list">
                    @foreach($scheduledTasks as $task)
                        <article role="listitem">
                            <div class="schedule-command"><code>{{ $task['command'] }}</code><small>{{ $task['cadence'] }} · Cron: {{ $task['expression'] }}</small></div>
                            <div class="schedule-next"><span>Próxima ejecución</span><strong>{{ $task['next'] }}</strong></div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="schedule-empty">No se encontraron tareas interpretables en la respuesta de Laravel. Puedes ver la salida técnica abajo.</div>
            @endif
            <details class="schedule-raw"><summary>Ver salida técnica de Laravel</summary><pre class="laravel-console">{{ $scheduleInspection->output }}</pre></details>
        @else
            <div class="schedule-empty">Aún no se han consultado las tareas de este proyecto. Pulsa <strong>Actualizar tareas</strong> para cargarlas.</div>
        @endif
    </section>
    <section class="panel" x-show="tab === 'queues'" x-cloak>
        <h2>Colas y workers</h2><p>Crea o actualiza los workers de la cola indicada. Deshabilitar detiene todos sus workers.</p>
        <div class="form-grid"><label>Nombre de cola<input wire:model="queueName"></label><label>Workers<input type="number" min="1" max="12" wire:model="workers"></label><label>Intentos<input type="number" min="1" max="20" wire:model="tries"></label><label>Timeout (segundos)<input type="number" min="10" max="3600" wire:model="workerTimeout"></label></div>
        <p class="muted">El retry_after de tu conexión Laravel debe ser mayor que el timeout del worker.</p>
        <div class="button-row"><button class="primary" wire:click="queueService(true)">Guardar y activar</button><button class="secondary" wire:click="queueService(false)">Deshabilitar cola</button><button class="secondary" wire:click="queueCommand('queue:restart')">Reiniciar workers</button><button class="secondary" wire:click="queueCommand('queue:failed')">Jobs fallidos</button><button class="ghost" wire:click="serviceStatus">Consultar servicios</button></div>
    </section>
    <section class="panel" x-show="['schedule', 'queues'].includes(tab)" x-cloak>
        @foreach($activity as $operation)
            @php($isQueue = ($operation->parameters['service'] ?? '') === 'queue' || str_starts_with($operation->parameters['arguments'][0] ?? '', 'queue:'))
            @php($isSchedule = in_array($operation->parameters['service'] ?? '', ['schedule', 'status'], true))
            @if($isSchedule)<article x-show="tab === 'schedule'" wire:key="laravel-operation-{{ $operation->id }}"><small>{{ $operation->status }} · {{ $operation->created_at->format('d/m/Y H:i') }}</small><pre class="laravel-console">{{ $operation->output }}</pre></article>@endif
            @if($isQueue)<article x-show="tab === 'queues'" wire:key="laravel-operation-{{ $operation->id }}"><small>{{ $operation->status }} · {{ $operation->created_at->format('d/m/Y H:i') }}</small><pre class="laravel-console">{{ $operation->output }}</pre></article>@endif
        @endforeach
    </section>
    @if($showEnvironment)
        <dialog class="git-dialog" wire:ignore.self x-data x-init="$el.showModal()" @cancel.prevent="$wire.closeEnvironment()" aria-label="Editar .env">
            <header><h2>Editar .env</h2><button class="ghost" wire:click="closeEnvironment">Cerrar</button></header>
            <p>{{ $directory }}/.env · El archivo conserva permisos privados. No se sobrescribe si cambió desde que lo abriste.</p>
            @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <form wire:submit="saveEnvironment"><textarea class="env-editor" wire:model="environment" rows="22" spellcheck="false" autocomplete="off" aria-label="Contenido .env"></textarea><footer><button class="primary" wire:loading.attr="disabled">Guardar .env</button><button type="button" class="secondary" wire:click="closeEnvironment">Cancelar</button></footer></form>
        </dialog>
    @endif
</div>
