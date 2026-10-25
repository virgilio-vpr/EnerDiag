import { useEffect, useState } from 'react'

export type Viewport = 'mobile' | 'tablet' | 'desktop'

function getViewport(width: number): Viewport {
  if (width <= 640) return 'mobile'
  if (width <= 1024) return 'tablet'
  return 'desktop'
}

export function useViewport() {
  const [viewport, setViewport] = useState<Viewport>(() => getViewport(window.innerWidth))

  useEffect(() => {
    function updateViewport() {
      setViewport(getViewport(window.innerWidth))
    }

    window.addEventListener('resize', updateViewport)
    return () => window.removeEventListener('resize', updateViewport)
  }, [])

  return viewport
}
