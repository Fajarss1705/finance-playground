import { Head, router } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/SwitcherController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AuthLayout from '@/layouts/auth-layout';

type Role = {
    id: number;
    name: string;
    team: {
        id: number;
        name: string;
        organization: {
            id: number;
            name: string;
        };
    };
};

type Workspace = {
    id: number;
    name: string;
    description: string | null;
};

type WorkspaceGroup = {
    workspace: Workspace;
    roles: Role[];
};

type Props = {
    workspaces: WorkspaceGroup[];
};

export default function RoleSelector({ workspaces }: Props) {
    const handleSelect = (roleId: number, workspaceId: number) => {
        router.post(store.url(), {
            role_id: roleId,
            workspace_id: workspaceId,
        });
    };

    return (
        <AuthLayout
            title="Pilih Role"
            description="Pilih workspace dan role untuk melanjutkan"
        >
            <Head title="Pilih Role" />

            <div className="flex flex-col gap-4">
                {workspaces.map(({ workspace, roles }) => (
                    <Card key={workspace.id}>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">{workspace.name}</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            {Object.entries(
                                roles.reduce<Record<string, Role[]>>((groups, role) => {
                                    const key = role.team.name;
                                    (groups[key] ??= []).push(role);
                                    return groups;
                                }, {}),
                            ).map(([teamName, teamRoles]) => (
                                <div key={teamName} className="flex flex-col gap-1.5 rounded-lg border bg-muted/30 p-3 dark:bg-muted/20">
                                    <div className="text-xs font-medium text-muted-foreground">{teamName}</div>
                                    {teamRoles.map((role) => (
                                        <Button
                                            key={role.id}
                                            variant="outline"
                                            className="h-auto justify-start px-3 py-2 text-left"
                                            onClick={() => handleSelect(role.id, workspace.id)}
                                        >
                                            <div>
                                                <div className="font-medium">{role.name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {role.team.organization.name}
                                                </div>
                                            </div>
                                        </Button>
                                    ))}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AuthLayout>
    );
}
