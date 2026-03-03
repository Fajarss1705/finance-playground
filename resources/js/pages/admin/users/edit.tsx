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
};

type UserData = {
    id: number;
    name: string;
    email: string;
    phone_number: string | null;
    jabatan: string | null;
    roles: RoleItem[];
};

type Props = {
    user: UserData;
    roles: RoleItem[];
};

export default function UsersEdit({ user, roles }: Props) {
    const [selectedRoleIds, setSelectedRoleIds] = useState<number[]>(
        user.roles.map((r) => r.id),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Manajemen', href: adminIndex() },
        { title: 'Pengguna', href: usersIndex() },
        { title: 'Edit', href: UserController.edit(user.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Pengguna" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Edit Pengguna"
                    description={`Edit pengguna "${user.name}"`}
                />
                <Form
                    {...UserController.update.form(user.id)}
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
                                    defaultValue={user.name}
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
                                    defaultValue={user.email}
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
                                    placeholder="Kosongkan jika tidak ingin mengubah password"
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
                                    defaultValue={user.phone_number ?? ''}
                                    placeholder="Nomor telepon (opsional)"
                                />
                                <InputError message={errors.phone_number} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="jabatan">Jabatan</Label>
                                <Input
                                    id="jabatan"
                                    name="jabatan"
                                    defaultValue={user.jabatan ?? ''}
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
                                                    className="flex items-center gap-2"
                                                >
                                                    <Checkbox
                                                        id={`role-${role.id}`}
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
                                                    <Label
                                                        htmlFor={`role-${role.id}`}
                                                        className="font-normal"
                                                    >
                                                        {role.name}
                                                    </Label>
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
