import { Search, UserRoundPlus, X } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/i18n/i18n-context';
import type { QueryChangeRequestUser } from '@/types/change-requests';

const MAX_MENTIONS = 10;

export function MentionSelector({
    candidates,
    selected,
    onChange,
    disabled = false,
}: {
    candidates: QueryChangeRequestUser[];
    selected: number[];
    onChange: (ids: number[]) => void;
    disabled?: boolean;
}) {
    const { t } = useI18n();
    const searchId = useId();
    const [search, setSearch] = useState('');
    const selectedSet = useMemo(() => new Set(selected), [selected]);
    const selectedUsers = candidates.filter((candidate) =>
        selectedSet.has(candidate.id),
    );
    const filteredCandidates = candidates.filter((candidate) =>
        candidate.name.toLocaleLowerCase().includes(search.toLocaleLowerCase()),
    );

    function toggle(userId: number, checked: boolean) {
        if (checked) {
            if (selected.length >= MAX_MENTIONS || selectedSet.has(userId)) {
                return;
            }

            onChange([...selected, userId]);

            return;
        }

        onChange(selected.filter((id) => id !== userId));
    }

    if (candidates.length === 0) {
        return null;
    }

    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">
                {t('changeRequests.mentionsLabel')}
            </legend>
            <p className="text-xs text-muted-foreground">
                {t('changeRequests.mentionsHelp', {
                    count: selected.length,
                    max: MAX_MENTIONS,
                })}
            </p>

            {selectedUsers.length > 0 && (
                <div
                    className="flex flex-wrap gap-2"
                    aria-label={t('changeRequests.selectedMentions')}
                >
                    {selectedUsers.map((candidate) => (
                        <Badge key={candidate.id} variant="secondary">
                            <UserRoundPlus aria-hidden="true" />
                            {candidate.name}
                            <button
                                type="button"
                                className="rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                onClick={() => toggle(candidate.id, false)}
                                disabled={disabled}
                                aria-label={t('changeRequests.removeMention', {
                                    name: candidate.name,
                                })}
                            >
                                <X aria-hidden="true" />
                            </button>
                        </Badge>
                    ))}
                </div>
            )}

            <div className="relative">
                <Label htmlFor={searchId} className="sr-only">
                    {t('changeRequests.searchMentions')}
                </Label>
                <Search
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    id={searchId}
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={t('changeRequests.searchMentions')}
                    className="pl-9"
                    disabled={disabled}
                />
            </div>

            <div className="max-h-44 space-y-1 overflow-y-auto" role="list">
                {filteredCandidates.length === 0 ? (
                    <p className="py-3 text-center text-sm text-muted-foreground">
                        {t('changeRequests.noMentionCandidates')}
                    </p>
                ) : (
                    filteredCandidates.map((candidate) => {
                        const checked = selectedSet.has(candidate.id);
                        const atLimit =
                            selected.length >= MAX_MENTIONS && !checked;
                        const checkboxId = `mention-${searchId}-${candidate.id}`;

                        return (
                            <div
                                key={candidate.id}
                                role="listitem"
                                className="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted/60"
                            >
                                <Checkbox
                                    id={checkboxId}
                                    checked={checked}
                                    onCheckedChange={(value) =>
                                        toggle(candidate.id, value === true)
                                    }
                                    disabled={disabled || atLimit}
                                />
                                <Label
                                    htmlFor={checkboxId}
                                    className="min-w-0 flex-1 cursor-pointer truncate font-normal"
                                >
                                    {candidate.name}
                                </Label>
                            </div>
                        );
                    })
                )}
            </div>
        </fieldset>
    );
}
