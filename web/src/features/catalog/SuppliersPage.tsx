import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { errorMessage } from '@/lib/errors'
import type { Supplier } from '@/lib/types'
import { useSaveSupplier, useSuppliers } from './hooks'

function SupplierDialog({ supplier, open, onOpenChange }: { supplier: Supplier | null; open: boolean; onOpenChange: (o: boolean) => void }) {
  const save = useSaveSupplier()
  const [name, setName] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    if (open) {
      setName(supplier?.name ?? '')
      setNotes(supplier?.notes ?? '')
      setError(null)
    }
  }, [open, supplier])

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!name.trim()) return setError('El nombre es obligatorio.')
    try {
      await save.mutateAsync({ id: supplier?.id, name: name.trim(), notes: notes.trim() || null })
      onOpenChange(false)
    } catch (err) {
      setError(errorMessage(err))
    }
  }
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{supplier ? 'Editar proveedor' : 'Nuevo proveedor'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="grid gap-3">
          <Field>
            <FieldLabel htmlFor="s-name">Nombre</FieldLabel>
            <Input id="s-name" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
          </Field>
          <Field>
            <FieldLabel htmlFor="s-notes">Notas</FieldLabel>
            <Input id="s-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>
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

export default function SuppliersPage() {
  const { data, isLoading } = useSuppliers()
  const save = useSaveSupplier()
  const [editing, setEditing] = useState<Supplier | null>(null)
  const [open, setOpen] = useState(false)

  return (
    <div>
      <PageHeader title="Proveedores">
        <Button
          onClick={() => {
            setEditing(null)
            setOpen(true)
          }}
        >
          Nuevo proveedor
        </Button>
      </PageHeader>
      {isLoading ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead className="hidden sm:table-cell">Notas</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.map((s) => (
              <TableRow key={s.id} className={s.active ? '' : 'opacity-60'}>
                <TableCell className="font-medium">{s.name}</TableCell>
                <TableCell className="hidden text-muted-foreground sm:table-cell">{s.notes}</TableCell>
                <TableCell>
                  <button
                    type="button"
                    aria-label={s.active ? `Desactivar ${s.name}` : `Activar ${s.name}`}
                    onClick={() => save.mutate({ id: s.id, active: !s.active }, { onError: (e) => toast.error(errorMessage(e)) })}
                  >
                    <Badge variant={s.active ? 'default' : 'secondary'}>{s.active ? 'Activo' : 'Inactivo'}</Badge>
                  </button>
                </TableCell>
                <TableCell className="text-right">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setEditing(s)
                      setOpen(true)
                    }}
                  >
                    Editar
                  </Button>
                </TableCell>
              </TableRow>
            ))}
            {data?.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="text-center text-muted-foreground">
                  Aún no hay proveedores.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      )}
      <SupplierDialog supplier={editing} open={open} onOpenChange={setOpen} />
    </div>
  )
}
