import { Head, Link } from '@inertiajs/react';
import { Database, Plus, Server, Zap } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import oracleTenants from '@/routes/oracle-tenants';
import queries from '@/routes/queries';

type Stats = {
    totalQueries: number;
    myQueries: number;
    activeTenants: number;
};

type RecentQuery = {
    id: number;
    name: string;
    description: string | null;
    mode: 'single' | 'agent';
    tenant_label: string | null;
    visibility: 'private' | 'shared';
    owner: string;
    can: { update: boolean };
};

type TenantDetail = {
    key: string;
    label: string;
    base_url: string;
    username: string;
    source: 'config' | 'database';
    is_default: boolean;
    is_active: boolean;
};

type DashboardProps = {
    stats: Stats;
    recentQueries: RecentQuery[];
    tenants: TenantDetail[];
};

export default function Dashboard({
    stats,
    recentQueries,
    tenants,
}: DashboardProps) {
    return (
        <>
            <Head title="Tableau de bord" />

            <div className="space-y-6 px-4 py-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">
                            Tableau de bord
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Vue d'ensemble de vos requêtes et environnements
                            Oracle.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={queries.create()}>
                            <Plus className="size-4" />
                            Nouvelle requête
                        </Link>
                    </Button>
                </div>

                {/* KPIs */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Requêtes accessibles
                            </CardTitle>
                            <Database className="size-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.totalQueries}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Vos requêtes + requêtes partagées
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Mes requêtes
                            </CardTitle>
                            <Zap className="size-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.myQueries}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Requêtes créées par vous
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">
                                Tenants Oracle
                            </CardTitle>
                            <Server className="size-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {stats.activeTenants}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Environnements configurés
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Requêtes récentes */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Requêtes récentes</CardTitle>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={queries.index()}>
                                        Voir tout
                                    </Link>
                                </Button>
                            </div>
                            <CardDescription>
                                Les 5 dernières requêtes accessibles
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recentQueries.length === 0 ? (
                                <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                                    Aucune requête pour l'instant.
                                    <div className="mt-3">
                                        <Button asChild variant="outline" size="sm">
                                            <Link href={queries.create()}>
                                                Créer ma première requête
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <ul className="divide-y">
                                    {recentQueries.map((query) => (
                                        <li key={query.id}>
                                            <Link
                                                href={queries.show(query.id)}
                                                className="flex items-center justify-between gap-3 px-6 py-3 hover:bg-muted/40"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium">
                                                        {query.name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {query.tenant_label ??
                                                            'Aucun tenant'}{' '}
                                                        · {query.owner}
                                                    </p>
                                                </div>
                                                <div className="flex shrink-0 gap-1">
                                                    <Badge
                                                        variant="outline"
                                                        className="text-xs"
                                                    >
                                                        {query.mode === 'agent'
                                                            ? 'Analyse'
                                                            : 'Requête'}
                                                    </Badge>
                                                    {query.visibility ===
                                                        'shared' && (
                                                        <Badge className="text-xs">
                                                            Partagée
                                                        </Badge>
                                                    )}
                                                </div>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    {/* Tenants */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Environnements Oracle</CardTitle>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={oracleTenants.index()}>
                                        Gérer
                                    </Link>
                                </Button>
                            </div>
                            <CardDescription>
                                Connexions Oracle Fusion disponibles
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            {tenants.length === 0 ? (
                                <div className="px-6 py-10 text-center text-sm text-muted-foreground">
                                    Aucun tenant configuré.
                                    <div className="mt-3">
                                        <Button asChild variant="outline" size="sm">
                                            <Link href={oracleTenants.index()}>
                                                Ajouter un tenant
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <ul className="divide-y">
                                    {tenants.map((tenant) => (
                                        <li
                                            key={tenant.key}
                                            className="flex items-center justify-between gap-3 px-6 py-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {tenant.label}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {tenant.base_url || '—'}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 gap-1">
                                                <span
                                                    className={`inline-block size-2 rounded-full ${
                                                        tenant.is_active
                                                            ? 'bg-emerald-500'
                                                            : 'bg-red-400'
                                                    }`}
                                                    title={
                                                        tenant.is_active
                                                            ? 'Actif'
                                                            : 'Inactif'
                                                    }
                                                />
                                                {tenant.is_default && (
                                                    <Badge className="text-xs">
                                                        Défaut
                                                    </Badge>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Tableau de bord',
            href: dashboard(),
        },
    ],
};
