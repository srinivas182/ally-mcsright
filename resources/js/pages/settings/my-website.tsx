import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { MediaPicker, type MediaItem } from '@/components/sites/media-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/http';

type Rules = {
    enabled: boolean;
    template: boolean;
    colours: boolean;
    sections: boolean;
    domain: boolean;
};
type Site = {
    ulid: string;
    status: 'draft' | 'live';
    template: string;
    brand: Record<string, string | null>;
    info: Record<string, string | null>;
    url: string | null;
    pages: { ulid: string; path: string; title: string; has_draft: boolean }[];
    domains: { host: string; verified: boolean }[];
    credentials: {
        licence: { state: string | null; number: string } | null;
        mls_ids: string[];
    };
};
type Props = {
    available: boolean;
    brokerage_live: boolean;
    rules: Rules;
    site: Site | null;
    templates: { key: string; name: string }[];
    media: MediaItem[];
    cname_target: string;
};

const opts = { preserveScroll: true };
const str = (v: unknown) => (typeof v === 'string' ? v : '');

/** Settings → My website: the agent's own website on the brokerage's address, within the broker's brand kit. */
export default function MyWebsite({
    available,
    brokerage_live,
    rules,
    site,
    templates,
    media,
    cname_target,
}: Props) {
    return (
        <>
            <Head title="My website" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="My website"
                    description="Your own page on the brokerage website, with your listings, your story and a contact form. Leads from it come straight to you, with what they looked at."
                />
                {!available ? (
                    <p className="text-sm text-muted-foreground">
                        Your brokerage has not turned on agent websites yet.
                    </p>
                ) : site === null ? (
                    <div className="grid gap-3 rounded-lg border p-4 text-sm">
                        <p>
                            Create your website from your profile. You can
                            change everything before it goes live.
                        </p>
                        <div>
                            <Button
                                onClick={() =>
                                    router.post(
                                        '/settings/my-website',
                                        {},
                                        opts,
                                    )
                                }
                            >
                                Create my website
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Manage
                        site={site}
                        rules={rules}
                        templates={templates}
                        media={media}
                        brokerageLive={brokerage_live}
                        cnameTarget={cname_target}
                    />
                )}
            </div>
        </>
    );
}

