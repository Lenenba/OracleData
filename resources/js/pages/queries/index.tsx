import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import queries from '@/routes/queries';

type QueryRow = {
    id: number;
    name: string;
    description: string | null;
    resource_path: string;
    visibility: 'private' | 'shared';
    owner: string;
    can: { update: boolean };
};

export default function QueriesIndex({
    queries: rows,
}: {
    queries: QueryRow[];
}) {
    return (
        <>
            <Head title="Requêtes" />

            <div className="space-y-6 px-4 py-6">
                <div className="flex items-center justify-between gap-4">
                    <Heading
                        title="Bibliothèque de requêtes"
                        description="Vos requêtes enregistrées et celles partagées avec vous."
                    />
                    <Button asChild>
                        <Link href={queries.create()}>
                            <Plus className="size-4" />
                            Nouvelle requête
                        </Link>
                    </Button>
                </div>

                {rows.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <p className="text-sm text-muted-foreground">
                            Aucune requête pour l'instant.
                        </p>
                        <Button asChild className="mt-4" variant="outline">
                            <Link href={queries.create()}>
                                Créer ma première requête
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Nom
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Chemin REST
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Visibilité
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Propriétaire
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((query) => (
                                    <tr
                                        key={query.id}
                                        className="hover:bg-muted/40"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {query.name}
                                            </div>
                                            {query.description && (
                                                <div className="text-xs text-muted-foreground">
                                                    {query.description}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <code className="text-xs">
                                                {query.resource_path}
                                            </code>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant={
                                                    query.visibility ===
                                                    'shared'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {query.visibility === 'shared'
                                                    ? 'Partagée'
                                                    : 'Privée'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {query.owner}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={queries.show(
                                                        query.id,
                                                    )}
                                                >
                                                    Exécuter
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

QueriesIndex.layout = {
    breadcrumbs: [{ title: 'Requêtes', href: queries.index() }],
};
