import { useEffect, useState } from 'react'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { errorMessage } from '@/lib/errors'
import type { Category } from '@/lib/types'
import { useCategories, useSaveCategory } from './hooks'

function CategoryDialog({ category, open, onOpenChange }: { category: Category | null; open: boolean; onOpenChange: (o: boolean) => void }) {
  const save = useSaveCategory()
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    if (open) {
      setName(category?.name ?? '')
      setError(null)
    }
  }, [open, category])
  async function submit(e: React.FormEvent) {
    e.preventDefault()
    e.stopPropagation()
    if (!name.trim()) return setError('El nombre es obligatorio.')
    try {
      await save.mutateAsync({ id: category?.id, name: name.trim() })
      onOpenChange(false)
    } catch (err) {
      setError(errorMessage(err))
    }
  }
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{category ? 'Editar categoría' : 'Nueva categoría'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="grid gap-3">
          <Field>
            <FieldLabel htmlFor="c-name">Nombre</FieldLabel>
            <Input id="c-name" value={name} onChange={(e) => setName(e.target.value)} autoFocus />
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

export default function CategoriesPage() {
  const { data, isLoading } = useCategories()
  const [editing, setEditing] = useState<Category | null>(null)
  const [open, setOpen] = useState(false)
  return (
    <div>
      <PageHeader title="Categorías">
        <Button
          onClick={() => {
            setEditing(null)
            setOpen(true)
          }}
        >
          Nueva categoría
        </Button>
      </PageHeader>
      {isLoading ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.map((c) => (
              <TableRow key={c.id}>
                <TableCell className="font-medium">{c.name}</TableCell>
                <TableCell className="text-right">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setEditing(c)
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
                <TableCell colSpan={2} className="text-center text-muted-foreground">
                  Aún no hay categorías.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      )}
      <CategoryDialog category={editing} open={open} onOpenChange={setOpen} />
    </div>
  )
}
