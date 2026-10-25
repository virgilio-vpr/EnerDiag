/// <reference types="node" />

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import App from '../App'

const themeStyles = readFileSync('src/index.css', 'utf8')

function contrastRatio(foreground: string, background: string) {
  function luminance(color: string) {
    const channels = color.match(/[\da-f]{2}/gi)?.map((channel) => parseInt(channel, 16) / 255) ?? []
    const [red, green, blue] = channels.map((channel) => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
    return 0.2126 * red! + 0.7152 * green! + 0.0722 * blue!
  }
  const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a)
  return (values[0]! + 0.05) / (values[1]! + 0.05)
}

describe('theme', () => {
  it('toggles and saves the selected theme', async () => {
    localStorage.clear()
    const { unmount } = render(<App />)

    fireEvent.click(screen.getByRole('button', { name: 'Tema escuro' }))

    await waitFor(() => expect(localStorage.getItem('enerdiag-theme')).toBe('dark'))
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(screen.getByRole('button', { name: 'Tema claro' })).toHaveAttribute('aria-pressed', 'true')

    unmount()
    render(<App />)
    expect(screen.getByRole('button', { name: 'Tema claro' })).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'Tema claro' }))
    await waitFor(() => expect(localStorage.getItem('enerdiag-theme')).toBe('light'))
    expect(screen.getByRole('button', { name: 'Tema escuro' })).toHaveAttribute('aria-pressed', 'false')
  })

  it('uses text and accent colors that meet WCAG AA on both surfaces', () => {
    for (const theme of ['light', 'dark']) {
      const palette = themeStyles.split(`:root[data-theme="${theme}"] {`)[1]!.split('}')[0]!
      function color(token: string) {
        return palette.match(new RegExp(`--${token}: (#[\\da-f]+);`))![1]!
      }

      for (const token of ['text', 'text-h', 'accent', 'secondary']) {
        for (const surface of ['bg', 'code-bg']) {
          expect(contrastRatio(color(token), color(surface))).toBeGreaterThanOrEqual(4.5)
        }
      }

      expect(contrastRatio(color('toggle-icon'), color('toggle-track'))).toBeGreaterThanOrEqual(3)
      expect(contrastRatio(color(theme === 'light' ? 'toggle-sun' : 'toggle-moon'), color('toggle-active'))).toBeGreaterThanOrEqual(3)
    }
  })
})