function Manage({
    site,
    rules,
    templates,
    media: initialMedia,
    brokerageLive,
    cnameTarget,
}: {
    site: Site;
    rules: Rules;
    templates: Props['templates'];
    media: MediaItem[];
    brokerageLive: boolean;
    cnameTarget: string;
}) {
    const [media, setMedia] = useState(initialMedia);
    const form = useForm({
        name: str(site.info.name),
        tagline: str(site.info.tagline),
        bio: str(site.info.bio),
        phone: str(site.info.phone),
        email: str(site.info.email),
        areas: str(site.info.areas),
        languages: str(site.info.languages),
        photo: (site.info.photo as string | null) ?? null,
        template: site.template,
        brand: {
            primary: str(site.brand.primary) || '#1d4ed8',
            accent: str(site.brand.accent) || '#0f172a',
        },
    });
    const domain = useForm({ host: '' });
    const [bioBusy, setBioBusy] = useState(false);
    const [bioError, setBioError] = useState<string | null>(null);
    const writeBio = async () => {
        setBioBusy(true);
        setBioError(null);
        try {
            const res = await postJson<{ result: string; warnings: string[] }>(
                '/settings/website/ai/assist',
                {
                    action: 'bio',
                    site: site.ulid,
                    profile: {
                        name: form.data.name,
                        tagline: form.data.tagline,
                        areas: form.data.areas,
                        languages: form.data.languages,
                        bio: form.data.bio,
                    },
                },
            );
            if (res.result) {
                form.setData('bio', res.result);
            }
        } catch (e) {
            setBioError(
                e instanceof Error
                    ? e.message
                    : 'AI help is not available right now.',
            );
        } finally {
            setBioBusy(false);
        }
    };

    return (
        <>
            <section className="flex flex-wrap items-center gap-3 rounded-lg border p-4 text-sm">
                <Badge
                    variant={site.status === 'live' ? 'default' : 'secondary'}
                >
                    {site.status === 'live' ? 'Live' : 'Draft'}
                </Badge>
                {site.url && (
                    <a
                        className="font-medium underline"
                        href={site.url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        {site.url.replace('https://', '')}
                    </a>
                )}
                {!brokerageLive && (
                    <span className="text-muted-foreground">
                        Visitors see it once the brokerage website is live.
                    </span>
                )}
                <Button
                    className="ml-auto"
                    variant={site.status === 'live' ? 'outline' : 'default'}
                    onClick={() =>
                        router.post(
                            '/settings/my-website/publish',
                            { live: site.status !== 'live' },
                            opts,
                        )
                    }
                >
                    {site.status === 'live' ? 'Take offline' : 'Publish'}
                </Button>
            </section>

            <form
                className="grid gap-3 rounded-lg border p-4 text-sm"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch('/settings/my-website', opts);
                }}
            >
                <h2 className="font-semibold">About you</h2>
                <MediaPicker
                    label="Your photo"
                    value={form.data.photo}
                    media={media}
                    site={site.ulid}
                    onChange={(v) => form.setData('photo', v)}
                    onUploaded={(m) => setMedia([m, ...media])}
                />
                <div className="grid gap-3 sm:grid-cols-2">
                    <Input
                        aria-label="Name"
                        placeholder="Name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <Input
                        aria-label="Tagline"
                        placeholder="Tagline, e.g. Helping first-time buyers in South Austin"
                        value={form.data.tagline}
                        onChange={(e) =>
                            form.setData('tagline', e.target.value)
                        }
                    />
                    <Input
                        aria-label="Phone"
                        placeholder="Phone"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                    <Input
                        aria-label="Email"
                        type="email"
                        placeholder="Email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    <Input
                        aria-label="Areas you serve"
                        placeholder="Areas you serve, separated by commas"
                        value={form.data.areas}
                        onChange={(e) => form.setData('areas', e.target.value)}
                    />
                    <Input
                        aria-label="Languages"
                        placeholder="Languages, separated by commas"
                        value={form.data.languages}
                        onChange={(e) =>
                            form.setData('languages', e.target.value)
                        }
                    />
                </div>
                <Textarea
                    aria-label="Your story"
                    rows={5}
                    placeholder="Your story: who you help, where, and how"
                    value={form.data.bio}
                    onChange={(e) => form.setData('bio', e.target.value)}
                />
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={bioBusy}
                        onClick={writeBio}
                    >
                        {bioBusy ? 'Writing…' : 'Write my story with AI'}
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        Uses AI credits; from your profile above.
                    </span>
                </div>
                {bioError && (
                    <p className="text-xs text-destructive">{bioError}</p>
                )}
                {(rules.template || rules.colours) && (
                    <div className="flex flex-wrap items-end gap-3">
                        {rules.template && (
                            <label className="grid gap-1">
                                Design
                                <select
                                    className="rounded-md border bg-background px-2 py-1.5"
                                    value={form.data.template}
                                    onChange={(e) =>
                                        form.setData('template', e.target.value)
                                    }
                                >
                                    {templates.map((t) => (
                                        <option key={t.key} value={t.key}>
                                            {t.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {rules.colours && (
                            <>
                                <label className="grid gap-1">
                                    Colour
                                    <Input
                                        type="color"
                                        value={form.data.brand.primary}
                                        onChange={(e) =>
                                            form.setData('brand', {
                                                ...form.data.brand,
                                                primary: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Accent
                                    <Input
                                        type="color"
                                        value={form.data.brand.accent}
                                        onChange={(e) =>
                                            form.setData('brand', {
                                                ...form.data.brand,
                                                accent: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                            </>
                        )}
                    </div>
                )}
                <p className="text-xs text-muted-foreground">
                    Your brokerage&apos;s name, licence and required notices
                    always appear on your website.
                    {site.credentials.licence
                        ? ` Your licence (${site.credentials.licence.state ?? ''} ${site.credentials.licence.number}) is shown from your roster profile.`
                        : ' Add your licence in your roster profile to show it.'}
                    {site.credentials.mls_ids.length > 0
                        ? ' Your listings come from MLS ID ' +
                          site.credentials.mls_ids.join(', ') +
                          '.'
                        : ' Add your MLS ID in your roster profile to show your listings.'}
                </p>
                {Object.values(form.errors).map((m) => (
                    <p key={m} className="text-destructive">
                        {m}
                    </p>
                ))}
                <div>
                    <Button disabled={form.processing}>Save</Button>
                </div>
            </form>

            <section className="grid gap-2 rounded-lg border p-4 text-sm">
                <h2 className="font-semibold">Pages</h2>
                {site.pages.map((p) => (
                    <div
                        key={p.ulid}
                        className="flex flex-wrap items-center gap-2"
                    >
                        <span>{p.title}</span>
                        <code className="text-xs text-muted-foreground">
                            {p.path}
                        </code>
                        {p.has_draft && (
                            <Badge variant="outline">Unpublished changes</Badge>
                        )}
                        {rules.sections && (
                            <Button
                                asChild
                                size="sm"
                                variant="outline"
                                className="ml-auto"
                            >
                                <Link
                                    href={`/settings/website/pages/${p.ulid}/edit`}
                                >
                                    Edit page
                                </Link>
                            </Button>
                        )}
                    </div>
                ))}
                {!rules.sections && (
                    <p className="text-xs text-muted-foreground">
                        Your brokerage manages the layout of agent pages; your
                        profile above fills them in.
                    </p>
                )}
            </section>

            {rules.domain && (
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Your own domain</h2>
                    {site.domains.map((d) => (
                        <p key={d.host}>
                            <span className="font-medium">{d.host}</span>{' '}
                            <Badge
                                variant={d.verified ? 'secondary' : 'outline'}
                            >
                                {d.verified ? 'Connected' : 'Waiting for DNS'}
                            </Badge>{' '}
                            {!d.verified && (
                                <span className="text-muted-foreground">
                                    Add a CNAME record for <code>{d.host}</code>{' '}
                                    pointing to <code>{cnameTarget}</code>.
                                </span>
                            )}
                        </p>
                    ))}
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            domain.post('/settings/my-website/domains', {
                                ...opts,
                                onSuccess: () => domain.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Your domain"
                            className="max-w-xs"
                            placeholder="www.janedoehomes.com"
                            value={domain.data.host}
                            onChange={(e) =>
                                domain.setData('host', e.target.value)
                            }
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
            )}
        </>
    );
}
