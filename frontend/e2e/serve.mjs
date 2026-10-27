import { spawn } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:http'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { once } from 'node:events'
import { SMTPServer } from 'smtp-server'
import { simpleParser } from 'mailparser'
import { apiPort, frontendPort, smtpPort, mailboxPort } from './runtime.ts'

const frontend = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const backend = resolve(frontend, '../backend')
const directory = mkdtempSync('/tmp/enerdiag-e2e-')
const children = []
const messages = []
let stopping = false

for (const path of ['bootstrap', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs']) {
  mkdirSync(resolve(directory, path), { recursive: true })
}
writeFileSync(resolve(directory, 'database.sqlite'), '')
const env = { ...process.env, ENERDIAG_E2E_DIRECTORY: directory, APP_KEY: `base64:${randomBytes(32).toString('base64')}` }

const smtp = new SMTPServer({
  disabledCommands: ['AUTH', 'STARTTLS'],
  disableReverseLookup: true,
  logger: false,
  onData(stream, session, callback) {
    simpleParser(stream).then((mail) => {
      messages.push({
        recipients: session.envelope.rcptTo.map((recipient) => recipient.address),
        subject: mail.subject ?? '', text: mail.text ?? '',
        attachments: mail.attachments.map((attachment) => ({
          filename: attachment.filename, contentType: attachment.contentType, content: attachment.content.toString('base64'),
        })),
      })
      callback()
    }).catch(callback)
  },
})
const mailbox = createServer((request, response) => {
  if (request.method !== 'GET' || request.url !== '/messages') {
    response.writeHead(404).end()
    return
  }
  response.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(messages))
})

async function stop(code = 0) {
  if (stopping) return
  stopping = true
  for (const child of children) child.kill('SIGTERM')
  await Promise.all(children.map((child) => child.exitCode !== null ? Promise.resolve() : once(child, 'exit').catch(() => {})))
  smtp.close()
  mailbox.close()
  rmSync(directory, { recursive: true, force: true })
  process.exit(code)
}

function start(command, args, cwd, extraEnv = {}) {
  const child = spawn(command, args, { cwd, env: { ...env, ...extraEnv }, stdio: ['ignore', 'inherit', 'inherit'] })
  children.push(child)
  child.on('error', (error) => { console.error(error.message); void stop(1) })
  return child
}

process.on('SIGTERM', () => void stop())
process.on('SIGINT', () => void stop())
process.on('uncaughtException', (error) => { console.error(error.message); void stop(1) })

try {
  await Promise.all([
    new Promise((resolve, reject) => { smtp.once('error', reject); smtp.listen(smtpPort, '127.0.0.1', resolve) }),
    new Promise((resolve, reject) => { mailbox.once('error', reject); mailbox.listen(mailboxPort, '127.0.0.1', resolve) }),
  ])
  const setup = start('php', ['tests/E2E/console.php', 'prepare'], backend)
  const [exitCode] = await once(setup, 'exit')
  if (exitCode !== 0) throw new Error('Could not prepare the isolated E2E environment.')
  const api = start('php', ['-S', `127.0.0.1:${apiPort}`, '-t', 'public', 'tests/E2E/server.php'], backend)
  const worker = start('php', ['tests/E2E/console.php', 'worker'], backend)
  const vite = start(process.execPath, ['node_modules/vite/bin/vite.js', 'preview', '--host', '127.0.0.1', '--port', String(frontendPort), '--strictPort'], frontend, { ENERDIAG_E2E_API_ORIGIN: `http://127.0.0.1:${apiPort}` })
  for (const child of [api, worker, vite]) child.on('exit', () => { if (!stopping) void stop(1) })
} catch (error) {
  console.error(error.message)
  await stop(1)
}
