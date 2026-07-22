import { Head, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserPlus,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/i18n/i18n-context';
import { readCsrfToken } from '@/lib/csrf';
import groupRoutes from '@/routes/groups';
import type {
    GroupMember,
    GroupRole,
    GroupSummary,
    PaginatedGroupMemberCandidates,
    PaginatedGroupMembers,
} from '@/types';

type GroupsProps = {
    groups: GroupSummary[];
    selectedGroup: GroupSummary | null;
    members: PaginatedGroupMembers;
    candidates: PaginatedGroupMemberCandidates;
    search: string;
    roles: GroupRole[];
};

type Feedback = { type: 'success' | 'error'; message: string } | null;

function firstError(errors: Record<string, string>): string | null {
    return Object.values(errors).find((message) => message !== '') ?? null;
}

async function responseError(response: Response): Promise<string | null> {
    const payload = (await response.json().catch(() => null)) as {
        message?: unknown;
        errors?: Record<string, unknown>;
    } | null;

    if (typeof payload?.message === 'string' && payload.message !== '') {
        return payload.message;
    }

    return (
        Object.values(payload?.errors ?? {})
            .flatMap((value) => (Array.isArray(value) ? value : [value]))
            .find((value): value is string => typeof value === 'string') ?? null
    );
}

async function mutate(
    url: string,
    method: 'POST' | 'PATCH' | 'DELETE',
    data?: Record<string, unknown>,
): Promise<void> {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCsrfToken(),
        },
        credentials: 'same-origin',
        body: data === undefined ? undefined : JSON.stringify(data),
    });

    if (!response.ok) {
        throw new Error((await responseError(response)) ?? 'Request failed.');
    }
}

