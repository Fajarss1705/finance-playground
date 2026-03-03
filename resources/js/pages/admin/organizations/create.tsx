import { Form, Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import OrganizationController from '@/actions/App/Http/Controllers/Admin/OrganizationController';
import { index as organizationsIndex } from '@/routes/admin/organizations';
import { dashboard } from '@/routes';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Organisasi', href: organizationsIndex() },
    { title: 'Tambah', href: OrganizationController.create() },
];

export default function OrganizationsCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tambah Organisasi" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Tambah Organisasi"
                    description="Buat organisasi baru"
                />
                <Form
                    {...OrganizationController.store.form()}
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
                                    placeholder="Nama organisasi"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">Deskripsi</Label>
                                <Input
                                    id="description"
                                    name="description"
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
