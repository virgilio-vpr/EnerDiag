import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import App from '../App'

function setViewportWidth(width: number) {
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: width })
  fireEvent(window, new Event('resize'))
}

afterEach(() => setViewportWidth(1024))

describe('responsive layout', () => {
  it.each([
    [375, 'mobile'],
    [768, 'tablet'],
    [1280, 'desktop'],
  ])('renders at %s px as %s layout', (width, viewport) => {
    setViewportWidth(width as number)
    render(<App />)

    expect(screen.getByTestId('app-shell')).toHaveAttribute('data-viewport', viewport)
    expect(screen.getByRole('heading', { name: 'EnerDiag' })).toBeInTheDocument()
  })
})
