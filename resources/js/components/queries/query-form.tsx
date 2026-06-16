import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type QueryFormDefaults = {
    name?: string;
    description?: string | null;
    resource_path?: string;
    parameters?: {
        limit?: number | null;
        q?: string | null;
        fields?: string | null;
    } | null;
    visibility?: 'private' | 'shared';
};

type QueryFormProps = {
    errors: Partial<Record<string, string>>;
    processing: boolean;
    submitLabel: string;
    defaults?: QueryFormDefaults;
};

export function QueryForm({
    errors,
    processing,
    submitLabel,
    defaults,
}: QueryFormProps) {
    const parameters = defaults?.parameters ?? undefined;

    return (
        <div className="space-y-6">
            <div className="grid gap-2">
                <Label htmlFor="name">Nom</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    defaultValue={defaults?.name}
                    placeholder="Liste des employés"
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Description</Label>
                <textarea
                    id="description"
                    name="description"
                    rows={3}
                    defaultValue={defaults?.description ?? ''}
                    placeholder="À quoi sert cette requête ?"
                    className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                />
                <InputError message={errors.description} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="resource_path">Chemin REST Fusion</Label>
                <Input
                    id="resource_path"
                    name="resource_path"
                    required
                    defaultValue={defaults?.resource_path}
                    placeholder="/hcmRestApi/resources/11.13.18.05/workers"
                />
                <InputError message={errors.resource_path} />
                <p className="text-xs text-muted-foreground">
                    Doit commencer par <code>/hcmRestApi/</code> ou{' '}
                    <code>/fscmRestApi/</code>.
                </p>
            </div>

            <fieldset className="grid gap-4 rounded-lg border p-4">
                <legend className="px-1 text-sm font-medium">Paramètres</legend>

                <div className="grid gap-2">
                    <Label htmlFor="parameters-limit">Limite</Label>
                    <Input
                        id="parameters-limit"
                        name="parameters[limit]"
                        type="number"
                        min={1}
                        max={500}
                        defaultValue={parameters?.limit ?? 25}
                    />
                    <InputError message={errors['parameters.limit']} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="parameters-q">Filtre (q)</Label>
                    <Input
                        id="parameters-q"
                        name="parameters[q]"
                        defaultValue={parameters?.q ?? ''}
                        placeholder={'DisplayName LIKE "A%"'}
                    />
                    <InputError message={errors['parameters.q']} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="parameters-fields">Champs (fields)</Label>
                    <Input
                        id="parameters-fields"
                        name="parameters[fields]"
                        defaultValue={parameters?.fields ?? ''}
                        placeholder="PersonId,DisplayName"
                    />
                    <InputError message={errors['parameters.fields']} />
                </div>
            </fieldset>

            <div className="grid gap-2">
                <Label htmlFor="visibility">Visibilité</Label>
                <Select
                    name="visibility"
                    defaultValue={defaults?.visibility ?? 'private'}
                >
                    <SelectTrigger id="visibility" className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="private">
                            Privée — visible par moi seul
                        </SelectItem>
                        <SelectItem value="shared">
                            Partagée — visible par tous
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError message={errors.visibility} />
            </div>

            <div className="flex items-center gap-4">
                <Button type="submit" disabled={processing}>
                    {submitLabel}
                </Button>
            </div>
        </div>
    );
}
