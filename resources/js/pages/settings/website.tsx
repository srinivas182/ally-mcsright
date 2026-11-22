import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { confirmCredits } from '@/components/credit-consent';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DomainGuide } from '@/components/domain-guide';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/http';

type Page = {
    ulid: string;
    path: string;
    title: string;
    meta_description: string | null;
    noindex: boolean;
    in_menu: boolean;
    layout?: string;
};
type Site = {
    ulid: string;
    template: string;
    status: 'draft' | 'live';
    published_at: string | null;
    info: Record<string, string | Record<string, string>>;
    brand: Record<string, string | null>;
    seo: { title_suffix?: string; description?: string };
    ai_crawlers: boolean;
    signup_after: number;
    pages: Page[];
};
type Props = {
    site: Site | null;
    hosts: { host: string; kind: string; verified: boolean }[];
    live_host: string | null;
    cname_target: string;
    templates: { key: string; name: string; description: string }[];
    powered_by: { text: string; url: string } | null;
    menus: {
        main: MenuItem[];
        footer: MenuItem[];
        custom: boolean;
    } | null;
    redirects: {
        id: number;
        from: string;
        to: string;
        code: number;
        hits: number;
    }[];
    agent_rules: AgentRules;
    calculators: {
        rate: number;
        down_pct: number;
        tax_rate: number;
        insurance: number;
    };
    agent_sites: { ulid: string; name: string; slug: string; status: string }[];
};
type AgentRules = {
    enabled: boolean;
    template: boolean;
    colours: boolean;
    sections: boolean;
    domain: boolean;
    referrals: boolean;
    approval: boolean;
};
type MenuItem = { label: string; url: string };

const opts = { preserveScroll: true };
const text = (v: unknown): string => (typeof v === 'string' ? v : '');

/** Settings → Website: the brokerage's public website (templates, details, SEO and AI visibility, pages, domains). */
export default function WebsiteSettings(props: Props) {
    return (
        <>
            <Head title="Website" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Website"
                    description="Your brokerage website: listings from your MLS, lead forms straight into your CRM, and pages built to be found on Google and quoted by AI assistants."
                />
                {props.site === null ? (
                    <CreateSite templates={props.templates} />
                ) : (
                    <Manage {...props} site={props.site} />
                )}
            </div>
        </>
    );
}

function TemplatePicker({
    templates,
    value,
    onChange,
}: {
    templates: Props['templates'];
    value: string;
    onChange: (k: string) => void;
}) {
    return (
        <div
            className="grid gap-3 sm:grid-cols-2"
            role="radiogroup"
            aria-label="Design"
        >
            {templates.map((t) => (
                <button
                    key={t.key}
                    type="button"
                    role="radio"
                    aria-checked={value === t.key}
                    onClick={() => onChange(t.key)}
                    className={`grid gap-1 rounded-lg border p-4 text-left transition ${value === t.key ? 'border-primary ring-2 ring-primary/30' : 'hover:border-foreground/30'}`}
                >
                    <span
                        aria-hidden
                        className={`h-20 rounded-md ${t.key === 'aurum' ? 'bg-gradient-to-br from-neutral-950 via-neutral-900 to-amber-700' : 'bg-gradient-to-br from-white via-slate-100 to-blue-200'}`}
                    />
                    <span className="font-medium">{t.name}</span>
                    <span className="text-xs text-muted-foreground">
                        {t.description}
                    </span>
                </button>
            ))}
        </div>
    );
}

