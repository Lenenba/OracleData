import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { QueryForm } from '@/components/queries/query-form';
import queries from '@/routes/queries';

export default function CreateQuery() {
    return (
        <>
            <Head title="Nouvelle requête" />

            <div className="px-4 py-6">
                <Heading
                    title="Nouvelle requête"
                    description="Enregistrez une requête Oracle Fusion à réutiliser et partager."
                />

                <Form {...queries.store.form()} className="max-w-2xl">
                    {({ processing, errors }) => (
                        <QueryForm
                            errors={errors}
                            processing={processing}
                            submitLabel="Enregistrer"
                        />
                    )}
                </Form>
            </div>
        </>
    );
}

CreateQuery.layout = {
    breadcrumbs: [
        { title: 'Requêtes', href: queries.index() },
        { title: 'Nouvelle requête', href: queries.create() },
    ],
};
