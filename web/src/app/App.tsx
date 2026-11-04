import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { Toaster } from '@/components/ui/sonner'
import LoginPage from '@/features/auth/LoginPage'
import CategoriesPage from '@/features/catalog/CategoriesPage'
import ProductsPage from '@/features/catalog/ProductsPage'
import SuppliersPage from '@/features/catalog/SuppliersPage'
import Placeholder from '@/features/placeholders/Placeholder'
import ReceiptDetailPage from '@/features/receipts/ReceiptDetailPage'
import ReceiptForm from '@/features/receipts/ReceiptForm'
import ReceiptsListPage from '@/features/receipts/ReceiptsListPage'
import RequireAuth from './RequireAuth'

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter basename={import.meta.env.BASE_URL.replace(/\/$/, '')}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<RequireAuth />}>
            <Route path="/" element={<Placeholder title="Panel" />} />
            <Route path="/compras" element={<ReceiptsListPage />} />
            <Route path="/compras/nueva" element={<ReceiptForm />} />
            <Route path="/compras/:id" element={<ReceiptDetailPage />} />
            <Route path="/consultar" element={<Placeholder title="Consultar precio" />} />
            <Route path="/productos" element={<ProductsPage />} />
            <Route path="/productos/:id" element={<Placeholder title="Producto" />} />
            <Route path="/proveedores" element={<SuppliersPage />} />
            <Route path="/categorias" element={<CategoriesPage />} />
            <Route path="/historial" element={<Placeholder title="Historial" />} />
            <Route path="/ajustes" element={<Placeholder title="Ajustes" />} />
            <Route path="/importar" element={<Placeholder title="Importar planilla" />} />
          </Route>
        </Routes>
      </BrowserRouter>
      <Toaster position="top-center" />
    </QueryClientProvider>
  )
}
