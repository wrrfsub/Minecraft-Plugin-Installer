<?php

namespace PluginInstaller;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class Settings extends ExtensionSettingsDefinition
{
    public function update(array $input): void
    {
        parent::update($input);

        if (array_key_exists('eggs', $input)) {
            $tag = Tag::query()->firstOrCreate(['slug' => 'plugin-installer'], ['name' => 'Plugin Installer']);
            $ids = array_map('intval', (array) $this->get('eggs'));
            foreach (Egg::query()->get() as $egg) {
                in_array($egg->id, $ids, true) ? $egg->tags()->syncWithoutDetaching([$tag->id]) : $egg->tags()->detach($tag->id);
            }
        }
    }
}
