<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SiteLifecycle;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class PurgeSiteTrash extends Command
{
    protected $signature = 'minipanel:purge-site-trash';

    protected $description = 'Elimina los dominios cuyo plazo de cinco días en papelera terminó';

    /**
     * Execute the console command.
     */
    public function handle(SiteLifecycle $lifecycle): int
    {
        if (! config('minipanel.execution_enabled')) {
            return self::SUCCESS;
        }
        Site::onlyTrashed()->where('purge_after', '<=', now())->whereNull('lifecycle_action')->whereNull('lifecycle_error')->each(function (Site $site) use ($lifecycle): void {
            if (Site::withTrashed()->find($site->id)?->lifecycle_action) {
                return;
            }
            if ($site->trash_group && Site::onlyTrashed()->where('trash_group', $site->trash_group)->where('purge_after', '>', now())->exists()) {
                return;
            }
            try {
                $lifecycle->request($site->id, 'purge');
            } catch (ValidationException $exception) {
                $this->warn('No se pudo programar el borrado de '.$site->domain.': '.$exception->getMessage());
            }
        });

        return self::SUCCESS;
    }
}
