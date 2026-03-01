export type User = {
    id: number;
    name: string;
    email: string;
    phone_number?: string | null;
    avatar?: string | null;
    jabatan?: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type ActiveRole = {
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

export type ActiveWorkspace = {
    id: number;
    name: string;
    description: string | null;
};

export type WorkspaceGroup = {
    workspace: ActiveWorkspace;
    roles: ActiveRole[];
};

export type Auth = {
    user: User;
    activeRole?: ActiveRole | null;
    activeWorkspace?: ActiveWorkspace | null;
    permissions: string[];
    workspaces: WorkspaceGroup[];
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
