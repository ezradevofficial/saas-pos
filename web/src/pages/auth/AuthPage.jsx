import { useEffect, useRef } from 'react'
import { Link } from 'react-router'
import { Alert } from '@/components/ds'
import { usePageTitle } from '@/lib/usePageTitle'
import { cn } from '@/lib/utils'

/** Title, short intro, the form card and links underneath. */
export function AuthPage({ title, intro, children, footer }) {
  usePageTitle(title)
  return (
    <>
      <div className="flex flex-col gap-1">
        <h1 className="text-h1 text-ink">{title}</h1>
        {intro ? <p className="text-body text-ink-muted">{intro}</p> : null}
      </div>
      <div className="rounded-lg border border-border bg-surface-200 p-5">{children}</div>
      {footer ? <div className="flex flex-col gap-2 text-body text-ink-muted">{footer}</div> : null}
    </>
  )
}

/**
 * A form that leaves validation to the API, so messages are the translated
 * ones. After a failed submit (`failure`, the mutation error) focus moves to
 * the first invalid field, or to the alert when only the form has an error,
 * so keyboard and screen-reader users land on what needs fixing.
 */
export function AuthForm({ onSubmit, error, failure, children }) {
  const formRef = useRef(null)
  const alertRef = useRef(null)

  useEffect(() => {
    if (!failure) return
    const invalid = formRef.current?.querySelector('[aria-invalid="true"]')
    if (invalid) invalid.focus()
    else alertRef.current?.focus()
  }, [failure])

  return (
    <form
      ref={formRef}
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        onSubmit()
      }}
      className="flex flex-col gap-4"
    >
      {error ? (
        <div ref={alertRef} tabIndex={-1} className="rounded-md">
          <Alert tone="danger" title={error} />
        </div>
      ) : null}
      {children}
    </form>
  )
}

export function TextLink({ className, ...props }) {
  return <Link className={cn('font-medium text-primary hover:text-primary-hover', className)} {...props} />
}
