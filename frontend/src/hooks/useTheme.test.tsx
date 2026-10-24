import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import App from '../App'

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
    render(<App />)

    fireEvent.click(screen.getByRole('button', { name: 'Tema escuro' }))

    await waitFor(() => expect(localStorage.getItem('enerdiag-theme')).toBe('dark'))
    expect(document.documentElement.dataset.theme).toBe('dark')
  })

  it('uses text and accent colors that meet WCAG AA on both surfaces', () => {
    expect(contrastRatio('#172033', '#f8fafc')).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio('#6d28d9', '#f8fafc')).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio('#f8fafc', '#202833')).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio('#c4b5fd', '#202833')).toBeGreaterThanOrEqual(4.5)
  })
})
