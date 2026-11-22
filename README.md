# Minecraft Plugin Installer

A free Pterodactyl v2 extension. Users find and install Minecraft plugins from Modrinth, Hangar, SpigotMC and CurseForge right from the panel.

![Plugin Installer](images/01-hero.png)
![Knows your server](images/02-detect.png)
![Pick a version and install](images/03-install.png)
![Every plugin in one list](images/04-installed.png)

## Install

Upload the `.pteroext` in the panel under Extensions, or run:

```
php artisan p:extension:install plugin-installer.pteroext --enable --replace
```

## Set up

- Pick which eggs show the Plugins page in the extension settings.
- Add a CurseForge API key there to turn on CurseForge.

![A free s4way product](images/05-footer.png)
