@foreach($lifecycleSites as $pendingSite)
    <div class="{{ $pendingSite->lifecycle_error ? 'error' : 'notice' }}" wire:key="lifecycle-{{ $pendingSite->id }}" role="status">
        <strong>{{ $pendingSite->domain }}</strong> · {{ $pendingSite->lifecycle_action }}
        @if($pendingSite->lifecycle_error)
            <p>No se completó: {{ $pendingSite->lifecycle_error }}</p><p>Se conserva el registro y el estado de recuperación. No se ha marcado la operación como exitosa.</p>
            <button class="secondary" wire:click="retrySiteAction({{ $pendingSite->id }})" wire:loading.attr="disabled">Reintentar operación</button>
        @else<p>Procesando en el servidor…</p>@endif
    </div>
@endforeach
@if($actionSite)
    <div class="settings-backdrop" wire:key="site-action-{{ $actionSite->id }}-{{ $siteActionMode }}">
        <section class="panel settings-drawer" role="dialog" aria-modal="true" aria-labelledby="site-action-title">
            <div class="section-title"><h2 id="site-action-title">{{ ['rename' => 'Renombrar dominio', 'delete' => 'Eliminar dominio', 'restore' => 'Restaurar dominio', 'purge' => 'Eliminar definitivamente'][$siteActionMode] }}</h2><button type="button" class="ghost" wire:click="closeSiteAction">Cerrar</button></div>
            <p><strong>{{ $actionSite->domain }}</strong></p>
            <p>Las operaciones de hosting que estén esperando en cola se cancelarán. Si hay una operación ejecutándose o una transferencia pendiente, primero deberá terminar.</p>
            <form wire:submit="confirmSiteAction" class="form-grid">
                @if($siteActionMode === 'rename')
                    <label>Nuevo dominio<input wire:model="newDomain" required autocomplete="off"></label>
                    <p>Se cambia el nombre atendido por Nginx. Se conservan la carpeta <code>{{ $actionSite->path }}</code>, el usuario del sistema, los archivos, las bases de datos y las tareas.</p>
                    <p>Actualiza el DNS y emite un certificado para el nombre nuevo. El certificado anterior no lo cubre. Revisa también APP_URL y las URL configuradas en tu aplicación.</p>
                    <p>Los subdominios conservan sus nombres y pasarán a mostrarse como dominios independientes.</p>
                @else
                    <p>Incluye los archivos, bases y usuarios exclusivos registrados por Freyja, tareas, configuración Nginx, claves de deploy, respaldos y certificados exclusivos. Los recursos compartidos no se eliminan.</p>
                    @if($actionSite->children->isNotEmpty())<p>También incluye estos subdominios:</p><ul>@foreach($actionSite->children as $affected)<li wire:key="affected-{{ $affected->id }}">{{ $affected->domain }}</li>@endforeach</ul>@endif
                    @if($siteActionMode === 'delete')
                        <label class="check"><input type="checkbox" wire:model.live="deleteImmediately">No pasar por la papelera: borrar inmediatamente</label>
                        @if($deleteImmediately)<p class="error">Esta acción es irreversible. No habrá cinco días de recuperación.</p>@else<p>El sitio quedará fuera de servicio. Podrás restaurarlo durante cinco días; al vencer el plazo se eliminará definitivamente.</p>@endif
                    @elseif($siteActionMode === 'purge')<p class="error">Borrado inmediato e irreversible de todo el grupo. No podrás restaurarlo desde la papelera.</p>
                    @else<p>Se restaurarán los recursos y el estado activo o suspendido que tenían antes de eliminarlos.</p>@endif
                @endif
                @foreach($errors->all() as $error)<p class="error" role="alert">{{ $error }}</p>@endforeach
                <div class="form-actions"><button type="button" class="secondary" wire:click="closeSiteAction">Cancelar</button><button type="submit" class="{{ $siteActionMode === 'purge' || ($siteActionMode === 'delete' && $deleteImmediately) ? 'danger-button' : 'primary' }}" wire:loading.attr="disabled">{{ $siteActionMode === 'rename' ? 'Renombrar' : ($siteActionMode === 'restore' ? 'Restaurar' : (($siteActionMode === 'purge' || $deleteImmediately) ? 'Eliminar definitivamente' : 'Mover a papelera')) }}</button></div>
            </form>
        </section>
    </div>
@endif