function CreateSite({ templates }: { templates: Props['templates'] }) {
    const form = useForm({
        template: templates[0]?.key ?? 'modern',
        info: {
            tagline: '',
            phone: '',
            email: '',
            city: '',
            state: '',
            area: '',
        },
    });
    const set = (k: keyof typeof form.data.info, v: string) =>
        form.setData('info', { ...form.data.info, [k]: v });

    return (
        <form
            className="grid gap-4 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/settings/website', opts);
            }}
        >
            <h2 className="font-semibold">Choose a design</h2>
            <TemplatePicker
                templates={templates}
                value={form.data.template}
                onChange={(k) => form.setData('template', k)}
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <Input
                    aria-label="Tagline"
                    placeholder="Tagline, e.g. Boutique real estate in Austin"
                    value={form.data.info.tagline}
                    onChange={(e) => set('tagline', e.target.value)}
                />
                <Input
                    aria-label="Area you serve"
                    placeholder="Area you serve, e.g. Greater Austin"
                    value={form.data.info.area}
                    onChange={(e) => set('area', e.target.value)}
                />
                <Input
                    aria-label="Phone"
                    placeholder="Phone"
                    value={form.data.info.phone}
                    onChange={(e) => set('phone', e.target.value)}
                />
                <Input
                    aria-label="Email"
                    type="email"
                    placeholder="Email"
                    value={form.data.info.email}
                    onChange={(e) => set('email', e.target.value)}
                />
                <Input
                    aria-label="City"
                    placeholder="City"
                    value={form.data.info.city}
                    onChange={(e) => set('city', e.target.value)}
                />
                <Input
                    aria-label="State"
                    placeholder="State (TX)"
                    maxLength={2}
                    value={form.data.info.state}
                    onChange={(e) => set('state', e.target.value.toUpperCase())}
                />
            </div>
            {Object.values(form.errors).map((m) => (
                <p key={m} className="text-destructive">
                    {m}
                </p>
            ))}
            <div>
                <Button disabled={form.processing}>Create my website</Button>
            </div>
        </form>
    );
}

