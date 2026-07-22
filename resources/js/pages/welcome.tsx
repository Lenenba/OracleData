import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    CheckCircle2,
    Database,
    FileBarChart2,
    Lock,
    Search,
    Server,
    Sparkles,
    Zap,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login, register } from '@/routes';

const FEATURES = [
    {
        icon: Search,
        title: 'Recherche guidée',
        desc: 'Choisissez la ressource, les colonnes et les filtres Oracle sans écrire de code.',
    },
    {
        icon: Database,
        title: 'Oracle Fusion natif',
        desc: 'Un catalogue REST intégré pour Finance, Procurement, HCM, Projets et Actifs.',
    },
    {
        icon: Zap,
        title: 'Aperçu instantané',
        desc: 'Validez le résultat en direct, exportez-le et générez le SQL BI Publisher.',
    },
    {
        icon: Server,
        title: 'Multi-environnements',
        desc: 'Pilotez production, recette et sandbox dans le même espace sécurisé.',
    },
    {
        icon: FileBarChart2,
        title: 'Bibliothèque partagée',
        desc: 'Capitalisez les requêtes utiles et collaborez avec votre équipe.',
    },
    {
        icon: Lock,
        title: 'Sécurité intégrée',
        desc: 'Lecture seule, secrets chiffrés, permissions fines, 2FA et passkeys.',
    },
];

const DOMAINS = [
    ['Procurement', 'Fournisseurs · Commandes · Contrats'],
    ['Finance', 'Factures · Journaux · Paiements'],
    ['HCM', 'Employés · Affectations · Absences'],
    ['Projets & Actifs', 'Projets · Actifs · Inventaire'],
];

