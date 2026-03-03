import { Form, Head, Link } from '@inertiajs/react';
import OrganizationController from '@/actions/App/Http/Controllers/Admin/OrganizationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { index as organizationsIndex } from '@/routes/admin/organizations';
import type { BreadcrumbItem } from '@/types';

type Organization = {
    id: number;
    name: string;
    description: string | null;
};

type Props = {
    organization: Organization;
};

export default function OrganizationsEdit({ organization }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Organisasi', href: organizationsIndex() },
        { title: 'Edit', href: OrganizationController.edit(organization.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Organisasi" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Edit Organisasi"
                    description={`Edit organisasi "${organization.name}"`}
                />
                <Form
                    {...OrganizationController.update.form(organization.id)}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    defaultValue={organization.name}
                                    placeholder="Nama organisasi"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">Deskripsi</Label>
                                <Input
                                    id="description"
                                    name="description"
                                    defaultValue={organization.description ?? ''}
                                    placeholder="Deskripsi (opsional)"
                                />
                                <InputError message={errors.description} />
                            </div>
                            <div className="flex gap-4">
                                <Button disabled={processing}>Simpan</Button>
                                <Button variant="secondary" asChild>
                                    <Link href={organizationsIndex().url}>
                                        Batal
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </AppLayout>
    );
}
