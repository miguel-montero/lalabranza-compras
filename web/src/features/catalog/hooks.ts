import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { BaseUnit } from '@/lib/calc'
import type { Category, Product, Supplier } from '@/lib/types'

export function useSuppliers(q = '', active: 'all' | '1' = 'all') {
  return useQuery({
    queryKey: ['suppliers', q, active],
    queryFn: () => api<Supplier[]>('GET', `/suppliers?active=${active}&q=${encodeURIComponent(q)}`),
  })
}

export function useCategories() {
  return useQuery({ queryKey: ['categories'], queryFn: () => api<Category[]>('GET', '/categories') })
}

export function useProducts(q = '', active: 'all' | '1' = 'all', limit = 200) {
  return useQuery({
    queryKey: ['products', q, active, limit],
    queryFn: () => api<Product[]>('GET', `/products?active=${active}&limit=${limit}&q=${encodeURIComponent(q)}`),
    placeholderData: (prev) => prev,
  })
}

/** Invalidates a catalog list family after a mutation. */
function useInvalidate(key: string) {
  const qc = useQueryClient()
  return () => qc.invalidateQueries({ queryKey: [key] })
}

export function useSaveSupplier() {
  const inv = useInvalidate('suppliers')
  return useMutation({
    mutationFn: (v: { id?: number; name?: string; notes?: string | null; active?: boolean }) =>
      v.id
        ? api<Supplier>('PATCH', `/suppliers/${v.id}`, { name: v.name, notes: v.notes, active: v.active })
        : api<Supplier>('POST', '/suppliers', { name: v.name, notes: v.notes ?? undefined }),
    onSuccess: inv,
  })
}

export function useSaveCategory() {
  const inv = useInvalidate('categories')
  return useMutation({
    mutationFn: (v: { id?: number; name: string }) =>
      v.id ? api<Category>('PATCH', `/categories/${v.id}`, { name: v.name }) : api<Category>('POST', '/categories', { name: v.name }),
    onSuccess: inv,
  })
}

export interface ProductInput {
  id?: number
  name?: string
  base_unit?: BaseUnit
  category_id?: number | null
  notes?: string | null
  active?: boolean
}

export function useSaveProduct() {
  const inv = useInvalidate('products')
  return useMutation({
    mutationFn: ({ id, ...body }: ProductInput) =>
      id ? api<Product>('PATCH', `/products/${id}`, body) : api<Product>('POST', '/products', body),
    onSuccess: inv,
  })
}

export function useAliases() {
  const inv = useInvalidate('products')
  const add = useMutation({
    mutationFn: (v: { productId: number; alias: string }) => api('POST', `/products/${v.productId}/aliases`, { alias: v.alias }),
    onSuccess: inv,
  })
  const remove = useMutation({
    mutationFn: (aliasId: number) => api('DELETE', `/aliases/${aliasId}`),
    onSuccess: inv,
  })
  return { add, remove }
}
