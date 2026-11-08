import { CheckIcon, ChevronsUpDownIcon } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

type ComboboxOption = { value: string; label: string; hint?: string };

type ComboboxProps = {
    options: ComboboxOption[];
    value: string | null;
    onChange: (value: string | null) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    disabled?: boolean;
    className?: string;
    id?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
    'aria-label'?: string;
};

/**
 * Searchable single select for long lists (accounts, agents, MLS IDs). Works with FormField:
 * spread the control props onto it.
 */
function Combobox({
    options,
    value,
    onChange,
    placeholder = 'Select…',
    searchPlaceholder = 'Search…',
    emptyText = 'No matches.',
    disabled = false,
    className,
    ...aria
}: ComboboxProps) {
    const [open, setOpen] = React.useState(false);
    const selected = options.find((option) => option.value === value);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className={cn(
                        'w-full justify-between font-normal',
                        !selected && 'text-muted-foreground',
                        className,
                    )}
                    {...aria}
                >
                    <span className="truncate">
                        {selected?.label ?? placeholder}
                    </span>
                    <ChevronsUpDownIcon
                        aria-hidden="true"
                        className="opacity-50"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-(--radix-popover-trigger-width) p-0"
                align="start"
            >
                <Command>
                    <CommandInput placeholder={searchPlaceholder} />
                    <CommandList>
                        <CommandEmpty>{emptyText}</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.value}
                                    value={`${option.label} ${option.hint ?? ''}`}
                                    onSelect={() => {
                                        onChange(
                                            option.value === value
                                                ? null
                                                : option.value,
                                        );
                                        setOpen(false);
                                    }}
                                >
                                    <CheckIcon
                                        aria-hidden="true"
                                        className={cn(
                                            'size-4',
                                            option.value === value
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <span className="truncate">
                                        {option.label}
                                    </span>
                                    {option.hint && (
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            {option.hint}
                                        </span>
                                    )}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export { Combobox };
export type { ComboboxOption, ComboboxProps };
