import { http } from '@pterodactyl/sdk';

export type Source = 'modrinth' | 'hangar' | 'spigot' | 'curseforge';

export interface Context {
    sources: Source[];
    versions: string[];
    loaders: string[];
    version: string | null;
    loader: string;
    can: { install: boolean; delete: boolean };
}

export interface Project {
    id: string;
    name: string;
    author: string | null;
    author_url: string | null;
    description: string;
    icon: string | null;
    downloads: number;
    stars: number;
    updated: string | null;
    url: string | null;
    installable: boolean;
}

export interface Installed {
    file: string;
    size: number;
    name: string;
    source: Source | null;
    project: string | null;
    version: string | null;
    icon: string | null;
    url: string | null;
}

export interface Filters {
    source: Source;
    version: string;
    loader: string;
}

export const SOURCES: Record<Source, string> = { modrinth: 'Modrinth', hangar: 'Hangar', spigot: 'SpigotMC', curseforge: 'CurseForge' };

export const LOADERS: Record<string, string> = { paper: 'Paper', purpur: 'Purpur', folia: 'Folia', spigot: 'Spigot', bukkit: 'Bukkit', velocity: 'Velocity', bungeecord: 'BungeeCord' };

const base = (uuid: string) => `/api/client/servers/${uuid}/extensions/plugin-installer`;

const params = (f: Filters) => ({ source: f.source, version: f.version || undefined, loader: f.loader });

export const api = {
    context: (uuid: string) => http.get<Context>(`${base(uuid)}/context`).then((r) => r.data),
    search: (uuid: string, f: Filters, q: string, page: number) =>
        http.get<{ items: Project[]; total: number }>(`${base(uuid)}/search`, { params: { ...params(f), q, page } }).then((r) => r.data),
    versions: (uuid: string, f: Filters, id: string) => http.get<{ id: string; name: string }[]>(`${base(uuid)}/versions`, { params: { ...params(f), id } }).then((r) => r.data),
    install: (uuid: string, f: Filters, id: string, versionId: string) =>
        http.post<{ file: string; replaced: string[] }>(`${base(uuid)}/install`, { ...params(f), id, version_id: versionId }).then((r) => r.data),
    installed: (uuid: string) => http.get<Installed[]>(`${base(uuid)}/installed`).then((r) => r.data),
    remove: (uuid: string, file: string) => http.post(`${base(uuid)}/delete`, { file }),
};

const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 2 });
export const count = (n: number) => compact.format(n);

const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
export const ago = (iso: string | null) => {
    if (!iso) return null;
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of steps) if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    return 'just now';
};
