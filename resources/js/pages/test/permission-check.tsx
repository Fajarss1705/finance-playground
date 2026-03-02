import { Head } from '@inertiajs/react';
import { Can } from '@/components/can';
import { usePermission } from '@/hooks/use-permission';
import AppLayout from '@/layouts/app-layout';

export default function PermissionCheck() {
    const { can, canAny } = usePermission();

    return (
        <AppLayout>
            <Head title="Test: Permission Check" />
            <div className="mx-auto max-w-2xl space-y-6 p-6">
                <h1 className="text-2xl font-bold">Test: Can Component & usePermission Hook</h1>

                {/* Section 1: Can component with single permission */}
                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="font-semibold">1. Can Component — Single Permission</h2>

                    <Can permission="dashboard">
                        <div className="rounded bg-green-100 p-3 text-green-800 dark:bg-green-900 dark:text-green-200">
                            [VISIBLE] Kamu punya permission: <code>dashboard</code>
                        </div>
                    </Can>

                    <Can permission="profile.edit">
                        <div className="rounded bg-green-100 p-3 text-green-800 dark:bg-green-900 dark:text-green-200">
                            [VISIBLE] Kamu punya permission: <code>profile.edit</code>
                        </div>
                    </Can>

                    <Can permission="profile.destroy">
                        <div className="rounded bg-green-100 p-3 text-green-800 dark:bg-green-900 dark:text-green-200">
                            [VISIBLE] Kamu punya permission: <code>profile.destroy</code>
                        </div>
                    </Can>
                </section>

                {/* Section 2: Can component with fallback */}
                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="font-semibold">2. Can Component — Dengan Fallback</h2>

                    <Can
                        permission="profile.destroy"
                        fallback={
                            <div className="rounded bg-red-100 p-3 text-red-800 dark:bg-red-900 dark:text-red-200">
                                [FALLBACK] Kamu TIDAK punya permission: <code>profile.destroy</code>
                            </div>
                        }
                    >
                        <div className="rounded bg-green-100 p-3 text-green-800 dark:bg-green-900 dark:text-green-200">
                            [VISIBLE] Kamu punya permission: <code>profile.destroy</code>
                        </div>
                    </Can>

                    <Can
                        permission="non.existent.permission"
                        fallback={
                            <div className="rounded bg-red-100 p-3 text-red-800 dark:bg-red-900 dark:text-red-200">
                                [FALLBACK] Kamu TIDAK punya permission: <code>non.existent.permission</code>
                            </div>
                        }
                    >
                        <div className="rounded bg-green-100 p-3 text-green-800 dark:bg-green-900 dark:text-green-200">
                            [VISIBLE] Kamu punya permission: <code>non.existent.permission</code>
                        </div>
                    </Can>
                </section>

                {/* Section 3: usePermission hook */}
                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="font-semibold">3. usePermission Hook — can() & canAny()</h2>

                    <div className="space-y-1 text-sm">
                        <div>
                            <code>can('dashboard')</code>: <Badge value={can('dashboard')} />
                        </div>
                        <div>
                            <code>can('profile.edit')</code>: <Badge value={can('profile.edit')} />
                        </div>
                        <div>
                            <code>can('profile.destroy')</code>: <Badge value={can('profile.destroy')} />
                        </div>
                        <div>
                            <code>can('non.existent')</code>: <Badge value={can('non.existent')} />
                        </div>
                        <div className="pt-2">
                            <code>canAny(['dashboard', 'profile.edit'])</code>: <Badge value={canAny(['dashboard', 'profile.edit'])} />
                        </div>
                        <div>
                            <code>canAny(['non.existent', 'also.fake'])</code>: <Badge value={canAny(['non.existent', 'also.fake'])} />
                        </div>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}

function Badge({ value }: { value: boolean }) {
    return (
        <span className={`ml-2 inline-block rounded px-2 py-0.5 text-xs font-semibold ${value ? 'bg-green-200 text-green-800' : 'bg-red-200 text-red-800'}`}>
            {value ? 'true' : 'false'}
        </span>
    );
}
