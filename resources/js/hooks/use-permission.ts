import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types';

export function usePermission() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const permissions = auth.permissions ?? [];

    return {
        can: (permission: string) => permissions.includes(permission),
        canAny: (perms: string[]) => perms.some((p) => permissions.includes(p)),
    };
}
