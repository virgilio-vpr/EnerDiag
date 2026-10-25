import { lazy, Suspense, useEffect, useState } from 'react'
import { useTheme } from './hooks/useTheme'
import { useViewport } from './hooks/useViewport'
import { ApiErrorHandler } from './components/ApiErrorHandler'
import { InvoiceUploadPage } from './pages/InvoiceUploadPage'
import { ProfilePage } from './pages/ProfilePage'
import { ForgotPasswordPage, LoginPage, RegistrationPage, ResetPasswordPage, VerifyEmailPage } from './pages/CustomerAccessPage'
import { getAuthToken, getAuthenticatedUser } from './services/api/client'
import { ProtectedRoute } from './routes/ProtectedRoute'
import { AdminDashboardPage } from './pages/AdminDashboardPage'
import energyIllustration from './assets/energy-efficiency.png'
import './App.css'

const SimulationReportPage = lazy(() => import('./pages/SimulationReportPage').then(({ SimulationReportPage: page }) => ({ default: page })))

function App() {
  const { theme, toggleTheme } = useTheme()
  const viewport = useViewport()
  const [pathname, setPathname] = useState(window.location.pathname)

  useEffect(() => {
    function updatePath() { setPathname(window.location.pathname) }
    window.addEventListener('popstate', updatePath)
    return () => window.removeEventListener('popstate', updatePath)
  }, [])

  const isLandingPage = pathname === '/'
  const reportMatch = pathname.match(/^\/simulations\/(\d+)\/report$/)
  const isAuthenticated = Boolean(getAuthToken())

  return (
    <div className="app-shell" data-testid="app-shell" data-viewport={viewport}>
      <ApiErrorHandler />
      <header className="app-header">
        <a className="brand" href="/">Ener<span>Diag</span></a>
        <nav className="header-actions" aria-label="Acesso">
          {isAuthenticated ? <a className="header-login" href="/perfil">Meu perfil</a> : <><a className="header-login" href="/login">Entrar</a><a className="button button--small" href="/register">Criar conta</a></>}
        </nav>
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
      <main>
        {pathname === '/register' ? <RegistrationPage /> : pathname === '/verify-email' ? <VerifyEmailPage email={new URLSearchParams(window.location.search).get('email') ?? ''} /> : pathname === '/login' ? <LoginPage /> : pathname === '/forgot-password' ? <ForgotPasswordPage /> : pathname === '/reset-password' ? <ResetPasswordPage email={new URLSearchParams(window.location.search).get('email') ?? ''} token={new URLSearchParams(window.location.search).get('token') ?? ''} /> : pathname === '/admin' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminDashboardPage /></ProtectedRoute> : pathname === '/upload-faturas' ? <InvoiceUploadPage /> : pathname === '/perfil' ? <ProfilePage /> : reportMatch ? <Suspense fallback={<section className="access-page" aria-live="polite"><p>Carregando seu relatório…</p></section>}><SimulationReportPage simulationId={reportMatch[1]} /></Suspense> : isLandingPage ? <>
        <section className="hero" aria-labelledby="hero-title">
          <div className="hero-copy">
            <p className="hero-eyebrow">Inteligência para sua energia</p>
            <h1 id="hero-title">Sua energia pode trabalhar melhor pela sua empresa.</h1>
            <p className="hero-description">Descubra oportunidades de economia na sua conta de energia com uma análise clara do consumo e da demanda da sua empresa.</p>
            <a className="button" href="/register">Começar minha análise <span aria-hidden="true">→</span></a>
            <p className="hero-note">Análise guiada para empresas do Grupo A atendidas pela ENEL.</p>
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
        </section>

        <section className="landing-section process-section" aria-labelledby="process-title">
          <p className="section-eyebrow">Simples do início ao resultado</p>
          <h2 id="process-title">Como funciona</h2>
          <div className="process-grid">
            <article className="info-card">
              <div className="process-card-top"><span className="step-number step-number--tertiary">01</span><svg className="process-visual process-visual--tertiary" viewBox="0 0 120 80" fill="none" aria-hidden="true"><rect x="23" y="10" width="48" height="58" rx="8" className="process-visual__paper" /><path d="M35 28h24M35 38h24M35 48h15" className="process-visual__line" /><path d="M78 25h19a7 7 0 0 1 7 7v34a7 7 0 0 1-7 7H48" className="process-visual__outline" /><path d="m75 48 10 10 19-22" className="process-visual__accent" /></svg></div>
              <h3>Envie suas faturas</h3><p>Compartilhe de 6 a 12 contas recentes da sua empresa em PDF.</p>
            </article>
            <article className="info-card">
              <div className="process-card-top"><span className="step-number step-number--tertiary">02</span><svg className="process-visual process-visual--tertiary" viewBox="0 0 120 80" fill="none" aria-hidden="true"><rect x="17" y="12" width="86" height="56" rx="9" className="process-visual__paper" /><path d="M29 56V43M43 56V34M57 56V46M71 56V27" className="process-visual__bar" /><path d="m29 32 15-9 14 7 18-13 15 6" className="process-visual__accent" /><circle cx="91" cy="23" r="4" className="process-visual__dot" /></svg></div>
              <h3>Deixe a análise com a gente</h3><p>Avaliamos demanda, consumo e oportunidades com base nos seus dados.</p>
            </article>
            <article className="info-card">
              <div className="process-card-top"><span className="step-number step-number--tertiary">03</span><svg className="process-visual process-visual--tertiary" viewBox="0 0 120 80" fill="none" aria-hidden="true"><path d="M32 9h42l15 15v42a5 5 0 0 1-5 5H32a5 5 0 0 1-5-5V14a5 5 0 0 1 5-5Z" className="process-visual__paper" /><path d="M74 10v14h14M38 29h23M38 36h15" className="process-visual__line" /><path d="M39 58V51M50 58V44M61 58V49M72 58V40" className="process-visual__bar" /><path d="m39 47 11-8 11 4 12-12" className="process-visual__accent" /><path d="M68 31h5v5" className="process-visual__accent" /></svg></div>
              <h3>Veja seu potencial</h3><p>Receba um relatório claro com estimativas e caminhos para economizar.</p>
            </article>
          </div>
        </section>

        <section className="landing-section benefits-section" aria-labelledby="benefits-title">
          <div className="benefits-copy">
            <p className="section-eyebrow">Mais clareza para decidir</p>
            <h2 id="benefits-title">Entenda onde estão as oportunidades.</h2>
            <p>Uma visão organizada dos custos de energia ajuda sua empresa a avaliar os próximos passos com confiança.</p>
          </div>
          <ul className="benefits-list">
            <li><span aria-hidden="true">✓</span><div><strong>Potencial de economia</strong><p>Estimativas mensais e anuais fáceis de entender.</p></div></li>
            <li><span aria-hidden="true">✓</span><div><strong>Consumo e demanda em contexto</strong><p>Indicadores e gráficos para acompanhar os principais componentes.</p></div></li>
            <li><span aria-hidden="true">✓</span><div><strong>Recomendações para sua realidade</strong><p>Informações para conversar com especialistas e planejar ações.</p></div></li>
          </ul>
        </section>

        <section className="privacy-banner" aria-labelledby="privacy-title">
          <div className="privacy-icon" aria-hidden="true">◈</div>
          <div><h2 id="privacy-title">Seus dados tratados com cuidado</h2><p>Suas faturas são usadas para preparar a análise e não ficam disponíveis publicamente. Você controla sua conta e pode solicitar a exclusão dos seus dados.</p></div>
        </section>

        <section className="closing-cta" aria-labelledby="closing-title">
          <p className="section-eyebrow">O próximo passo começa aqui</p>
          <h2 id="closing-title">Faça sua energia render mais.</h2>
          <a className="button" href="/register">Criar minha conta <span aria-hidden="true">→</span></a>
        </section>

        <section className="landing-section contact-section" id="contato" aria-labelledby="contact-title">
          <div className="contact-copy">
            <p className="section-eyebrow">Estamos por aqui</p>
            <h2 id="contact-title">Fale com a EnerDiag</h2>
            <p>Entre em contato para tirar dúvidas sobre a análise de energia da sua empresa.</p>
            <p className="contact-demo-note">Canais fictícios para demonstração.</p>
          </div>
          <div className="contact-options">
            <a className="contact-card contact-card--email" href="mailto:contato@enerdiag.example">
              <svg className="contact-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg><span className="contact-card__content"><span className="contact-card__label">E-mail</span><strong>contato@enerdiag.example</strong></span>
            </a>
            <a className="contact-card contact-card--phone" href="tel:+5511000000000">
              <svg className="contact-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M7.2 3.5h2.6l1.3 4.2-1.8 1.7a15.1 15.1 0 0 0 5.3 5.3l1.7-1.8 4.2 1.3v2.6a2 2 0 0 1-2.1 2A15.9 15.9 0 0 1 5.2 5.6a2 2 0 0 1 2-2.1Z" /></svg><span className="contact-card__content"><span className="contact-card__label">Telefone</span><strong>+55 (11) 00000-0000</strong></span>
            </a>
            <a className="contact-card contact-card--whatsapp" href="https://wa.me/5511000000000" target="_blank" rel="noopener noreferrer">
              <svg className="contact-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M20.2 11.5a8.2 8.2 0 0 1-12.1 7.2L4 20l1.3-4A8.2 8.2 0 1 1 20.2 11.5Z" /><path d="M9 8.5c.4 2.7 2.1 4.4 4.8 4.8l1-1.1 2 .6v1.3a1 1 0 0 1-1.1 1 9 9 0 0 1-7.5-7.5 1 1 0 0 1 1-1.1h1.3l.6 2L9 8.5Z" /></svg><span className="contact-card__content"><span className="contact-card__label">WhatsApp</span><strong>+55 (11) 00000-0000</strong></span>
            </a>
          </div>
        </section>
        </> : <section className="access-page"><h1>Página não encontrada</h1><a href="/">Voltar ao início</a></section>}
      </main>
      <footer className="app-footer"><span>Eficiência energética para sua empresa</span>{isLandingPage && <a href="#contato">Contato</a>}<a href={isLandingPage ? '/register' : '/'}>{isLandingPage ? 'Começar análise' : 'Página inicial'}</a></footer>
    </div>
  )
}

export default App
