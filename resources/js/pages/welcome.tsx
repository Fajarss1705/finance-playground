import { Head, Link, usePage } from '@inertiajs/react';
import { login, register } from '@/routes';
import { index as personalIndex } from '@/routes/personal';

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Selamat Datang" />
            <div className="flex min-h-screen flex-col bg-linear-to-b from-blue-950 via-blue-900 to-blue-950 text-white">
                {/* Header */}
                <header className="flex w-full items-center justify-between px-6 py-4 lg:px-12">
                    <div className="flex items-center gap-3">
                        <img
                            src="/images/app-logo.png"
                            alt="Finance Playground"
                            className="h-8 brightness-0 invert"
                        />
                    </div>
                    <nav className="flex items-center gap-3">
                        {auth.user ? (
                            <Link
                                href={personalIndex()}
                                className="rounded-lg bg-white/10 px-5 py-2 text-sm font-medium backdrop-blur-sm transition hover:bg-white/20"
                            >
                                Dashboard
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="rounded-lg px-5 py-2 text-sm font-medium transition hover:bg-white/10"
                                >
                                    Masuk
                                </Link>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="rounded-lg bg-white px-5 py-2 text-sm font-medium text-blue-950 transition hover:bg-blue-50"
                                    >
                                        Daftar
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </header>

                {/* Hero Section */}
                <main className="flex flex-1 flex-col items-center justify-center px-6 text-center">
                    <div className="mx-auto max-w-2xl">
                        <div className="mb-8 flex justify-center">
                            <img
                                src="/images/app-logo.png"
                                alt="Finance Playground"
                                className="h-20 brightness-0 invert lg:h-24"
                            />
                        </div>
                        <h1 className="mb-4 text-4xl font-bold tracking-tight lg:text-5xl">
                            Finance Playground
                        </h1>
                        <p className="mb-2 text-lg text-blue-200 lg:text-xl">
                            Sistem Monitoring & Evaluasi
                        </p>
                        <div className="mb-10" />

                        <div className="flex flex-col items-center justify-center gap-3 sm:flex-row">
                            {auth.user ? (
                                <Link
                                    href={personalIndex()}
                                    className="inline-flex items-center gap-2 rounded-lg bg-white px-8 py-3 text-sm font-semibold text-blue-950 shadow-lg transition hover:bg-blue-50"
                                >
                                    Buka Dashboard
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                    </svg>
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="inline-flex items-center gap-2 rounded-lg bg-white px-8 py-3 text-sm font-semibold text-blue-950 shadow-lg transition hover:bg-blue-50"
                                    >
                                        Masuk
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                        </svg>
                                    </Link>
                                    {canRegister && (
                                        <Link
                                            href={register()}
                                            className="inline-flex items-center rounded-lg border border-white/30 px-8 py-3 text-sm font-semibold text-white transition hover:bg-white/10"
                                        >
                                            Daftar Akun
                                        </Link>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                </main>

                {/* Footer */}
                <footer className="px-6 py-6 text-center text-xs text-blue-400">
                    <p>&copy; {new Date().getFullYear()} Finance Playground</p>
                </footer>
            </div>
        </>
    );
}
