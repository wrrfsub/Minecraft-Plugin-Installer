import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Input, Label, NamedIcon, Select, ServerContentBlock, Spinner, httpErrorToHuman, toast, useCurrentServerUuid } from '@pterodactyl/sdk';
import { LOADERS, SOURCES, ago, api, count, type Context, type Filters, type Installed, type Project, type Source } from '../api';

const card = 'pli:flex pli:flex-col pli:gap-3 pli:rounded-lg pli:border pli:border-border pli:bg-card pli:p-4';

function Icon({ src, size = 'md' }: { src: string | null; size?: 'md' | 'lg' }) {
    const box = size === 'lg' ? 'pli:size-16' : 'pli:size-14';
    const [broken, setBroken] = useState(false);
    if (src && !broken) return <img src={src} alt={''} onError={() => setBroken(true)} className={`${box} pli:shrink-0 pli:rounded-lg pli:bg-muted pli:object-cover`} />;

    return <span className={`${box} pli:flex pli:shrink-0 pli:items-center pli:justify-center pli:rounded-lg pli:bg-muted pli:text-2xl pli:font-bold pli:text-muted-foreground`}>?</span>;
}

function Title({ name, url }: { name: string; url: string | null }) {
    return (
        <p className={'pli:flex pli:min-w-0 pli:items-center pli:gap-1.5 pli:font-semibold pli:text-foreground'}>
            <span className={'pli:truncate'}>{name}</span>
            {url && (
                <a href={url} target={'_blank'} rel={'noreferrer'} aria-label={'Open plugin page'} className={'pli:shrink-0 pli:text-muted-foreground pli:hover:text-foreground'}>
                    <NamedIcon name={'external-link'} size={14} />
                </a>
            )}
        </p>
    );
}

function Pages({ page, pages, onPage }: { page: number; pages: number; onPage: (page: number) => void }) {
    if (pages <= 1) return null;
    const start = Math.max(1, Math.min(page - 2, pages - 5));
    const list = Array.from({ length: Math.min(6, pages) }, (_, i) => start + i);
    const btn = (on: boolean) =>
        `pli:flex pli:size-9 pli:items-center pli:justify-center pli:rounded-md pli:border pli:text-sm pli:transition-colors pli:disabled:opacity-40 ${on ? 'pli:border-primary pli:bg-primary pli:text-primary-foreground' : 'pli:border-border pli:bg-card pli:text-muted-foreground pli:hover:text-foreground'}`;

    return (
        <div className={'pli:flex pli:flex-wrap pli:items-center pli:justify-center pli:gap-1.5'}>
            {page > 1 && (
                <button type={'button'} aria-label={'Previous page'} className={btn(false)} onClick={() => onPage(page - 1)}>
                    <NamedIcon name={'chevrons-left'} size={15} />
                </button>
            )}
            {list.map((n) => (
                <button key={n} type={'button'} aria-current={n === page ? 'page' : undefined} className={btn(n === page)} onClick={() => onPage(n)}>
                    {n}
                </button>
            ))}
            {page < pages && (
                <button type={'button'} aria-label={'Next page'} className={btn(false)} onClick={() => onPage(page + 1)}>
                    <NamedIcon name={'chevrons-right'} size={15} />
                </button>
            )}
        </div>
    );
}

