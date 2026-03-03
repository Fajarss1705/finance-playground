import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceController from '@/actions/App/Http/Controllers/Admin/WorkspaceController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import { index as workspacesIndex } from '@/routes/admin/workspaces';
import type { BreadcrumbItem } from '@/types';

type Organization = {
    id: number;
    name: string;
};

type Props = {
    organizations: Organization[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Workspace', href: workspacesIndex() },
    { title: 'Tambah', href: WorkspaceController.create() },
];

export default function WorkspacesCreate({ organizations }: Props) {
    const [selectedOrgIds, setSelectedOrgIds] = useState<number[]>([]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tambah Workspace" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Tambah Workspace"
                    description="Buat workspace baru"
                />
                <Form
                    {...WorkspaceController.store.form()}
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
                                    placeholder="Nama workspace"
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
                            <div className="grid gap-2">
                                <Label>Organisasi</Label>
                                <div className="rounded-lg border p-4">
                                    {organizations.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            Belum ada organisasi.
                                        </p>
                                    ) : (
                                        <div className="space-y-3">
                                            {organizations.map((org) => (
                                                <div
                                                    key={org.id}
                                                    className="flex items-center gap-2"
                                                >
                                                    <Checkbox
                                                        id={`org-${org.id}`}
                                                        checked={selectedOrgIds.includes(
                                                            org.id,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) => {
                                                            setSelectedOrgIds(
                                                                (prev) =>
                                                                    checked
                                                                        ? [
                                                                              ...prev,
                                                                              org.id,
                                                                          ]
                                                                        : prev.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  org.id,
                                                                          ),
                                                            );
                                                        }}
                                                    />
                                                    <Label
                                                        htmlFor={`org-${org.id}`}
                                                        className="font-normal"
                                                    >
                                                        {org.name}
                                                    </Label>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <InputError
                                    message={errors.organization_ids}
                                />
                            </div>
                            {selectedOrgIds.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="organization_ids[]"
                                    value={id}
                                />
                            ))}
                            <div className="flex gap-4">
                                <Button disabled={processing}>Simpan</Button>
                                <Button variant="secondary" asChild>
                                    <Link href={workspacesIndex().url}>
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
