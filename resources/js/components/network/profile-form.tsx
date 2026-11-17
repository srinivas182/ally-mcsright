import { useForm } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type MyProfile = {
    areas: string[];
    sides_key: string;
    languages: string[];
    state: string;
    licence: string | null;
    bio: string | null;
    phone_raw: string | null;
    share_phone: boolean;
    public: boolean;
    status: string;
    code: string;
    public_url: string;
    tips: string[];
};

/** The agent's own network profile: where they work, which side, languages, and what others may see. */
export function ProfileForm({
    profile,
    sides,
}: {
    profile: MyProfile | null;
    sides: Record<string, string>;
}) {
    const form = useForm({
        areas: (profile?.areas ?? []).join('\n'),
        sides: profile?.sides_key ?? 'both',
        languages: (profile?.languages ?? ['English']).join(', '),
        state_code: profile?.state ?? '',
        licence: profile?.licence ?? '',
        bio: profile?.bio ?? '',
        phone: profile?.phone_raw ?? '',
        share_phone: profile?.share_phone ?? false,
        public: profile?.public ?? false,
        paused: profile?.status === 'paused',
    });

    return (
        <form
            className="grid max-w-2xl gap-4 text-base"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    areas: d.areas
                        .split('\n')
                        .map((a) => a.trim())
                        .filter(Boolean),
                    languages: d.languages
                        .split(',')
                        .map((l) => l.trim())
                        .filter(Boolean),
                }));
                form.post('/network/profile', { preserveScroll: true });
            }}
        >
            <label className="grid gap-1">
                Where you work: one city ("Austin, TX") or ZIP code per line
                <textarea
                    className="min-h-28 rounded-md border bg-transparent p-2"
                    value={form.data.areas}
                    onChange={(e) => form.setData('areas', e.target.value)}
                />
            </label>
            <InputError message={form.errors.areas} />
            <fieldset className="flex flex-wrap gap-4">
                <legend className="mb-1">You help</legend>
                {Object.entries(sides).map(([k, label]) => (
                    <label key={k} className="flex items-center gap-2">
                        <input
                            type="radio"
                            name="sides"
                            checked={form.data.sides === k}
                            onChange={() => form.setData('sides', k)}
                        />
                        {label}
                    </label>
                ))}
            </fieldset>
            <div className="grid gap-3 sm:grid-cols-3">
                <label className="grid gap-1">
                    Languages (commas)
                    <Input
                        value={form.data.languages}
                        onChange={(e) =>
                            form.setData('languages', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Licence state
                    <Input
                        maxLength={2}
                        value={form.data.state_code}
                        onChange={(e) =>
                            form.setData(
                                'state_code',
                                e.target.value.toUpperCase(),
                            )
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Licence number
                    <Input
                        value={form.data.licence}
                        onChange={(e) =>
                            form.setData('licence', e.target.value)
                        }
                    />
                </label>
            </div>
            <InputError message={form.errors.state_code} />
            <label className="grid gap-1">
                A few words about you (optional)
                <textarea
                    className="min-h-20 rounded-md border bg-transparent p-2"
                    maxLength={500}
                    value={form.data.bio}
                    onChange={(e) => form.setData('bio', e.target.value)}
                />
            </label>
            <label className="grid max-w-xs gap-1">
                Phone
                <Input
                    type="tel"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                />
            </label>
            <InputError message={form.errors.phone} />
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={form.data.share_phone}
                    onChange={(e) =>
                        form.setData('share_phone', e.target.checked)
                    }
                />
                Let other agents call me (shows a call button)
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={form.data.public}
                    onChange={(e) => form.setData('public', e.target.checked)}
                />
                Give me a public page that search engines can find
            </label>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={form.data.paused}
                    onChange={(e) => form.setData('paused', e.target.checked)}
                />
                Pause: I'm not taking referrals right now
            </label>
            <InputError
                message={(form.errors as Record<string, string>).network}
            />
            {profile?.public && (
                <p className="text-sm">
                    Your public page:{' '}
                    <a
                        className="underline"
                        href={profile.public_url}
                        target="_blank"
                        rel="noopener"
                    >
                        {profile.public_url}
                    </a>
                </p>
            )}
            <div>
                <Button type="submit" size="lg" disabled={form.processing}>
                    Save my profile
                </Button>
            </div>
        </form>
    );
}
