import { useEffect, useState } from 'react';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import Modal from '../components/Modal.jsx';

const EMPTY = { name: '', contact_person: '', email: '', phone: '', status: 'Active' };

export default function Suppliers() {
  const { hasRole } = useAuth();
  const canEdit = hasRole(ROLES.ADMIN, ROLES.PURCHASING);

  const [suppliers, setSuppliers] = useState([]);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [editing, setEditing] = useState(null); // null = sarado; {} = bago; {supplier_code...} = edit
  const [form, setForm] = useState(EMPTY);
  const [formError, setFormError] = useState('');
  const [saving, setSaving] = useState(false);

  const load = () => {
    setLoading(true);
    api
      .get('/suppliers', { params: { search: search || undefined, status: status || undefined } })
      .then((res) => setSuppliers(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [status]);

  const openForm = (supplier) => {
    setEditing(supplier || {});
    setForm(supplier ? { ...EMPTY, ...supplier } : EMPTY);
    setFormError('');
  };

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormError('');
    const body = {
      name: form.name,
      contact_person: form.contact_person || null,
      email: form.email || null,
      phone: form.phone || null,
      status: form.status,
    };
    try {
      if (editing.supplier_code) {
        await api.put(`/suppliers/${editing.id}`, body);
      } else {
        await api.post('/suppliers', body);
      }
      setEditing(null);
      load();
    } catch (err) {
      setFormError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const set = (field) => (e) => setForm({ ...form, [field]: e.target.value });

  return (
    <>
      <div className="page-head">
        <h1>Suppliers</h1>
        {canEdit && (
          <button className="primary" onClick={() => openForm(null)}>
            + Bagong supplier
          </button>
        )}
      </div>

      <form
        className="filters"
        onSubmit={(e) => {
          e.preventDefault();
          load();
        }}
      >
        <input placeholder="Hanapin (pangalan, code, contact)" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">Lahat ng status</option>
          <option>Active</option>
          <option>Inactive</option>
        </select>
        <button>Hanapin</button>
      </form>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>Code</th>
              <th>Pangalan</th>
              <th>Contact person</th>
              <th>Email</th>
              <th>Phone</th>
              <th className="num">SKUs</th>
              <th>Status</th>
              {canEdit && <th />}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan="8" className="muted">Naglo-load...</td>
              </tr>
            ) : suppliers.length === 0 ? (
              <tr>
                <td colSpan="8" className="muted">Walang supplier.</td>
              </tr>
            ) : (
              suppliers.map((s) => (
                <tr key={s.id}>
                  <td className="code">{s.supplier_code}</td>
                  <td>{s.name}</td>
                  <td>{s.contact_person}</td>
                  <td>{s.email}</td>
                  <td>{s.phone}</td>
                  <td className="num">{s.products_count}</td>
                  <td>
                    <span className={`badge ${s.status === 'Active' ? 'green' : 'gray'}`}>{s.status}</span>
                  </td>
                  {canEdit && (
                    <td className="actions">
                      <button className="link" onClick={() => openForm(s)}>
                        Edit
                      </button>
                    </td>
                  )}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {editing && (
        <Modal title={editing.supplier_code ? `Edit ${editing.supplier_code}` : 'Bagong supplier'} onClose={() => setEditing(null)}>
          <form className="form" onSubmit={save}>
            {formError && <div className="alert error">{formError}</div>}
            {!editing.supplier_code && <p className="muted">Kusang gagawin ang supplier code (SUP-....).</p>}
            <label>
              Pangalan *
              <input value={form.name} onChange={set('name')} required />
            </label>
            <label>
              Contact person
              <input value={form.contact_person ?? ''} onChange={set('contact_person')} />
            </label>
            <div className="row">
              <label>
                Email
                <input type="email" value={form.email ?? ''} onChange={set('email')} />
              </label>
              <label>
                Phone
                <input value={form.phone ?? ''} onChange={set('phone')} />
              </label>
            </div>
            <label>
              Status
              <select value={form.status} onChange={set('status')}>
                <option>Active</option>
                <option>Inactive</option>
              </select>
            </label>
            <div className="form-actions">
              <button type="button" onClick={() => setEditing(null)}>
                Kanselahin
              </button>
              <button className="primary" disabled={saving}>
                {saving ? 'Sine-save...' : 'I-save'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </>
  );
}
