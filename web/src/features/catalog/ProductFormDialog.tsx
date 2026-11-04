import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ApiError } from '@/lib/api'
import type { BaseUnit } from '@/lib/calc'
import { errorMessage } from '@/lib/errors'
import type { Product } from '@/lib/types'
import { useAliases, useCategories, useSaveProduct } from './hooks'

const NONE = '__none__'
export const BASE_UNIT_LABEL: Record<BaseUnit, string> = { kg: 'kg (peso)', L: 'L (volumen)', unidad: 'unidad' }

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Editing when set; creating otherwise. */
  product?: Product | null
  /** Pre-filled name when creating inline from a picker. */
  initialName?: string
  onSaved?: (p: Product) => void
}

export function ProductFormDialog({ open, onOpenChange, product, initialName = '', onSaved }: Props) {
  const save = useSaveProduct()
  const cats = useCategories()
  const { add, remove } = useAliases()
  const [name, setName] = useState('')
  const [unit, setUnit] = useState<BaseUnit>('kg')
  const [cat, setCat] = useState<string>(NONE)
  const [notes, setNotes] = useState('')
  const [alias, setAlias] = useState('')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return
    setName(product?.name ?? initialName)
    setUnit(product?.base_unit ?? 'kg')
    setCat(product?.category_id ? String(product.category_id) : NONE)
    setNotes(product?.notes ?? '')
    setAlias('')
    setError(null)
    // Reset only when (re)opened or switching product, not when the live list refetches.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, product?.id, initialName])

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    // The dialog can be rendered inside another <form> (receipt entry); React events bubble through portals.
    e.stopPropagation()
    setError(null)
    if (!name.trim()) return setError('El nombre es obligatorio.')
    try {
      const saved = await save.mutateAsync({
        id: product?.id,
        name: name.trim(),
        base_unit: unit,
        category_id: cat === NONE ? null : Number(cat),
        notes: notes.trim() || null,
      })
      onSaved?.(saved)
      onOpenChange(false)
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  async function addAlias() {
    if (!product || !alias.trim()) return
    try {
      await add.mutateAsync({ productId: product.id, alias: alias.trim() })
      setAlias('')
      setError(null)
    } catch (err) {
      setError(err instanceof ApiError ? errorMessage(err) : 'No se pudo agregar el alias.')
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{product ? 'Editar producto' : 'Nuevo producto'}</DialogTitle>
          <DialogDescription>La unidad base define en qué unidad se comparan los precios.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="grid gap-3">
          <Field>
            <FieldLabel htmlFor="p-name">Nombre</FieldLabel>
            <Input id="p-name" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field>
              <FieldLabel>Unidad base</FieldLabel>
              <Select value={unit} onValueChange={(v) => setUnit(v as BaseUnit)}>
                <SelectTrigger className="w-full" aria-label="Unidad base">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(Object.keys(BASE_UNIT_LABEL) as BaseUnit[]).map((u) => (
                    <SelectItem key={u} value={u}>
                      {BASE_UNIT_LABEL[u]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
            <Field>
              <FieldLabel>Categoría</FieldLabel>
              <Select value={cat} onValueChange={setCat}>
                <SelectTrigger className="w-full" aria-label="Categoría">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>Sin categoría</SelectItem>
                  {cats.data?.map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
          </div>
          <Field>
            <FieldLabel htmlFor="p-notes">Notas</FieldLabel>
            <Input id="p-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>
          {product && (
            <Field>
              <FieldLabel htmlFor="p-alias">Alias</FieldLabel>
              <div className="flex flex-wrap gap-1.5">
                {product.aliases.map((a) => (
                  <span key={a.id} className="inline-flex items-center gap-1 rounded-full bg-secondary px-2.5 py-1 text-sm">
                    {a.alias}
                    <button
                      type="button"
                      aria-label={`Quitar alias ${a.alias}`}
                      className="text-muted-foreground hover:text-foreground"
                      onClick={() => remove.mutate(a.id, { onError: (e) => toast.error(errorMessage(e)) })}
                    >
                      ×
                    </button>
                  </span>
                ))}
              </div>
              <div className="flex gap-2">
                <Input
                  id="p-alias"
                  value={alias}
                  placeholder="Otro nombre con que aparece en boletas"
                  onChange={(e) => setAlias(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      void addAlias()
                    }
                  }}
                />
                <Button type="button" variant="outline" onClick={addAlias}>
                  Agregar
                </Button>
              </div>
            </Field>
          )}
          {error && <FieldError>{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={save.isPending}>
              Guardar
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