function Manage({
    site,
    hosts,
    live_host,
    cname_target,
    templates,
    powered_by,
    menus,
    redirects,
    agent_rules,
    agent_sites,
    calculators,
}: Props & { site: Site }) {
    const social = (site.info.social ?? {}) as Record<string, string>;
    const form = useForm({
        template: site.template,
        ai_crawlers: site.ai_crawlers,
        settings: { signup_after: site.signup_after },
        info: {
            name: text(site.info.name),
            tagline: text(site.info.tagline),
            about: text(site.info.about),
            phone: text(site.info.phone),
            email: text(site.info.email),
            privacy: text(site.info.privacy),
            social,
        },
        brand: {
            primary: site.brand.primary ?? '#1d4ed8',
            accent: site.brand.accent ?? '#0f172a',
            logo_url: site.brand.logo_url ?? '',
            hero_image: site.brand.hero_image ?? '',
        },
        seo: {
            title_suffix: site.seo.title_suffix ?? '',
            description: site.seo.description ?? '',
        },
    });
    const info = (
        k: 'name' | 'tagline' | 'about' | 'phone' | 'email' | 'privacy',
        v: string,
    ) => form.setData('info', { ...form.data.info, [k]: v });
    const brand = (k: keyof typeof form.data.brand, v: string) =>
        form.setData('brand', { ...form.data.brand, [k]: v });
    const domain = useForm({ host: '' });

    return (
        <>
            <section className="flex flex-wrap items-center gap-3 rounded-lg border p-4 text-sm">
                <Badge
                    variant={site.status === 'live' ? 'default' : 'secondary'}
                >
                    {site.status === 'live' ? 'Live' : 'Draft'}
                </Badge>
                {live_host && (
                    <a
                        className="font-medium underline"
                        href={`https://${live_host}`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {live_host}
                    </a>
                )}
                <span className="text-muted-foreground">
                    {site.status === 'live'
                        ? 'Visitors and search engines can see your site.'
                        : 'Visitors see a coming-soon page until you publish.'}
                </span>
                <Button
                    className="ml-auto"
                    variant={site.status === 'live' ? 'outline' : 'default'}
                    onClick={() =>
                        router.post(
                            '/settings/website/publish',
                            { live: site.status !== 'live' },
                            opts,
                        )
                    }
                >
                    {site.status === 'live' ? 'Take offline' : 'Publish'}
                </Button>
            </section>

            <form
                className="grid gap-4 rounded-lg border p-4 text-sm"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({
                        ...d,
                        brand: {
                            ...d.brand,
                            logo_url: d.brand.logo_url || null,
                            hero_image: d.brand.hero_image || null,
                        },
                    }));
                    form.patch('/settings/website', opts);
                }}
            >
                <h2 className="font-semibold">Design</h2>
                <TemplatePicker
                    templates={templates}
                    value={form.data.template}
                    onChange={(k) => form.setData('template', k)}
                />
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="grid gap-1">
                        Brand colour
                        <Input
                            type="color"
                            value={form.data.brand.primary}
                            onChange={(e) => brand('primary', e.target.value)}
                        />
                    </label>
                    <label className="grid gap-1">
                        Accent colour
                        <Input
                            type="color"
                            value={form.data.brand.accent}
                            onChange={(e) => brand('accent', e.target.value)}
                        />
                    </label>
                    <Input
                        aria-label="Logo link"
                        placeholder="Logo image link (https)"
                        value={form.data.brand.logo_url}
                        onChange={(e) => brand('logo_url', e.target.value)}
                    />
                    <Input
                        aria-label="Hero image link"
                        placeholder="Hero photo link (https)"
                        value={form.data.brand.hero_image}
                        onChange={(e) => brand('hero_image', e.target.value)}
                    />
                </div>

                <h2 className="font-semibold">Details</h2>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Input
                        aria-label="Brokerage name"
                        placeholder="Brokerage name"
                        value={form.data.info.name}
                        onChange={(e) => info('name', e.target.value)}
                    />
                    <Input
                        aria-label="Tagline"
                        placeholder="Tagline"
                        value={form.data.info.tagline}
                        onChange={(e) => info('tagline', e.target.value)}
                    />
                    <Input
                        aria-label="Phone"
                        placeholder="Phone"
                        value={form.data.info.phone}
                        onChange={(e) => info('phone', e.target.value)}
                    />
                    <Input
                        aria-label="Email"
                        type="email"
                        placeholder="Email"
                        value={form.data.info.email}
                        onChange={(e) => info('email', e.target.value)}
                    />
                </div>
                <Textarea
                    aria-label="About"
                    placeholder="About your brokerage"
                    rows={4}
                    value={form.data.info.about}
                    onChange={(e) => info('about', e.target.value)}
                />
                <label className="grid gap-1 text-sm">
                    Privacy policy (optional). Your website has a standard
                    policy at /privacy that matches how it works; paste your own
                    here to replace it. Lead-form ads link to it.
                    <Textarea
                        aria-label="Your own privacy policy"
                        rows={4}
                        value={form.data.info.privacy}
                        onChange={(e) => info('privacy', e.target.value)}
                    />
                </label>

                <h2 className="font-semibold">Search and AI answers</h2>
                <Input
                    aria-label="Site name in titles"
                    placeholder="Site name shown in search results"
                    value={form.data.seo.title_suffix}
                    onChange={(e) =>
                        form.setData('seo', {
                            ...form.data.seo,
                            title_suffix: e.target.value,
                        })
                    }
                />
                <Textarea
                    aria-label="Site description"
                    placeholder="One or two sentences describing your brokerage for Google and AI assistants"
                    rows={2}
                    maxLength={300}
                    value={form.data.seo.description}
                    onChange={(e) =>
                        form.setData('seo', {
                            ...form.data.seo,
                            description: e.target.value,
                        })
                    }
                />
                <label className="flex items-start gap-2">
                    <input
                        type="checkbox"
                        className="mt-1"
                        checked={form.data.ai_crawlers}
                        onChange={(e) =>
                            form.setData('ai_crawlers', e.target.checked)
                        }
                    />
                    <span>
                        Let AI assistants (ChatGPT, Perplexity, Claude, Gemini)
                        read the site, so they can recommend and quote you.
                        <span className="block text-xs text-muted-foreground">
                            Recommended. Turning this off hides you from AI
                            answers; Google search is unaffected.
                        </span>
                    </span>
                </label>
                <h2 className="font-semibold">Visitors</h2>
                <label className="grid gap-1 sm:max-w-sm">
                    Ask visitors to create a free account after viewing this
                    many homes
                    <Input
                        type="number"
                        min={0}
                        max={20}
                        value={form.data.settings.signup_after}
                        onChange={(e) =>
                            form.setData('settings', {
                                signup_after: Number(e.target.value),
                            })
                        }
                    />
                    <span className="text-xs text-muted-foreground">
                        0 turns the prompt off. Visitors can always close it.
                        Accounts become leads in your CRM with everything they
                        viewed.
                    </span>
                </label>

                {Object.values(form.errors).map((m) => (
                    <p key={m} className="text-destructive">
                        {m}
                    </p>
                ))}
                <div>
                    <Button disabled={form.processing}>Save</Button>
                </div>
            </form>

            <section className="grid gap-3 rounded-lg border p-4 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-semibold">Pages</h2>
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="ml-auto"
                    >
                        <Link href="/settings/website/blog">Blog</Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <Link href="/settings/website/areas">Area pages</Link>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <Link href="/settings/website/media">
                            Media library
                        </Link>
                    </Button>
                </div>
                <ul className="grid gap-3">
                    {site.pages.map((p) => (
                        <PageRow key={p.ulid} page={p} />
                    ))}
                </ul>
                <NewPage />
                <AiPage />
                <p className="text-xs text-muted-foreground">
                    The sitemap, llms.txt and structured data update
                    automatically when you publish.
                </p>
            </section>

            {menus && <Menus menus={menus} />}
            <AgentsPanel
                rules={agent_rules}
                sites={agent_sites}
                liveHost={live_host}
            />
            <CalculatorsPanel values={calculators} />
            <RedirectsPanel redirects={redirects} />

            <section className="grid gap-3 rounded-lg border p-4 text-sm">
                <h2 className="font-semibold">Domains</h2>
                <DomainGuide
                    cnameTarget={cname_target}
                    example="www.yourbrokerage.com"
                    connected={hosts.some(
                        (h) => h.kind !== 'platform' && h.verified,
                    )}
                />
                <ul className="grid gap-1">
                    {hosts.map((h) => (
                        <li
                            key={h.host}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span className="font-medium">{h.host}</span>
                            <Badge
                                variant={h.verified ? 'secondary' : 'outline'}
                            >
                                {h.verified
                                    ? h.kind === 'platform'
                                        ? 'Included'
                                        : 'Connected'
                                    : 'Waiting for DNS'}
                            </Badge>
                            {!h.verified && (
                                <span className="text-muted-foreground">
                                    Add a CNAME record for <code>{h.host}</code>{' '}
                                    pointing to <code>{cname_target}</code>.
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        domain.post('/settings/website/domains', {
                            ...opts,
                            onSuccess: () => domain.reset(),
                        });
                    }}
                >
                    <Input
                        aria-label="Your domain"
                        className="max-w-xs"
                        placeholder="www.yourbrokerage.com"
                        value={domain.data.host}
                        onChange={(e) => domain.setData('host', e.target.value)}
                    />
                    <Button variant="outline" disabled={domain.processing}>
                        Connect domain
                    </Button>
                    {domain.errors.host && (
                        <p className="w-full text-destructive">
                            {domain.errors.host}
                        </p>
                    )}
                </form>
            </section>

            {powered_by && (
                <p className="text-xs text-muted-foreground">
                    Your site footer shows “{powered_by.text}”, set by
                    RightAlly.
                </p>
            )}
        </>
    );
}

function PageRow({ page }: { page: Page }) {
    const form = useForm({
        title: page.title,
        meta_description: page.meta_description ?? '',
        noindex: page.noindex,
        in_menu: page.in_menu,
    });
    const [busy, setBusy] = useState(false);
    const preview = async () => {
        setBusy(true);
        try {
            const body = await postJson<{ url: string | null }>(
                `/settings/website/pages/${page.ulid}/preview`,
                {},
            );
            if (body.url) {
                window.open(body.url, '_blank', 'noopener');
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <li className="grid gap-2 rounded-md border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <code className="text-xs text-muted-foreground">
                    {page.path}
                </code>
                <Button asChild size="sm" variant="outline" className="ml-auto">
                    <Link href={`/settings/website/pages/${page.ulid}/edit`}>
                        Edit page
                    </Link>
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={busy}
                    onClick={preview}
                >
                    Preview
                </Button>
            </div>
            <form
                className="grid gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(`/settings/website/pages/${page.ulid}`, opts);
                }}
            >
                <Input
                    aria-label="Page title"
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                />
                <Textarea
                    aria-label="Search description"
                    rows={2}
                    maxLength={300}
                    placeholder="Search description (about 155 characters works best)"
                    value={form.data.meta_description}
                    onChange={(e) =>
                        form.setData('meta_description', e.target.value)
                    }
                />
                <div className="flex flex-wrap items-center gap-4">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.in_menu}
                            onChange={(e) =>
                                form.setData('in_menu', e.target.checked)
                            }
                        />{' '}
                        In menu
                    </label>
                    {page.path !== '/' && (
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.noindex}
                                onChange={(e) =>
                                    form.setData('noindex', e.target.checked)
                                }
                            />{' '}
                            Hide from search engines
                        </label>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={form.processing || !form.isDirty}
                    >
                        Save page
                    </Button>
                </div>
            </form>
        </li>
    );
}

function NewPage() {
    const form = useForm({ title: '', path: '', layout: 'standard' });

    return (
        <form
            className="flex flex-wrap gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/settings/website/pages', opts);
            }}
        >
            <Input
                aria-label="New page title"
                className="max-w-xs"
                placeholder="New page title, e.g. Buyer guide"
                value={form.data.title}
                onChange={(e) => {
                    const title = e.target.value;
                    form.setData({
                        title,
                        path:
                            '/' +
                            title
                                .toLowerCase()
                                .replace(/[^a-z0-9]+/g, '-')
                                .replace(/^-|-$/g, ''),
                    });
                }}
            />
            <Input
                aria-label="New page address"
                className="max-w-xs"
                placeholder="/buyer-guide"
                value={form.data.path}
                onChange={(e) => form.setData('path', e.target.value)}
            />
            <select
                aria-label="Page kind"
                className="rounded-md border bg-background px-2"
                value={form.data.layout}
                onChange={(e) => form.setData('layout', e.target.value)}
            >
                <option value="standard">Page</option>
                <option value="landing">Landing page (no menu, for ads)</option>
            </select>
            <Button
                variant="outline"
                disabled={form.processing || form.data.title === ''}
            >
                Add page
            </Button>
            {Object.values(form.errors).map((m) => (
                <p key={m} className="w-full text-destructive">
                    {m}
                </p>
            ))}
        </form>
    );
}

