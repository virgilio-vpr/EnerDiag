import { test, expect } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { invoiceFixtures } from './invoice-fixtures.ts'
import { mailboxUrl, type CapturedMessage } from './runtime.ts'

// API calls, queue jobs, PDF extraction and SMTP delivery all use the real application.
for (const invoiceCount of [1, 12]) {
  test(`customer registers, uploads ${invoiceCount} invoices and receives the report PDF`, async ({ page, request }) => {
    test.setTimeout(300_000)
    if (invoiceCount === 12) test.setTimeout(480_000)
    const email = `customer-${Date.now()}@example.test`
    const password = 'EnerDiagSyntheticPassword2026!'
    const messages = async (): Promise<CapturedMessage[]> => {
      const response = await request.get(`${mailboxUrl}/messages`)
      expect(response.ok()).toBeTruthy()
      return response.json()
    }

    await page.goto('/register')
    await page.getByLabel('Nome do responsável').fill('Cliente de teste E2E')
    await page.getByLabel('Nome da empresa').fill('Empresa Sintética E2E')
    await page.getByLabel('E-mail', { exact: true }).fill(email)
    await page.getByLabel('Senha', { exact: true }).fill(password)
    await page.getByLabel('Confirme sua senha').fill(password)
    await page.getByRole('checkbox', { name: /Li e aceito/ }).check()
    const registered = page.waitForResponse((response) => response.url().endsWith('/api/v1/auth/register'))
    await page.getByRole('button', { name: 'Criar conta', exact: true }).click()
    const registrationResponse = await registered
    expect(registrationResponse.status(), await registrationResponse.text()).toBe(201)
    await expect(page.getByRole('heading', { name: 'Verifique seu e-mail' })).toBeVisible()

    let verification: CapturedMessage | undefined
    await expect.poll(async () => {
      verification = (await messages()).find((mail) => mail.recipients.includes(email) && mail.subject === 'Código de verificação do EnerDiag')
      return Boolean(verification)
    }).toBe(true)
    const code = verification!.text.match(/\b[0-9]{6}\b/)?.[0]
    expect(code).toBeDefined()
    await page.getByLabel('Código de confirmação').fill(code!)
    await page.getByRole('button', { name: 'Confirmar e-mail', exact: true }).click()
    await expect(page.getByRole('status')).toContainText('E-mail confirmado')

    await page.getByRole('link', { name: 'Voltar para entrar' }).click()
    await page.getByLabel('E-mail', { exact: true }).fill(email)
    await page.getByLabel('Senha', { exact: true }).fill(password)
    await page.getByRole('button', { name: 'Entrar', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Você entrou na sua conta' })).toBeVisible()
    await page.getByRole('button', { name: 'Continuar para minhas simulações' }).click()
    await page.getByRole('link', { name: 'Nova simulação', exact: true }).click()

    await page.getByRole('combobox', { name: 'Distribuidora', exact: true }).selectOption({ label: 'ENEL SP' })
    await page.getByLabel('Endereço da unidade consumidora').fill('Rua Sintética, 100, São Paulo/SP')
    const invoices = invoiceFixtures(invoiceCount)
    await page.getByRole('button', { name: 'Começar envio' }).click()
    await page.getByLabel('Selecionar faturas').setInputFiles(invoices)
    await expect(page.getByText(`${invoiceCount} de 12 faturas lidas e salvas`)).toBeVisible({ timeout: Math.max(60_000, invoiceCount * 30_000) })
    await expect(page.getByRole('table', { name: 'Faturas em ordem do mês da medição' })).toBeVisible()
    const submitted = page.waitForResponse((response) => /\/api\/v1\/invoice-imports\/\d+\/complete$/.test(response.url()) && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'Simular relatório' }).click()
    expect((await submitted).status()).toBe(200)
    await expect(page.getByRole('heading', { name: 'Sua análise está pronta' })).toBeVisible({ timeout: 60_000 })
    const reportResponse = page.waitForResponse((response) => /\/api\/v1\/simulations\/\d+\/report$/.test(response.url()) && response.request().method() === 'GET')
    await page.getByRole('link', { name: 'Ver relatório completo' }).click()
    const savedReport = (await (await reportResponse).json()).data as { total_monthly_savings: string; total_yearly_savings: string; reactive_savings_monthly: string }
    await expect(page.getByRole('heading', { name: 'Seu relatório de energia' })).toBeVisible()
    await expect(page.getByText(`Resultados calculados para ${invoiceCount} ${invoiceCount === 1 ? 'fatura' : 'faturas'}.`)).toBeVisible()
    if (invoiceCount < 12) {
      await expect(page.getByText(/Esta avaliação inicial considera menos de 12 faturas/)).toBeVisible()
    } else {
      await expect(page.getByText(/Esta avaliação inicial considera menos de 12 faturas/)).toHaveCount(0)
    }
    const monthlyAmount = Number(savedReport.total_monthly_savings).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    const yearlyAmount = Number(savedReport.total_yearly_savings).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    await expect(page.locator('.report-highlight').filter({ hasText: 'Economia mensal média' })).toContainText(monthlyAmount)
    await expect(page.locator('.report-highlight').filter({ hasText: 'Projeção de economia anual' })).toContainText(yearlyAmount)
    expect(savedReport.reactive_savings_monthly).toBe('54.42')

    const downloaded = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Baixar relatório em PDF' }).click()
    const download = await downloaded
    const pdf = await readFile((await download.path())!)
    expect(pdf.subarray(0, 8).toString()).toBe('%PDF-1.4')
    expect(pdf.toString('latin1')).toContain(monthlyAmount)
    expect(pdf.toString('latin1')).toContain(yearlyAmount)
    expect(pdf.toString('latin1')).toContain('54,42')
    expect(pdf.toString('latin1')).toContain('Economia por componente')
    expect(pdf.toString('latin1')).toContain('Economia com reativos por m\u00eas')
    expect(pdf.toString('latin1')).toContain('Registrada')
    expect(pdf.toString('latin1')).toContain('/Count ')

    let reportMail: CapturedMessage | undefined
    await expect.poll(async () => {
      reportMail = (await messages()).find((mail) => mail.recipients.includes(email) && mail.subject === 'Seu relatório EnerDiag está pronto')
      return Boolean(reportMail)
    }).toBe(true)
    const attachment = reportMail!.attachments.find((item) => item.filename === 'relatorio-enerdiag.pdf')
    expect(attachment?.contentType).toBe('application/pdf')
    expect(Buffer.from(attachment!.content, 'base64').equals(pdf)).toBe(true)
    expect((await messages()).filter((mail) => mail.recipients.includes(email) && mail.subject === reportMail!.subject)).toHaveLength(1)

    await page.locator('.report-actions').getByRole('link', { name: 'Minhas simulações', exact: true }).click()
    await expect(page.getByText('1 simulação salva · Limite de 3')).toBeVisible()
    await page.getByRole('link', { name: 'Ver relatório', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Seu relatório de energia' })).toBeVisible()
    await page.locator('.report-actions').getByRole('link', { name: 'Minhas simulações', exact: true }).click()

    // Drafts count toward the same limit as completed reports.
    for (const total of [2, 3]) {
      await page.getByRole('link', { name: 'Nova simulação', exact: true }).click()
      await page.getByRole('combobox', { name: 'Distribuidora', exact: true }).selectOption({ label: 'ENEL SP' })
      await page.getByLabel('Endereço da unidade consumidora').fill('Rua Sintética, 100, São Paulo/SP')
      await page.getByRole('button', { name: 'Começar envio' }).click()
      await expect(page.getByLabel('Selecionar faturas')).toBeAttached()
      await page.getByRole('link', { name: 'Voltar para minhas simulações', exact: true }).click()
      await expect(page.getByText(`${total} simulações salvas · Limite de 3`)).toBeVisible()
      if (total === 2) {
        await page.getByRole('link', { name: 'Continuar envio', exact: true }).click()
        await expect(page.getByLabel('Selecionar faturas')).toBeAttached()
        await page.getByRole('link', { name: 'Voltar para minhas simulações', exact: true }).click()
      }
    }
    await page.getByRole('button', { name: 'Nova simulação', exact: true }).click()
    await expect(page.getByRole('dialog', { name: 'Limite de simulações atingido' })).toContainText('exclua uma das simulações')
    await page.getByRole('button', { name: 'Entendi', exact: true }).click()
    await expect(page.getByRole('dialog')).toHaveCount(0)
    await expect(page.getByText(/Exclua 1 simulação para iniciar uma nova/)).toBeVisible()
    const draft = page.getByRole('article').filter({ has: page.getByRole('link', { name: 'Continuar envio', exact: true }) }).first()
    await draft.getByRole('button', { name: /^Excluir simulação/ }).click()
    await draft.getByRole('button', { name: 'Cancelar', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Confirmar exclusão', exact: true })).toHaveCount(0)
    await draft.getByRole('button', { name: /^Excluir simulação/ }).click()
    await draft.getByRole('button', { name: 'Confirmar exclusão', exact: true }).click()
    await expect(page.getByText('2 simulações salvas · Limite de 3')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Nova simulação', exact: true })).toBeVisible()
  })
}
