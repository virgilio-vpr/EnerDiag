import { NavigationLink } from './components/NavigationControl'
import { lazy, Suspense, useEffect, useState, useSyncExternalStore } from 'react'
import { useTheme } from './hooks/useTheme'
import { useViewport } from './hooks/useViewport'
import { ApiErrorHandler } from './components/ApiErrorHandler'
import { CustomerSimulationsPage } from './pages/CustomerSimulationsPage'
import { ForgotPasswordPage, LoginPage, RegistrationPage, ResetPasswordPage, VerifyEmailPage } from './pages/CustomerAccessPage'
import { ApiError, apiRequest, getAuthSession, getAuthenticatedUser, setAuthToken, subscribeAuthChanges } from './services/api/client'
import { ProtectedRoute } from './routes/ProtectedRoute'
import energyIllustration from './assets/energy-efficiency.png'
import './App.css'
import companyContact from '../../backend/resources/company-contact.json'
import { loadSimulationReport } from './pages/reportLoader'

const SimulationReportPage = lazy(loadSimulationReport)
const InvoiceUploadPage = lazy(() => import('./pages/InvoiceUploadPage').then((module) => ({ default: module.InvoiceUploadPage })))
const ProfilePage = lazy(() => import('./pages/ProfilePage').then((module) => ({ default: module.ProfilePage })))
const AdminDashboardPage = lazy(() => import('./pages/AdminDashboardPage').then((module) => ({ default: module.AdminDashboardPage })))
const AdminCustomerDetailPage = lazy(() => import('./pages/AdminDataPages').then((module) => ({ default: module.AdminCustomerDetailPage })))
const AdminCustomersPage = lazy(() => import('./pages/AdminDataPages').then((module) => ({ default: module.AdminCustomersPage })))
const AdminSimulationDetailPage = lazy(() => import('./pages/AdminDataPages').then((module) => ({ default: module.AdminSimulationDetailPage })))
const AdminSimulationsPage = lazy(() => import('./pages/AdminDataPages').then((module) => ({ default: module.AdminSimulationsPage })))
const AdminReportDetailPage = lazy(() => import('./pages/AdminReportPages').then((module) => ({ default: module.AdminReportDetailPage })))
const AdminReportsPage = lazy(() => import('./pages/AdminReportPages').then((module) => ({ default: module.AdminReportsPage })))
const AdminParametersPage = lazy(() => import('./pages/AdminTariffPages').then((module) => ({ default: module.AdminParametersPage })))
const AdminTariffsPage = lazy(() => import('./pages/AdminTariffPages').then((module) => ({ default: module.AdminTariffsPage })))
const AdminLeadsPage = lazy(() => import('./pages/AdminLeadsPage').then((module) => ({ default: module.AdminLeadsPage })))
const AdminQuotationItemsPage = lazy(() => import('./pages/AdminQuotationItemsPage').then((module) => ({ default: module.AdminQuotationItemsPage })))
const AdminQuotationsPage = lazy(() => import('./pages/AdminQuotationsPage').then((module) => ({ default: module.AdminQuotationsPage })))

