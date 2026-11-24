import { NavLink, Outlet } from 'react-router';
import { ROLES, useAuth } from '../auth.jsx';

// Ang menu, ayon sa daloy: supplier → PR → PO → receive → return; at mga reklamo galing CRM.
// `roles`: kung sino lang ang makakakita (wala = lahat).
const MENU = [
  { to: '/purchase-requests', label: 'Purchase Requests' },
  { to: '/purchase-orders', label: 'Purchase Orders' },
  { to: '/goods-receipts', label: 'Goods Receipts' },
  { to: '/supplier-returns', label: 'Supplier Returns' },
  { to: '/supplier-issues', label: 'Supplier Issues' },
  { to: '/suppliers', label: 'Suppliers' },
  { to: '/supplier-products', label: 'Supplier Products' },
  { heading: 'Settings', roles: [ROLES.ADMIN] },
  { to: '/users', label: 'Users', roles: [ROLES.ADMIN] },
  { to: '/integrations', label: 'Integrations', roles: [ROLES.ADMIN] },
];

export default function Layout() {
  const { user, logout, hasRole } = useAuth();
  const menu = MENU.filter((item) => !item.roles || hasRole(...item.roles));

  return (
    <div className="shell">
      <aside className="sidebar">
        <div className="brand">
          PROC
          <small>Supplier &amp; Procurement</small>
        </div>
        <nav>
          {menu.map((item) =>
            item.heading ? (
              <div key={item.heading} className="nav-group">{item.heading}</div>
            ) : (
              <NavLink key={item.to} to={item.to}>
                {item.label}
              </NavLink>
            ),
          )}
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