function ProductPreview() {
    return (
        <div className="relative mx-auto w-full max-w-[680px] overflow-hidden rounded border border-white/10 bg-[#f6f7fb] shadow-[0_28px_80px_rgba(20,30,55,.35)]">
            <div className="flex h-[410px] sm:h-[470px]">
                <aside className="hidden w-[145px] shrink-0 bg-[#1e1f27] p-3 text-[#7a8ea7] sm:block">
                    <div className="mb-6 flex items-center gap-2 px-1 text-white">
                        <span className="grid size-7 place-items-center rounded bg-gradient-to-br from-purple to-primary">
                            <AppLogoIcon className="size-4" />
                        </span>
                        <strong className="text-sm">
                            Oracle<span className="text-[#8495ab]">Data</span>
                        </strong>
                    </div>
                    <p className="mb-2 text-[7px] font-bold tracking-widest uppercase">
                        Plateforme
                    </p>
                    {['Analytics', 'Requêtes', 'Modèles', 'Dashboards'].map(
                        (item, index) => (
                            <div
                                key={item}
                                className={`mb-1 rounded px-2 py-2 text-[9px] ${index === 0 ? 'bg-[#22232c] text-white' : ''}`}
                            >
                                {item}
                            </div>
                        ),
                    )}
                    <p className="mt-5 mb-2 text-[7px] font-bold tracking-widest uppercase">
                        Collaboration
                    </p>
                    {['Groupes', 'Notifications'].map((item) => (
                        <div
                            key={item}
                            className="mb-1 rounded px-2 py-2 text-[9px]"
                        >
                            {item}
                        </div>
                    ))}
                </aside>
                <div className="min-w-0 flex-1">
                    <div className="flex h-12 items-center justify-between border-b border-[#e7e9eb] bg-white px-4">
                        <span className="h-6 w-32 rounded-full border border-[#e7e9eb]" />
                        <div className="flex gap-2">
                            <span className="size-5 rounded-full bg-[#eef2f7]" />
                            <span className="size-5 rounded-full bg-primary/15" />
                        </div>
                    </div>
                    <div className="p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <div>
                                <strong className="block text-[12px] text-[#4c4c5c]">
                                    Analytics
                                </strong>
                                <span className="text-[8px] text-[#8a969c]">
                                    Vue d'ensemble Oracle Fusion
                                </span>
                            </div>
                            <span className="rounded bg-primary px-2 py-1 text-[8px] text-white">
                                + Nouvelle requête
                            </span>
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            {[
                                ['Requêtes', '248', 'bg-success'],
                                ['Exécutions', '1 840', 'bg-purple'],
                                ['Connexions', '6', 'bg-info'],
                            ].map(([label, value, tone]) => (
                                <div
                                    key={label}
                                    className="rounded bg-white p-3 shadow-[0_1px_4px_rgba(130,143,163,.15)]"
                                >
                                    <span className="text-[7px] font-bold text-[#8a969c] uppercase">
                                        {label}
                                    </span>
                                    <div className="mt-2 flex items-center gap-2">
                                        <span
                                            className={`size-5 rounded-full ${tone}`}
                                        />
                                        <strong className="text-sm text-[#4c4c5c]">
                                            {value}
                                        </strong>
                                    </div>
                                    <div className="mt-3 flex h-7 items-end gap-0.5">
                                        {[25, 55, 38, 76, 48, 88, 66, 100].map(
                                            (height, index) => (
                                                <span
                                                    key={index}
                                                    className="flex-1 rounded-t-sm bg-primary/60"
                                                    style={{
                                                        height: `${height}%`,
                                                    }}
                                                />
                                            ),
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="mt-2 grid grid-cols-[1.7fr_1fr] gap-2">
                            <div className="rounded bg-white shadow-[0_1px_4px_rgba(130,143,163,.15)]">
                                <div className="border-b border-dashed border-[#e7e9eb] px-3 py-2 text-[9px] font-bold text-[#4c4c5c]">
                                    Activité des requêtes
                                </div>
                                <div className="p-3">
                                    <svg
                                        viewBox="0 0 300 90"
                                        className="h-24 w-full"
                                    >
                                        <defs>
                                            <linearGradient
                                                id="welcome-chart"
                                                x1="0"
                                                y1="0"
                                                x2="0"
                                                y2="1"
                                            >
                                                <stop
                                                    offset="0"
                                                    stopColor="#236dc9"
                                                    stopOpacity=".3"
                                                />
                                                <stop
                                                    offset="1"
                                                    stopColor="#236dc9"
                                                    stopOpacity="0"
                                                />
                                            </linearGradient>
                                        </defs>
                                        <path
                                            d="M0 75 C35 70 45 28 80 44 S125 68 150 37 S200 20 220 41 S265 52 300 12 L300 90 L0 90Z"
                                            fill="url(#welcome-chart)"
                                        />
                                        <path
                                            d="M0 75 C35 70 45 28 80 44 S125 68 150 37 S200 20 220 41 S265 52 300 12"
                                            fill="none"
                                            stroke="#236dc9"
                                            strokeWidth="2"
                                        />
                                    </svg>
                                </div>
                            </div>
                            <div className="rounded bg-white shadow-[0_1px_4px_rgba(130,143,163,.15)]">
                                <div className="border-b border-dashed border-[#e7e9eb] px-3 py-2 text-[9px] font-bold text-[#4c4c5c]">
                                    Santé
                                </div>
                                <div className="grid place-items-center p-5">
                                    <div className="grid size-20 place-items-center rounded-full bg-[conic-gradient(#02bc9c_97%,#eef2f7_0)] p-2">
                                        <span className="grid size-full place-items-center rounded-full bg-white text-sm font-bold text-[#4c4c5c]">
                                            97%
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Welcome() {
    const { auth } = usePage().props;
    const primaryHref = auth.user ? dashboard() : register();

    return (
        <>
            <Head title="OracleData — Interrogez Oracle Fusion" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="sticky top-0 z-40 border-t-[3px] border-b border-border border-t-[#313a46] bg-card/95 backdrop-blur">
                    <div className="mx-auto flex h-[65px] max-w-7xl items-center justify-between px-5 lg:px-8">
                        <Link href="/" className="flex items-center gap-2.5">
                            <span className="paces-brand-mark">
                                <AppLogoIcon />
                            </span>
                            <span className="text-xl font-extrabold tracking-tight">
                                Oracle
                                <span className="text-muted-foreground">
                                    Data
                                </span>
                            </span>
                        </Link>
                        <nav className="flex items-center gap-2">
                            {!auth.user && (
                                <Link
                                    href={login()}
                                    className="btn h-9 border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted"
                                >
                                    Se connecter
                                </Link>
                            )}
                            <Link
                                href={primaryHref}
                                className="btn h-9 bg-primary px-4 text-[13px] font-semibold text-white hover:bg-primary/90"
                            >
                                {auth.user
                                    ? 'Tableau de bord'
                                    : 'Créer un compte'}{' '}
                                <ArrowRight className="size-3.5" />
                            </Link>
                        </nav>
                    </div>
                </header>

                <main>
                    <section className="relative overflow-hidden bg-[#313a46] py-20 text-white lg:py-24">
                        <div className="absolute inset-0 [background-image:radial-gradient(circle_at_20%_20%,#7b70ef_0,transparent_32%),radial-gradient(circle_at_85%_65%,#236dc9_0,transparent_35%)] opacity-20" />
                        <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-5 lg:grid-cols-[.85fr_1.15fr] lg:px-8">
                            <div>
                                <span className="mb-5 inline-flex items-center gap-2 rounded bg-white/10 px-3 py-1.5 text-[11px] font-bold tracking-wide text-white/80 uppercase">
                                    <Sparkles className="size-3.5 text-warning" />{' '}
                                    Oracle intelligence workspace
                                </span>
                                <h1 className="text-4xl leading-tight font-semibold tracking-tight text-white sm:text-5xl">
                                    Vos données Oracle,
                                    <br />
                                    <span className="text-[#8cc7ff]">
                                        enfin faciles à explorer.
                                    </span>
                                </h1>
                                <p className="mt-6 max-w-xl text-[15px] leading-7 text-white/70">
                                    Construisez, exécutez et partagez des
                                    requêtes Oracle Fusion dans une plateforme
                                    claire, sécurisée et pensée pour toute
                                    l'équipe.
                                </p>
                                <div className="mt-8 flex flex-wrap gap-3">
                                    <Link
                                        href={primaryHref}
                                        className="btn h-11 bg-primary px-6 text-sm font-bold text-white hover:bg-[#1d5eae]"
                                    >
                                        {auth.user
                                            ? 'Ouvrir Analytics'
                                            : 'Commencer gratuitement'}{' '}
                                        <ArrowRight className="size-4" />
                                    </Link>
                                    {!auth.user && (
                                        <Link
                                            href={login()}
                                            className="btn h-11 border border-white/25 bg-white/5 px-6 text-sm font-bold text-white hover:bg-white/10"
                                        >
                                            J'ai déjà un compte
                                        </Link>
                                    )}
                                </div>
                                <div className="mt-8 flex flex-wrap gap-x-5 gap-y-2 text-xs text-white/65">
                                    {[
                                        'Lecture seule',
                                        'Secrets chiffrés',
                                        'Multi-tenant',
                                    ].map((item) => (
                                        <span
                                            key={item}
                                            className="flex items-center gap-1.5"
                                        >
                                            <CheckCircle2 className="size-3.5 text-success" />{' '}
                                            {item}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <ProductPreview />
                        </div>
                    </section>

                    <section className="border-b border-border bg-card">
                        <div className="mx-auto grid max-w-7xl grid-cols-2 divide-x divide-y divide-border px-5 sm:grid-cols-4 sm:divide-y-0 lg:px-8">
                            {DOMAINS.map(([title, text]) => (
                                <div key={title} className="px-5 py-6">
                                    <p className="text-[13px] font-bold">
                                        {title}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {text}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="mx-auto max-w-7xl px-5 py-20 lg:px-8">
                        <div className="mx-auto mb-10 max-w-2xl text-center">
                            <span className="text-[11px] font-bold tracking-widest text-primary uppercase">
                                Une plateforme complète
                            </span>
                            <h2 className="mt-2 text-3xl font-semibold">
                                Tout ce qu'il faut pour exploiter Oracle Fusion
                            </h2>
                            <p className="mt-3 text-sm leading-6 text-muted-foreground">
                                Une interface Paces cohérente, du premier
                                environnement Oracle au dashboard partagé.
                            </p>
                        </div>
                        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                            {FEATURES.map(
                                ({ icon: Icon, title, desc }, index) => (
                                    <article key={title} className="card group">
                                        <div className="card-body">
                                            <span
                                                className={`grid size-11 place-items-center rounded-full ${['bg-primary/15 text-primary', 'bg-purple/15 text-purple', 'bg-success/15 text-success', 'bg-info/15 text-info', 'bg-warning/15 text-warning', 'bg-destructive/15 text-destructive'][index]}`}
                                            >
                                                <Icon className="size-5" />
                                            </span>
                                            <h3 className="mt-4 text-base font-semibold group-hover:text-primary">
                                                {title}
                                            </h3>
                                            <p className="mt-2 text-[13px] leading-6 text-muted-foreground">
                                                {desc}
                                            </p>
                                        </div>
                                    </article>
                                ),
                            )}
                        </div>
                    </section>

                    <section className="bg-primary py-14 text-white">
                        <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-6 px-5 text-center md:flex-row md:text-left">
                            <div>
                                <h2 className="text-2xl font-semibold text-white">
                                    Prêt à piloter vos données Oracle ?
                                </h2>
                                <p className="mt-2 text-sm text-white/75">
                                    Créez votre espace et connectez votre
                                    premier environnement en quelques minutes.
                                </p>
                            </div>
                            <Link
                                href={primaryHref}
                                className="btn h-11 shrink-0 bg-white px-6 text-sm font-bold text-primary hover:bg-white/90"
                            >
                                Accéder à OracleData{' '}
                                <BarChart3 className="size-4" />
                            </Link>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-border bg-card">
                    <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-5 py-6 text-xs text-muted-foreground sm:flex-row lg:px-8">
                        <span className="flex items-center gap-2">
                            <AppLogoIcon className="size-4" /> OracleData ·
                            Oracle Fusion REST
                        </span>
                        <span>
                            © {new Date().getFullYear()} · Données protégées
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}
