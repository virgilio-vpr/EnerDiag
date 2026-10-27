export const apiPort = 18761
export const frontendPort = 18762
export const smtpPort = 18763
export const mailboxPort = 18764
export const frontendUrl = `http://127.0.0.1:${frontendPort}`
export const mailboxUrl = `http://127.0.0.1:${mailboxPort}`

export type CapturedMessage = {
  recipients: string[]
  subject: string
  text: string
  attachments: Array<{ filename: string; contentType: string; content: string }>
}
