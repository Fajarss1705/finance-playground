import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import AlertError from '@/components/alert-error';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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

type Team = {
    id: number;
    name: string;
    description: string | null;
    roles_count: number;
    organization: Organization;
};

type PaginatedTeams = {
    data: Team[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    teams: PaginatedTeams;
    organizations: Organization[];
    filters: {
        organization_id: string | null;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
    { title: 'Tim', href: teamsIndex() },
];

export default function TeamsIndex({ teams, organizations, filters }: Props) {
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    function handleOrganizationFilter(value: string) {
        const params =
            value === 'all' ? {} : { organization_id: value };
        router.get(teamsIndex().url, params, { preserveState: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tim" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading title="Tim" description="Kelola data tim" />
                    <Can permission="admin.teams.create">
                        <Button asChild>
                            <Link
                                href={TeamController.create().url}
                                prefetch
                            >
                                Tambah Tim
                            </Link>
                        </Button>
                    </Can>
                </div>

                {errors.delete && (
                    <AlertError
                        errors={[errors.delete]}
                        title="Gagal menghapus"
                    />
                )}

                <div className="flex items-center gap-4">
                    <Select
                        value={filters.organization_id ?? 'all'}
                        onValueChange={handleOrganizationFilter}
                    >
                        <SelectTrigger className="w-64">
                            <SelectValue placeholder="Filter organisasi" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                Semua Organisasi
                            </SelectItem>
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
                </div>

                <div className="rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted/50">
                                <th className="px-4 py-3 text-left font-medium">
                                    Nama
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Organisasi
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Deskripsi
                                </th>
                                <th className="px-4 py-3 text-center font-medium">
                                    Jumlah Role
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {teams.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <Users className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Belum ada tim.
                                    </td>
                                </tr>
                            ) : (
                                teams.data.map((team) => (
                                    <tr
                                        key={team.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {team.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {team.organization.name}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {team.description || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Badge variant="secondary">
                                                {team.roles_count}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Can permission="admin.teams.edit">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={TeamController.edit(
                                                                team.id,
                                                            ).url}
                                                            prefetch
                                                        >
                                                            Edit
                                                        </Link>
                                                    </Button>
                                                </Can>
                                                <Can permission="admin.teams.destroy">
                                                    <DeleteTeamDialog
                                                        team={team}
                                                    />
                                                </Can>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination
                    links={teams.links}
                    currentPage={teams.current_page}
                    lastPage={teams.last_page}
                    total={teams.total}
                />
            </div>
        </AppLayout>
    );
}

function DeleteTeamDialog({ team }: { team: Team }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Hapus
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Hapus Tim</DialogTitle>
                <DialogDescription>
                    Apakah Anda yakin ingin menghapus tim &quot;{team.name}
                    &quot;? Tindakan ini tidak dapat dibatalkan.
                </DialogDescription>
                <Form
                    {...TeamController.destroy.form(team.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Batal</Button>
                            </DialogClose>
                            <Button
                                variant="destructive"
                                disabled={processing}
                                asChild
                            >
                                <button type="submit">Hapus</button>
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
