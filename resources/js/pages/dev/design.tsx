import { Head } from '@inertiajs/react';
import { useState } from 'react';

import type { ColumnDef } from '@tanstack/react-table';
import { InboxIcon } from 'lucide-react';
import { toast } from 'sonner';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { DataTable } from '@/components/ui/data-table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/ui/pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { StatCard } from '@/components/ui/stat-card';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const steps = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

type SampleAgent = { id: string; name: string; office: string; deals: number };

const sampleAgents: SampleAgent[] = [
    { id: '1', name: 'Sarah Lee', office: 'Jackson', deals: 18 },
    { id: '2', name: 'Bob Jones', office: 'Ridgeland', deals: 31 },
    { id: '3', name: 'Ann Kim', office: 'Jackson', deals: 7 },
];

const agentColumns: ColumnDef<SampleAgent>[] = [
    { accessorKey: 'name', header: 'Agent' },
    { accessorKey: 'office', header: 'Office' },
    { accessorKey: 'deals', header: 'Closed deals' },
];

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="grid gap-4 rounded-lg border bg-card p-6 shadow-xs">
            <h2 className="text-lg font-semibold">{title}</h2>
            {children}
        </section>
    );
}

/**
 * Interim design gallery (local development only) so designers can review tokens and
 * components in light and dark mode until Storybook is added.
 */
