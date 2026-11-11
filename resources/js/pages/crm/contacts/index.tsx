import { Head, Link, router, useForm } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar } from '@/components/ui/filter-bar';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PhoneInput } from '@/components/ui/number-inputs';
import { PageHeader } from '@/components/ui/page-header';
import { Pagination } from '@/components/ui/pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Row = {
    ulid: string;
    name: string;
    email: string | null;
    phone: string | null;
    origin: 'personal' | 'company';
    source: string;
    lifecycle: string;
    owner: string | null;
};

type ListItem = {
    key: string;
    name: string;
    count: number;
    ready_made: boolean;
    shared: boolean;
    mine: boolean;
};

type Props = {
    contacts: {
        data: Row[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        search: string;
        origin: string;
        lifecycle: string;
        list: string;
        tag: string;
    };
    lists: ListItem[];
    tags: string[];
    lifecycles: string[];
    sources: string[];
    isManager: boolean;
};

const ALL = 'all';
const label = (v: string) => v.replace(/_/g, ' ');

function QuickAdd({
    open,
    onOpenChange,
    sources,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    sources: string[];
}) {
    const [phoneDisplay, setPhoneDisplay] = useState('');
    const form = useForm({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        source: 'manual',
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add a contact</DialogTitle>
                    <DialogDescription>
                        A name plus an email or phone is enough. You can add the
                        rest later.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="quick-add"
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/contacts', {
                            onSuccess: () => {
                                form.reset();
                                setPhoneDisplay('');
                                onOpenChange(false);
                            },
                        });
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="First name"
                            error={form.errors.first_name}
                        >
                            {(c) => (
                                <Input
                                    {...c}
                                    autoFocus
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
                    <FormField label="Email" error={form.errors.email}>
                        {(c) => (
                            <Input
                                {...c}
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField label="Mobile phone" error={form.errors.phone}>
                        {(c) => (
                            <PhoneInput
                                {...c}
                                value={phoneDisplay}
                                onChange={(display) => {
                                    setPhoneDisplay(display);
                                    form.setData('phone', display);
                                }}
                            />
                        )}
                    </FormField>
                    <FormField label="Source" error={form.errors.source}>
                        {(c) => (
                            <Select
                                value={form.data.source}
                                onValueChange={(v) => form.setData('source', v)}
                            >
                                <SelectTrigger {...c}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {sources.map((s) => (
                                        <SelectItem
                                            key={s}
                                            value={s}
                                            className="capitalize"
                                        >
                                            {label(s)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </FormField>
                    {errors.duplicate && (
                        <p role="alert" className="text-sm">
                            <Link
                                href={`/contacts/${errors.duplicate}`}
                                className="text-primary underline"
                            >
                                Open the existing contact
                            </Link>
                        </p>
                    )}
                </form>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="quick-add"
                        disabled={form.processing}
                    >
                        Add contact
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SaveList({
    filters,
    isManager,
}: {
    filters: Props['filters'];
    isManager: boolean;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', shared: false });
    const saved = {
        ...(filters.lifecycle ? { lifecycle: [filters.lifecycle] } : {}),
        ...(filters.origin ? { origin: filters.origin } : {}),
        ...(filters.tag ? { tags: [filters.tag] } : {}),
    };

    return (
        <>
            <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                Save as list
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Save as a smart list</DialogTitle>
                        <DialogDescription>
                            It stays up to date as contacts change.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        id="save-list"
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform((d) => ({ ...d, filters: saved }));
                            form.post('/smart-lists', {
                                onSuccess: () => setOpen(false),
                            });
                        }}
                    >
                        <FormField label="List name" error={form.errors.name}>
                            {(c) => (
                                <Input
                                    {...c}
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        {isManager && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="size-4 rounded border"
                                    checked={form.data.shared}
                                    onChange={(e) =>
                                        form.setData('shared', e.target.checked)
                                    }
                                />
                                Share with the whole brokerage
                            </label>
                        )}
                    </form>
                    <DialogFooter>
                        <Button
                            type="submit"
                            form="save-list"
                            disabled={form.processing}
                        >
                            Save list
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default function ContactsIndex({
    contacts,
    filters,
    lists,
    tags,
    lifecycles,
    sources,
    isManager,
}: Props) {
    const [adding, setAdding] = useState(false);
    const [search, setSearch] = useState(filters.search);

    const apply = (next: Partial<Props['filters']>) => {
        const query = { ...filters, search, ...next };
        router.get(
            '/contacts',
            Object.fromEntries(
                Object.entries(query).filter(([, v]) => v !== '' && v !== ALL),
            ),
            {
                preserveState: true,
                replace: true,
            },
        );
    };
    const active =
        filters.search !== '' ||
        filters.origin !== '' ||
        filters.lifecycle !== '';

    return (
        <>
            <Head title="Contacts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Contacts"
                    description={`${contacts.total} ${isManager ? 'contacts you can see' : 'of your contacts'}`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/contacts/work${filters.list ? `?list=${filters.list}` : ''}`}
                                >
                                    Work this list
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/contacts/board">
                                    Pipeline board
                                </Link>
                            </Button>
                            <Button onClick={() => setAdding(true)}>
                                <PlusIcon aria-hidden="true" /> Add contact
                            </Button>
                        </>
                    }
                />

                <nav
                    aria-label="Lists"
                    className="flex gap-2 overflow-x-auto pb-1"
                >
                    <Button
                        size="sm"
                        variant={filters.list === '' ? 'default' : 'outline'}
                        onClick={() => router.get('/contacts')}
                    >
                        All
                    </Button>
                    {lists.map((l) => (
                        <Button
                            key={l.key}
                            size="sm"
                            variant={
                                filters.list === l.key ? 'default' : 'outline'
                            }
                            onClick={() =>
                                router.get('/contacts', { list: l.key })
                            }
                        >
                            {l.name}
                            <Badge variant="secondary" className="ml-1">
                                {l.count}
                            </Badge>
                        </Button>
                    ))}
                </nav>

                <FilterBar
                    active={active}
                    onClear={() => router.get('/contacts')}
                >
                    <form
                        className="contents"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({});
                        }}
                    >
                        <Input
                            aria-label="Search contacts"
                            placeholder="Name, email or phone"
                            className="max-w-xs"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </form>
                    <Select
                        value={filters.origin || ALL}
                        onValueChange={(origin) => apply({ origin })}
                    >
                        <SelectTrigger
                            aria-label="Filter by origin"
                            className="w-40"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                Personal and company
                            </SelectItem>
                            <SelectItem value="personal">
                                Personal sphere
                            </SelectItem>
                            <SelectItem value="company">Company</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.lifecycle || ALL}
                        onValueChange={(lifecycle) => apply({ lifecycle })}
                    >
                        <SelectTrigger
                            aria-label="Filter by stage"
                            className="w-40"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                All (not archived)
                            </SelectItem>
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
                    {tags.length > 0 && (
                        <Select
                            value={filters.tag || ALL}
                            onValueChange={(tag) => apply({ tag })}
                        >
                            <SelectTrigger
                                aria-label="Filter by tag"
                                className="w-40"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any tag</SelectItem>
                                {tags.map((t) => (
                                    <SelectItem key={t} value={t}>
                                        {t}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    {(filters.lifecycle || filters.origin || filters.tag) &&
                        filters.list === '' && (
                            <SaveList filters={filters} isManager={isManager} />
                        )}
                </FilterBar>

                {contacts.data.length === 0 ? (
                    <EmptyState
                        title={active ? 'No contacts match' : 'No contacts yet'}
                        description={
                            active
                                ? 'Try another search.'
                                : 'Add your first contact, or import a list later.'
                        }
                        action={
                            !active && (
                                <Button onClick={() => setAdding(true)}>
                                    Add contact
                                </Button>
                            )
                        }
                    />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {contacts.data.map((c) => (
                            <li key={c.ulid}>
                                <Link
                                    href={`/contacts/${c.ulid}`}
                                    className="flex flex-wrap items-center justify-between gap-2 p-4 hover:bg-muted/40"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {c.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {[c.email, c.phone]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                                'No contact details'}
                                            {isManager && c.owner
                                                ? ` · ${c.owner}`
                                                : ''}
                                        </span>
                                    </span>
                                    <span className="flex gap-2">
                                        <Badge
                                            variant="outline"
                                            className="capitalize"
                                        >
                                            {label(c.lifecycle)}
                                        </Badge>
                                        {c.origin === 'personal' && (
                                            <Badge variant="secondary">
                                                Personal
                                            </Badge>
                                        )}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination
                    page={contacts.current_page}
                    lastPage={contacts.last_page}
                    total={contacts.total}
                    onPageChange={(page) =>
                        router.get(
                            '/contacts',
                            { ...filters, page },
                            { preserveState: true },
                        )
                    }
                />
            </div>
            <QuickAdd
                open={adding}
                onOpenChange={setAdding}
                sources={sources}
            />
        </>
    );
}

ContactsIndex.layout = {
    breadcrumbs: [{ title: 'Contacts', href: '/contacts' }],
};
