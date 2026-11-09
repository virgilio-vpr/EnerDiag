export const passwordHint = 'Use pelo menos 8 caracteres, incluindo letras maiúsculas e minúsculas, números e pelo menos um caractere especial.'

export function isValidPassword(password: string) {
  return password.length >= 8 && /[a-z]/.test(password) && /[A-Z]/.test(password) && /\d/.test(password) && /[^a-zA-Z0-9\s]/.test(password)
}
