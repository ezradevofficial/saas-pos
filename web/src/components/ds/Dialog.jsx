import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Dialog as DialogPrimitive, DialogClose, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { Button } from './Button'
import { Icon } from './Icon'

const PANEL = 'flex max-h-dialog flex-col gap-0 rounded-lg border border-border bg-surface-200 p-0 text-body text-ink shadow-lg ring-0'
const WIDTH = { md: 'sm:max-w-md', lg: 'sm:max-w-2xl' }

// Forwards props so DialogClose asChild can attach its close handler.
function CloseButton(props) {
  const { t } = useTranslation()
  return (
    <Button variant="ghost" {...props} aria-label={t('ds.dialog.close')} className="size-icon-btn shrink-0 px-0">
      <Icon name="x" size={18} />
    </Button>
  )
}

function Body({ children, footer }) {
  return (
    <>
      <div className="min-h-0 flex-1 overflow-auto px-5 pb-5 text-ink-muted">{children}</div>
      {footer ? <footer className="flex flex-wrap justify-end gap-2 border-t border-border px-5 py-3">{footer}</footer> : null}
    </>
  )
}

/**
 * A focused window for a decision. Built on the shadcn (Radix) dialog, which
 * traps focus, closes on Escape and labels the dialog with its title.
 */
export function Dialog({ open, title, onClose, footer, children, size = 'md', inline = false }) {
  const titleId = useId()

  if (inline) {
    if (!open) return null
    return (
      <div role="dialog" aria-labelledby={titleId} className={cn(PANEL, 'w-full max-w-md', size === 'lg' && 'max-w-2xl')}>
        <header className="flex items-center justify-between gap-4 px-5 pt-5 pb-2">
          <h2 id={titleId} className="text-h2 text-ink">
            {title}
          </h2>
          {onClose ? <CloseButton onClick={onClose} /> : null}
        </header>
        <Body footer={footer}>{children}</Body>
      </div>
    )
  }

  return (
    <DialogPrimitive open={open} onOpenChange={(next) => (next ? undefined : onClose?.())}>
      <DialogContent showCloseButton={false} aria-describedby={undefined} className={cn(PANEL, WIDTH[size] ?? WIDTH.md)}>
        <header className="flex items-center justify-between gap-4 px-5 pt-5 pb-2">
          <DialogTitle className="text-h2 text-ink">{title}</DialogTitle>
          {onClose ? (
            <DialogClose asChild>
              <CloseButton />
            </DialogClose>
          ) : null}
        </header>
        <Body footer={footer}>{children}</Body>
      </DialogContent>
    </DialogPrimitive>
  )
}