export default function DesignGallery() {
    const [dark, setDark] = useState(false);
    const [agent, setAgent] = useState<string | null>(null);
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState<string[]>([]);

    function toggleDark(value: boolean) {
        setDark(value);
        document.documentElement.classList.toggle('dark', value);
    }

    return (
        <TooltipProvider>
            <Head title="Design gallery" />
            <main className="mx-auto grid max-w-5xl gap-6 p-6">
                <header className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            RightAlly design gallery
                        </h1>
                        <p className="text-muted-foreground">
                            Tokens from resources/design/tokens.json
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Label htmlFor="dark-mode">Dark mode</Label>
                        <Switch
                            id="dark-mode"
                            checked={dark}
                            onCheckedChange={toggleDark}
                        />
                    </div>
                </header>

                <Section title="Colour scales">
                    {['brand', 'neutral'].map((name) => (
                        <div key={name} className="grid gap-1">
                            <span className="text-sm font-medium capitalize">
                                {name}
                            </span>
                            <div className="grid grid-cols-11 gap-1">
                                {steps.map((step) => (
                                    <div key={step} className="grid gap-1">
                                        <div
                                            className="h-10 rounded-md border"
                                            style={{
                                                background: `var(--${name}-${step})`,
                                            }}
                                        />
                                        <span className="text-xs text-muted-foreground">
                                            {step}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </Section>

                <Section title="Buttons">
                    <div className="flex flex-wrap items-center gap-3">
                        <Button>Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="outline">Outline</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="destructive">Delete</Button>
                        <Button variant="link">Link</Button>
                        <Button disabled>
                            <Spinner /> Saving
                        </Button>
                        <Button size="sm">Small</Button>
                        <Button size="lg">Large</Button>
                    </div>
                </Section>

                <Section title="Form controls">
                    <div className="grid gap-6 md:grid-cols-2">
                        <FormField
                            label="Licence number"
                            help="As shown on your state licence"
                            required
                        >
                            {(control) => (
                                <Input
                                    placeholder="e.g. S-123456"
                                    {...control}
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Email"
                            error="Enter a valid email address"
                        >
                            {(control) => (
                                <Input
                                    type="email"
                                    defaultValue="jane@"
                                    {...control}
                                />
                            )}
                        </FormField>
                        <FormField label="Office">
                            {(control) => (
                                <Select>
                                    <SelectTrigger {...control}>
                                        <SelectValue placeholder="Choose an office" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="jackson">
                                            Jackson
                                        </SelectItem>
                                        <SelectItem value="ridgeland">
                                            Ridgeland
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                        <FormField label="Notes">
                            {(control) => (
                                <Textarea
                                    placeholder="Anything we should know?"
                                    {...control}
                                />
                            )}
                        </FormField>
                    </div>
                    <div className="flex flex-wrap items-center gap-6">
                        <div className="flex items-center gap-2">
                            <Checkbox id="terms" defaultChecked />
                            <Label htmlFor="terms">Accept terms</Label>
                        </div>
                        <RadioGroup
                            defaultValue="monthly"
                            className="flex gap-4"
                        >
                            <div className="flex items-center gap-2">
                                <RadioGroupItem value="monthly" id="monthly" />
                                <Label htmlFor="monthly">Monthly</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem value="annual" id="annual" />
                                <Label htmlFor="annual">Annual</Label>
                            </div>
                        </RadioGroup>
                        <div className="flex items-center gap-2">
                            <Switch id="notify" defaultChecked />
                            <Label htmlFor="notify">Email notifications</Label>
                        </div>
                    </div>
                </Section>

                <Section title="Status and identity">
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge>Active</Badge>
                        <Badge variant="secondary">Pending</Badge>
                        <Badge variant="outline">Draft</Badge>
                        <Badge variant="destructive">Overdue</Badge>
                        <Avatar>
                            <AvatarFallback>JS</AvatarFallback>
                        </Avatar>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="outline">Hover me</Button>
                            </TooltipTrigger>
                            <TooltipContent>Helpful tooltip</TooltipContent>
                        </Tooltip>
                    </div>
                </Section>
                <Section title="Key numbers">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="GCI this month"
                            value="$184,200"
                            change={12.4}
                            comparison="vs last month"
                        />
                        <StatCard
                            label="Active agents"
                            value="142"
                            change={0}
                        />
                        <StatCard
                            label="Avg. days to close"
                            value="31"
                            change={-6}
                            higherIsBetter={false}
                        />
                        <StatCard
                            label="Leads uncontacted"
                            value="9"
                            change={18}
                            higherIsBetter={false}
                        />
                    </div>
                </Section>

                <Section title="Data table">
                    <DataTable
                        columns={agentColumns}
                        data={sampleAgents}
                        caption="Agents"
                        getRowId={(row) => row.id}
                        onSelectionChange={setSelected}
                    />
                    <p className="text-sm text-muted-foreground">
                        Selected:{' '}
                        {selected.length === 0 ? 'none' : selected.join(', ')}.
                        Resize below 768 px to see rows become cards.
                    </p>
                    <Pagination
                        page={page}
                        lastPage={4}
                        total={96}
                        onPageChange={setPage}
                    />
                </Section>

                <Section title="Navigation and disclosure">
                    <Tabs defaultValue="overview">
                        <TabsList>
                            <TabsTrigger value="overview">Overview</TabsTrigger>
                            <TabsTrigger value="deals">Deals</TabsTrigger>
                            <TabsTrigger value="documents">
                                Documents
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent value="overview">
                            Overview content
                        </TabsContent>
                        <TabsContent value="deals">Deals content</TabsContent>
                        <TabsContent value="documents">
                            Documents content
                        </TabsContent>
                    </Tabs>
                    <Accordion type="single" collapsible className="max-w-xl">
                        <AccordionItem value="cap">
                            <AccordionTrigger>What is a cap?</AccordionTrigger>
                            <AccordionContent>
                                The most company dollar an agent pays in a cap
                                year.
                            </AccordionContent>
                        </AccordionItem>
                        <AccordionItem value="share">
                            <AccordionTrigger>
                                How is revenue share paid?
                            </AccordionTrigger>
                            <AccordionContent>
                                Monthly, by level, from company dollar.
                            </AccordionContent>
                        </AccordionItem>
                    </Accordion>
                </Section>

                <Section title="Overlays">
                    <div className="flex flex-wrap items-center gap-3">
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="outline">Dialog</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>
                                        Approve commission
                                    </DialogTitle>
                                    <DialogDescription>
                                        Dialogs trap focus and close with
                                        Escape.
                                    </DialogDescription>
                                </DialogHeader>
                            </DialogContent>
                        </Dialog>
                        <Sheet>
                            <SheetTrigger asChild>
                                <Button variant="outline">Drawer</Button>
                            </SheetTrigger>
                            <SheetContent>
                                <SheetHeader>
                                    <SheetTitle>Deal details</SheetTitle>
                                    <SheetDescription>
                                        On phones this slides in as a
                                        full-height sheet.
                                    </SheetDescription>
                                </SheetHeader>
                            </SheetContent>
                        </Sheet>
                        <Popover>
                            <PopoverTrigger asChild>
                                <Button variant="outline">Popover</Button>
                            </PopoverTrigger>
                            <PopoverContent>
                                Quick details without leaving the page.
                            </PopoverContent>
                        </Popover>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">Menu</Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent>
                                <DropdownMenuLabel>Deal</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem>Edit</DropdownMenuItem>
                                <DropdownMenuItem>Duplicate</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button
                            variant="outline"
                            onClick={() =>
                                toast.success('Commission approved.')
                            }
                        >
                            Toast
                        </Button>
                    </div>
                    <div className="max-w-sm">
                        <FormField
                            label="Agent"
                            help="Searchable for long lists"
                        >
                            {(control) => (
                                <Combobox
                                    {...control}
                                    value={agent}
                                    onChange={setAgent}
                                    placeholder="Choose an agent"
                                    options={sampleAgents.map((a) => ({
                                        value: a.id,
                                        label: a.name,
                                        hint: a.office,
                                    }))}
                                />
                            )}
                        </FormField>
                    </div>
                </Section>

                <Section title="Feedback and loading">
                    <Alert>
                        <AlertTitle>Licence renews in 30 days</AlertTitle>
                        <AlertDescription>
                            Upload the renewal before 14 December to keep
                            listing.
                        </AlertDescription>
                    </Alert>
                    <Alert variant="destructive">
                        <AlertTitle>Payment failed</AlertTitle>
                        <AlertDescription>
                            We will retry on 3 December.
                        </AlertDescription>
                    </Alert>
                    <Progress value={65} aria-label="Onboarding progress" />
                    <div className="grid gap-2">
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-4 w-2/3" />
                    </div>
                    <EmptyState
                        icon={<InboxIcon />}
                        title="No leads yet"
                        description="Connect a lead source or import a list to get started."
                        action={<Button>Import leads</Button>}
                    />
                </Section>
            </main>
        </TooltipProvider>
    );
}
