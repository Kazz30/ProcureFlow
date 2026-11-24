import { useEffect, useState } from 'react';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import Modal from '../components/Modal.jsx';
import { statusColor } from '../format';

const ROLE_LIST = [ROLES.ADMIN, ROLES.MANAGER, ROLES.PURCHASING];

/**
 * F1 – Mga user account (Admin lang).
 * Ang employment_status at "Puwedeng mag-login" ay galing sa HR; hindi binabago dito.
 */
export default function Users() {
  const { user: me } = useAuth();
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(null); // {} = bago
  const [reload, setReload] = useState(0);

  useEffect(() => {
    setLoading(true);
    api
      .get('/users')
      .then((res) => setUsers(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, [reload]);

  return (
    <>
      <div className="page-head">
        <h1>Users</h1>
        <button className="primary" onClick={() => setEditing({})}>+ Bagong account</button>
      </div>
      <p className="muted">
        Pumili ng employee mula sa kopya ng HR. Kapag naging Suspended, Resigned o Terminated siya sa HR, hindi na siya
        makaka-login at mala-logout agad.
      </p>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Pangalan</th>
              <th>Email</th>
              <th>Role</th>
              <th>HR status</th>
              <th>Login</th>
              <th className="num">Sessions</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="8" className="muted">Naglo-load...</td></tr>
            ) : (
              users.map((u) => (
                <tr key={u.id}>
                  <td className="code">{u.employee_no || '—'}</td>
                  <td>
                    {u.name}
                    {u.employee?.position && <div className="muted small">{u.employee.position} · {u.employee.branch_code}</div>}
                  </td>
                  <td>{u.email}</td>
                  <td>{u.role}</td>
                  <td><span className={`badge ${u.employment_status === 'Active' ? 'green' : 'amber'}`}>{u.employment_status || '—'}</span></td>
                  <td>
                    <span className={`badge ${u.can_login ? 'green' : 'red'}`}>{u.can_login ? 'Puwede' : 'Hindi puwede'}</span>
                  </td>
                  <td className="num">{u.tokens_count}</td>
                  <td className="actions">
                    <button className="link" onClick={() => setEditing(u)}>Edit</button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {editing && (
        <UserModal
          user={editing}
          isMe={editing.id === me.id}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReload((n) => n + 1);
          }}
        />
      )}
    </>
  );
}

function UserModal({ user, isMe, onClose, onSaved }) {
  const isNew = !user.id;
  const [employees, setEmployees] = useState([]);
  const [form, setForm] = useState({
    employee_no: '',
    email: user.email || '',
    role: user.role || ROLES.PURCHASING,
    password: '',
  });
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (isNew) api.get('/users/available-employees').then((res) => setEmployees(res.data.data));
  }, [isNew]);

  const pickEmployee = (employeeNo) => {
    const e = employees.find((x) => x.employee_no === employeeNo);
    setForm({ ...form, employee_no: employeeNo, email: e?.email || form.email });
  };

  const chosen = employees.find((e) => e.employee_no === form.employee_no);

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      if (isNew) {
        await api.post('/users', form);
      } else {
        await api.put(`/users/${user.id}`, { role: form.role, email: form.email, password: form.password || null });
      }
      onSaved();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={isNew ? 'Bagong account' : `Edit ${user.name}`} onClose={onClose}>
      <form className="form" onSubmit={save}>
        {error && <div className="alert error">{error}</div>}
        {isNew && (
          <label>
            Employee (galing HR) *
            <select value={form.employee_no} onChange={(e) => pickEmployee(e.target.value)} required>
              <option value="">Pumili...</option>
              {employees.map((e) => (
                <option key={e.employee_no} value={e.employee_no}>
                  {e.employee_no} – {e.first_name} {e.last_name} ({e.position})
                </option>
              ))}
            </select>
          </label>
        )}
        {chosen && !chosen.can_login && (
          <div className="alert error">{chosen.employment_status} sa HR: hindi pa rin siya makaka-login kahit gawan ng account.</div>
        )}
        <label>
          Email (gagamitin sa login) *
          <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
        </label>
        <label>
          Role *
          <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} disabled={isMe}>
            {ROLE_LIST.map((r) => <option key={r}>{r}</option>)}
          </select>
          {isMe && <span className="muted small">Hindi mo mababago ang sarili mong role.</span>}
        </label>
        <label>
          {isNew ? 'Password *' : 'Bagong password (iwanang blangko kung hindi papalitan)'}
          <input type="password" minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required={isNew} />
        </label>
        <div className="form-actions">
          <button type="button" onClick={onClose}>Kanselahin</button>
          <button className="primary" disabled={saving}>{saving ? 'Sine-save...' : 'I-save'}</button>
        </div>
      </form>
    </Modal>
  );
}
