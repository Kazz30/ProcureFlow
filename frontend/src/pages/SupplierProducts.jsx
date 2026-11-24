import { useEffect, useState } from 'react';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import Modal from '../components/Modal.jsx';

const EMPTY = { supplier_code: '', sku: '', unit_cost: '', lead_time_days: '0', is_preferred: false };

export default function SupplierProducts() {
  const { hasRole } = useAuth();
  const canEdit = hasRole(ROLES.ADMIN, ROLES.PURCHASING);

  const [items, setItems] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [products, setProducts] = useState([]);
  const [filter, setFilter] = useState({ supplier_code: '', sku: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY);
  const [formError, setFormError] = useState('');
  const [saving, setSaving] = useState(false);

  // Mga dropdown: suppliers (sarili) at products (kopya galing INV).
  useEffect(() => {
    api.get('/suppliers').then((res) => setSuppliers(res.data.data));
    api.get('/lookups/products').then((res) => setProducts(res.data.data));
  }, []);

  const load = () => {
    setLoading(true);
    api
      .get('/supplier-products', {
        params: { supplier_code: filter.supplier_code || undefined, sku: filter.sku || undefined },
      })
      .then((res) => setItems(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [filter]);

  const openForm = (item) => {
    setEditing(item || {});
    setForm(
      item
        ? { ...item, unit_cost: item.unit_cost, lead_time_days: String(item.lead_time_days) }
        : EMPTY,
    );
    setFormError('');
  };

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFormError('');
    const body = {
      unit_cost: form.unit_cost,
      lead_time_days: Number(form.lead_time_days),
      is_preferred: form.is_preferred,
    };
    try {
      if (editing.id) {
        await api.put(`/supplier-products/${editing.id}`, body);
      } else {
        await api.post('/supplier-products', { ...body, supplier_code: form.supplier_code, sku: form.sku });
      }
      setEditing(null);
      load();
    } catch (err) {
      setFormError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const remove = async (item) => {
    if (!window.confirm(`Tanggalin ang ${item.sku} sa ${item.supplier_code}?`)) return;
    try {
      await api.delete(`/supplier-products/${item.id}`);
      load();
    } catch (err) {
      setError(errorMessage(err));
    }
  };

  // Kung may ibang preferred na para sa napiling SKU, ipakita bilang paalala.
  const currentPreferred = items.find(
    (i) => i.sku === form.sku && i.is_preferred && i.id !== editing?.id,
  );

  return (
    <>
      <div className="page-head">
        <h1>Supplier Products</h1>
        {canEdit && (
          <button className="primary" onClick={() => openForm(null)}>
            + Bagong presyo
          </button>
        )}
      </div>
      <p className="muted">
        Price list ng bawat supplier. Ang <span className="badge blue">Preferred</span> ang default na supplier kapag gumawa ng PO mula sa PR.
        Isa lang ang preferred bawat SKU.
      </p>

      <div className="filters">
        <select value={filter.supplier_code} onChange={(e) => setFilter({ ...filter, supplier_code: e.target.value })}>
          <option value="">Lahat ng supplier</option>
          {suppliers.map((s) => (
            <option key={s.supplier_code} value={s.supplier_code}>
              {s.supplier_code} – {s.name}
            </option>
          ))}
        </select>
        <select value={filter.sku} onChange={(e) => setFilter({ ...filter, sku: e.target.value })}>
          <option value="">Lahat ng SKU</option>
          {products.map((p) => (
            <option key={p.sku} value={p.sku}>
              {p.sku} – {p.name}
            </option>
          ))}
        </select>
      </div>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>SKU</th>
              <th>Produkto</th>
              <th>Supplier</th>
              <th className="num">Unit cost</th>
              <th className="num">Lead time</th>
              <th />
              {canEdit && <th />}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan="7" className="muted">Naglo-load...</td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan="7" className="muted">Walang laman.</td>
              </tr>
            ) : (
              items.map((i) => (
                <tr key={i.id}>
                  <td className="code">{i.sku}</td>
                  <td>{i.product?.name ?? <span className="muted">(wala sa products)</span>}</td>
                  <td>
                    <span className="code">{i.supplier_code}</span> {i.supplier?.name}
                  </td>
                  <td className="num">₱{Number(i.unit_cost).toFixed(2)}</td>
                  <td className="num">{i.lead_time_days} araw</td>
                  <td>{i.is_preferred && <span className="badge blue">Preferred</span>}</td>
                  {canEdit && (
                    <td className="actions">
                      <button className="link" onClick={() => openForm(i)}>
                        Edit
                      </button>
                      <button className="link danger" onClick={() => remove(i)}>
                        Tanggalin
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
        <Modal
          title={editing.id ? `Edit ${editing.sku} · ${editing.supplier_code}` : 'Bagong presyo'}
          onClose={() => setEditing(null)}
        >
          <form className="form" onSubmit={save}>
            {formError && <div className="alert error">{formError}</div>}
            {!editing.id && (
              <>
                <label>
                  Supplier *
                  <select value={form.supplier_code} onChange={(e) => setForm({ ...form, supplier_code: e.target.value })} required>
                    <option value="">Pumili...</option>
                    {suppliers
                      .filter((s) => s.status === 'Active')
                      .map((s) => (
                        <option key={s.supplier_code} value={s.supplier_code}>
                          {s.supplier_code} – {s.name}
                        </option>
                      ))}
                  </select>
                </label>
                <label>
                  SKU *
                  <select value={form.sku} onChange={(e) => setForm({ ...form, sku: e.target.value })} required>
                    <option value="">Pumili...</option>
                    {products.map((p) => (
                      <option key={p.sku} value={p.sku}>
                        {p.sku} – {p.name}
                      </option>
                    ))}
                  </select>
                </label>
              </>
            )}
            <div className="row">
              <label>
                Unit cost (₱) *
                <input type="number" min="0" step="0.01" value={form.unit_cost} onChange={(e) => setForm({ ...form, unit_cost: e.target.value })} required />
              </label>
              <label>
                Lead time (araw) *
                <input type="number" min="0" max="365" step="1" value={form.lead_time_days} onChange={(e) => setForm({ ...form, lead_time_days: e.target.value })} required />
              </label>
            </div>
            <label className="check">
              <input type="checkbox" checked={form.is_preferred} onChange={(e) => setForm({ ...form, is_preferred: e.target.checked })} />
              Preferred supplier para sa SKU na ito
            </label>
            {form.is_preferred && currentPreferred && (
              <div className="alert info">
                Ang preferred ngayon ng {form.sku} ay si {currentPreferred.supplier_code}. Kapag na-save, papalitan ito.
              </div>
            )}
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
