import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import { index as usersIndex } from '@/routes/admin/users';
import type { BreadcrumbItem } from '@/types';

type RoleItem = {
    id: number;
    name: string;
    team: { id: number; name: string } | null;
};

type Props = {
    roles: RoleItem[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Pengguna', href: usersIndex() },
    { title: 'Tambah', href: UserController.create() },
];

export default function UsersCreate({ roles }: Props) {
    const [selectedRoleIds, setSelectedRoleIds] = useState<number[]>([]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tambah Pengguna" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Tambah Pengguna"
                    description="Buat pengguna baru"
                />
                <Form
                    {...UserController.store.form()}
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
                                    placeholder="Nama pengguna"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    required
                                    placeholder="Email pengguna"
                                />
                                <InputError message={errors.email} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    placeholder="Password"
                                />
                                <InputError message={errors.password} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Konfirmasi Password
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    required
                                    placeholder="Konfirmasi password"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="phone_number">
                                    Nomor Telepon
                                </Label>
                                <Input
                                    id="phone_number"
                                    name="phone_number"
                                    placeholder="Nomor telepon (opsional)"
                                />
                                <InputError message={errors.phone_number} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="jabatan">Jabatan</Label>
                                <Input
                                    id="jabatan"
                                    name="jabatan"
                                    placeholder="Jabatan (opsional)"
                                />
                                <InputError message={errors.jabatan} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Role</Label>
                                <div className="max-h-64 overflow-y-auto rounded-lg border p-4">
                                    {roles.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            Belum ada role.
                                        </p>
                                    ) : (
                                        <div className="space-y-3">
                                            {roles.map((role) => (
                                                <div
                                                    key={role.id}
                                                    className="flex items-start gap-2"
                                                >
                                                    <Checkbox
                                                        id={`role-${role.id}`}
                                                        className="mt-0.5"
                                                        checked={selectedRoleIds.includes(
                                                            role.id,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) => {
                                                            setSelectedRoleIds(
                                                                (prev) =>
                                                                    checked
                                                                        ? [
                                                                              ...prev,
                                                                              role.id,
                                                                          ]
                                                                        : prev.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  role.id,
                                                                          ),
                                                            );
                                                        }}
                                                    />
                                                    <div className="grid">
                                                        <Label
                                                            htmlFor={`role-${role.id}`}
                                                            className="font-normal"
                                                        >
                                                            {role.name}
                                                        </Label>
                                                        {role.team && (
                                                            <span className="text-xs text-muted-foreground">
                                                                {
                                                                    role.team
                                                                        .name
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <InputError message={errors.role_ids} />
                            </div>
                            {selectedRoleIds.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="role_ids[]"
                                    value={id}
                                />
                            ))}
                            <div className="flex gap-4">
                                <Button disabled={processing}>Simpan</Button>
                                <Button variant="secondary" asChild>
                                    <Link href={usersIndex().url}>Batal</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </AppLayout>
    );
}