function App() {
  const { theme, toggleTheme } = useTheme()
  const viewport = useViewport()
  const session = useSyncExternalStore(subscribeAuthChanges, getAuthSession)
  const [signingOut, setSigningOut] = useState(false)
  const [logoutError, setLogoutError] = useState('')
  const [logoutRevision, setLogoutRevision] = useState(0)

  async function logout() {
    if (signingOut) return
    setSigningOut(true)
    setLogoutError('')
    try {
      await apiRequest('/auth/logout', { method: 'POST', signal: AbortSignal.timeout(10000) }, { notifyGlobalError: false })
    } catch (error) {
      if (!(error instanceof ApiError && error.status === 401)) {
        setLogoutError('Não foi possível encerrar a sessão. Tente sair novamente.')
        return
      }
    } finally {
      setSigningOut(false)
    }
    setAuthToken(null)
    setLogoutRevision((revision) => revision + 1)
    window.history.replaceState({}, '', '/login')
    window.dispatchEvent(new PopStateEvent('popstate'))
  }
  const [pathname, setPathname] = useState(window.location.pathname)

  useEffect(() => {
    function updatePath() { setPathname(window.location.pathname) }
    function navigateInternalLink(event: MouseEvent) {
      if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
      const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null
      if (!(anchor instanceof HTMLAnchorElement) || anchor.hasAttribute('download') || (anchor.target && anchor.target !== '_self')) return
      const url = new URL(anchor.href, window.location.href)
      if (url.origin !== window.location.origin || url.pathname.startsWith('/api/')) return
      if (url.pathname === window.location.pathname && url.search === window.location.search) return
      event.preventDefault()
      window.history.pushState({}, '', url.pathname + url.search + url.hash)
      window.dispatchEvent(new PopStateEvent('popstate'))
    }
    window.addEventListener('popstate', updatePath)
    document.addEventListener('click', navigateInternalLink)
    return () => {
      window.removeEventListener('popstate', updatePath)
      document.removeEventListener('click', navigateInternalLink)
    }
  }, [])

  const isLandingPage = pathname === '/'
  const reportMatch = pathname.match(/^\/simulations\/(\d+)\/report$/)
  const adminCustomerMatch = pathname.match(/^\/admin\/customers\/(\d+)$/)
  const adminSimulationMatch = pathname.match(/^\/admin\/simulations\/(\d+)$/)
  const adminReportMatch = pathname.match(/^\/admin\/reports\/(\d+)$/)
  const isAuthenticated = Boolean(session.token)

  return (
    <div className="app-shell" data-testid="app-shell" data-viewport={viewport}>
      <ApiErrorHandler />
      <header className="app-header">
        <a className="brand" href="/">
          <span className="brand-icon" aria-hidden="true">
            <img src="/enerdiag-logo-horizontal.png" alt="" width="264" height="88" />
          </span>
          <span className="brand-name">Ener<span>Diag</span></span>
        </a>
        <nav className="header-actions" aria-label="Acesso">
          {isAuthenticated ? <><a className="header-login" href={session.user?.role === 'admin' ? '/admin' : '/minhas-simulacoes'}>{session.user?.role === 'admin' ? 'Painel administrativo' : 'Minhas simulações'}</a><a className="header-login" href="/perfil">Meu perfil</a><button className="header-login header-logout" type="button" disabled={signingOut} onClick={() => void logout()}>{signingOut ? 'Saindo…' : 'Sair'}</button></> : <><a className="header-login" href="/login">Login</a><a className="button button--small" href="/register">Criar conta</a></>}
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
      {logoutError && <p className="form-errors" role="alert">{logoutError}</p>}
      <main>
        <Suspense fallback={<section className="access-page" aria-live="polite"><p>Carregando página…</p></section>}>
        {pathname === '/register' ? <RegistrationPage /> : pathname === '/verify-email' ? <VerifyEmailPage email={new URLSearchParams(window.location.search).get('email') ?? ''} /> : pathname === '/login' ? <LoginPage key={logoutRevision} /> : pathname === '/forgot-password' ? <ForgotPasswordPage /> : pathname === '/reset-password' ? <ResetPasswordPage email={new URLSearchParams(window.location.search).get('email') ?? ''} token={new URLSearchParams(window.location.search).get('token') ?? ''} /> : pathname === '/admin' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminDashboardPage /></ProtectedRoute> : pathname === '/admin/customers' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminCustomersPage /></ProtectedRoute> : adminCustomerMatch ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminCustomerDetailPage customerId={adminCustomerMatch[1]} /></ProtectedRoute> : pathname === '/admin/simulations' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminSimulationsPage /></ProtectedRoute> : adminSimulationMatch ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminSimulationDetailPage simulationId={adminSimulationMatch[1]} /></ProtectedRoute> : pathname === '/admin/reports' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminReportsPage /></ProtectedRoute> : adminReportMatch ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminReportDetailPage simulationId={adminReportMatch[1]} /></ProtectedRoute> : pathname === '/admin/tariffs' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminTariffsPage /></ProtectedRoute> : pathname === '/admin/parameters' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminParametersPage /></ProtectedRoute> : pathname === '/admin/leads' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminLeadsPage /></ProtectedRoute> : pathname === '/admin/quotation-items' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminQuotationItemsPage /></ProtectedRoute> : pathname === '/admin/quotations' ? <ProtectedRoute user={getAuthenticatedUser()} requiredRole="admin"><AdminQuotationsPage /></ProtectedRoute> : pathname === '/minhas-simulacoes' ? <CustomerSimulationsPage /> : pathname === '/upload-faturas' ? <InvoiceUploadPage /> : pathname === '/perfil' ? <ProfilePage /> : reportMatch ? <Suspense fallback={<section className="access-page" aria-live="polite"><p>Carregando seu relatório…</p></section>}><SimulationReportPage key={reportMatch[1]} simulationId={reportMatch[1]} /></Suspense> : isLandingPage ? <>
          <section className="hero" aria-labelledby="hero-title">
            <div className="hero-copy">
              <p className="hero-eyebrow">Inteligência para sua energia</p>
              <h1 id="hero-title">Sua energia pode trabalhar melhor pela sua empresa.</h1>
              <p className="hero-description">Descubra oportunidades de economia na sua conta de energia com uma análise clara e totalmente gratuita do consumo e da demanda da sua empresa.</p>
              <NavigationLink variant="primary" href="/register">Começar minha análise gratuita</NavigationLink>
              <p className="hero-note">Análise guiada para empresas do Grupo A - Modalidades Azul e Verde</p>
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
                <h3>Envie suas faturas</h3><p>Comece com 1 fatura em PDF para uma avaliação inicial. O ideal são 12 faturas mensais para cobrir um ciclo anual.</p>
              </article>
              <article className="info-card">
                <div className="process-card-top"><span className="step-number step-number--tertiary">02</span><svg className="process-visual process-visual--tertiary" viewBox="0 0 120 80" fill="none" aria-hidden="true"><rect x="17" y="12" width="86" height="56" rx="9" className="process-visual__paper" /><path d="M29 56V43M43 56V34M57 56V46M71 56V27" className="process-visual__bar" /><path d="m29 32 15-9 14 7 18-13 15 6" className="process-visual__accent" /><circle cx="91" cy="23" r="4" className="process-visual__dot" /></svg></div>
                <h3>Deixe a análise com a gente</h3><p>Avaliamos demanda, consumo e oportunidades com base nos seus dados.</p>
              </article>
              <article className="info-card">
                <div className="process-card-top">
                  <span className="step-number step-number--tertiary">03</span>
                  <svg className="process-visual process-visual--tertiary" viewBox="0 0 120 80" fill="none" aria-hidden="true">
                    <rect x="10" y="10" width="100" height="60" rx="9" className="process-visual__paper" />
                    <circle cx="38" cy="40" r="16" className="process-visual__report-ring" />
                    <circle cx="38" cy="40" r="16" pathLength="100" strokeDasharray="48 52" transform="rotate(-90 38 40)" className="process-visual__report-segment" />
                    <circle cx="38" cy="40" r="16" pathLength="100" strokeDasharray="24 76" strokeDashoffset="-48" transform="rotate(-90 38 40)" className="process-visual__report-segment process-visual__report-segment--tertiary" />
                    <circle cx="68" cy="27" r="2.5" className="process-visual__dot" />
                    <circle cx="68" cy="40" r="2.5" className="process-visual__dot" />
                    <circle cx="68" cy="53" r="2.5" className="process-visual__report-dot" />
                    <path d="M77 27h21M77 40h15M77 53h19" className="process-visual__line" />
                  </svg>
                </div>
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
            <NavigationLink variant="primary" href="/register">Criar minha conta</NavigationLink>
          </section>

          <section className="landing-section contact-section" id="contato" aria-labelledby="contact-title">
            <div className="contact-copy">
              <p className="section-eyebrow">Estamos por aqui</p>
              <h2 id="contact-title">Fale com a EnerDiag</h2>
              <p>Entre em contato para tirar dúvidas sobre a análise de energia da sua empresa.</p>
              <p className="contact-demo-note">Canais fictícios para demonstração.</p>
            </div>
            <div className="contact-options">
              <a className="contact-card contact-card--email" href={`mailto:${companyContact.email}`}>
                <svg className="contact-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg><span className="contact-card__content"><span className="contact-card__label">E-mail</span><strong>{companyContact.email}</strong></span>
              </a>
              <a className="contact-card contact-card--phone" href={`tel:+${companyContact.phone.replace(/\D/g, '')}`}>
                <svg className="contact-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M7.2 3.5h2.6l1.3 4.2-1.8 1.7a15.1 15.1 0 0 0 5.3 5.3l1.7-1.8 4.2 1.3v2.6a2 2 0 0 1-2.1 2A15.9 15.9 0 0 1 5.2 5.6a2 2 0 0 1 2-2.1Z" /></svg><span className="contact-card__content"><span className="contact-card__label">Telefone</span><strong>{companyContact.phone}</strong></span>
              </a>
              <a className="contact-card contact-card--whatsapp" href={`https://wa.me/${companyContact.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer">
                <svg className="contact-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M20.2 11.5a8.2 8.2 0 0 1-12.1 7.2L4 20l1.3-4A8.2 8.2 0 1 1 20.2 11.5Z" /><path d="M9 8.5c.4 2.7 2.1 4.4 4.8 4.8l1-1.1 2 .6v1.3a1 1 0 0 1-1.1 1 9 9 0 0 1-7.5-7.5 1 1 0 0 1 1-1.1h1.3l.6 2L9 8.5Z" /></svg><span className="contact-card__content"><span className="contact-card__label">WhatsApp</span><strong>{companyContact.whatsapp}</strong></span>
              </a>
            </div>
          </section>
        </> : <section className="access-page"><h1>Página não encontrada</h1><NavigationLink direction="back" href="/">Voltar ao início</NavigationLink></section>}
      </Suspense>
      </main>
      <footer className="app-footer"><span>Eficiência energética para sua empresa</span>{isLandingPage && <a href="#contato">Contato</a>}<a href={isLandingPage ? '/register' : '/'}>{isLandingPage ? 'Começar análise' : 'Página inicial'}</a></footer>
    </div>
  )
}

export default App
