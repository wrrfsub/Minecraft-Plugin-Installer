<?php

namespace PluginInstaller;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Models\Egg;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Throwable;

class PluginInstallerProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        try {
            $eggs = Cache::remember('plugin-installer:egg-options', 60, fn () => Egg::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Egg $egg) => ['value' => $egg->id, 'label' => $egg->name])->all());
        } catch (Throwable) {
            $eggs = [];
        }

        $this->registerSettings(new Settings($this->settings(), [
            ...($eggs === [] ? [] : [
                ExtensionSettingDefinition::make('eggs', 'eggs', [], ['array'])
                    ->label('Show on eggs')
                    ->help('The Plugins tab only appears on servers using these eggs. Leave empty to show it on every server.')
                    ->multiselect($eggs)
                    ->frontend()
                    ->frontendType('array'),
            ]),
            ExtensionSettingDefinition::make('curseforge_key', 'curseforge_key', '', ['nullable', 'string', 'max:255'])
                ->label('CurseForge API key')
                ->help('Optional. Get one for free at console.curseforge.com to show CurseForge plugins.')
                ->secret(),
        ]));
    }
}
