import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Outlet } from 'react-router'
import { Button, Icon } from '@/components/ds'
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { NotificationBell } from './NotificationBell'
import { LogoMark, Sidebar } from './Sidebar'

/**
 * Back office layout (design system, Layout): a 232px sidebar and the
 * content on surface-100, at most 1280px wide with space-10 padding. Below
 * 768px the sidebar becomes a top bar whose menu button opens it in a sheet.
 */
export function AppShell() {
  const { t } = useTranslation()
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <div className="flex min-h-screen bg-surface-100 text-body text-ink">
      <aside className="sticky top-0 hidden h-screen w-sidebar shrink-0 border-r border-sidebar-border md:block">
        <Sidebar />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 flex h-12 items-center gap-3 border-b border-sidebar-border bg-sidebar px-4 md:hidden">
          <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
            <Button
              variant="ghost"
              aria-label={t('shell.openMenu')}
              aria-expanded={menuOpen}
              onClick={() => setMenuOpen(true)}
              className="size-icon-btn px-0 text-sidebar-ink hover:bg-sidebar-active hover:text-sidebar-ink-active"
            >
              <Icon name="menu" size={18} />
            </Button>
            <SheetContent
              side="left"
              showCloseButton={false}
              className="gap-0 border-sidebar-border bg-sidebar p-0 text-body data-[side=left]:w-sidebar"
            >
              <SheetTitle className="sr-only">{t('shell.menu')}</SheetTitle>
              <SheetDescription className="sr-only">{t('shell.menuDescription')}</SheetDescription>
              <SheetClose asChild>
                <Button
                  variant="ghost"
                  aria-label={t('shell.closeMenu')}
                  className="absolute top-3 right-3 z-10 size-icon-btn px-0 text-sidebar-ink hover:bg-sidebar-active hover:text-sidebar-ink-active"
                >
                  <Icon name="x" size={18} />
                </Button>
              </SheetClose>
              <Sidebar onNavigate={() => setMenuOpen(false)} showBell={false} />
            </SheetContent>
          </Sheet>
          <LogoMark />
          <span className="min-w-0 flex-1 truncate text-h3 text-sidebar-ink-active">{t('app.name')}</span>
          <NotificationBell />
        </header>

        <main className="min-w-0 flex-1 px-4 py-6 md:p-10">
          <div className="mx-auto flex max-w-content flex-col gap-6">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
