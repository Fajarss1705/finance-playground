import { Form, Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';
import TeamController from '@/actions/App/Http/Controllers/Admin/TeamController';
import { Can } from '@/components/can';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { index as adminIndex } from '@/routes/admin';
import { index as teamsIndex } from '@/routes/admin/teams';
import type { BreadcrumbItem } from '@/types';

type TeamRow = {
    id: number;
    name: string;
    organization: { name: string } | null;
    deleted_at: string;
};

type PaginatedTeams = {
    data: TeamRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Props = {
    teams: PaginatedTeams;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Manajemen', href: adminIndex() },
    { title: 'Tim', href: teamsIndex() },
    { title: 'Sampah', href: TeamController.trash() },
];

export default function TeamsTrash({ teams }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sampah Tim" />
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Sampah Tim"
                        description="Data tim yang telah dihapus"
                    />
                    <Button variant="outline" asChild>
                        <Link href={teamsIndex().url} prefetch>
                            Kembali
                        </Link>
                    </Button>
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
                                    Dihapus pada
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
                                        colSpan={4}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        <Users className="mx-auto mb-2 h-8 w-8 opacity-50" />
                                        Tidak ada data di sampah.
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
                                            {team.organization?.name || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {new Date(
                                                team.deleted_at,
                                            ).toLocaleDateString('id-ID', {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Can permission="admin.teams.restore">
                                                <Form
                                                    {...TeamController.restore.form(
                                                        team.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                            asChild
                                                        >
                                                            <button type="submit">
                                                                Pulihkan
                                                            </button>
                                                        </Button>
                                                    )}
                                                </Form>
                                            </Can>
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
