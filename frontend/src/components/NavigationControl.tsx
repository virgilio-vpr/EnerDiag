import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from 'react'

type Direction = 'back' | 'forward'
type NavigationOptions = { direction?: Direction; variant?: 'secondary' | 'primary' }

export function NavigationArrow({ direction = 'forward' }: { direction?: Direction }) {
  return <span className="navigation-control__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={direction === 'back' ? 'M19 12H5m7-7-7 7 7 7' : 'M5 12h14m-7-7 7 7-7 7'} /></svg></span>
}

function NavigationContent({ direction, children }: { direction: Direction; children: ReactNode }) {
  return <>{direction === 'back' && <NavigationArrow direction="back" />}<span className="navigation-control__label">{children}</span>{direction === 'forward' && <NavigationArrow />}</>
}

function navigationClass(direction: Direction, variant: 'secondary' | 'primary', className: string) {
  return `button ${variant === 'secondary' ? 'button--secondary' : ''} navigation-control navigation-control--${direction} ${variant === 'primary' ? 'navigation-control--primary' : ''} ${className}`.trim()
}

export function NavigationLink({ direction = 'forward', variant = 'secondary', className = '', children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & NavigationOptions) {
  return <a {...props} className={navigationClass(direction, variant, className)}><NavigationContent direction={direction}>{children}</NavigationContent></a>
}

export function NavigationButton({ direction = 'forward', variant = 'secondary', className = '', children, type = 'button', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & NavigationOptions) {
  return <button {...props} type={type} className={navigationClass(direction, variant, className)}><NavigationContent direction={direction}>{children}</NavigationContent></button>
}
