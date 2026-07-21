import { router } from '@inertiajs/react';
import { Plus, Settings, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { update as updateParameters } from '@/actions/App/Http/Controllers/QueryParameterController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/i18n/i18n-context';
import type {
    QueryTemplateParameterDefinition,
    QueryTemplateParameterOption,
} from '@/types/query-template';

type RawDefinition = Omit<QueryTemplateParameterDefinition, 'options'> & {
    options?: QueryTemplateParameterOption[];
    binding?: {
        kind: 'filter' | 'parameter';
        field?: string;
        operator?: string;
        key?: string;
    };
};

type Props = {
    queryId: number;
    definitions: QueryTemplateParameterDefinition[];
};

function emptyDefinition(): RawDefinition {
    return {
        key: '',
        type: 'string',
        label: '',
        required: false,
    };
}

export function ParameterDefinitionEditor({ queryId, definitions }: Props) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<RawDefinition[]>(
        definitions.length > 0
            ? (definitions as RawDefinition[])
            : [],
    );
    const [saving, setSaving] = useState(false);

    function openDialog() {
        setItems(
            definitions.length > 0
                ? (definitions as RawDefinition[])
                : [],
        );
        setOpen(true);
    }

    function addDefinition() {
        setItems((prev) => [...prev, emptyDefinition()]);
    }

    function removeDefinition(index: number) {
        setItems((prev) => prev.filter((_, i) => i !== index));
    }

    function updateField<K extends keyof RawDefinition>(
        index: number,
        field: K,
        value: RawDefinition[K],
    ) {
        setItems((prev) =>
            prev.map((item, i) =>
                i === index ? { ...item, [field]: value } : item,
            ),
        );
    }

    function save() {
        setSaving(true);
        router.put(
            updateParameters.url(queryId),
            { parameter_definitions: items } as Parameters<typeof router.put>[1],
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    const count = definitions.length;

    return (
        <Dialog open={open} onOpenChange={(next) => !saving && setOpen(next)}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={openDialog}
                >
                    <Settings className="size-4" />
                    {t('queries.parameterDefinitions')}
                    {count > 0 && (
                        <Badge variant="secondary" className="ml-1">
                            {count}
                        </Badge>
                    )}
                </Button>
            </DialogTrigger>

            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {t('queries.parameterDefinitionsTitle')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('queries.parameterDefinitionsDescription')}
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[60vh] space-y-4 overflow-y-auto pr-1">
                    {items.length === 0 && (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            {t('queries.parameterDefinitionsEmpty')}
                        </p>
                    )}

                    {items.map((item, index) => (
                        <div
                            key={index}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium">
                                    {t('queries.parameterN', {
                                        n: index + 1,
                                    })}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 text-destructive hover:text-destructive"
                                    onClick={() => removeDefinition(index)}
                                >
                                    <Trash2 className="size-3.5" />
                                    <span className="sr-only">
                                        {t('queries.parameterRemove')}
                                    </span>
                                </Button>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`param-key-${index}`}
                                        className="text-xs"
                                    >
                                        {t('queries.parameterKey')}
                                    </Label>
                                    <Input
                                        id={`param-key-${index}`}
                                        value={item.key}
                                        placeholder="ex: department"
                                        className="h-8 font-mono text-sm"
                                        onChange={(e) =>
                                            updateField(
                                                index,
                                                'key',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`param-type-${index}`}
                                        className="text-xs"
                                    >
                                        {t('queries.parameterType')}
                                    </Label>
                                    <Select
                                        value={item.type}
                                        onValueChange={(v) =>
                                            updateField(
                                                index,
                                                'type',
                                                v as RawDefinition['type'],
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={`param-type-${index}`}
                                            className="h-8"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(
                                                [
                                                    'string',
                                                    'integer',
                                                    'number',
                                                    'date',
                                                    'boolean',
                                                    'select',
                                                ] as const
                                            ).map((type) => (
                                                <SelectItem
                                                    key={type}
                                                    value={type}
                                                >
                                                    {t(
                                                        `queries.parameterType_${type}`,
                                                    )}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-1.5">
                                <Label
                                    htmlFor={`param-label-${index}`}
                                    className="text-xs"
                                >
                                    {t('queries.parameterLabel')}
                                </Label>
                                <Input
                                    id={`param-label-${index}`}
                                    value={item.label}
                                    placeholder={t(
                                        'queries.parameterLabelPlaceholder',
                                    )}
                                    className="h-8 text-sm"
                                    onChange={(e) =>
                                        updateField(
                                            index,
                                            'label',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label
                                    htmlFor={`param-default-${index}`}
                                    className="text-xs"
                                >
                                    {t('queries.parameterDefault')}
                                </Label>
                                <Input
                                    id={`param-default-${index}`}
                                    value={
                                        item.default !== undefined &&
                                        item.default !== null
                                            ? String(item.default)
                                            : ''
                                    }
                                    placeholder={t(
                                        'queries.parameterDefaultPlaceholder',
                                    )}
                                    className="h-8 text-sm"
                                    onChange={(e) =>
                                        updateField(
                                            index,
                                            'default',
                                            e.target.value || undefined,
                                        )
                                    }
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={`param-required-${index}`}
                                    checked={item.required}
                                    onCheckedChange={(checked) =>
                                        updateField(
                                            index,
                                            'required',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`param-required-${index}`}
                                    className="text-xs font-normal"
                                >
                                    {t('queries.parameterRequired')}
                                </Label>
                            </div>

                            {/* Filter binding */}
                            <div className="grid gap-1.5">
                                <Label className="text-xs">
                                    {t('queries.parameterFilterBinding')}
                                </Label>
                                <div className="grid grid-cols-3 gap-2">
                                    <Input
                                        value={item.binding?.field ?? ''}
                                        placeholder={t(
                                            'queries.parameterFilterField',
                                        )}
                                        className="h-8 font-mono text-xs"
                                        onChange={(e) =>
                                            updateField(index, 'binding', {
                                                kind: 'filter',
                                                field: e.target.value,
                                                operator:
                                                    item.binding?.operator ??
                                                    '=',
                                            })
                                        }
                                    />
                                    <Select
                                        value={item.binding?.operator ?? '='}
                                        onValueChange={(v) =>
                                            updateField(index, 'binding', {
                                                kind: 'filter',
                                                field:
                                                    item.binding?.field ?? '',
                                                operator: v,
                                            })
                                        }
                                    >
                                        <SelectTrigger className="h-8">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[
                                                '=',
                                                '!=',
                                                '>',
                                                '>=',
                                                '<',
                                                '<=',
                                                'LIKE',
                                            ].map((op) => (
                                                <SelectItem key={op} value={op}>
                                                    {op}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="flex items-center text-xs text-muted-foreground">
                                        {t('queries.parameterFilterHint')}
                                    </p>
                                </div>
                            </div>
                        </div>
                    ))}

                    {items.length < 20 && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-full"
                            onClick={addDefinition}
                        >
                            <Plus className="size-4" />
                            {t('queries.parameterAdd')}
                        </Button>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setOpen(false)}
                        disabled={saving}
                    >
                        {t('queries.parameterCancel')}
                    </Button>
                    <Button
                        type="button"
                        onClick={save}
                        disabled={saving}
                    >
                        {saving && <Spinner />}
                        {t('queries.parameterSave')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
