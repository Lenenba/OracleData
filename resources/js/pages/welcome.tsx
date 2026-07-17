import { Head, Link, usePage } from '@inertiajs/react';
import { Database, FileBarChart2, Lock, Search, Server, Zap } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login } from '@/routes';
import { register } from '@/routes';

const FEATURES = [
    {
        icon: Search,
        title: 'Recherche guidée',
        desc: 'Wizard en 3 étapes : choisissez la ressource, sélectionnez les colonnes, filtrez par nom, numéro, statut ou date — sans écrire une ligne de code.',
    },
    {
        icon: Database,
        title: 'Oracle Fusion natif',
        desc: 'Fournisseurs, factures, bons de commande, employés, projets, paiements et bien plus. Catalogue de ressources REST Oracle intégré.',
    },
    {
        icon: Zap,
        title: 'Aperçu instantané',
        desc: 'Testez votre requête en direct avant de l\'enregistrer. Résultats en tableau, export CSV, SQL BIP prêt à coller dans BI Publisher.',
    },
    {
        icon: Server,
        title: 'Multi-tenant',
        desc: 'Gérez plusieurs environnements Oracle (production, recette, sandbox) et basculez d\'un tenant à l\'autre en un clic.',
    },
    {
        icon: FileBarChart2,
        title: 'Bibliothèque partagée',
        desc: 'Enregistrez vos requêtes, partagez-les avec votre équipe, ré-exécutez-les à tout moment.',
    },
    {
        icon: Lock,
        title: 'Sécurité by design',
        desc: 'Lecture seule. Identifiants chiffrés. Connexion par mot de passe ou passkey. Accès limité à vos données.',
    },
];

