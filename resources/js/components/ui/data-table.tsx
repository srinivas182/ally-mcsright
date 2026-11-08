import {
    type ColumnDef,
    flexRender,
    getCoreRowModel,
    getSortedRowModel,
    type RowSelectionState,
    type SortingState,
    useReactTable,
} from '@tanstack/react-table';
import { ArrowDownIcon, ArrowUpDownIcon, ArrowUpIcon } from 'lucide-react';
import * as React from 'react';

import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

type DataTableProps<TData> = {
    columns: ColumnDef<TData>[];
    data: TData[];
    /** Accessible name for the table. */
    caption: string;
    getRowId: (row: TData) => string;
    /** Enable row selection checkboxes and receive the selected IDs. */
    onSelectionChange?: (ids: string[]) => void;
    emptyMessage?: React.ReactNode;
    className?: string;
};

/**
 * Sortable, selectable table. On phones each row becomes a card with label/value pairs.
 * Pagination and filtering stay on the server; this component sorts the current page.
 */
function DataTable<TData>({
    columns,
    data,
    caption,
    getRowId,
    onSelectionChange,
    emptyMessage = 'Nothing to show yet.',
    className,
}: DataTableProps<TData>) {
    const [sorting, setSorting] = React.useState<SortingState>([]);
    const [rowSelection, setRowSelection] = React.useState<RowSelectionState>(
        {},
    );
    const selectable = onSelectionChange !== undefined;

    const allColumns = React.useMemo<ColumnDef<TData>[]>(() => {
        if (!selectable) {
            return columns;
        }
        return [
            {
                id: '__select',
                enableSorting: false,
                header: ({ table }) => (
                    <Checkbox
                        aria-label="Select all rows"
                        checked={
                            table.getIsAllRowsSelected() ||
                            (table.getIsSomeRowsSelected() && 'indeterminate')
                        }
                        onCheckedChange={(v) =>
                            table.toggleAllRowsSelected(v === true)
                        }
                    />
                ),
                cell: ({ row }) => (
                    <Checkbox
                        aria-label="Select row"
                        checked={row.getIsSelected()}
                        onCheckedChange={(v) => row.toggleSelected(v === true)}
                    />
                ),
            },
            ...columns,
        ];
    }, [columns, selectable]);

    const table = useReactTable({
        data,
        columns: allColumns,
        state: { sorting, rowSelection },
        getRowId,
        enableRowSelection: selectable,
        onSortingChange: setSorting,
        onRowSelectionChange: (updater) => {
            const next =
                typeof updater === 'function' ? updater(rowSelection) : updater;
            setRowSelection(next);
            onSelectionChange?.(Object.keys(next).filter((id) => next[id]));
        },
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
    });

    const rows = table.getRowModel().rows;

    return (
        <div data-slot="data-table" className={cn('grid gap-3', className)}>
            {/* Desktop and tablet: real table */}
            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <caption className="sr-only">{caption}</caption>
                    <thead className="bg-muted/50 text-left">
                        {table.getHeaderGroups().map((group) => (
                            <tr key={group.id}>
                                {group.headers.map((header) => {
                                    const sorted = header.column.getIsSorted();
                                    return (
                                        <th
                                            key={header.id}
                                            scope="col"
                                            className="p-3 font-medium"
                                            aria-sort={
                                                sorted === 'asc'
                                                    ? 'ascending'
                                                    : sorted === 'desc'
                                                      ? 'descending'
                                                      : undefined
                                            }
                                        >
                                            {header.isPlaceholder ? null : header.column.getCanSort() ? (
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center gap-1 rounded-sm outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                    onClick={header.column.getToggleSortingHandler()}
                                                >
                                                    {flexRender(
                                                        header.column.columnDef.header,
                                                        header.getContext(),
                                                    )}
                                                    {sorted === 'asc' ? (
                                                        <ArrowUpIcon aria-hidden="true" className="size-3.5" />
                                                    ) : sorted === 'desc' ? (
                                                        <ArrowDownIcon aria-hidden="true" className="size-3.5" />
                                                    ) : (
                                                        <ArrowUpDownIcon aria-hidden="true" className="size-3.5 opacity-40" />
                                                    )}
                                                </button>
                                            ) : (
                                                flexRender(
                                                    header.column.columnDef.header,
                                                    header.getContext(),
                                                )
                                            )}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={allColumns.length}
                                    className="p-6 text-center text-muted-foreground"
                                >
                                    {emptyMessage}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr
                                    key={row.id}
                                    data-state={row.getIsSelected() ? 'selected' : undefined}
                                    className="border-t hover:bg-muted/30 data-[state=selected]:bg-accent"
                                >
                                    {row.getVisibleCells().map((cell) => (
                                        <td key={cell.id} className="p-3">
                                            {flexRender(
                                                cell.column.columnDef.cell,
                                                cell.getContext(),
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Phones: one card per row */}
            <ul aria-label={caption} className="grid gap-3 md:hidden">
                {rows.length === 0 && (
                    <li className="rounded-lg border p-6 text-center text-sm text-muted-foreground">
                        {emptyMessage}
                    </li>
                )}
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className="grid gap-2 rounded-lg border bg-card p-4 text-sm"
                    >
                        {row.getVisibleCells().map((cell) => {
                            const header = cell.column.columnDef.header;
                            return (
                                <div
                                    key={cell.id}
                                    className="flex items-start justify-between gap-4"
                                >
                                    {typeof header === 'string' && (
                                        <span className="text-muted-foreground">
                                            {header}
                                        </span>
                                    )}
                                    <span className="text-right">
                                        {flexRender(
                                            cell.column.columnDef.cell,
                                            cell.getContext(),
                                        )}
                                    </span>
                                </div>
                            );
                        })}
                    </li>
                ))}
            </ul>
        </div>
    );
}

export { DataTable };
export type { DataTableProps };
