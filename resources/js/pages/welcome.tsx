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
                        <p className="mx-auto mb-10 max-w-lg text-sm leading-relaxed text-blue-300">
                            Aplikasi pengelolaan program kerja, anggaran, pencairan dana, dan laporan bulanan Finance Playground.
                        </p>

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

                    {/* Feature Cards */}
                    <div className="mx-auto mt-16 grid w-full max-w-4xl grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <FeatureCard
                            title="Program Kerja"
                            description="Penyusunan & pengesahan program kerja tahunan"
                            icon={
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15a2.25 2.25 0 012.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z" />
                                </svg>
                            }
                        />
                        <FeatureCard
                            title="Anggaran"
                            description="Pengelolaan kegiatan dan anggaran per divisi"
                            icon={
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" />
                                </svg>
                            }
                        />
                        <FeatureCard
                            title="Pencairan"
                            description="Proses pencairan dana bulanan per kegiatan"
                            icon={
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            }
                        />
                        <FeatureCard
                            title="Laporan"
                            description="Pelaporan realisasi & evaluasi kegiatan bulanan"
                            icon={
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                                </svg>
                            }
                        />
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

function FeatureCard({
    title,
    description,
    icon,
}: {
    title: string;
    description: string;
    icon: React.ReactNode;
}) {
    return (
        <div className="rounded-xl border border-white/10 bg-white/5 p-5 text-left backdrop-blur-sm transition hover:bg-white/10">
            <div className="mb-3 text-blue-300">{icon}</div>
            <h3 className="mb-1 text-sm font-semibold">{title}</h3>
            <p className="text-xs leading-relaxed text-blue-300">{description}</p>
        </div>
    );
}
