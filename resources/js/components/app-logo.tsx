export default function AppLogo() {
    return (
        <>
            {/* Expanded: full logo */}
            <img
                src="/images/app-logo.png"
                alt="Finance Playground"
                className="h-12 w-full object-cover object-[center_45%] brightness-0 group-data-[collapsible=icon]:hidden dark:invert"
            />
            {/* Collapsed: square favicon */}
            <img
                src="/favicon.svg"
                alt="Finance Playground"
                className="hidden size-8 group-data-[collapsible=icon]:block"
            />
        </>
    );
}
