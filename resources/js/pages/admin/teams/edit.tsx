import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import TeamController from '@/actions/App/Http/Controllers/Admin/TeamController';
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
import { dashboard } from '@/routes';
import { index as teamsIndex } from '@/routes/admin/teams';
import type { BreadcrumbItem } from '@/types';

type Organization = {
    id: number;
    name: string;
};

type Team = {
    id: number;
    name: string;
    description: string | null;
    organization_id: number;
    organization: Organization;
};

type Props = {
    team: Team;
    organizations: Organization[];
};

export default function TeamsEdit({ team, organizations }: Props) {
    const [organizationId, setOrganizationId] = useState(
        team.organization_id.toString(),
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Tim', href: teamsIndex() },
        { title: 'Edit', href: TeamController.edit(team.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Tim" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <Heading
                    title="Edit Tim"
                    description={`Edit tim "${team.name}"`}
                />
                <Form
                    {...TeamController.update.form(team.id)}
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
                                    defaultValue={team.name}
                                    placeholder="Nama tim"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">Deskripsi</Label>
                                <Input
                                    id="description"
                                    name="description"
                                    defaultValue={team.description ?? ''}
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
