import { Badge } from '@/components/ui/badge';

export type TenantStatus =
    | 'provisioning'
    | 'active'
    | 'suspended'
    | 'closing'
    | 'closed';

const statusStyles: Record<
    TenantStatus,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    active: 'default',
    provisioning: 'secondary',
    suspended: 'destructive',
    closing: 'outline',
    closed: 'secondary',
};

export function StatusBadge({ status }: { status: TenantStatus }) {
    return (
        <Badge variant={statusStyles[status]} className="capitalize">
            {status}
        </Badge>
    );
}

export function formatDate(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
          })
        : '—';
}
