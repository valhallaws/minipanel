<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GitAgentTest extends TestCase
{
    private function agent(): \MiniPanelGit
    {
        require_once base_path('ops-agent/minipanel-git.php');
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('site');

        return new \MiniPanelGit(Storage::disk('local')->path('site'), '8.3');
    }

    public function test_creates_a_folder_and_returns_it_in_the_picker(): void
    {
        $agent = $this->agent();
        $this->assertSame(['created' => true], $agent->execute('mkdir', ['directory' => 'httpdocs']));
        $this->assertSame(['folders' => [['name' => 'httpdocs', 'path' => 'httpdocs']]], $agent->execute('folders', ['directory' => '.']));
    }

    public function test_rejects_paths_outside_the_domain(): void
    {
        $agent = $this->agent();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ruta no válida');
        $agent->execute('mkdir', ['directory' => '../outside']);
    }

    public function test_rejects_symlink_destinations(): void
    {
        $agent = $this->agent();
        Storage::disk('local')->makeDirectory('outside');
        symlink(Storage::disk('local')->path('outside'), Storage::disk('local')->path('site/linked'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('enlaces simbólicos');
        $agent->execute('mkdir', ['directory' => 'linked/project']);
    }

    public function test_key_is_idempotent_and_private_material_is_not_returned(): void
    {
        $agent = $this->agent();
        $id = '7f423dea-0000-4000-8000-000000000001';
        $first = $agent->execute('key', ['id' => $id]);
        $this->assertSame($first, $agent->execute('key', ['id' => $id]));
        $this->assertSame(['public_key'], array_keys($first));
        $this->assertStringStartsWith('ssh-ed25519 ', $first['public_key']);
        $this->assertSame(0600, fileperms(Storage::disk('local')->path('site/.ssh/minipanel/'.$id)) & 0777);
    }

    public function test_https_is_rejected_before_any_network_operation(): void
    {
        $agent = $this->agent();
        $id = '7f423dea-0000-4000-8000-000000000002';
        $agent->execute('key', ['id' => $id]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solo se permiten repositorios SSH');
        $agent->execute('sync', ['id' => $id, 'url' => 'https://example.com/a.git', 'directory' => 'httpdocs']);
    }

    public function test_existing_folder_is_not_overwritten(): void
    {
        $agent = $this->agent();
        Storage::disk('local')->put('site/httpdocs/index.html', 'Keep me');
        try {
            $agent->execute('mkdir', ['directory' => 'httpdocs']);
            $this->fail('Existing folder must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ya existe', $exception->getMessage());
        }
        $this->assertSame('Keep me', Storage::disk('local')->get('site/httpdocs/index.html'));
    }
}
