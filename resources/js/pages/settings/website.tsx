import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Page = {
    ulid: string;
    path: string;
    title: string;
    meta_description: string | null;
    noindex: boolean;
    in_menu: boolean;
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
    pages: Page[];
};
type Props = {
    site: Site | null;
    hosts: { host: string; kind: string; verified: boolean }[];
    live_host: string | null;
    cname_target: string;
    templates: { key: string; name: string; description: string }[];
    powered_by: { text: string; url: string } | null;
};

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
}: Props & { site: Site }) {
    const social = (site.info.social ?? {}) as Record<string, string>;
    const form = useForm({
        template: site.template,
        ai_crawlers: site.ai_crawlers,
        info: {
            name: text(site.info.name),
            tagline: text(site.info.tagline),
            about: text(site.info.about),
            phone: text(site.info.phone),
            email: text(site.info.email),
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
        k: 'name' | 'tagline' | 'about' | 'phone' | 'email',
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
                <h2 className="font-semibold">Pages</h2>
                <ul className="grid gap-3">
                    {site.pages.map((p) => (
                        <PageRow key={p.ulid} page={p} />
                    ))}
                </ul>
                <p className="text-xs text-muted-foreground">
                    The page builder, blog and more pages arrive next; the
                    sitemap, llms.txt and structured data update automatically.
                </p>
            </section>

            <section className="grid gap-3 rounded-lg border p-4 text-sm">
                <h2 className="font-semibold">Domains</h2>
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
            const token =
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '';
            const res = await fetch(
                `/settings/website/pages/${page.ulid}/preview`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                },
            );
            const body = (await res.json()) as { url: string | null };
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
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto"
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
