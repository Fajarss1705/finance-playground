export default function AppLogo() {
    return (
        <>
            {/*
              Expanded: full logo. h-8 w-auto, not h-12 w-full object-cover —
              the old pair cropped it twice over. object-cover fills the box and
              throws away the overflow, and the box was taller than the space it
              sat in: SidebarMenuButton size="lg" is h-12 with p-2 and
              overflow-hidden, so 32px of room for a 48px image. Sizing to the
              content box and letting the width follow the 227x77 aspect ratio
              means nothing is cropped by either.
            */}
            <img
                src="/images/app-logo.png"
                alt="Finance Playground"
                className="h-8 w-auto brightness-0 group-data-[collapsible=icon]:hidden dark:invert"
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
