import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex items-center justify-center">
                <AppLogoIcon className="h-8 brightness-0 dark:invert" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    Finance Playground
                </span>
            </div>
        </>
    );
}
