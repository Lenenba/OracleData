import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageSwitcher } from '@/components/language-switcher';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="min-h-screen bg-background">
            <div className="flex min-h-screen w-full">
                <section className="relative flex min-h-screen min-w-full flex-col justify-between overflow-hidden bg-card p-8 shadow-[var(--surface-shadow)] sm:p-[50px] md:max-w-[472px] md:min-w-[424px]">
                    <div className="pointer-events-none absolute top-0 right-0 size-48 rounded-bl-[100%] bg-gradient-to-bl from-primary/15 via-purple/10 to-transparent" />

                    <div className="relative z-10 flex items-center justify-between">
                        <Link
                            href={home()}
                            className="flex items-center gap-2.5"
                        >
                            <span className="paces-brand-mark">
                                <AppLogoIcon aria-hidden="true" />
                            </span>
                            <span className="text-xl font-extrabold tracking-tight text-foreground">
                                Oracle
                                <span className="text-muted-foreground">
                                    Data
                                </span>
                            </span>
                        </Link>
                        <LanguageSwitcher compact />
                    </div>

                    <div className="relative z-10 my-10">
                        <div className="mb-7 text-center">
                            <h1 className="mb-2 text-lg font-bold text-foreground">
                                {title}
                            </h1>
                            <p className="mx-auto max-w-sm text-[13px] leading-5 text-muted-foreground">
                                {description}
                            </p>
                        </div>

                        {children}
                    </div>

                    <p className="relative z-10 text-center text-xs text-muted-foreground">
                        © {new Date().getFullYear()} OracleData
                    </p>
                </section>

                <aside className="relative hidden min-h-screen flex-1 overflow-hidden bg-[#313a46] md:block">
                    <div
                        className="absolute inset-0 bg-cover bg-center bg-no-repeat"
                        style={{
                            backgroundImage: "url('/images/paces/auth.jpg')",
                        }}
                    />
                    <div className="absolute inset-0 flex items-end bg-gradient-to-t from-[#313a46] via-[#313a46]/35 to-transparent p-10 lg:p-14">
                        <div className="max-w-xl text-white">
                            <p className="mb-3 text-[11px] font-bold tracking-[0.14em] text-white/70 uppercase">
                                Oracle intelligence workspace
                            </p>
                            <h2 className="text-3xl font-semibold text-white lg:text-4xl">
                                Interrogez Oracle Fusion avec clarté.
                            </h2>
                            <p className="mt-4 max-w-lg text-sm leading-6 text-white/75">
                                Des requêtes sécurisées, des analyses lisibles
                                et des décisions plus rapides dans un seul
                                espace.
                            </p>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    );
}
