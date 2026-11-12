import {
    BookOpen,
    Building2,
    CalendarCheck,
    Contact,
    Circle,
    Landmark,
    LayoutGrid,
    ListChecks,
    type LucideIcon,
    Sparkles,
    KeyRound,
    Shield,
    Sun,
    Target,
    Users,
} from 'lucide-react';

/** Icons the server may name for menu items. Unknown names fall back to a dot. */
const icons: Record<string, LucideIcon> = {
    'book-open': BookOpen,
    'building-2': Building2,
    'calendar-check': CalendarCheck,
    contact: Contact,
    landmark: Landmark,
    'layout-grid': LayoutGrid,
    'list-checks': ListChecks,
    sparkles: Sparkles,
    'key-round': KeyRound,
    shield: Shield,
    sun: Sun,
    target: Target,
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
