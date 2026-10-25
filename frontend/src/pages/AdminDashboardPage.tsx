const adminSections = [
  { href: '/admin/customers', title: 'Clientes e contatos', description: 'Consulte clientes, solicitações de contato e dados cadastrais.' },
  { href: '/admin/simulations', title: 'Simulações', description: 'Acompanhe simulações e os dados extraídos das faturas.' },
  { href: '/admin/reports', title: 'Relatórios', description: 'Revise textos, reenvie relatórios e solicite recálculos.' },
  { href: '/admin/tariffs', title: 'Tarifas e parâmetros', description: 'Gerencie tarifas, importações da ANEEL e parâmetros regulatórios.' },
  { href: '/admin/leads', title: 'Leads e contatos', description: 'Acompanhe interessados e pedidos de contato comercial.' },
  { href: '/admin/quotation-items', title: 'Orçamento', description: 'Acesse a estrutura preparatória de itens de orçamento.' },
]

export function AdminDashboardPage() {
  return (
    <section className="admin-page" aria-labelledby="admin-title">
      <p className="section-eyebrow">Área administrativa</p>
      <h1 id="admin-title">Painel administrativo</h1>
      <p className="access-intro">Acesse as ferramentas de gestão da EnerDiag.</p>
      <nav className="admin-grid" aria-label="Gestão administrativa">
        {adminSections.map((section) => (
          <a className="admin-card" href={section.href} key={section.href}>
            <h2>{section.title}</h2>
            <p>{section.description}</p>
            <span aria-hidden="true">Abrir →</span>
          </a>
        ))}
      </nav>
    </section>
  )
}
