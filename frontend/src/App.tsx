import { useTheme } from './hooks/useTheme'
import { useViewport } from './hooks/useViewport'
import { ApiErrorHandler } from './components/ApiErrorHandler'
import './App.css'

function App() {
  const { theme, toggleTheme } = useTheme()
  const viewport = useViewport()

  return (
    <div className="app-shell" data-testid="app-shell" data-viewport={viewport}>
      <ApiErrorHandler />
      <header className="app-header">
        <a className="brand" href="/">EnerDiag</a>
        <button type="button" className="theme-toggle" onClick={toggleTheme}>
          Tema {theme === 'light' ? 'escuro' : 'claro'}
        </button>
      </header>
      <main id="center">
        <h1>EnerDiag</h1>
        <p>Simule o potencial de economia da sua empresa.</p>
      </main>
      <footer className="app-footer">Eficiência energética para sua empresa</footer>
    </div>
  )
}

export default App
