import { test, expect } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { invoiceFixtures } from './invoice-fixtures.ts'
import { mailboxUrl, type CapturedMessage } from './runtime.ts'

// API calls, queue jobs, PDF extraction and SMTP delivery all use the real application.
test('customer registers, verifies email, uploads invoices and receives the report PDF', async ({ page, request }) => {
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
  await page.getByRole('button', { name: 'Continuar para minhas faturas' }).click()

  await page.getByRole('combobox', { name: 'Distribuidora', exact: true }).selectOption({ label: 'ENEL SP' })
  await page.getByLabel('Endereço da unidade consumidora').fill('Rua Sintética, 100, São Paulo/SP')
  for (const fixture of invoiceFixtures()) {
    await page.getByLabel('Faturas em PDF').setInputFiles(fixture)
  }
  await expect(page.getByText(/6 páginas/)).toBeVisible()
  const submitted = page.waitForResponse((response) => response.url().endsWith('/complete') && response.request().method() === 'POST')
  await page.getByRole('button', { name: 'Simular economia' }).click()
  expect((await submitted).status()).toBe(202)
  await expect(page.getByRole('heading', { name: 'Sua análise está pronta' })).toBeVisible({ timeout: 60_000 })
  await page.getByRole('link', { name: 'Ver relatório completo' }).click()
  await expect(page.getByRole('heading', { name: 'Seu relatório de energia' })).toBeVisible()
  await expect(page.getByText('Resultados calculados para 6 faturas.')).toBeVisible()
  await expect(page.locator('.report-highlight').filter({ hasText: 'Economia mensal média' })).toContainText('54,42')
  await expect(page.locator('.report-highlight').filter({ hasText: 'Projeção de economia anual' })).toContainText('653,04')

  const downloaded = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Baixar relatório em PDF' }).click()
  const download = await downloaded
  const pdf = await readFile((await download.path())!)
  expect(pdf.subarray(0, 8).toString()).toBe('%PDF-1.4')
  expect(pdf.toString('latin1')).toContain('54.42')
  expect(pdf.toString('latin1')).toContain('653.04')

  let reportMail: CapturedMessage | undefined
  await expect.poll(async () => {
    reportMail = (await messages()).find((mail) => mail.recipients.includes(email) && mail.subject === 'Seu relatório EnerDiag está pronto')
    return Boolean(reportMail)
  }).toBe(true)
  const attachment = reportMail!.attachments.find((item) => item.filename === 'relatorio-enerdiag.pdf')
  expect(attachment?.contentType).toBe('application/pdf')
  expect(Buffer.from(attachment!.content, 'base64').equals(pdf)).toBe(true)
  expect((await messages()).filter((mail) => mail.recipients.includes(email) && mail.subject === reportMail!.subject)).toHaveLength(1)
})
