import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { login } from '@/lib/api'
import { errorMessage } from '@/lib/errors'
import { useSession } from '@/app/session'

/** Only same-app paths are allowed as a post-login target. */
export function safeNext(next: string | null): string {
  return next && next.startsWith('/') && !next.startsWith('//') ? next : '/'
}

export default function LoginPage() {
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const session = useSession()
  const next = safeNext(params.get('next'))

  if (session.data === true) return <Navigate to={next} replace />

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(password)
      qc.setQueryData(['session'], true)
      navigate(next, { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-6">
      <div>
        <h1 className="text-3xl font-semibold">La Labranza</h1>
        <p className="text-muted-foreground">Control de precios de compra</p>
      </div>
      <form onSubmit={submit} className="grid gap-4">
        <Field>
          <FieldLabel htmlFor="password">Contraseña</FieldLabel>
          <Input id="password" type="password" autoComplete="current-password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)} />
        </Field>
        {error && <FieldError>{error}</FieldError>}
        <Button type="submit" size="lg" disabled={busy || !password}>
          Entrar
        </Button>
      </form>
    </main>
  )
}
