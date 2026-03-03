import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { index as adminIndex } from '@/routes/admin';
import { index as rolesIndex } from '@/routes/admin/roles';
import type { BreadcrumbItem } from '@/types';

type Team = {
    id: number;
    name: string;
};

type PermissionItem = {
    id: number;
    name: string;
};

type Props = {
    teams: Team[];
    permissions: PermissionItem[];
    selectedTeamId: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Role', href: rolesIndex() },
    { title: 'Tambah', href: RoleController.create() },
];

export default function RolesCreate({
    teams,
    permissions,
    selectedTeamId,
}: Props) {
    const [teamId, setTeamId] = useState(selectedTeamId ?? '');
    const [selectedPermissionIds, setSelectedPermissionIds] = useState<
        number[]
    >([]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tambah Role" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Tambah Role"
                    description="Buat role baru dalam tim"
                />
                <Form
                    {...RoleController.store.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="team_id"
                                value={teamId}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="team_id">Tim</Label>
                                <Select
                                    value={teamId}
                                    onValueChange={setTeamId}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Pilih tim" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {teams.map((team) => (
                                            <SelectItem
                                                key={team.id}
                                                value={team.id.toString()}
                                            >
                                                {team.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.team_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    placeholder="Nama role"
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
                                <Label>Permission</Label>
                                <div className="max-h-64 overflow-y-auto rounded-lg border p-4">
                                    {permissions.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            Belum ada permission.
                                        </p>
                                    ) : (
                                        <div className="space-y-3">
                                            {permissions.map((perm) => (
                                                <div
                                                    key={perm.id}
                                                    className="flex items-center gap-2"
                                                >
                                                    <Checkbox
                                                        id={`perm-${perm.id}`}
                                                        checked={selectedPermissionIds.includes(
                                                            perm.id,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) => {
                                                            setSelectedPermissionIds(
                                                                (prev) =>
                                                                    checked
                                                                        ? [
                                                                              ...prev,
                                                                              perm.id,
                                                                          ]
                                                                        : prev.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  perm.id,
                                                                          ),
                                                            );
                                                        }}
                                                    />
                                                    <Label
                                                        htmlFor={`perm-${perm.id}`}
                                                        className="font-normal"
                                                    >
                                                        {perm.name}
                                                    </Label>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <InputError
                                    message={errors.permission_ids}
                                />
                            </div>
                            {selectedPermissionIds.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="permission_ids[]"
                                    value={id}
                                />
                            ))}
                            <div className="flex gap-4">
                                <Button disabled={processing}>Simpan</Button>
                                <Button variant="secondary" asChild>
                                    <Link href={rolesIndex().url}>Batal</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </AppLayout>
    );
}
