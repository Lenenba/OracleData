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
                <h2 className="mb-0.5 text-[15px] font-semibold">{title}</h2>
                {description && (
                    <p className="text-[13px] leading-5 text-muted-foreground">
                        {description}
                    </p>
                )}
            </header>
        );
    }

    return (
        <header className="paces-page-title-head mb-5">
            <div className="min-w-0 space-y-1">
                <h1 className="paces-page-main-title truncate">{title}</h1>
                {description && (
                    <p className="max-w-3xl text-[13px] leading-5 text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </header>
    );
}
