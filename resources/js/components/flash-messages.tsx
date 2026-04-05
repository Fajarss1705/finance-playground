import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

type PageProps = {
    flash: {
        success?: string | null;
        error?: string | null;
    };
};

export default function FlashMessages() {
    const { flash } = usePage<PageProps>().props;
    const lastFlash = useRef<{ success?: string | null; error?: string | null }>({});

    useEffect(() => {
        if (flash?.success && flash.success !== lastFlash.current.success) {
            toast.success(flash.success);
        }
        if (flash?.error && flash.error !== lastFlash.current.error) {
            toast.error(flash.error);
        }
        lastFlash.current = { success: flash?.success, error: flash?.error };
    }, [flash]);

    return null;
}
