import type { LucideIcon } from 'lucide-react';

/**
 * Pastille bordée icône + libellé Paces :
 * utilisée pour les tenants, domaines et modes dans les tables.
 */
export function EntityChip({
    label,
    icon: Icon,
    className = '',
}: {
    label: string;
    icon?: LucideIcon;
    className?: string;
}) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded border border-border bg-muted/45 px-2 py-1 text-xs font-semibold text-foreground ${className}`}
        >
            {Icon && <Icon className="size-3.5 text-muted-foreground" />}
            {label}
        </span>
    );
}
