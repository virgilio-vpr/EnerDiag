import { useState } from 'react'
import heroImg from './assets/hero.png'
import reactLogo from './assets/react.svg'
import viteLogo from './assets/vite.svg'
import { useTheme } from './hooks/useTheme'
import { ApiErrorHandler } from './components/ApiErrorHandler'
import './App.css'

function App() {
  const [count, setCount] = useState(0)
  const { theme, toggleTheme } = useTheme()

  return (
    <>
      <ApiErrorHandler />
      <button type="button" className="theme-toggle" onClick={toggleTheme}>
        Tema {theme === 'light' ? 'escuro' : 'claro'}
      </button>
      <section id="center">
        <div className="hero">
          <img src={heroImg} className="base" width="170" height="179" alt="" />
          <img src={reactLogo} className="framework" alt="React logo" />
          <img src={viteLogo} className="vite" alt="Vite logo" />
        </div>
        <div>
          <h1>EnerDiag</h1>
          <p>Simule o potencial de economia da sua empresa.</p>
        </div>
        <button
          type="button"
          className="counter"
          onClick={() => setCount((currentCount) => currentCount + 1)}
        >
          Count is {count}
        </button>
      </section>
    </>
  )
}

export default App
