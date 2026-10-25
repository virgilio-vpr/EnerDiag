import { useTheme } from './hooks/useTheme'
import { useViewport } from './hooks/useViewport'
import { ApiErrorHandler } from './components/ApiErrorHandler'
import energyIllustration from './assets/energy-efficiency.png'
import './App.css'

function App() {
  const { theme, toggleTheme } = useTheme()
  const viewport = useViewport()

  return (
    <div className="app-shell" data-testid="app-shell" data-viewport={viewport}>
      <ApiErrorHandler />
      <header className="app-header">
        <a className="brand" href="/">Ener<span>Diag</span></a>
        <button
          type="button"
          className="theme-toggle"
          aria-label={`Tema ${theme === 'light' ? 'escuro' : 'claro'}`}
          aria-pressed={theme === 'dark'}
          title={`Ativar tema ${theme === 'light' ? 'escuro' : 'claro'}`}
          onClick={toggleTheme}
        >
          <span className="theme-toggle__thumb" aria-hidden="true" />
          <svg className="theme-toggle__icon theme-toggle__sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" />
          </svg>
          <svg className="theme-toggle__icon theme-toggle__moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M20.9 13.2A9 9 0 0 1 10.8 3.1a9 9 0 1 0 10.1 10.1Z" />
          </svg>
        </button>
      </header>
      <main id="center">
        <div className="hero-copy">
          <p className="hero-eyebrow">Inteligência para sua energia</p>
          <h1>EnerDiag</h1>
          <p className="hero-description">Simule o potencial de economia da sua empresa.</p>
        </div>
        <div className="hero-artwork">
          <img
            src={energyIllustration}
            alt="Ilustração de uma instalação industrial com transformador, medidor de energia e gráficos de consumo."
            width="1774"
            height="887"
            fetchPriority="high"
          />
        </div>
      </main>
      <footer className="app-footer">Eficiência energética para sua empresa</footer>
    </div>
  )
}

export default App
