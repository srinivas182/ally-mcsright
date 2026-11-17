import { Head, usePage } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { EasierReadingPanel } from '@/components/reading/easier-reading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const reading = usePage().props.reading;

    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Appearance settings"
                    description="Light or dark, and easier reading: bigger text, stronger contrast, underlined links and less movement."
                />
                <AppearanceTabs />
                {reading && <EasierReadingPanel reading={reading} />}
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
