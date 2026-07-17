import type { ReactNode } from 'react';

/**
 * En-tête de page : titre + description à gauche, zone d'actions à droite.
 * La variante « small » sert aux sous-sections (settings…).
 */
export default function Heading({
    title,
    description,
    variant = 'default',
    actions,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    actions?: ReactNode;
}) {
    if (variant === 'small') {
        return (
            <header>
                <h2 className="mb-0.5 text-base font-medium">{title}</h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </header>
        );
    }

    return (
        <header className="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div className="space-y-1">
                <h2 className="text-2xl font-bold tracking-tight">{title}</h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}
