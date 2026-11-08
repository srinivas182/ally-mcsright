import { fireEvent, render, screen } from '@testing-library/react';
import type { ColumnDef } from '@tanstack/react-table';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Combobox } from '@/components/ui/combobox';
import { DataTable } from '@/components/ui/data-table';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Pagination } from '@/components/ui/pagination';
import { Progress } from '@/components/ui/progress';
import { StatCard } from '@/components/ui/stat-card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { expectNoA11yViolations } from '@/test/axe';

type Agent = { id: string; name: string; deals: number };
const agents: Agent[] = [
    { id: 'a', name: 'Sarah Lee', deals: 12 },
    { id: 'b', name: 'Bob Jones', deals: 30 },
    { id: 'c', name: 'Ann Kim', deals: 7 },
];
const columns: ColumnDef<Agent>[] = [
    { accessorKey: 'name', header: 'Agent' },
    { accessorKey: 'deals', header: 'Deals' },
];

function desktopRows(): string[] {
    return screen
        .getAllByRole('row')
        .slice(1)
        .map((row) => row.querySelector('td')?.textContent ?? '');
}

describe('DataTable', () => {
    it('sorts numbers highest first, then lowest, and announces the order', async () => {
        const { container } = render(
            <DataTable
                columns={columns}
                data={agents}
                caption="Agents"
                getRowId={(a) => a.id}
            />,
        );

        const sortButton = screen.getByRole('button', { name: /Deals/ });
        const header = screen.getByRole('columnheader', { name: /Deals/ });

        fireEvent.click(sortButton);
        expect(header.getAttribute('aria-sort')).toBe('descending');
        expect(desktopRows()).toEqual(['Bob Jones', 'Sarah Lee', 'Ann Kim']);

        fireEvent.click(sortButton);
        expect(header.getAttribute('aria-sort')).toBe('ascending');
        expect(desktopRows()).toEqual(['Ann Kim', 'Sarah Lee', 'Bob Jones']);
        await expectNoA11yViolations(container);
    });

    it('reports selected row IDs', () => {
        const onSelectionChange = vi.fn();
        render(
            <DataTable
                columns={columns}
                data={agents}
                caption="Agents"
                getRowId={(a) => a.id}
                onSelectionChange={onSelectionChange}
            />,
        );

        fireEvent.click(screen.getAllByRole('checkbox', { name: 'Select row' })[1]);

        expect(onSelectionChange).toHaveBeenLastCalledWith(['b']);
    });

    it('shows the empty message and a phone card list', () => {
        render(
            <DataTable
                columns={columns}
                data={[]}
                caption="Agents"
                getRowId={(a) => a.id}
                emptyMessage="No agents yet."
            />,
        );

        expect(screen.getAllByText('No agents yet.')).toHaveLength(2);
        expect(screen.getByRole('list', { name: 'Agents' })).toBeTruthy();
    });
});

describe('Combobox', () => {
    function Harness() {
        const [value, setValue] = useState<string | null>(null);
        return (
            <FormField label="Agent">
                {(control) => (
                    <Combobox
                        {...control}
                        value={value}
                        onChange={setValue}
                        options={agents.map((a) => ({ value: a.id, label: a.name }))}
                        placeholder="Choose an agent"
                    />
                )}
            </FormField>
        );
    }

    it('is labelled, searchable and selects an option', async () => {
        const { container } = render(<Harness />);
        const trigger = screen.getByRole('combobox', { name: 'Agent' });

        await expectNoA11yViolations(container);
        fireEvent.click(trigger);
        fireEvent.change(screen.getByPlaceholderText('Search…'), {
            target: { value: 'bob' },
        });
        fireEvent.click(screen.getByRole('option', { name: /Bob Jones/ }));

        expect(trigger.textContent).toContain('Bob Jones');
    });
});

describe('StatCard', () => {
    it('states the trend in words, not only colour', () => {
        render(<StatCard label="GCI" value="$84,000" change={12.5} comparison="vs last month" />);

        expect(screen.getByText(/Up 12\.5%/)).toBeTruthy();
    });

    it('treats a fall as good when lower is better', () => {
        render(<StatCard label="Costs" value="$9,000" change={-4} higherIsBetter={false} />);

        expect(screen.getByText(/Down 4%/).closest('p')?.className).toContain('emerald');
    });
});

describe('Pagination', () => {
    it('disables the ends and reports page changes', () => {
        const onPageChange = vi.fn();
        render(<Pagination page={1} lastPage={3} total={60} onPageChange={onPageChange} />);

        expect((screen.getByRole('button', { name: /Previous/ }) as HTMLButtonElement).disabled).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: /Next/ }));
        expect(onPageChange).toHaveBeenCalledWith(2);
        expect(screen.getByText(/Page 1 of 3 · 60 results/)).toBeTruthy();
    });

    it('renders nothing for a single page', () => {
        const { container } = render(<Pagination page={1} lastPage={1} onPageChange={() => {}} />);

        expect(container.innerHTML).toBe('');
    });
});

describe('Accessible building blocks', () => {
    it('tabs, accordion, progress and empty state have no axe violations', async () => {
        const { container } = render(
            <div>
                <Tabs defaultValue="a">
                    <TabsList>
                        <TabsTrigger value="a">Overview</TabsTrigger>
                        <TabsTrigger value="b">Deals</TabsTrigger>
                    </TabsList>
                    <TabsContent value="a">Overview content</TabsContent>
                    <TabsContent value="b">Deals content</TabsContent>
                </Tabs>
                <Accordion type="single" collapsible>
                    <AccordionItem value="one">
                        <AccordionTrigger>What is a cap?</AccordionTrigger>
                        <AccordionContent>The most company dollar an agent pays per year.</AccordionContent>
                    </AccordionItem>
                </Accordion>
                <Progress value={40} aria-label="Onboarding progress" />
                <EmptyState title="No deals yet" description="Deals you add appear here." />
            </div>,
        );

        await expectNoA11yViolations(container);
        expect(screen.getByRole('progressbar').getAttribute('aria-valuenow')).toBe('40');
    });
});