function DeleteDialog({ uuid, plugin, onClose, onDone }: { uuid: string; plugin: Installed | null; onClose: () => void; onDone: () => void }) {
    const [busy, setBusy] = useState(false);
    const remove = async () => {
        if (!plugin) return;
        setBusy(true);
        try {
            await api.remove(uuid, plugin.file);
            toast.success(`Deleted ${plugin.name}. Restart the server to unload it.`);
            onDone();
        } catch (error) {
            toast.error(httpErrorToHuman(error));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog.Confirm open={plugin !== null} title={'Delete this plugin?'} confirm={'Delete'} pending={busy} onClose={onClose} onConfirmed={remove}>
            {plugin?.file} will be deleted from the plugins folder. Restart the server afterwards.
        </Dialog.Confirm>
    );
}

function VersionDialog({ uuid, filters, project, onClose, onDone }: { uuid: string; filters: Filters; project: Project; onClose: () => void; onDone: () => void }) {
    const versions = useQuery({ queryKey: ['plugin-installer', uuid, 'versions', filters, project.id], queryFn: () => api.versions(uuid, filters, project.id) });
    const [picked, setPicked] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const chosen = picked ?? versions.data?.[0]?.id ?? null;

    const install = async () => {
        if (!chosen) return;
        setBusy(true);
        try {
            const result = await api.install(uuid, filters, project.id, chosen);
            toast.success(result.replaced.length ? `Updated ${project.name}. Restart the server to load it.` : `Installed ${project.name}. Restart the server to load it.`);
            onDone();
        } catch (error) {
            toast.error(httpErrorToHuman(error));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open title={project.name} onClose={() => !busy && onClose()}>
            <div className={'pli:flex pli:flex-col pli:gap-4'}>
                <div className={'pli:flex pli:items-start pli:gap-3'}>
                    <Icon src={project.icon} size={'lg'} />
                    <div className={'pli:min-w-0'}>
                        {project.author && <p className={'pli:text-sm pli:text-muted-foreground'}>By {project.author}</p>}
                        <p className={'pli:mt-1 pli:flex pli:flex-wrap pli:gap-x-3 pli:text-xs pli:text-muted-foreground'}>
                            <span className={'pli:flex pli:items-center pli:gap-1'}>
                                <NamedIcon name={'download'} size={13} /> {count(project.downloads)} downloads
                            </span>
                            {project.stars > 0 && (
                                <span className={'pli:flex pli:items-center pli:gap-1'}>
                                    <NamedIcon name={'star'} size={13} color={'#facc15'} fill={'#facc15'} /> {count(project.stars)}
                                </span>
                            )}
                            {project.updated && (
                                <span className={'pli:flex pli:items-center pli:gap-1'}>
                                    <NamedIcon name={'calendar'} size={13} /> Updated {ago(project.updated)}
                                </span>
                            )}
                        </p>
                    </div>
                </div>
                {project.description && <p className={'pli:text-sm pli:text-muted-foreground'}>{project.description}</p>}
                <div className={'pli:flex pli:flex-col pli:gap-1.5'}>
                    <Label htmlFor={'pli-version'}>Version</Label>
                    {versions.isLoading ? (
                        <Spinner size={'small'} />
                    ) : versions.data?.length ? (
                        <Select id={'pli-version'} value={chosen} options={versions.data.map((v) => ({ value: v.id, label: v.name }))} onChange={(value) => setPicked(String(value))} />
                    ) : (
                        <Alert type={'warning'}>No version for {LOADERS[filters.loader]} {filters.version}. Try another version or loader in the filters.</Alert>
                    )}
                </div>
            </div>
            <Dialog.Footer>
                <Button.Text onClick={onClose} disabled={busy}>
                    Cancel
                </Button.Text>
                <Button onClick={install} isLoading={busy} disabled={!chosen}>
                    Install plugin
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}

function Browse({ uuid, ctx, filters, installed, onInstalled }: { uuid: string; ctx: Context; filters: Filters; installed: Installed[]; onInstalled: () => void }) {
    const [text, setText] = useState('');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [picked, setPicked] = useState<Project | null>(null);
    useEffect(() => {
        const timer = setTimeout(() => setQ(text.trim()), 400);
        return () => clearTimeout(timer);
    }, [text]);
    useEffect(() => setPage(1), [q, filters.source, filters.version, filters.loader]);

    const results = useQuery({ queryKey: ['plugin-installer', uuid, 'search', filters, q, page], queryFn: () => api.search(uuid, filters, q, page), placeholderData: (prev) => prev });
    const pages = Math.max(1, Math.ceil((results.data?.total ?? 0) / 18));
    const [removing, setRemoving] = useState<Installed | null>(null);
    const find = (p: Project) => installed.find((i) => i.source === filters.source && i.project === p.id);

    return (
        <div className={'pli:flex pli:flex-col pli:gap-4'}>
            <div className={'pli:relative'}>
                <NamedIcon name={'search'} size={15} className={'pli:pointer-events-none pli:absolute pli:left-3 pli:top-1/2 pli:-translate-y-1/2 pli:text-muted-foreground'} />
                <Input className={'pli:pl-9'} value={text} placeholder={`Search ${SOURCES[filters.source]} plugins`} onChange={(e) => setText(e.target.value)} />
            </div>
            {results.error ? <Alert type={'danger'}>{httpErrorToHuman(results.error)}</Alert> : null}
            {results.isLoading && <Spinner centered />}
            {results.data && results.data.items.length === 0 && <p className={'pli:py-12 pli:text-center pli:text-sm pli:text-muted-foreground'}>No plugins found.</p>}
            <div className={`pli:grid pli:grid-cols-1 pli:gap-3 pli:md:grid-cols-2 pli:xl:grid-cols-3 ${results.isFetching ? 'pli:opacity-60' : ''}`}>
                {results.data?.items.map((p) => (
                    <div key={p.id} className={card}>
                        <div className={'pli:flex pli:items-center pli:gap-3'}>
                            <Icon src={p.icon} />
                            <div className={'pli:min-w-0'}>
                                <Title name={p.name} url={p.url} />
                                {p.author && <p className={'pli:truncate pli:text-sm pli:text-muted-foreground'}>By {p.author}</p>}
                            </div>
                        </div>
                        <p className={'pli:line-clamp-2 pli:text-sm pli:text-muted-foreground'}>{p.description}</p>
                        {p.updated && (
                            <p className={'pli:flex pli:items-center pli:gap-1.5 pli:text-xs pli:text-muted-foreground'}>
                                <NamedIcon name={'calendar'} size={13} /> Updated {ago(p.updated)}
                            </p>
                        )}
                        <div className={'pli:mt-auto pli:flex pli:items-center pli:justify-between pli:gap-3'}>
                            <span className={'pli:flex pli:items-center pli:gap-3 pli:text-xs pli:text-muted-foreground'}>
                                <span className={'pli:flex pli:items-center pli:gap-1'}>
                                    <NamedIcon name={'download'} size={13} /> {count(p.downloads)}
                                </span>
                                <span className={'pli:flex pli:items-center pli:gap-1'}>
                                    <NamedIcon name={'star'} size={13} color={'#facc15'} fill={'#facc15'} /> {count(p.stars)}
                                </span>
                            </span>
                            {find(p) ? (
                                <Button.Danger size={'small'} disabled={!ctx.can.delete} onClick={() => setRemoving(find(p)!)}>
                                    <span className={'pli:flex pli:items-center pli:gap-1.5'}>
                                        <NamedIcon name={'trash-2'} size={14} /> Delete
                                    </span>
                                </Button.Danger>
                            ) : !p.installable ? (
                                <span className={'pli:text-xs pli:text-muted-foreground'}>Website only</span>
                            ) : (
                                <Button size={'small'} disabled={!ctx.can.install} onClick={() => setPicked(p)}>
                                    Select version
                                </Button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
            <Pages
                page={page}
                pages={pages}
                onPage={(n) => {
                    setPage(n);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }}
            />
            <DeleteDialog
                uuid={uuid}
                plugin={removing}
                onClose={() => setRemoving(null)}
                onDone={() => {
                    setRemoving(null);
                    onInstalled();
                }}
            />
            {picked && (
                <VersionDialog
                    uuid={uuid}
                    filters={filters}
                    project={picked}
                    onClose={() => setPicked(null)}
                    onDone={() => {
                        setPicked(null);
                        onInstalled();
                    }}
                />
            )}
        </div>
    );
}

function InstalledList({ uuid, ctx, plugins, loading, onChange }: { uuid: string; ctx: Context; plugins: Installed[]; loading: boolean; onChange: () => void }) {
    const [removing, setRemoving] = useState<Installed | null>(null);
    const [text, setText] = useState('');
    const shown = plugins.filter((p) => !text.trim() || p.name.toLowerCase().includes(text.trim().toLowerCase()) || p.file.toLowerCase().includes(text.trim().toLowerCase()));

    if (loading) return <Spinner centered />;

    return (
        <div className={'pli:flex pli:flex-col pli:gap-4'}>
            <div className={'pli:relative'}>
                <NamedIcon name={'search'} size={15} className={'pli:pointer-events-none pli:absolute pli:left-3 pli:top-1/2 pli:-translate-y-1/2 pli:text-muted-foreground'} />
                <Input className={'pli:pl-9'} value={text} placeholder={'Search installed plugins'} onChange={(e) => setText(e.target.value)} />
            </div>
            {plugins.length === 0 && <p className={'pli:py-12 pli:text-center pli:text-sm pli:text-muted-foreground'}>No plugins in the plugins folder yet.</p>}
            <div className={'pli:grid pli:grid-cols-1 pli:gap-3 pli:md:grid-cols-2 pli:xl:grid-cols-3'}>
                {shown.map((p) => (
                    <div key={p.file} className={`${card} pli:flex-row pli:items-center`}>
                        <Icon src={p.icon} />
                        <div className={'pli:min-w-0 pli:flex-1'}>
                            <Title name={p.name} url={p.url} />
                            <p className={'pli:truncate pli:text-sm pli:text-muted-foreground'} title={p.file}>
                                {p.source ? `${SOURCES[p.source]}${p.version ? ` · ${p.version}` : ''}` : 'Added manually'}
                            </p>
                        </div>
                        {ctx.can.delete && (
                            <button
                                type={'button'}
                                aria-label={`Delete ${p.name}`}
                                title={'Delete'}
                                onClick={() => setRemoving(p)}
                                className={'pli:flex pli:size-9 pli:shrink-0 pli:items-center pli:justify-center pli:rounded-md pli:bg-destructive pli:text-white pli:transition-opacity pli:hover:opacity-85'}
                            >
                                <NamedIcon name={'trash-2'} size={16} />
                            </button>
                        )}
                    </div>
                ))}
            </div>
            <DeleteDialog
                uuid={uuid}
                plugin={removing}
                onClose={() => setRemoving(null)}
                onDone={() => {
                    setRemoving(null);
                    onChange();
                }}
            />
        </div>
    );
}

export default function Plugins() {
    const uuid = useCurrentServerUuid() ?? '';
    const client = useQueryClient();
    const ctx = useQuery({ queryKey: ['plugin-installer', uuid, 'context'], queryFn: () => api.context(uuid), staleTime: 60_000 });
    const installed = useQuery({ queryKey: ['plugin-installer', uuid, 'installed'], queryFn: () => api.installed(uuid) });
    const [tab, setTab] = useState<'browse' | 'installed'>('browse');
    const [filters, setFilters] = useState<Filters | null>(null);
    const current: Filters | null = filters ?? (ctx.data ? { source: ctx.data.sources[0], version: ctx.data.version ?? '', loader: ctx.data.loader } : null);
    const set = (patch: Partial<Filters>) => current && setFilters({ ...current, ...patch });
    const refresh = () => client.invalidateQueries({ queryKey: ['plugin-installer', uuid, 'installed'] });

    if (ctx.isLoading || !current) {
        return (
            <ServerContentBlock title={'Plugins'}>
                {ctx.error ? <Alert type={'danger'}>{httpErrorToHuman(ctx.error)}</Alert> : <Spinner centered />}
            </ServerContentBlock>
        );
    }

    const tabClass = (on: boolean) =>
        `pli:flex pli:items-center pli:gap-2 pli:border-b-2 pli:px-1 pli:pb-2.5 pli:text-sm pli:font-medium pli:transition-colors ${on ? 'pli:border-primary pli:text-foreground' : 'pli:border-transparent pli:text-muted-foreground pli:hover:text-foreground'}`;

    return (
        <ServerContentBlock title={'Plugins'}>
            <div className={'pli:flex pli:flex-col pli:gap-4'}>
                <div className={'pli:flex pli:flex-col pli:gap-3 pli:border-b pli:border-border pli:lg:flex-row pli:lg:items-end pli:lg:justify-between'}>
                    <div className={'pli:flex pli:gap-6'}>
                        <button type={'button'} className={tabClass(tab === 'browse')} onClick={() => setTab('browse')}>
                            <NamedIcon name={'search'} size={15} /> Browse plugins
                        </button>
                        <button type={'button'} className={tabClass(tab === 'installed')} onClick={() => setTab('installed')}>
                            <NamedIcon name={'puzzle'} size={15} /> Installed plugins
                            <span className={'pli:rounded-md pli:bg-muted pli:px-1.5 pli:text-xs'}>{installed.data?.length ?? 0}</span>
                        </button>
                    </div>
                    {tab === 'browse' && (
                        <div className={'pli:grid pli:grid-cols-1 pli:gap-2 pli:pb-3 pli:sm:grid-cols-3 pli:lg:w-[34rem]'}>
                            <Select value={current.source} options={ctx.data!.sources.map((s) => ({ value: s, label: SOURCES[s] }))} onChange={(v) => set({ source: v as Source })} />
                            <Select
                                value={current.version || 'any'}
                                searchPlaceholder={'Find a version'}
                                options={[{ value: 'any', label: 'Any version' }, ...ctx.data!.versions.map((v) => ({ value: v, label: v }))]}
                                onChange={(v) => set({ version: v === 'any' ? '' : String(v) })}
                            />
                            <Select value={current.loader} options={ctx.data!.loaders.map((l) => ({ value: l, label: LOADERS[l] ?? l }))} onChange={(v) => set({ loader: String(v) })} />
                        </div>
                    )}
                </div>
                {tab === 'browse' ? (
                    <Browse uuid={uuid} ctx={ctx.data!} filters={current} installed={installed.data ?? []} onInstalled={refresh} />
                ) : (
                    <InstalledList uuid={uuid} ctx={ctx.data!} plugins={installed.data ?? []} loading={installed.isLoading} onChange={refresh} />
                )}
            </div>
        </ServerContentBlock>
    );
}