export default function GroupsIndex({
    groups,
    selectedGroup,
    members,
    candidates,
    search,
    roles,
}: GroupsProps) {
    const { t } = useI18n();
    const [dialogMode, setDialogMode] = useState<'create' | 'edit' | null>(
        null,
    );
    const [groupName, setGroupName] = useState('');
    const [groupDescription, setGroupDescription] = useState('');
    const [candidateSearch, setCandidateSearch] = useState(search);
    const [selectedCandidate, setSelectedCandidate] = useState('');
    const [newRole, setNewRole] = useState<GroupRole>('member');
    const [groupPending, setGroupPending] = useState(false);
    const [candidatePending, setCandidatePending] = useState(false);
    const [memberPending, setMemberPending] = useState<number | 'add' | null>(
        null,
    );
    const [feedback, setFeedback] = useState<Feedback>(null);
    const [dialogError, setDialogError] = useState<string | null>(null);

    const assignableRoles: GroupRole[] = roles.filter(
        (role) => role !== 'owner',
    );
    const selectedNewRole = assignableRoles.includes(newRole)
        ? newRole
        : (assignableRoles[0] ?? 'member');
    const roleLabels: Record<GroupRole, string> = {
        owner: t('groups.roleOwner'),
        manager: t('groups.roleManager'),
        member: t('groups.roleMember'),
    };

    function selectGroup(groupId: number) {
        setFeedback(null);
        setSelectedCandidate('');
        setCandidateSearch('');
        setNewRole('member');
        router.get(
            groupRoutes.index.url(),
            { group: groupId },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    function openCreateDialog() {
        setGroupName('');
        setGroupDescription('');
        setDialogError(null);
        setDialogMode('create');
    }

    function openEditDialog() {
        if (!selectedGroup) {
            return;
        }

        setGroupName(selectedGroup.name);
        setGroupDescription(selectedGroup.description ?? '');
        setDialogError(null);
        setDialogMode('edit');
    }

    function saveGroup(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (groupName.trim() === '' || groupPending) {
            return;
        }

        const isEditing = dialogMode === 'edit' && selectedGroup !== null;
        const url = isEditing
            ? groupRoutes.update.url(selectedGroup.id)
            : groupRoutes.store.url();
        const data = {
            name: groupName.trim(),
            description: groupDescription.trim() || null,
        };
        const options = {
            preserveScroll: true,
            onStart: () => {
                setGroupPending(true);
                setFeedback(null);
                setDialogError(null);
            },
            onSuccess: () => {
                setDialogMode(null);
                setFeedback({
                    type: 'success' as const,
                    message: isEditing
                        ? t('groups.updateSuccess')
                        : t('groups.createSuccess'),
                });
            },
            onError: (errors: Record<string, string>) =>
                setDialogError(firstError(errors) ?? t('groups.error')),
            onFinish: () => setGroupPending(false),
        };

        if (isEditing) {
            router.patch(url, data, options);
        } else {
            router.post(url, data, options);
        }
    }

    function deleteGroup() {
        if (
            !selectedGroup ||
            !window.confirm(
                t('groups.deleteConfirm', { name: selectedGroup.name }),
            )
        ) {
            return;
        }

        router.delete(groupRoutes.destroy.url(selectedGroup.id), {
            preserveScroll: true,
            onStart: () => {
                setGroupPending(true);
                setFeedback(null);
            },
            onSuccess: () =>
                setFeedback({
                    type: 'success',
                    message: t('groups.deleteSuccess'),
                }),
            onError: (errors) =>
                setFeedback({
                    type: 'error',
                    message: firstError(errors) ?? t('groups.error'),
                }),
            onFinish: () => setGroupPending(false),
        });
    }

    function loadGroupData(
        memberPage: number,
        candidatePage: number,
        nextSearch = candidateSearch,
    ) {
        if (!selectedGroup) {
            return;
        }

        setCandidatePending(true);
        setSelectedCandidate('');
        router.get(
            groupRoutes.index.url(),
            {
                group: selectedGroup.id,
                search: nextSearch.trim() || undefined,
                member_page: memberPage,
                candidate_page: candidatePage,
                per_page: candidates.meta.per_page,
            },
            {
                only: ['members', 'candidates', 'search'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setCandidatePending(false),
            },
        );
    }

    function searchCandidates(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        loadGroupData(members.meta.current_page, 1);
    }

    function reloadSelectedGroup({
        memberPage = members.meta.current_page,
        candidatePage = candidates.meta.current_page,
    }: {
        memberPage?: number;
        candidatePage?: number;
    } = {}) {
        if (!selectedGroup) {
            return;
        }

        router.get(
            groupRoutes.index.url(),
            {
                group: selectedGroup.id,
                search: search || undefined,
                member_page: memberPage,
                candidate_page: candidatePage,
                per_page: members.meta.per_page,
            },
            {
                only: ['groups', 'selectedGroup', 'members', 'candidates'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    async function addMember() {
        if (!selectedGroup || selectedCandidate === '') {
            return;
        }

        setMemberPending('add');
        setFeedback(null);

        try {
            await mutate(
                groupRoutes.members.store.url(selectedGroup.id),
                'POST',
                {
                    user_id: Number(selectedCandidate),
                    role: selectedNewRole,
                },
            );
            setSelectedCandidate('');
            setNewRole('member');
            setFeedback({
                type: 'success',
                message: t('groups.memberAddSuccess'),
            });
            reloadSelectedGroup({ candidatePage: 1 });
        } catch (error) {
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('groups.error'),
            });
        } finally {
            setMemberPending(null);
        }
    }

    async function updateMemberRole(member: GroupMember, role: GroupRole) {
        if (!selectedGroup || role === member.role) {
            return;
        }

        setMemberPending(member.id);
        setFeedback(null);

        try {
            await mutate(
                groupRoutes.members.update.url([selectedGroup.id, member.id]),
                'PATCH',
                {
                    role,
                },
            );
            setFeedback({
                type: 'success',
                message: t('groups.memberUpdateSuccess'),
            });
            reloadSelectedGroup();
        } catch (error) {
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('groups.error'),
            });
        } finally {
            setMemberPending(null);
        }
    }

    async function removeMember(member: GroupMember) {
        if (
            !selectedGroup ||
            !window.confirm(
                t('groups.removeMemberConfirm', { name: member.name }),
            )
        ) {
            return;
        }

        setMemberPending(member.id);
        setFeedback(null);

        try {
            await mutate(
                groupRoutes.members.destroy.url([selectedGroup.id, member.id]),
                'DELETE',
            );
            setFeedback({
                type: 'success',
                message: t('groups.memberRemoveSuccess'),
            });
            const remainingMembers = Math.max(0, members.meta.total - 1);
            const lastMemberPage = Math.max(
                1,
                Math.ceil(remainingMembers / members.meta.per_page),
            );

            reloadSelectedGroup({
                memberPage: Math.min(members.meta.current_page, lastMemberPage),
            });
        } catch (error) {
            setFeedback({
                type: 'error',
                message:
                    error instanceof Error &&
                    error.message !== 'Request failed.'
                        ? error.message
                        : t('groups.error'),
            });
        } finally {
            setMemberPending(null);
        }
    }

    return (
        <>
            <Head title={t('groups.pageTitle')} />

            <div className="p-5">
                <Heading
                    title={t('groups.title')}
                    description={t('groups.description')}
                    actions={
                        <Button onClick={openCreateDialog}>
                            <Plus />
                            {t('groups.create')}
                        </Button>
                    }
                />

                <div className="mb-5 grid gap-5 md:grid-cols-3">
                    {[
                        {
                            label: 'Groupes accessibles',
                            value: groups.length,
                            icon: UsersRound,
                            tone: 'bg-primary/15 text-primary',
                        },
                        {
                            label: 'Membres cumulés',
                            value: groups.reduce(
                                (total, group) => total + group.member_count,
                                0,
                            ),
                            icon: UserPlus,
                            tone: 'bg-info/15 text-info',
                        },
                        {
                            label: 'Groupes administrés',
                            value: groups.filter(
                                (group) => group.can_manage_members,
                            ).length,
                            icon: Pencil,
                            tone: 'bg-purple/15 text-purple',
                        },
                    ].map(({ label, value, icon: Icon, tone }) => (
                        <div key={label} className="card">
                            <div className="card-body flex items-center gap-4">
                                <span
                                    className={`grid size-10 place-items-center rounded-full ${tone}`}
                                >
                                    <Icon className="size-4.5" />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wide text-muted-foreground uppercase">
                                        {label}
                                    </p>
                                    <strong className="mt-1 block text-2xl font-semibold tabular-nums">
                                        {value}
                                    </strong>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                <div className="space-y-5">
                    {feedback && (
                        <Alert
                            variant={
                                feedback.type === 'error'
                                    ? 'destructive'
                                    : 'default'
                            }
                            role={
                                feedback.type === 'error' ? 'alert' : 'status'
                            }
                            aria-live="polite"
                        >
                            <AlertTitle>
                                {feedback.type === 'error'
                                    ? t('groups.errorTitle')
                                    : t('groups.successTitle')}
                            </AlertTitle>
                            <AlertDescription>
                                {feedback.message}
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="grid gap-5 lg:grid-cols-[20rem_minmax(0,1fr)]">
                        <Card className="h-fit">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <UsersRound className="size-4" />
                                    {t('groups.listTitle')}
                                </CardTitle>
                                <CardDescription>
                                    {t('groups.listDescription')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {groups.length === 0 ? (
                                    <p className="rounded-lg border border-dashed p-5 text-center text-sm text-muted-foreground">
                                        {t('groups.noGroups')}
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {groups.map((group) => (
                                            <li key={group.id}>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        selectGroup(group.id)
                                                    }
                                                    className={`w-full rounded-lg border p-3 text-left transition-colors hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                                        selectedGroup?.id ===
                                                        group.id
                                                            ? 'border-primary bg-muted/60'
                                                            : ''
                                                    }`}
                                                >
                                                    <span className="block truncate font-medium">
                                                        {group.name}
                                                    </span>
                                                    <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                        <span>
                                                            {t(
                                                                'groups.memberCount',
                                                                {
                                                                    count: group.member_count,
                                                                },
                                                            )}
                                                        </span>
                                                        <Badge
                                                            variant="secondary"
                                                            className="font-normal"
                                                        >
                                                            {
                                                                roleLabels[
                                                                    group
                                                                        .current_user_role
                                                                ]
                                                            }
                                                        </Badge>
                                                    </span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        {!selectedGroup ? (
                            <Card>
                                <CardContent className="py-14 text-center">
                                    <UsersRound className="mx-auto mb-3 size-8 text-muted-foreground" />
                                    <p className="font-medium">
                                        {t('groups.selectGroup')}
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {t('groups.selectGroupDescription')}
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <Card aria-busy={memberPending !== null}>
                                <CardHeader>
                                    <div className="flex flex-wrap items-start justify-between gap-4">
                                        <div className="space-y-1.5">
                                            <CardTitle>
                                                {selectedGroup.name}
                                            </CardTitle>
                                            <CardDescription>
                                                {selectedGroup.description ||
                                                    t('groups.noDescription')}
                                            </CardDescription>
                                        </div>
                                        <div className="flex gap-2">
                                            {selectedGroup.can_update && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={openEditDialog}
                                                    disabled={groupPending}
                                                >
                                                    <Pencil />
                                                    {t('common.edit')}
                                                </Button>
                                            )}
                                            {selectedGroup.can_delete && (
                                                <Button
                                                    type="button"
                                                    variant="destructive"
                                                    onClick={deleteGroup}
                                                    disabled={groupPending}
                                                >
                                                    <Trash2 />
                                                    {t('common.delete')}
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    {selectedGroup.can_manage_members && (
                                        <div className="space-y-4 rounded-lg border p-4">
                                            <form
                                                onSubmit={searchCandidates}
                                                className="flex flex-col gap-2 sm:flex-row"
                                                role="search"
                                            >
                                                <div className="grid flex-1 gap-2">
                                                    <Label htmlFor="group-member-search">
                                                        {t(
                                                            'groups.searchMembers',
                                                        )}
                                                    </Label>
                                                    <Input
                                                        id="group-member-search"
                                                        type="search"
                                                        value={candidateSearch}
                                                        onChange={(event) =>
                                                            setCandidateSearch(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        placeholder={t(
                                                            'groups.searchMembersPlaceholder',
                                                        )}
                                                    />
                                                </div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    className="sm:self-end"
                                                    disabled={candidatePending}
                                                >
                                                    {candidatePending ? (
                                                        <Spinner
                                                            aria-label={t(
                                                                'common.loading',
                                                            )}
                                                        />
                                                    ) : (
                                                        <Search />
                                                    )}
                                                    {t('sharing.searchAction')}
                                                </Button>
                                            </form>

                                            <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_11rem_auto] md:items-end">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="group-member-candidate">
                                                        {t(
                                                            'groups.selectMember',
                                                        )}
                                                    </Label>
                                                    <Select
                                                        value={
                                                            selectedCandidate
                                                        }
                                                        onValueChange={
                                                            setSelectedCandidate
                                                        }
                                                        disabled={
                                                            candidatePending ||
                                                            candidates.data
                                                                .length === 0
                                                        }
                                                    >
                                                        <SelectTrigger id="group-member-candidate">
                                                            <SelectValue
                                                                placeholder={t(
                                                                    'groups.selectMemberPlaceholder',
                                                                )}
                                                            />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {candidates.data.map(
                                                                (candidate) => (
                                                                    <SelectItem
                                                                        key={
                                                                            candidate.id
                                                                        }
                                                                        value={String(
                                                                            candidate.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            candidate.name
                                                                        }{' '}
                                                                        —{' '}
                                                                        {
                                                                            candidate.email
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="new-group-member-role">
                                                        {t('groups.role')}
                                                    </Label>
                                                    <Select
                                                        value={selectedNewRole}
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            setNewRole(
                                                                value as GroupRole,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger id="new-group-member-role">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {assignableRoles.map(
                                                                (role) => (
                                                                    <SelectItem
                                                                        key={
                                                                            role
                                                                        }
                                                                        value={
                                                                            role
                                                                        }
                                                                    >
                                                                        {
                                                                            roleLabels[
                                                                                role
                                                                            ]
                                                                        }
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <Button
                                                    type="button"
                                                    onClick={addMember}
                                                    disabled={
                                                        memberPending !==
                                                            null ||
                                                        selectedCandidate === ''
                                                    }
                                                >
                                                    {memberPending === 'add' ? (
                                                        <Spinner
                                                            aria-label={t(
                                                                'common.loading',
                                                            )}
                                                        />
                                                    ) : (
                                                        <UserPlus />
                                                    )}
                                                    {t('groups.addMember')}
                                                </Button>
                                            </div>

                                            <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                                <span>
                                                    {t(
                                                        'groups.candidateCount',
                                                        {
                                                            count: candidates
                                                                .meta.total,
                                                        },
                                                    )}
                                                </span>
                                                <div className="flex items-center gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            loadGroupData(
                                                                members.meta
                                                                    .current_page,
                                                                candidates.meta
                                                                    .current_page -
                                                                    1,
                                                            )
                                                        }
                                                        disabled={
                                                            candidatePending ||
                                                            candidates.meta
                                                                .current_page <=
                                                                1
                                                        }
                                                        aria-label={t(
                                                            'queries.previous',
                                                        )}
                                                    >
                                                        <ChevronLeft />
                                                    </Button>
                                                    <span>
                                                        {t('queries.page')}{' '}
                                                        {
                                                            candidates.meta
                                                                .current_page
                                                        }{' '}
                                                        /{' '}
                                                        {
                                                            candidates.meta
                                                                .last_page
                                                        }
                                                    </span>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            loadGroupData(
                                                                members.meta
                                                                    .current_page,
                                                                candidates.meta
                                                                    .current_page +
                                                                    1,
                                                            )
                                                        }
                                                        disabled={
                                                            candidatePending ||
                                                            candidates.meta
                                                                .current_page >=
                                                                candidates.meta
                                                                    .last_page
                                                        }
                                                        aria-label={t(
                                                            'queries.next',
                                                        )}
                                                    >
                                                        <ChevronRight />
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    <section className="space-y-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <h3 className="font-semibold">
                                                {t('groups.membersTitle')}
                                            </h3>
                                            <Badge variant="secondary">
                                                {members.meta.total}
                                            </Badge>
                                        </div>

                                        {members.data.length === 0 ? (
                                            <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                                {t('groups.noMembers')}
                                            </p>
                                        ) : (
                                            <ul className="divide-y">
                                                {members.data.map((member) => (
                                                    <li
                                                        key={member.id}
                                                        className="grid gap-3 py-4 md:grid-cols-[minmax(0,1fr)_11rem_auto] md:items-center"
                                                    >
                                                        <div className="min-w-0">
                                                            <p className="truncate font-medium">
                                                                {member.name}
                                                            </p>
                                                            <p className="truncate text-sm text-muted-foreground">
                                                                {member.email}
                                                            </p>
                                                        </div>
                                                        {member.can_update_role ? (
                                                            <Select
                                                                value={
                                                                    member.role
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    updateMemberRole(
                                                                        member,
                                                                        value as GroupRole,
                                                                    )
                                                                }
                                                                disabled={
                                                                    memberPending !==
                                                                    null
                                                                }
                                                            >
                                                                <SelectTrigger
                                                                    aria-label={t(
                                                                        'groups.roleFor',
                                                                        {
                                                                            name: member.name,
                                                                        },
                                                                    )}
                                                                >
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {assignableRoles.map(
                                                                        (
                                                                            role,
                                                                        ) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    role
                                                                                }
                                                                                value={
                                                                                    role
                                                                                }
                                                                            >
                                                                                {
                                                                                    roleLabels[
                                                                                        role
                                                                                    ]
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className="w-fit"
                                                            >
                                                                {
                                                                    roleLabels[
                                                                        member
                                                                            .role
                                                                    ]
                                                                }
                                                            </Badge>
                                                        )}
                                                        <div className="flex justify-end">
                                                            {member.can_remove && (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        removeMember(
                                                                            member,
                                                                        )
                                                                    }
                                                                    disabled={
                                                                        memberPending !==
                                                                        null
                                                                    }
                                                                    className="text-destructive hover:text-destructive"
                                                                >
                                                                    {memberPending ===
                                                                    member.id ? (
                                                                        <Spinner
                                                                            aria-label={t(
                                                                                'common.loading',
                                                                            )}
                                                                        />
                                                                    ) : (
                                                                        <Trash2 />
                                                                    )}
                                                                    {t(
                                                                        'groups.removeMember',
                                                                    )}
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}

                                        <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                                            <span>
                                                {t('groups.memberCount', {
                                                    count: members.meta.total,
                                                })}
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        loadGroupData(
                                                            members.meta
                                                                .current_page -
                                                                1,
                                                            candidates.meta
                                                                .current_page,
                                                        )
                                                    }
                                                    disabled={
                                                        candidatePending ||
                                                        members.meta
                                                            .current_page <= 1
                                                    }
                                                    aria-label={t(
                                                        'queries.previous',
                                                    )}
                                                >
                                                    <ChevronLeft />
                                                </Button>
                                                <span>
                                                    {t('queries.page')}{' '}
                                                    {members.meta.current_page}{' '}
                                                    / {members.meta.last_page}
                                                </span>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        loadGroupData(
                                                            members.meta
                                                                .current_page +
                                                                1,
                                                            candidates.meta
                                                                .current_page,
                                                        )
                                                    }
                                                    disabled={
                                                        candidatePending ||
                                                        members.meta
                                                            .current_page >=
                                                            members.meta
                                                                .last_page
                                                    }
                                                    aria-label={t(
                                                        'queries.next',
                                                    )}
                                                >
                                                    <ChevronRight />
                                                </Button>
                                            </div>
                                        </div>
                                    </section>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>

            <Dialog
                open={dialogMode !== null}
                onOpenChange={(open) => !open && setDialogMode(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {dialogMode === 'edit'
                                ? t('groups.editTitle')
                                : t('groups.createTitle')}
                        </DialogTitle>
                        <DialogDescription>
                            {dialogMode === 'edit'
                                ? t('groups.editDescription')
                                : t('groups.createDescription')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={saveGroup} className="space-y-4">
                        {dialogError && (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {dialogError}
                            </p>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="group-name">
                                {t('groups.name')}
                            </Label>
                            <Input
                                id="group-name"
                                value={groupName}
                                onChange={(event) =>
                                    setGroupName(event.target.value)
                                }
                                placeholder={t('groups.namePlaceholder')}
                                maxLength={100}
                                required
                                autoFocus
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="group-description">
                                {t('groups.descriptionLabel')}
                            </Label>
                            <Textarea
                                id="group-description"
                                value={groupDescription}
                                onChange={(event) =>
                                    setGroupDescription(event.target.value)
                                }
                                placeholder={t('groups.descriptionPlaceholder')}
                                maxLength={1000}
                                rows={3}
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDialogMode(null)}
                                disabled={groupPending}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    groupPending || groupName.trim() === ''
                                }
                            >
                                {groupPending && (
                                    <Spinner aria-label={t('common.loading')} />
                                )}
                                {dialogMode === 'edit'
                                    ? t('common.save')
                                    : t('groups.create')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

GroupsIndex.layout = {
    breadcrumbs: [{ title: 'Groupes', href: groupRoutes.index.url() }],
};
