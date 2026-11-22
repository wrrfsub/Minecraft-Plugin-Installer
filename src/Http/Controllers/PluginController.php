<?php

namespace PluginInstaller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use PluginInstaller\Sources;
use Throwable;

class PluginController
{
    private const TABLE = 'ext_plugin_installer_plugins';

    private function authorize(Request $request, Server $server, string $permission): void
    {
        abort_unless($request->user()->can($permission, $server), 403, 'You do not have permission to do that.');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'source' => ['required', Rule::in(Sources::available())],
            'version' => ['nullable', 'string', 'max:32'],
            'loader' => ['required', Rule::in(Sources::LOADERS)],
        ]);
    }

    public function context(Request $request, Server $server): array
    {
        $this->authorize($request, $server, 'file.read');
        $versions = Sources::gameVersions();
        $egg = strtolower((string) $server->egg?->name);
        $history = rescue(fn () => json_decode(Daemon::server($server)->files()->getContent('/version_history.json'), true), null, false);
        preg_match('/MC: ([0-9][0-9.]*)/', (string) ($history['currentVersion'] ?? ''), $m);
        $set = $server->variables()->get()->firstWhere('env_variable', 'MINECRAFT_VERSION');
        $wanted = $m[1] ?? trim((string) ($set?->server_value ?? $set?->default_value));

        return [
            'sources' => Sources::available(),
            'versions' => $versions,
            'loaders' => Sources::LOADERS,
            'version' => in_array($wanted, $versions, true) ? $wanted : ($versions[0] ?? null),
            'loader' => $this->loader($egg),
            'can' => ['install' => $request->user()->can('file.create', $server), 'delete' => $request->user()->can('file.delete', $server)],
        ];
    }

    private function loader(string $egg): string
    {
        foreach (['purpur' => 'purpur', 'folia' => 'folia', 'velocity' => 'velocity', 'bungee' => 'bungeecord', 'waterfall' => 'bungeecord', 'spigot' => 'spigot', 'bukkit' => 'bukkit'] as $word => $loader) {
            if (str_contains($egg, $word)) {
                return $loader;
            }
        }

        return 'paper';
    }

    public function search(Request $request, Server $server): array
    {
        $this->authorize($request, $server, 'file.read');
        $f = $this->filters($request);
        $extra = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:500']]);

        return Sources::search($f['source'], trim((string) ($extra['q'] ?? '')), (int) ($extra['page'] ?? 1), $f['version'] ?? null, $f['loader']);
    }

    public function versions(Request $request, Server $server): array
    {
        $this->authorize($request, $server, 'file.read');
        $f = $this->filters($request);
        $id = $request->validate(['id' => ['required', 'string', 'max:100']])['id'];

        return collect(Sources::versions($f['source'], $id, $f['version'] ?? null, $f['loader']))->map(fn ($v) => ['id' => $v['id'], 'name' => $v['name']])->all();
    }

    public function install(Request $request, Server $server): array
    {
        $this->authorize($request, $server, 'file.create');
        $f = $this->filters($request);
        $data = $request->validate(['id' => ['required', 'string', 'max:100'], 'version_id' => ['required', 'string', 'max:100']]);

        $project = Sources::project($f['source'], $data['id']);
        throw_unless($project['installable'], DisplayException::class, 'This plugin can only be downloaded from its own website.');
        $version = Sources::version($f['source'], $data['id'], $data['version_id'], $f['version'] ?? null, $f['loader']);
        $file = basename(str_replace('\\', '/', $version['file']));
        throw_unless(str_ends_with(strtolower($file), '.jar') && ! str_starts_with($file, '.'), DisplayException::class, 'That download is not a plugin .jar file.');

        $files = Daemon::server($server)->files();
        rescue(fn () => $files->createDirectory('plugins', '/'), report: false);
        try {
            $files->pull(Sources::finalUrl($version['url']), '/plugins', ['filename' => $file, 'foreground' => true, 'timeout' => 300]);
        } catch (Throwable $e) {
            throw new DisplayException('The download failed. The plugin site may be down, try again later.');
        }

        $old = DB::table(self::TABLE)->where('server_id', $server->id)->where('source', $f['source'])->where('project_id', $project['id'])->where('file', '!=', $file)->pluck('file');
        if ($old->isNotEmpty()) {
            rescue(fn () => $files->deleteFiles('/plugins', $old->all()), report: false);
            DB::table(self::TABLE)->where('server_id', $server->id)->whereIn('file', $old)->delete();
        }
        DB::table(self::TABLE)->updateOrInsert(['server_id' => $server->id, 'file' => $file], [
            'source' => $f['source'], 'project_id' => $project['id'], 'name' => $project['name'], 'version' => $version['name'],
            'icon' => $project['icon'], 'url' => $project['url'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        Activity::event('server:plugin-installer.install')->property(['plugin' => $project['name'], 'file' => $file])->log();

        return ['file' => $file, 'replaced' => $old->values()->all()];
    }

    public function installed(Request $request, Server $server): array
    {
        $this->authorize($request, $server, 'file.read');
        try {
            $listing = Daemon::server($server)->files()->getDirectory('/plugins');
        } catch (Throwable) {
            $listing = [];
        }
        $jars = collect($listing)->filter(fn ($f) => ($f['file'] ?? false) && str_ends_with(strtolower($f['name']), '.jar'))->keyBy('name');
        $records = DB::table(self::TABLE)->where('server_id', $server->id)->get()->keyBy('file');
        DB::table(self::TABLE)->where('server_id', $server->id)->whereNotIn('file', $jars->keys()->all() ?: [''])->delete();

        return $jars->map(function (array $f) use ($records) {
            $r = $records->get($f['name']);

            return [
                'file' => $f['name'],
                'size' => $f['size'] ?? 0,
                'name' => $r->name ?? preg_replace('/\.jar$/i', '', $f['name']),
                'source' => $r->source ?? null,
                'project' => $r->project_id ?? null,
                'version' => $r->version ?? null,
                'icon' => $r->icon ?? null,
                'url' => $r->url ?? null,
            ];
        })->sortBy(fn ($p) => strtolower($p['name']))->values()->all();
    }

    public function delete(Request $request, Server $server): array
    {
        $this->authorize($request, $server, 'file.delete');
        $file = $request->validate(['file' => ['required', 'string', 'max:255']])['file'];
        throw_unless($file === basename($file) && str_ends_with(strtolower($file), '.jar'), DisplayException::class, 'That is not a plugin file.');

        Daemon::server($server)->files()->deleteFiles('/plugins', [$file]);
        DB::table(self::TABLE)->where('server_id', $server->id)->where('file', $file)->delete();
        Activity::event('server:plugin-installer.delete')->property('file', $file)->log();

        return ['deleted' => $file];
    }
}
