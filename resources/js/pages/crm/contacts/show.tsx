import { Head, Link, router, useForm } from '@inertiajs/react';
import { MailIcon, MessageSquareIcon, PhoneIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { FormSection } from '@/components/ui/form-section';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatUsPhone } from '@/components/ui/number-inputs';

type Contact = {
    ulid: string;
    name: string;
    first_name: string;
    last_name: string;
    company_name: string | null;
    origin: 'personal' | 'company';
    source: string;
    lifecycle: string;
    owner: string | null;
    owner_user_id: number | null;
    emails: { address: string; label: string; primary: boolean }[];
    phones: {
        e164: string;
        label: string;
        primary: boolean;
        can_text: boolean;
    }[];
    address: {
        line1: string | null;
        line2: string | null;
        city: string | null;
        state: string | null;
        zip: string | null;
    };
    birthday: string | null;
    consent: { email: boolean; sms: boolean; do_not_call: boolean };
};

type Props = {
    contact: Contact;
    households: {
        ulid: string;
        name: string;
        members: { ulid: string; name: string }[];
    }[];
    relationships: { relation: string; ulid: string; name: string }[];
    canManage: boolean;
    agents: { id: number; name: string }[];
    lifecycles: string[];
    relations: string[];
};

const label = (v: string) => v.replace(/_/g, ' ');

export default function ContactShow({
    contact,
    households,
    relationships,
    canManage,
    agents,
    lifecycles,
}: Props) {
    const base = `/contacts/${contact.ulid}`;
    const form = useForm({
        first_name: contact.first_name,
        last_name: contact.last_name,
        address_line1: contact.address.line1 ?? '',
        city: contact.address.city ?? '',
        state: contact.address.state ?? '',
        zip: contact.address.zip ?? '',
        birthday: contact.birthday ?? '',
    });

    return (
        <>
            <Head title={contact.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={contact.name}
                    description={
                        <span className="flex flex-wrap items-center gap-2">
                            <Badge
                                variant={
                                    contact.origin === 'personal'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {contact.origin === 'personal'
                                    ? 'Personal sphere'
                                    : 'Company contact'}
                            </Badge>
                            <span className="capitalize">
                                Source: {label(contact.source)}
                            </span>
                            {contact.owner && (
                                <span>· Owner: {contact.owner}</span>
                            )}
                        </span>
                    }
                    actions={
                        canManage && (
                            <Select
                                value={contact.lifecycle}
                                onValueChange={(lifecycle) =>
                                    router.patch(
                                        base,
                                        { lifecycle },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <SelectTrigger
                                    aria-label="Stage"
                                    className="w-44 capitalize"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {lifecycles.map((l) => (
                                        <SelectItem
                                            key={l}
                                            value={l}
                                            className="capitalize"
                                        >
                                            {label(l)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )
                    }
                />

                <div className="flex flex-wrap gap-2">
                    {contact.phones.map((p) => (
                        <span key={p.e164} className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                asChild
                                disabled={contact.consent.do_not_call}
                            >
                                <a href={`tel:${p.e164}`}>
                                    <PhoneIcon aria-hidden="true" />{' '}
                                    {formatUsPhone(p.e164)}
                                </a>
                            </Button>
                            {p.can_text && (
                                <Button variant="outline" size="sm" asChild>
                                    <a href={`sms:${p.e164}`}>
                                        <MessageSquareIcon aria-hidden="true" />{' '}
                                        Text
                                    </a>
                                </Button>
                            )}
                        </span>
                    ))}
                    {contact.emails.map((e) => (
                        <Button
                            key={e.address}
                            variant="outline"
                            size="sm"
                            asChild
                        >
                            <a href={`mailto:${e.address}`}>
                                <MailIcon aria-hidden="true" /> {e.address}
                            </a>
                        </Button>
                    ))}
                    {contact.consent.do_not_call && (
                        <Badge variant="destructive">Do not call</Badge>
                    )}
                    {!contact.consent.sms && contact.phones.length > 0 && (
                        <Badge variant="secondary">
                            No texting consent yet
                        </Badge>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="grid gap-6 lg:col-span-2">
                        <EmptyState
                            title="Timeline"
                            description="Notes, calls, emails, texts and tasks appear here from the next CRM sprint."
                        />
                        {canManage && (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.patch(base, { preserveScroll: true });
                                }}
                                className="grid gap-6"
                            >
                                <FormSection title="Details">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            label="First name"
                                            error={form.errors.first_name}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.first_name}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'first_name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                        <FormField
                                            label="Last name"
                                            error={form.errors.last_name}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.last_name}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'last_name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    </div>
                                    <FormField
                                        label="Street address"
                                        error={form.errors.address_line1}
                                    >
                                        {(c) => (
                                            <Input
                                                {...c}
                                                autoComplete="off"
                                                value={form.data.address_line1}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'address_line1',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <div className="grid gap-4 sm:grid-cols-[2fr_1fr_1fr]">
                                        <FormField
                                            label="City"
                                            error={form.errors.city}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.city}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'city',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                        <FormField
                                            label="State"
                                            error={form.errors.state}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    maxLength={2}
                                                    value={form.data.state}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'state',
                                                            e.target.value.toUpperCase(),
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                        <FormField
                                            label="ZIP"
                                            error={form.errors.zip}
                                        >
                                            {(c) => (
                                                <Input
                                                    {...c}
                                                    value={form.data.zip}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'zip',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                        </FormField>
                                    </div>
                                    <FormField
                                        label="Birthday"
                                        error={form.errors.birthday}
                                    >
                                        {(c) => (
                                            <Input
                                                {...c}
                                                type="date"
                                                value={form.data.birthday}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'birthday',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                    </FormField>
                                    <Button
                                        type="submit"
                                        className="justify-self-start"
                                        disabled={form.processing}
                                    >
                                        Save
                                    </Button>
                                </FormSection>
                            </form>
                        )}
                    </div>

                    <aside className="grid content-start gap-4">
                        {agents.length > 0 && (
                            <div className="grid gap-2 rounded-lg border p-4">
                                <p className="text-sm font-medium">Owner</p>
                                <Select
                                    value={
                                        contact.owner_user_id
                                            ? String(contact.owner_user_id)
                                            : ''
                                    }
                                    onValueChange={(id) =>
                                        router.post(
                                            `${base}/assign`,
                                            { owner_user_id: Number(id) },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <SelectTrigger aria-label="Owner">
                                        <SelectValue placeholder="Unassigned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {agents.map((a) => (
                                            <SelectItem
                                                key={a.id}
                                                value={String(a.id)}
                                            >
                                                {a.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="grid gap-2 rounded-lg border p-4">
                            <p className="text-sm font-medium">
                                Household and relationships
                            </p>
                            {households.length === 0 &&
                                relationships.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        None yet.
                                    </p>
                                )}
                            {households.map((h) => (
                                <p key={h.ulid} className="text-sm">
                                    {h.name}:{' '}
                                    {h.members.map((m) => m.name).join(', ')}
                                </p>
                            ))}
                            {relationships.map((r) => (
                                <p
                                    key={r.ulid + r.relation}
                                    className="text-sm"
                                >
                                    <span className="capitalize">
                                        {r.relation}
                                    </span>
                                    :{' '}
                                    <Link
                                        href={`/contacts/${r.ulid}`}
                                        className="text-primary hover:underline"
                                    >
                                        {r.name}
                                    </Link>
                                </p>
                            ))}
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

ContactShow.layout = {
    breadcrumbs: [{ title: 'Contacts', href: '/contacts' }],
};
