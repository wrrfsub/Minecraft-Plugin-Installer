<?php

namespace PluginInstaller;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Extensions\ExtensionRepository;

final class Sources
{
    public const LIMIT = 18;

    public const LOADERS = ['paper', 'purpur', 'folia', 'spigot', 'bukkit', 'velocity', 'bungeecord'];

    private const COMPATIBLE = [
        'paper' => ['paper', 'spigot', 'bukkit'],
        'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
        'folia' => ['folia'],
        'spigot' => ['spigot', 'bukkit'],
        'bukkit' => ['bukkit'],
        'velocity' => ['velocity'],
        'bungeecord' => ['bungeecord', 'waterfall'],
    ];

    public static function curseforgeKey(): string
    {
        return (string) app(ExtensionRepository::class)->settings('plugin-installer')->get('curseforge_key');
    }

    public static function available(): array
    {
        return array_values(array_filter(['modrinth', 'hangar', 'spigot', self::curseforgeKey() !== '' ? 'curseforge' : null]));
    }

    private static function http(): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->withUserAgent('s4way/plugin-installer (Pterodactyl)');
    }

    private static function get(string $url, array $query = [], array $headers = []): array
    {
        $response = self::http()->withHeaders($headers)->get($url, $query);
        if (! $response->successful()) {
            throw new DisplayException('The plugin site did not answer ('.$response->status().'). Try again in a moment.');
        }

        return (array) $response->json();
    }

    private static function cached(array $key, callable $fn): mixed
    {
        return Cache::remember('plugin-installer:'.sha1(json_encode($key)), 300, $fn);
    }

    public static function gameVersions(): array
    {
        return Cache::remember('plugin-installer:game-versions', 86400, fn () => collect(self::get('https://api.modrinth.com/v2/tag/game_version'))
            ->where('version_type', 'release')->pluck('version')->take(40)->values()->all());
    }

    public static function search(string $source, string $query, int $page, ?string $version, string $loader): array
    {
        return self::cached([$source, $query, $page, $version, $loader], fn () => match ($source) {
            'modrinth' => self::modrinthSearch($query, $page, $version, $loader),
            'hangar' => self::hangarSearch($query, $page, $version, $loader),
            'spigot' => self::spigotSearch($query, $page),
            'curseforge' => self::curseforgeSearch($query, $page, $version),
        });
    }

    public static function versions(string $source, string $id, ?string $version, string $loader): array
    {
        return self::cached(['v', $source, $id, $version, $loader], fn () => match ($source) {
            'modrinth' => self::modrinthVersions($id, $version, $loader),
            'hangar' => self::hangarVersions($id, $version, $loader),
            'spigot' => self::spigotVersions($id),
            'curseforge' => self::curseforgeVersions($id, $version),
        });
    }

    public static function project(string $source, string $id): array
    {
        return self::cached(['p', $source, $id], fn () => match ($source) {
            'modrinth' => self::modrinthProject(self::get("https://api.modrinth.com/v2/project/$id")),
            'hangar' => self::hangarProject(self::get('https://hangar.papermc.io/api/v1/projects/'.rawurlencode($id))),
            'spigot' => self::spigotProject(self::get("https://api.spiget.org/v2/resources/$id")),
            'curseforge' => self::curseforgeProject(self::get("https://api.curseforge.com/v1/mods/$id", [], ['x-api-key' => self::curseforgeKey()])['data'] ?? []),
        });
    }

    public static function version(string $source, string $id, string $versionId, ?string $version, string $loader): array
    {
        return collect(self::versions($source, $id, $version, $loader))->firstWhere('id', $versionId)
            ?? throw new DisplayException('That version is not available anymore.');
    }

    public static function finalUrl(string $url): string
    {
        for ($i = 0; $i < 5; $i++) {
            $response = self::http()->withOptions(['allow_redirects' => false])->head($url);
            $location = $response->header('Location');
            if (! $response->redirect() || $location === '') {
                break;
            }
            $url = str_starts_with($location, 'http') ? $location : parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).$location;
        }

        return $url;
    }

    private static function modrinthProject(array $p): array
    {
        $slug = $p['slug'] ?? $p['project_id'] ?? $p['id'];

        return [
            'id' => $p['project_id'] ?? $p['id'],
            'name' => $p['title'],
            'author' => $p['author'] ?? null,
            'description' => $p['description'] ?? '',
            'icon' => $p['icon_url'] ?: null,
            'downloads' => $p['downloads'] ?? 0,
            'stars' => $p['follows'] ?? $p['followers'] ?? 0,
            'updated' => $p['date_modified'] ?? $p['updated'] ?? null,
            'url' => "https://modrinth.com/plugin/$slug",
            'installable' => true,
        ];
    }

    private static function modrinthSearch(string $query, int $page, ?string $version, string $loader): array
    {
        $facets = [['project_type:plugin'], array_map(fn ($l) => "categories:$l", self::COMPATIBLE[$loader])];
        if ($version) {
            $facets[] = ["versions:$version"];
        }
        $data = self::get('https://api.modrinth.com/v2/search', [
            'query' => $query,
            'limit' => self::LIMIT,
            'offset' => ($page - 1) * self::LIMIT,
            'index' => $query === '' ? 'downloads' : 'relevance',
            'facets' => json_encode($facets),
        ]);

        return ['items' => array_map(self::modrinthProject(...), $data['hits'] ?? []), 'total' => $data['total_hits'] ?? 0];
    }

    private static function modrinthVersions(string $id, ?string $version, string $loader): array
    {
        $data = self::get("https://api.modrinth.com/v2/project/$id/version", array_filter([
            'loaders' => json_encode(self::COMPATIBLE[$loader]),
            'game_versions' => $version ? json_encode([$version]) : null,
        ]));

        return collect($data)->map(function (array $v) {
            $file = collect($v['files'] ?? [])->firstWhere('primary', true) ?? ($v['files'][0] ?? null);

            return $file ? ['id' => $v['id'], 'name' => $v['version_number'].($v['name'] && $v['name'] !== $v['version_number'] ? " ({$v['name']})" : ''), 'file' => $file['filename'], 'url' => $file['url']] : null;
        })->filter()->take(25)->values()->all();
    }

    private static function hangarPlatform(string $loader): string
    {
        return match ($loader) {
            'velocity' => 'VELOCITY',
            'bungeecord' => 'WATERFALL',
            default => 'PAPER',
        };
    }

    private static function hangarProject(array $p): array
    {
        return [
            'id' => $p['namespace']['slug'],
            'name' => $p['name'],
            'author' => $p['namespace']['owner'],
            'description' => $p['description'] ?? '',
            'icon' => $p['avatarUrl'] ?? null,
            'downloads' => $p['stats']['downloads'] ?? 0,
            'stars' => $p['stats']['stars'] ?? 0,
            'updated' => $p['lastUpdated'] ?? null,
            'url' => "https://hangar.papermc.io/{$p['namespace']['owner']}/{$p['namespace']['slug']}",
            'installable' => true,
        ];
    }

    private static function hangarSearch(string $query, int $page, ?string $version, string $loader): array
    {
        $data = self::get('https://hangar.papermc.io/api/v1/projects', array_filter([
            'q' => $query ?: null,
            'limit' => self::LIMIT,
            'offset' => ($page - 1) * self::LIMIT,
            'sort' => '-downloads',
            'platform' => self::hangarPlatform($loader),
            'version' => $version,
        ]));

        return ['items' => array_map(self::hangarProject(...), $data['result'] ?? []), 'total' => $data['pagination']['count'] ?? 0];
    }

    private static function hangarVersions(string $id, ?string $version, string $loader): array
    {
        $platform = self::hangarPlatform($loader);
        $data = self::get('https://hangar.papermc.io/api/v1/projects/'.rawurlencode($id).'/versions', array_filter([
            'limit' => 25,
            'platform' => $platform,
            'platformVersion' => $version,
        ]));

        return collect($data['result'] ?? [])->map(function (array $v) use ($platform) {
            $d = $v['downloads'][$platform] ?? null;

            return $d && $d['downloadUrl'] ? ['id' => $v['name'], 'name' => $v['name'].' ('.($v['channel']['name'] ?? 'Release').')', 'file' => $d['fileInfo']['name'], 'url' => $d['downloadUrl']] : null;
        })->filter()->values()->all();
    }

    private static function spigotProject(array $p, array $authors = []): array
    {
        $icon = $p['icon']['url'] ?? '';

        return [
            'id' => (string) $p['id'],
            'name' => $p['name'],
            'author' => $authors[$p['author']['id'] ?? 0] ?? null,
            'description' => html_entity_decode($p['tag'] ?? ''),
            'icon' => $icon !== '' ? 'https://www.spigotmc.org/'.$icon : null,
            'downloads' => $p['downloads'] ?? 0,
            'stars' => round($p['rating']['average'] ?? 0, 1),
            'updated' => isset($p['updateDate']) ? Carbon::createFromTimestamp($p['updateDate'])->toIso8601String() : null,
            'url' => "https://www.spigotmc.org/resources/{$p['id']}/",
            'installable' => ! ($p['external'] ?? false) && ! ($p['premium'] ?? false),
        ];
    }

    private static function spigotSearch(string $query, int $page): array
    {
        $params = ['size' => self::LIMIT, 'page' => $page, 'sort' => '-downloads', 'fields' => 'id,name,tag,icon,downloads,rating,updateDate,author,external,premium'];
        $response = self::http()->get($query === '' ? 'https://api.spiget.org/v2/resources' : 'https://api.spiget.org/v2/search/resources/'.rawurlencode($query), $params);
        if ($response->status() === 404) {
            return ['items' => [], 'total' => 0];
        }
        $data = (array) $response->json();
        $ids = collect($data)->pluck('author.id')->filter()->unique();
        $authors = $ids->mapWithKeys(fn ($id) => [$id => Cache::remember("plugin-installer:spigot-author:$id", 86400, fn () => self::http()->get("https://api.spiget.org/v2/authors/$id", ['fields' => 'name'])->json('name'))])->all();

        return ['items' => array_map(fn ($p) => self::spigotProject($p, $authors), $data), 'total' => (int) ($response->header('X-Total') ?: count($data))];
    }

    private static function spigotVersions(string $id): array
    {
        $project = self::get("https://api.spiget.org/v2/resources/$id");
        if (($project['external'] ?? false) || ($project['premium'] ?? false)) {
            return [];
        }
        $latest = self::http()->get("https://api.spiget.org/v2/resources/$id/versions/latest")->json('name') ?? 'Latest';

        return [['id' => 'latest', 'name' => "$latest (latest)", 'file' => Str::slug($project['name']).'.jar', 'url' => "https://api.spiget.org/v2/resources/$id/download"]];
    }

    private static function curseforgeProject(array $p): array
    {
        return [
            'id' => (string) $p['id'],
            'name' => $p['name'],
            'author' => $p['authors'][0]['name'] ?? null,
            'description' => $p['summary'] ?? '',
            'icon' => $p['logo']['thumbnailUrl'] ?? null,
            'downloads' => $p['downloadCount'] ?? 0,
            'stars' => $p['thumbsUpCount'] ?? 0,
            'updated' => $p['dateModified'] ?? null,
            'url' => $p['links']['websiteUrl'] ?? null,
            'installable' => ($p['allowModDistribution'] ?? true) !== false,
        ];
    }

    private static function curseforgeSearch(string $query, int $page, ?string $version): array
    {
        $data = self::get('https://api.curseforge.com/v1/mods/search', array_filter([
            'gameId' => 432,
            'classId' => 5,
            'searchFilter' => $query ?: null,
            'gameVersion' => $version,
            'sortField' => 2,
            'sortOrder' => 'desc',
            'pageSize' => self::LIMIT,
            'index' => ($page - 1) * self::LIMIT,
        ]), ['x-api-key' => self::curseforgeKey()]);

        return ['items' => array_map(self::curseforgeProject(...), $data['data'] ?? []), 'total' => min($data['pagination']['totalCount'] ?? 0, 10000 - self::LIMIT)];
    }

    private static function curseforgeVersions(string $id, ?string $version): array
    {
        $data = self::get("https://api.curseforge.com/v1/mods/$id/files", array_filter(['gameVersion' => $version, 'pageSize' => 25]), ['x-api-key' => self::curseforgeKey()]);

        return collect($data['data'] ?? [])
            ->filter(fn ($f) => $f['downloadUrl'] && str_ends_with(strtolower($f['fileName']), '.jar'))
            ->map(fn ($f) => ['id' => (string) $f['id'], 'name' => $f['displayName'], 'file' => $f['fileName'], 'url' => $f['downloadUrl']])
            ->values()->all();
    }
}
