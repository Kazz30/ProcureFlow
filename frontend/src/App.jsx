import { Navigate, Route, Routes } from 'react-router';
import { useAuth } from './auth.jsx';
import Layout from './components/Layout.jsx';
import Login from './pages/Login.jsx';
import Suppliers from './pages/Suppliers.jsx';
import SupplierProducts from './pages/SupplierProducts.jsx';
import PurchaseRequests from './pages/PurchaseRequests.jsx';
import PurchaseOrders from './pages/PurchaseOrders.jsx';
import GoodsReceipts from './pages/GoodsReceipts.jsx';
import SupplierReturns from './pages/SupplierReturns.jsx';
import SupplierIssues from './pages/SupplierIssues.jsx';
import Users from './pages/Users.jsx';
import Integrations from './pages/Integrations.jsx';

/** Para sa mga page na kailangan naka-login. */
function RequireAuth({ children }) {
  const { user, loading } = useAuth();
  if (loading) return <p className="center muted">Naglo-load...</p>;
  if (!user) return <Navigate to="/login" replace />;
  return children;
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        <Route index element={<Navigate to="/purchase-requests" replace />} />
        <Route path="suppliers" element={<Suppliers />} />
        <Route path="supplier-products" element={<SupplierProducts />} />
        <Route path="purchase-requests" element={<PurchaseRequests />} />
        <Route path="purchase-orders" element={<PurchaseOrders />} />
        <Route path="goods-receipts" element={<GoodsReceipts />} />
        <Route path="supplier-returns" element={<SupplierReturns />} />
        <Route path="supplier-issues" element={<SupplierIssues />} />
        <Route path="users" element={<Users />} />
        <Route path="integrations" element={<Integrations />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
