import {
    BookOpen,
    Phone,
    ShieldAlert,
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
    FileText,
    IdCard,
    Scale,
    Receipt,
    Gift,
    Calculator,
    TrendingUp,
    BadgeDollarSign,
    CreditCard,
    Network,
    Briefcase,
    ClipboardCheck,
    Banknote,
    Wallet,
    Send,
    Shield,
    Sun,
    Target,
    Users,
} from 'lucide-react';

/** Icons the server may name for menu items. Unknown names fall back to a dot. */
const icons: Record<string, LucideIcon> = {
    'book-open': BookOpen,
    phone: Phone,
    'shield-alert': ShieldAlert,
    'building-2': Building2,
    'calendar-check': CalendarCheck,
    contact: Contact,
    landmark: Landmark,
    'layout-grid': LayoutGrid,
    'list-checks': ListChecks,
    sparkles: Sparkles,
    'key-round': KeyRound,
    'file-text': FileText,
    'id-card': IdCard,
    scale: Scale,
    receipt: Receipt,
    gift: Gift,
    calculator: Calculator,
    'trending-up': TrendingUp,
    'badge-dollar-sign': BadgeDollarSign,
    'credit-card': CreditCard,
    network: Network,
    briefcase: Briefcase,
    'clipboard-check': ClipboardCheck,
    banknote: Banknote,
    wallet: Wallet,
    send: Send,
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
