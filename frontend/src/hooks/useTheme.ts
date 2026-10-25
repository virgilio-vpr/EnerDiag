import { useEffect, useState } from 'react'

export type Theme = 'light' | 'dark'

const STORAGE_KEY = 'enerdiag-theme'

export function useTheme() {
  const [theme, setTheme] = useState<Theme>(() => {
    const storedTheme = window.localStorage.getItem(STORAGE_KEY)
    return storedTheme === 'dark' ? 'dark' : 'light'
  })

  useEffect(() => {
    document.documentElement.dataset.theme = theme
    window.localStorage.setItem(STORAGE_KEY, theme)
  }, [theme])

  function toggleTheme() {
    setTheme((currentTheme) => currentTheme === 'light' ? 'dark' : 'light')
  }

  return { theme, toggleTheme }
}