function MenuEditor({
    label,
    items,
    onChange,
}: {
    label: string;
    items: MenuItem[];
    onChange: (items: MenuItem[]) => void;
}) {
    const move = (i: number, d: number) => {
        const next = [...items];
        const [it] = next.splice(i, 1);
        next.splice(i + d, 0, it);
        onChange(next);
    };

    return (
        <div className="grid gap-2">
            <h3 className="font-medium">{label}</h3>
            {items.map((it, i) => (
                <div key={i} className="flex flex-wrap items-center gap-2">
                    <Input
                        aria-label={`${label} link ${i + 1} label`}
                        className="max-w-[12rem]"
                        value={it.label}
                        onChange={(e) =>
                            onChange(
                                items.map((x, n) =>
                                    n === i
                                        ? { ...x, label: e.target.value }
                                        : x,
                                ),
                            )
                        }
                    />
                    <Input
                        aria-label={`${label} link ${i + 1} address`}
                        className="max-w-xs"
                        value={it.url}
                        onChange={(e) =>
                            onChange(
                                items.map((x, n) =>
                                    n === i ? { ...x, url: e.target.value } : x,
                                ),
                            )
                        }
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        disabled={i === 0}
                        onClick={() => move(i, -1)}
                        aria-label="Move up"
                    >
                        ↑
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        disabled={i === items.length - 1}
                        onClick={() => move(i, 1)}
                        aria-label="Move down"
                    >
                        ↓
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            onChange(items.filter((_, n) => n !== i))
                        }
                        aria-label="Remove link"
                    >
                        ×
                    </Button>
                </div>
            ))}
            {items.length < 10 && (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() =>
                        onChange([...items, { label: '', url: '/' }])
                    }
                >
                    Add link
                </Button>
            )}
        </div>
    );
}

