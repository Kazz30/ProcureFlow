import { NavLink, Outlet } from 'react-router';
import { useAuth } from '../auth.jsx';

// Ang menu. Idadagdag dito ang PR, PO, GRN, returns at issues sa susunod na mga bahagi.
const MENU = [
  { to: '/suppliers', label: 'Suppliers' },
  { to: '/supplier-products', label: 'Supplier Products' },
  { to: '/purchase-requests', label: 'Purchase Requests' },
];

export default function Layout() {
  const { user, logout } = useAuth();

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          PROC
          <small>Supplier &amp; Procurement</small>
        </div>
        <nav>
          {MENU.map((item) => (
            <NavLink key={item.to} to={item.to}>
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="me">
          <strong>{user.name}</strong>
          <span>
            {user.role} · {user.employee_no}
          </span>
          <button className="link" onClick={logout}>
            Logout
          </button>
        </div>
      </aside>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
