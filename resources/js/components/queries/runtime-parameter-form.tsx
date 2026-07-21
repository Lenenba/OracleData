import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/i18n/i18n-context';
import type {
    QueryTemplateParameterDefinition,
    QueryTemplateValue,
    QueryTemplateValues,
} from '@/types/query-template';

type Props = {
    definitions: QueryTemplateParameterDefinition[];
    values: QueryTemplateValues;
    errors?: Record<string, string>;
    disabled?: boolean;
    onChange: (key: string, value: QueryTemplateValue) => void;
};

/**
 * Runtime form for personal-query parameters (lot 10A).
 *
 * Intentionally re-uses the exact same rendering logic as
 * QueryTemplateParameters so the UX is consistent across query types.
 */
export function RuntimeParameterForm({
    definitions,
    values,
    errors = {},
    disabled = false,
    onChange,
}: Props) {
    const { t } = useI18n();

    if (definitions.length === 0) {
        return null;
    }

    return (
        <div className="space-y-1.5">
            <p className="text-sm font-medium">
                {t('queries.runtimeParameters')}
            </p>
            <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                {definitions.map((definition) => {
                    const id = `run-parameter-${definition.key}`;
                    const descriptionId = `${id}-description`;
                    const errorId = `${id}-error`;
                    const value = values[definition.key] ?? '';
                    const error = errors[definition.key];
                    const describedBy = [
                        definition.description ? descriptionId : null,
                        error ? errorId : null,
                    ]
                        .filter(Boolean)
                        .join(' ');

                    return (
                        <div
                            key={definition.key}
                            className="grid content-start gap-2"
                        >
                            <Label htmlFor={id}>
                                {definition.label}
                                {definition.required && (
                                    <span className="ml-1 text-destructive">
                                        {t('templates.required')}
                                    </span>
                                )}
                            </Label>

                            {definition.type === 'select' ? (
                                <Select
                                    value={String(value)}
                                    disabled={disabled}
                                    onValueChange={(next) =>
                                        onChange(definition.key, next)
                                    }
                                >
                                    <SelectTrigger
                                        id={id}
                                        aria-invalid={!!error}
                                        aria-required={definition.required}
                                        aria-describedby={
                                            describedBy || undefined
                                        }
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(definition.options ?? []).map(
                                            (option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            ) : definition.type === 'boolean' ? (
                                <div className="flex h-9 items-center gap-2 rounded-md border px-3">
                                    <Checkbox
                                        id={id}
                                        checked={Boolean(value)}
                                        disabled={disabled}
                                        aria-invalid={!!error}
                                        aria-required={definition.required}
                                        aria-describedby={
                                            describedBy || undefined
                                        }
                                        onCheckedChange={(checked) =>
                                            onChange(
                                                definition.key,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <span
                                        id={descriptionId}
                                        className="text-sm text-muted-foreground"
                                    >
                                        {definition.description}
                                    </span>
                                </div>
                            ) : (
                                <Input
                                    id={id}
                                    type={
                                        definition.type === 'integer' ||
                                        definition.type === 'number'
                                            ? 'number'
                                            : definition.type
                                    }
                                    value={String(value)}
                                    min={definition.min}
                                    max={definition.max}
                                    step={
                                        definition.step ??
                                        (definition.type === 'integer'
                                            ? 1
                                            : undefined)
                                    }
                                    disabled={disabled}
                                    required={definition.required}
                                    aria-invalid={!!error}
                                    aria-describedby={
                                        describedBy || undefined
                                    }
                                    onChange={(event) =>
                                        onChange(
                                            definition.key,
                                            event.target.value,
                                        )
                                    }
                                />
                            )}

                            {definition.type !== 'boolean' &&
                                definition.description && (
                                    <p
                                        id={descriptionId}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {definition.description}
                                    </p>
                                )}
                            {error && (
                                <p
                                    id={errorId}
                                    role="alert"
                                    className="text-xs text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
