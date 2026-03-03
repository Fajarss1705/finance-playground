import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import TeamController from '@/actions/App/Http/Controllers/Admin/TeamController';
import { index as teamsIndex } from '@/routes/admin/teams';
import { dashboard } from '@/routes';

type Organization = {
    id: number;
    name: string;
};

type Props = {
    organizations: Organization[];
    selectedOrganizationId: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Tim', href: teamsIndex() },
    { title: 'Tambah', href: TeamController.create() },
];

export default function TeamsCreate({
    organizations,
    selectedOrganizationId,
}: Props) {
    const [organizationId, setOrganizationId] = useState(
        selectedOrganizationId ?? '',
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tambah Tim" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Tambah Tim"
                    description="Buat tim baru dalam organisasi"
                />
                <Form
                    {...TeamController.store.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="organization_id"
                                value={organizationId}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="organization_id">
                                    Organisasi
                                </Label>
                                <Select
                                    value={organizationId}
                                    onValueChange={setOrganizationId}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Pilih organisasi" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {organizations.map((org) => (
                                            <SelectItem
                                                key={org.id}
                                                value={org.id.toString()}
                                            >
                                                {org.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={errors.organization_id}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="Nama tim"
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
                                    <Link href={teamsIndex().url}>Batal</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </AppLayout>
    );
}
