import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { Toaster } from '@/components/ui/sonner'
import LoginPage from '@/features/auth/LoginPage'
import CategoriesPage from '@/features/catalog/CategoriesPage'
import ProductsPage from '@/features/catalog/ProductsPage'
import SuppliersPage from '@/features/catalog/SuppliersPage'
import DashboardPage from '@/features/prices/DashboardPage'
import HistoryPage from '@/features/prices/HistoryPage'
import LookupPage from '@/features/prices/LookupPage'
import ProductPage from '@/features/prices/ProductPage'
import Placeholder from '@/features/placeholders/Placeholder'
import ReceiptEditPage from '@/features/receipts/ReceiptEditPage'
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
            <Route path="/" element={<DashboardPage />} />
            <Route path="/compras" element={<ReceiptsListPage />} />
            <Route path="/compras/nueva" element={<ReceiptForm />} />
            <Route path="/compras/:id" element={<ReceiptDetailPage />} />
            <Route path="/compras/:id/editar" element={<ReceiptEditPage />} />
            <Route path="/consultar" element={<LookupPage />} />
            <Route path="/productos" element={<ProductsPage />} />
            <Route path="/productos/:id" element={<ProductPage />} />
            <Route path="/proveedores" element={<SuppliersPage />} />
            <Route path="/categorias" element={<CategoriesPage />} />
            <Route path="/historial" element={<HistoryPage />} />
            <Route path="/ajustes" element={<Placeholder title="Ajustes" />} />
            <Route path="/importar" element={<Placeholder title="Importar planilla" />} />
          </Route>
        </Routes>
      </BrowserRouter>
      <Toaster position="top-center" />
    </QueryClientProvider>
  )
}
