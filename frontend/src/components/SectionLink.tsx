import { useSyncExternalStore, type AnchorHTMLAttributes } from 'react'

function subscribe(listener: () => void) {
  window.addEventListener('popstate', listener)
  return () => window.removeEventListener('popstate', listener)
}

export function SectionLink({ href, children, className = '', onClick, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) {
  const pathname = useSyncExternalStore(subscribe, () => window.location.pathname)
  const url = href ? new URL(href, window.location.href) : null
  const internal = url?.origin === window.location.origin && !url.pathname.startsWith('/api/') && !props.download && (!props.target || props.target === '_self')
  const current = internal && url.pathname === pathname && url.search === window.location.search && !url.hash

  if (current) return <button type="button" className={`${className} current-section`} disabled aria-current="page" aria-label={props['aria-label']} title={props.title}>{children}</button>

  return <a {...props} href={href} className={className} onClick={(event) => {
    onClick?.(event)
    if (!internal || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    window.history.pushState({}, '', url.pathname + url.search + url.hash)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }}>{children}</a>
}
