import {
    BookOpen,
    Building2,
    CalendarCheck,
    Circle,
    Landmark,
    LayoutGrid,
    type LucideIcon,
    Sun,
    Users,
} from 'lucide-react';

/** Icons the server may name for menu items. Unknown names fall back to a dot. */
const icons: Record<string, LucideIcon> = {
    'book-open': BookOpen,
    'building-2': Building2,
    'calendar-check': CalendarCheck,
    landmark: Landmark,
    'layout-grid': LayoutGrid,
    sun: Sun,
    users: Users,
};

export function NavIcon({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const Icon = icons[name] ?? Circle;
    return <Icon aria-hidden="true" className={className} />;
}
