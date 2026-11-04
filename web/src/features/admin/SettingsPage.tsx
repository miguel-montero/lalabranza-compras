import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { api, apiUrl } from '@/lib/api'
import { roundHalfAway } from '@/lib/calc'
import { errorMessage } from '@/lib/errors'
import { parseDecimal } from '@/lib/parse'

const fmt = (n: number) => String(n).replace('.', ',')

export default function SettingsPage() {
  const qc = useQueryClient()
  const settings = useQuery({ queryKey: ['settings'], queryFn: () => api<Record<string, string>>('GET', '/settings') })
  const [iva, setIva] = useState('')
  const [threshold, setThreshold] = useState('')
  const [days, setDays] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const s = settings.data
    if (!s) return
    setIva(fmt(roundHalfAway(Number(s.iva_rate) * 100, 2)))
    setThreshold(fmt(Number(s.alert_threshold_pct)))
    setDays(String(s.recent_days ?? '90'))
  }, [settings.data])

  async function save(e: React.FormEvent) {
    e.preventDefault()
    const errs: Record<string, string> = {}
    const ivaPct = parseDecimal(iva)
    const thr = parseDecimal(threshold)
    const d = /^\d+$/.test(days.trim()) ? Number(days) : null
    if (ivaPct === null || ivaPct > 100) errs.iva = 'Ingresa un porcentaje entre 0 y 100.'
    if (thr === null || thr > 100) errs.threshold = 'Ingresa un porcentaje entre 0 y 100.'
    if (d === null || d < 1 || d > 3650) errs.days = 'Ingresa un número de días entre 1 y 3650.'
    setErrors(errs)
    if (Object.keys(errs).length) return
    setBusy(true)
    try {
      await api('PUT', '/settings', {
        iva_rate: String(roundHalfAway((ivaPct as number) / 100, 4)),
        alert_threshold_pct: String(thr),
        recent_days: String(d),
      })
      await qc.invalidateQueries({ queryKey: ['settings'] })
      toast.success('Ajustes guardados')
    } catch (err) {
      toast.error(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="grid max-w-xl gap-4">
      <PageHeader title="Ajustes" />
      <Card>
        <CardHeader>
          <CardTitle>Cálculo y alertas</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={save} className="grid gap-4">
            <Field>
              <FieldLabel htmlFor="iva">IVA (%)</FieldLabel>
              <Input id="iva" inputMode="decimal" value={iva} onChange={(e) => setIva(e.target.value)} />
              {errors.iva && <FieldError>{errors.iva}</FieldError>}
            </Field>
            <Field>
              <FieldLabel htmlFor="thr">Umbral de alerta (%)</FieldLabel>
              <Input id="thr" inputMode="decimal" value={threshold} onChange={(e) => setThreshold(e.target.value)} />
              <FieldDescription>Se marca una alza o baja cuando el precio cambia más que este porcentaje.</FieldDescription>
              {errors.threshold && <FieldError>{errors.threshold}</FieldError>}
            </Field>
            <Field>
              <FieldLabel htmlFor="days">Días recientes</FieldLabel>
              <Input id="days" inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} />
              <FieldDescription>Ventana para «más bajo reciente».</FieldDescription>
              {errors.days && <FieldError>{errors.days}</FieldError>}
            </Field>
            <Button type="submit" disabled={busy || settings.isLoading}>
              Guardar ajustes
            </Button>
          </form>
        </CardContent>
      </Card>
      <PasswordCard />
      <Card>
        <CardHeader>
          <CardTitle>Respaldo</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-2">
          <p className="text-sm text-muted-foreground">Descarga todos los datos como archivo SQL.</p>
          <Button asChild variant="outline">
            <a href={apiUrl('/admin/backup')} download>
              Descargar respaldo
            </a>
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}

function PasswordCard() {
  const [cur, setCur] = useState('')
  const [next, setNext] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      await api('POST', '/auth/password', { current: cur, new: next })
      toast.success('Contraseña cambiada')
      setCur('')
      setNext('')
    } catch (err) {
      const code = (err as { code?: string }).code
      setError(
        code === 'wrong_password'
          ? 'La contraseña actual no es correcta.'
          : code === 'password_too_short'
            ? 'La contraseña nueva es demasiado corta.'
            : errorMessage(err),
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Cambiar contraseña</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid gap-4">
          <Field>
            <FieldLabel htmlFor="pw-cur">Contraseña actual</FieldLabel>
            <Input id="pw-cur" type="password" autoComplete="current-password" value={cur} onChange={(e) => setCur(e.target.value)} />
          </Field>
          <Field>
            <FieldLabel htmlFor="pw-new">Contraseña nueva</FieldLabel>
            <Input id="pw-new" type="password" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} />
          </Field>
          {error && <FieldError>{error}</FieldError>}
          <Button type="submit" disabled={busy || !cur || !next}>
            Cambiar contraseña
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
