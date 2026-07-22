import { Head } from '@inertiajs/react';
import { Palette } from 'lucide-react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Paramètres d'apparence" />
            <h1 className="sr-only">Paramètres d'apparence</h1>
            <section className="card">
                <div className="card-header">
                    <Heading
                        variant="small"
                        title="Apparence de l'interface"
                        description="Choisissez le thème utilisé sur l'ensemble de la plateforme."
                    />
                </div>
                <div className="card-body">
                    <div className="mb-5 flex items-center gap-3 rounded bg-primary/10 p-4 text-primary">
                        <Palette className="size-5 shrink-0" />
                        <p className="text-[13px]">
                            Le mode système suit automatiquement les préférences
                            d'affichage de votre appareil.
                        </p>
                    </div>
                    <AppearanceTabs />
                </div>
            </section>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [{ title: 'Apparence', href: editAppearance() }],
};