const DOMAINS = [
    { label: 'Procurement', emoji: '🛒', items: ['Fournisseurs', 'Bons de commande', 'Contrats', 'Réceptions'] },
    { label: 'Finance', emoji: '💰', items: ['Factures AP', 'Factures AR', 'Journaux GL', 'Paiements'] },
    { label: 'HCM', emoji: '👥', items: ['Employés', 'Affectations', 'Paie', 'Absences'] },
    { label: 'Projets & Actifs', emoji: '📦', items: ['Projets PPM', 'Actifs fixes', 'Stock', 'Inventaire'] },
];

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="OracleData — Interrogez Oracle Fusion" />

            <div className="min-h-screen bg-white text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">

                {/* ── Navbar ── */}
                <header className="border-b border-[#e3e3e0] dark:border-[#2a2a27]">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                        <div className="flex items-center gap-2.5">
                            <AppLogoIcon className="size-7 fill-current" />
                            <span className="text-base font-semibold tracking-tight">
                                Oracle<span className="text-[#706f6c] dark:text-[#A1A09A]">Data</span>
                            </span>
                        </div>
                        <nav className="flex items-center gap-3">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-md bg-[#1b1b18] px-4 py-1.5 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18] dark:hover:bg-white"
                                >
                                    Tableau de bord
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="rounded-md px-4 py-1.5 text-sm text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                                    >
                                        Se connecter
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="rounded-md bg-[#1b1b18] px-4 py-1.5 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18] dark:hover:bg-white"
                                    >
                                        Créer un compte
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                {/* ── Hero ── */}
                <section className="mx-auto max-w-5xl px-6 py-20 text-center">
                    <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-[#e3e3e0] bg-[#f7f7f5] px-3 py-1 text-xs font-medium text-[#706f6c] dark:border-[#2a2a27] dark:bg-[#1a1a17] dark:text-[#A1A09A]">
                        <span className="inline-block size-1.5 rounded-full bg-emerald-500" />
                        Oracle Fusion REST · Lecture seule · Multi-tenant
                    </div>
                    <h1 className="mb-5 text-4xl font-bold tracking-tight sm:text-5xl lg:text-6xl">
                        Interrogez Oracle Fusion<br />
                        <span className="text-[#706f6c] dark:text-[#A1A09A]">sans écrire de code</span>
                    </h1>
                    <p className="mx-auto mb-8 max-w-2xl text-lg text-[#706f6c] dark:text-[#A1A09A]">
                        Construisez des requêtes en quelques clics, filtrez par n'importe quel champ,
                        prévisualisez en direct et partagez avec votre équipe.
                        Fournisseurs, factures, employés, projets — tout Oracle Fusion à portée de main.
                    </p>
                    <div className="flex flex-wrap items-center justify-center gap-3">
                        {auth.user ? (
                            <>
                                <Link
                                    href={dashboard()}
                                    className="rounded-lg bg-[#1b1b18] px-6 py-2.5 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                                >
                                    Accéder au tableau de bord
                                </Link>
                            </>
                        ) : (
                            <>
                                <Link
                                    href={register()}
                                    className="rounded-lg bg-[#1b1b18] px-6 py-2.5 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                                >
                                    Commencer gratuitement
                                </Link>
                                <Link
                                    href={login()}
                                    className="rounded-lg border border-[#e3e3e0] px-6 py-2.5 text-sm font-medium text-[#1b1b18] hover:border-[#1b1b18] dark:border-[#2a2a27] dark:text-[#EDEDEC] dark:hover:border-[#EDEDEC]"
                                >
                                    Se connecter
                                </Link>
                            </>
                        )}
                    </div>
                </section>

                {/* ── Domaines couverts ── */}
                <section className="border-y border-[#e3e3e0] bg-[#f7f7f5] dark:border-[#2a2a27] dark:bg-[#111110]">
                    <div className="mx-auto max-w-5xl px-6 py-12">
                        <p className="mb-8 text-center text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]">
                            Ressources Oracle Fusion couvertes
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {DOMAINS.map((d) => (
                                <div
                                    key={d.label}
                                    className="rounded-xl border border-[#e3e3e0] bg-white p-4 dark:border-[#2a2a27] dark:bg-[#161615]"
                                >
                                    <div className="mb-3 flex items-center gap-2">
                                        <span className="text-xl">{d.emoji}</span>
                                        <span className="text-sm font-semibold">{d.label}</span>
                                    </div>
                                    <ul className="space-y-1.5">
                                        {d.items.map((item) => (
                                            <li
                                                key={item}
                                                className="flex items-center gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                            >
                                                <span className="inline-block size-1 rounded-full bg-[#c9c9c4] dark:bg-[#3a3a37]" />
                                                {item}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* ── Fonctionnalités ── */}
                <section className="mx-auto max-w-5xl px-6 py-20">
                    <h2 className="mb-12 text-center text-2xl font-bold tracking-tight">
                        Tout ce dont vous avez besoin
                    </h2>
                    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {FEATURES.map((f) => (
                            <div
                                key={f.title}
                                className="rounded-xl border border-[#e3e3e0] p-5 dark:border-[#2a2a27]"
                            >
                                <div className="mb-3 flex size-9 items-center justify-center rounded-lg border border-[#e3e3e0] bg-[#f7f7f5] dark:border-[#2a2a27] dark:bg-[#1a1a17]">
                                    <f.icon className="size-4 text-[#706f6c] dark:text-[#A1A09A]" />
                                </div>
                                <p className="mb-1.5 text-sm font-semibold">{f.title}</p>
                                <p className="text-xs leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                    {f.desc}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* ── CTA final ── */}
                <section className="border-t border-[#e3e3e0] bg-[#f7f7f5] dark:border-[#2a2a27] dark:bg-[#111110]">
                    <div className="mx-auto max-w-5xl px-6 py-16 text-center">
                        <h2 className="mb-3 text-2xl font-bold tracking-tight">
                            Prêt à interroger vos données Oracle ?
                        </h2>
                        <p className="mb-7 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                            Connectez vos tenants Oracle, créez votre première requête en moins de 2 minutes.
                        </p>
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="inline-block rounded-lg bg-[#1b1b18] px-8 py-3 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                            >
                                Accéder au tableau de bord
                            </Link>
                        ) : (
                            <Link
                                href={register()}
                                className="inline-block rounded-lg bg-[#1b1b18] px-8 py-3 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
                            >
                                Créer un compte gratuitement
                            </Link>
                        )}
                    </div>
                </section>

                {/* ── Footer ── */}
                <footer className="border-t border-[#e3e3e0] dark:border-[#2a2a27]">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
                        <div className="flex items-center gap-2">
                            <AppLogoIcon className="size-5 fill-current text-[#706f6c] dark:text-[#A1A09A]" />
                            <span className="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                                OracleData — Lecture seule, données protégées.
                            </span>
                        </div>
                        <span className="text-xs text-[#c9c9c4] dark:text-[#3a3a37]">
                            Oracle Fusion REST API
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}
