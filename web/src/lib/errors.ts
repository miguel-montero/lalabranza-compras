import { ApiError } from './api'

const MESSAGES: Record<string, string> = {
  invalid_credentials: 'Contraseña incorrecta.',
  too_many_attempts: 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.',
  duplicate_name: 'Ya existe un registro con ese nombre.',
  duplicate_alias: 'Ese alias ya está en uso.',
  base_unit_locked: 'No se puede cambiar la unidad: el producto ya tiene compras.',
  receipt_void: 'La compra está anulada.',
  network: 'Sin conexión con el servidor. Revisa tu señal e inténtalo de nuevo.',
  not_found: 'No se encontró el registro.',
  validation: 'Revisa los campos marcados.',
  server_error: 'Error del servidor. Inténtalo de nuevo.',
}

export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) return MESSAGES[e.code] ?? `Error (${e.code}).`
  return 'Ocurrió un error inesperado.'
}
