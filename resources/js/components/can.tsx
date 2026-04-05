import type { ReactNode } from 'react';
import { usePermission } from '@/hooks/use-permission';

type Props = {
    permission: string;
    children: ReactNode;
    fallback?: ReactNode;
};

export function Can({ permission, children, fallback = null }: Props) {
    const { can } = usePermission();

    return can(permission) ? <>{children}</> : <>{fallback}</>;
}