function Menus({ menus }: { menus: NonNullable<Props['menus']> }) {
    const form = useForm({ main: menus.main, footer: menus.footer });

    return (
        <form
            className="grid gap-4 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/settings/website/menus', opts);
            }}
        >
            <h2 className="font-semibold">Menus</h2>
            <p className="text-muted-foreground">
                {menus.custom
                    ? 'Your own menu.'
                    : 'Showing the automatic menu. Change it and save to use your own.'}{' '}
                Links can go to a page (like /homes or /blog) or an https
                address.
            </p>
            <MenuEditor
                label="Main menu"
                items={form.data.main}
                onChange={(items) => form.setData('main', items)}
            />
            <MenuEditor
                label="Footer menu (empty: same as main)"
                items={form.data.footer}
                onChange={(items) => form.setData('footer', items)}
            />
            {Object.values(form.errors).map((m) => (
                <p key={m} className="text-destructive">
                    {m}
                </p>
            ))}
            <div>
                <Button disabled={form.processing}>Save menus</Button>
            </div>
        </form>
    );
}

function RedirectsPanel({ redirects }: { redirects: Props['redirects'] }) {
    const one = useForm({ from: '', to: '', code: '301' });
    const bulk = useForm({ lines: '' });

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">Redirects</h2>
            <p className="text-muted-foreground">
                Moving from another website? Send old addresses to the new pages
                so you keep your Google rankings. Redirects apply only when a
                page is not found.
            </p>
            <form
                className="flex flex-wrap gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    one.post('/settings/website/redirects', {
                        ...opts,
                        onSuccess: () => one.reset(),
                    });
                }}
            >
                <Input
                    aria-label="Old address"
                    className="max-w-xs"
                    placeholder="/old-page.html"
                    value={one.data.from}
                    onChange={(e) => one.setData('from', e.target.value)}
                />
                <Input
                    aria-label="New address"
                    className="max-w-xs"
                    placeholder="/new-page"
                    value={one.data.to}
                    onChange={(e) => one.setData('to', e.target.value)}
                />
                <select
                    aria-label="Redirect type"
                    className="rounded-md border bg-background px-2"
                    value={one.data.code}
                    onChange={(e) => one.setData('code', e.target.value)}
                >
                    <option value="301">Permanent (301)</option>
                    <option value="302">Temporary (302)</option>
                </select>
                <Button variant="outline" disabled={one.processing}>
                    Add redirect
                </Button>
                {Object.values(one.errors).map((m) => (
                    <p key={m} className="w-full text-destructive">
                        {m}
                    </p>
                ))}
            </form>
            <form
                className="grid gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    bulk.post('/settings/website/redirects/import', {
                        ...opts,
                        onSuccess: () => bulk.reset(),
                    });
                }}
            >
                <Textarea
                    aria-label="Paste redirects"
                    rows={3}
                    placeholder={
                        'One per line: old address, then new address\n/listings/123.html /homes\nhttps://oldsite.com/about-us /about'
                    }
                    value={bulk.data.lines}
                    onChange={(e) => bulk.setData('lines', e.target.value)}
                />
                <div>
                    <Button
                        variant="outline"
                        disabled={bulk.processing || bulk.data.lines === ''}
                    >
                        Import
                    </Button>
                </div>
            </form>
            {redirects.length > 0 && (
                <ul className="grid gap-1">
                    {redirects.map((r) => (
                        <li
                            key={r.id}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <code>{r.from}</code> → <code>{r.to}</code>
                            <Badge variant="outline">{r.code}</Badge>
                            <span className="text-xs text-muted-foreground">
                                {r.hits} visits
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                onClick={() =>
                                    router.delete(
                                        `/settings/website/redirects/${r.id}`,
                                        opts,
                                    )
                                }
                            >
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function AgentsPanel({
    rules,
    sites,
    liveHost,
}: {
    rules: AgentRules;
    sites: Props['agent_sites'];
    liveHost: string | null;
}) {
    const form = useForm(rules);
    const box = (key: keyof AgentRules, label: string) => (
        <label className="flex items-center gap-2">
            <input
                type="checkbox"
                checked={form.data[key]}
                onChange={(e) => form.setData(key, e.target.checked)}
            />{' '}
            {label}
        </label>
    );

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">Agent websites and brand kit</h2>
            <p className="text-muted-foreground">
                Each agent can have a page at /agents/their-name with their
                listings and a contact form; their leads go straight to them.
                Your brokerage name, licence and the required notices are always
                shown.
            </p>
            <form
                className="grid gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/settings/website/agents', opts);
                }}
            >
                {box('enabled', 'Agents can have websites')}
                {box('sections', 'Agents can edit their page sections')}
                {box('template', 'Agents can choose their own design')}
                {box('colours', 'Agents can change colours')}
                {box('domain', 'Agents can use their own domain')}
                {box(
                    'referrals',
                    'Agents get a referral link and QR code (leads through it go to them for 30 days)',
                )}
                {box(
                    'approval',
                    'I approve agent websites before they go live',
                )}
                <div>
                    <Button variant="outline" disabled={form.processing}>
                        Save brand kit
                    </Button>
                </div>
            </form>
            {sites.length > 0 && (
                <ul className="grid gap-1">
                    {sites.map((s) => (
                        <li
                            key={s.ulid}
                            className="flex flex-wrap items-center gap-2"
                        >
                            {liveHost ? (
                                <a
                                    className="font-medium underline"
                                    href={`https://${liveHost}/agents/${s.slug}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {s.name}
                                </a>
                            ) : (
                                <span className="font-medium">{s.name}</span>
                            )}
                            <Badge
                                variant={
                                    s.status === 'live'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {s.status === 'live'
                                    ? 'Live'
                                    : s.status === 'review'
                                      ? 'Waiting for your approval'
                                      : 'Draft'}
                            </Badge>
                            {s.status === 'review' && (
                                <Button
                                    size="sm"
                                    className="ml-auto"
                                    onClick={() =>
                                        router.post(
                                            `/settings/website/agents/${s.ulid}/publish`,
                                            { live: true },
                                            opts,
                                        )
                                    }
                                >
                                    Approve and publish
                                </Button>
                            )}
                            {s.status === 'live' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto"
                                    onClick={() =>
                                        router.post(
                                            `/settings/website/agents/${s.ulid}/publish`,
                                            { live: false },
                                            opts,
                                        )
                                    }
                                >
                                    Take offline
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** AW1: the figures the website's calculators and listing payment estimates start from. */
function CalculatorsPanel({ values }: { values: Props['calculators'] }) {
    const form = useForm({
        ...values,
        insurance: Math.round(values.insurance),
    });
    const field = (
        key: 'rate' | 'down_pct' | 'tax_rate' | 'insurance',
        label: string,
        step: string,
    ) => (
        <label className="grid gap-1">
            {label}
            <Input
                type="number"
                step={step}
                min={0}
                value={form.data[key]}
                onChange={(e) => form.setData(key, Number(e.target.value))}
            />
        </label>
    );

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">Calculators</h2>
            <p className="text-muted-foreground">
                Your website has free calculators at /calculators (monthly
                payment, what you can afford, rent or buy). Visitors can change
                every number; these are the figures they start from, and the
                payment estimate on listing pages uses them too.
            </p>
            <form
                className="grid gap-3 sm:grid-cols-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/settings/website/calculators', opts);
                }}
            >
                {field('rate', 'Interest rate (%)', '0.125')}
                {field('down_pct', 'Down payment (%)', '0.5')}
                {field('tax_rate', 'Property tax (% a year)', '0.05')}
                {field('insurance', 'Insurance ($ a month)', '1')}
                <div className="sm:col-span-4">
                    <Button variant="outline" disabled={form.processing}>
                        Save calculator figures
                    </Button>
                </div>
            </form>
        </section>
    );
}

function AiPage() {
    const form = useForm({ topic: '', path: '', tone: 'friendly' });

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                void confirmCredits('ai.site_page').then(
                    (ok) => ok && form.post('/settings/website/ai/page'),
                );
            }}
        >
            <Input
                aria-label="AI page topic"
                className="max-w-sm"
                placeholder="Or describe a page for AI to draft, e.g. Guide to selling in 78704"
                value={form.data.topic}
                onChange={(e) => form.setData('topic', e.target.value)}
            />
            <Button
                variant="outline"
                disabled={form.processing || form.data.topic.trim() === ''}
            >
                {form.processing ? 'Drafting…' : 'Draft with AI'}
            </Button>
            <span className="text-xs text-muted-foreground">
                Uses AI credits. Drafts only.
            </span>
            {Object.values(form.errors).map((m) => (
                <p key={m} className="w-full text-destructive">
                    {m}
                </p>
            ))}
        </form>
    );
}
