import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { HTMLAttributes } from 'react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { appearance, updateAppearance } = useAppearance();

    const tabs: { value: Appearance; icon: LucideIcon; label: string }[] = [
        { value: 'light', icon: Sun, label: 'Light' },
        { value: 'dark', icon: Moon, label: 'Dark' },
        { value: 'system', icon: Monitor, label: 'System' },
    ];

    return (
        <div className={cn('grid gap-4 md:grid-cols-3', className)} {...props}>
            {tabs.map(({ value, icon: Icon, label }) => (
                <button
                    key={value}
                    onClick={() => updateAppearance(value)}
                    type="button"
                    aria-pressed={appearance === value}
                    className={cn(
                        'group overflow-hidden rounded border bg-card text-left transition hover:border-primary/50',
                        appearance === value
                            ? 'border-primary ring-1 ring-primary'
                            : 'border-border',
                    )}
                >
                    <span className="block h-28 bg-muted p-4">
                        <span className="block h-3 w-2/3 rounded-sm bg-card shadow-sm" />
                        <span className="mt-3 grid grid-cols-[28px_1fr] gap-2">
                            <span className="h-16 rounded-sm bg-[#1e1f27]" />
                            <span className="space-y-2 rounded-sm bg-card p-2 shadow-sm">
                                <span className="block h-2 w-full rounded-sm bg-muted" />
                                <span className="block h-7 w-full rounded-sm bg-primary/15" />
                                <span className="block h-2 w-3/4 rounded-sm bg-muted" />
                            </span>
                        </span>
                    </span>
                    <span className="flex items-center gap-2 border-t border-border px-4 py-3">
                        <Icon className="size-4 text-primary" />
                        <span className="text-sm font-semibold">{label}</span>
                        <span
                            className={cn(
                                'ml-auto size-3 rounded-full border-2',
                                appearance === value
                                    ? 'border-primary bg-primary'
                                    : 'border-muted-foreground/40',
                            )}
                        />
                    </span>
                </button>
            ))}
        </div>
    );
}
