import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const steps = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

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
            </main>
        </TooltipProvider>
    );
}
