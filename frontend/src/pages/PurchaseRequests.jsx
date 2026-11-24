import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import ConvertToPoModal from '../components/ConvertToPoModal.jsx';
import Modal from '../components/Modal.jsx';
import { date, dateTime, statusColor } from '../format';

const STATUSES = ['Pending', 'Approved', 'Rejected', 'Converted'];
const EMPTY_ITEM = { sku: '', quantity: '', reason: '' };

export default function PurchaseRequests() {
  const { hasRole } = useAuth();
  const canCreate = hasRole(ROLES.ADMIN, ROLES.PURCHASING);
  const isManager = hasRole(ROLES.MANAGER);

  const [prs, setPrs] = useState([]);
  const [filter, setFilter] = useState({ status: '', source_system: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const [products, setProducts] = useState([]);
  const [branches, setBranches] = useState([]);

  const [creating, setCreating] = useState(false);
  const [viewing, setViewing] = useState(null);

  useEffect(() => {
    api.get('/lookups/products').then((res) => setProducts(res.data.data));
    api.get('/lookups/branches').then((res) => setBranches(res.data.data));
  }, []);

  const load = () => {
    setLoading(true);
    api
      .get('/purchase-requests', {
        params: { status: filter.status || undefined, source_system: filter.source_system || undefined },
      })
      .then((res) => setPrs(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [filter]);

  const open = (pr) => {
    api.get(`/purchase-requests/${pr.id}`).then((res) => setViewing(res.data.data));
  };

  return (
    <>
      <div className="page-head">
        <h1>Purchase Requests</h1>
        {canCreate && (
          <button className="primary" onClick={() => setCreating(true)}>
            + Bagong PR
          </button>
        )}
      </div>

      <div className="filters">
        <select value={filter.status} onChange={(e) => setFilter({ ...filter, status: e.target.value })}>
          <option value="">Lahat ng status</option>
          {STATUSES.map((s) => (
            <option key={s}>{s}</option>
          ))}
        </select>
        <select value={filter.source_system} onChange={(e) => setFilter({ ...filter, source_system: e.target.value })}>
          <option value="">Lahat ng source</option>
          <option value="INV">Galing INV (low-stock)</option>
          <option value="PROC">Ginawa sa PROC</option>
        </select>
      </div>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>PR number</th>
              <th>Source</th>
              <th>Reference</th>
              <th>Branch</th>
              <th className="num">Items</th>
              <th className="num">Kabuuang qty</th>
              <th>Kailangan bago</th>
              <th>Status</th>
              <th>Ginawa</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="9" className="muted">Naglo-load...</td></tr>
            ) : prs.length === 0 ? (
              <tr><td colSpan="9" className="muted">Walang purchase request.</td></tr>
            ) : (
              prs.map((pr) => (
                <tr key={pr.id} className="clickable" onClick={() => open(pr)}>
                  <td className="code">{pr.pr_number}</td>
                  <td>
                    <span className={`badge ${pr.source_system === 'INV' ? 'blue' : 'gray'}`}>{pr.source_system}</span>
                  </td>
                  <td className="code">{pr.source_system === 'PROC' ? '—' : pr.reference_number}</td>
                  <td className="code">{pr.branch_code}</td>
                  <td className="num">{pr.items_count}</td>
                  <td className="num">{pr.total_quantity}</td>
                  <td>{date(pr.needed_by)}</td>
                  <td><span className={`badge ${statusColor(pr.status)}`}>{pr.status}</span></td>
                  <td className="muted">{dateTime(pr.created_at)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {creating && (
        <CreatePrModal
          products={products}
          branches={branches}
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            load();
          }}
        />
      )}

      {viewing && (
        <PrDetailModal
          pr={viewing}
          isManager={isManager}
          canConvert={canCreate}
          onClose={() => setViewing(null)}
          onChanged={(updated) => {
            setViewing(updated);
            load();
          }}
        />
      )}
    </>
  );
}

function CreatePrModal({ products, branches, onClose, onSaved }) {
  const [form, setForm] = useState({ branch_code: '', needed_by: '', remarks: '' });
  const [items, setItems] = useState([{ ...EMPTY_ITEM }]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const setItem = (index, field, value) =>
    setItems(items.map((item, i) => (i === index ? { ...item, [field]: value } : item)));

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      await api.post('/purchase-requests', {
        branch_code: form.branch_code,
        needed_by: form.needed_by || null,
        remarks: form.remarks || null,
        items: items.map((i) => ({ sku: i.sku, quantity: Number(i.quantity), reason: i.reason || null })),
      });
      onSaved();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title="Bagong purchase request" onClose={onClose} wide>
      <form className="form" onSubmit={save}>
        {error && <div className="alert error">{error}</div>}
        <div className="row">
          <label>
            Branch *
            <select value={form.branch_code} onChange={(e) => setForm({ ...form, branch_code: e.target.value })} required>
              <option value="">Pumili...</option>
              {branches.map((b) => (
                <option key={b.branch_code} value={b.branch_code}>
                  {b.branch_code} – {b.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Kailangan bago
            <input type="date" value={form.needed_by} onChange={(e) => setForm({ ...form, needed_by: e.target.value })} />
          </label>
        </div>

        <div>
          <div className="items-head">
            <strong>Mga item</strong>
            <button type="button" onClick={() => setItems([...items, { ...EMPTY_ITEM }])}>
              + Item
            </button>
          </div>
          <table className="items">
            <thead>
              <tr>
                <th>SKU</th>
                <th className="num">Qty</th>
                <th>Dahilan</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i}>
                  <td>
                    <select value={item.sku} onChange={(e) => setItem(i, 'sku', e.target.value)} required>
                      <option value="">Pumili...</option>
                      {products.map((p) => (
                        <option key={p.sku} value={p.sku}>
                          {p.sku} – {p.name}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td style={{ width: 100 }}>
                    <input type="number" min="1" step="1" value={item.quantity} onChange={(e) => setItem(i, 'quantity', e.target.value)} required />
                  </td>
                  <td>
                    <input value={item.reason} onChange={(e) => setItem(i, 'reason', e.target.value)} />
                  </td>
                  <td>
                    {items.length > 1 && (
                      <button type="button" className="link danger" onClick={() => setItems(items.filter((_, j) => j !== i))}>
                        ✕
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <label>
          Remarks
          <textarea rows="2" value={form.remarks} onChange={(e) => setForm({ ...form, remarks: e.target.value })} />
        </label>
        <div className="form-actions">
          <button type="button" onClick={onClose}>Kanselahin</button>
          <button className="primary" disabled={saving}>{saving ? 'Sine-save...' : 'I-save ang PR'}</button>
        </div>
      </form>
    </Modal>
  );
}

function PrDetailModal({ pr, isManager, canConvert, onClose, onChanged }) {
  const navigate = useNavigate();
  const [converting, setConverting] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [remarks, setRemarks] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const decide = async (action) => {
    setBusy(true);
    setError('');
    try {
      const res = await api.post(`/purchase-requests/${pr.id}/${action}`, action === 'reject' ? { remarks } : {});
      setRejecting(false);
      onChanged(res.data.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={pr.pr_number} onClose={onClose} wide>
      {error && <div className="alert error">{error}</div>}
      <dl className="details">
        <dt>Status</dt>
        <dd><span className={`badge ${statusColor(pr.status)}`}>{pr.status}</span></dd>
        <dt>Source</dt>
        <dd>
          {pr.source_system === 'INV' ? (
            <>Galing INV (low-stock) · <span className="code">{pr.reference_number}</span></>
          ) : (
            'Ginawa sa PROC'
          )}
        </dd>
        <dt>Branch</dt>
        <dd className="code">{pr.branch_code}</dd>
        <dt>Humiling</dt>
        <dd className="code">{pr.requested_by || '—'}</dd>
        <dt>Kailangan bago</dt>
        <dd>{date(pr.needed_by) || '—'}</dd>
        {pr.approved_by && (
          <>
            <dt>{pr.status === 'Rejected' ? 'Ni-reject ni' : 'In-approve ni'}</dt>
            <dd><span className="code">{pr.approved_by}</span> · {dateTime(pr.approved_at)}</dd>
          </>
        )}
        {pr.remarks && (
          <>
            <dt>Remarks</dt>
            <dd>{pr.remarks}</dd>
          </>
        )}
      </dl>

      <table className="items">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Produkto</th>
            <th className="num">Qty</th>
            <th>Dahilan</th>
          </tr>
        </thead>
        <tbody>
          {pr.items.map((item) => (
            <tr key={item.id}>
              <td className="code">{item.sku}</td>
              <td>{item.product?.name}</td>
              <td className="num">{item.quantity} {item.product?.unit}</td>
              <td className="muted">{item.reason}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {isManager && pr.status === 'Pending' && (
        <div className="decision">
          {rejecting ? (
            <div className="form">
              <label>
                Bakit nire-reject? *
                <textarea rows="2" value={remarks} onChange={(e) => setRemarks(e.target.value)} autoFocus />
              </label>
              <div className="form-actions">
                <button onClick={() => setRejecting(false)}>Bumalik</button>
                <button className="danger-btn" disabled={busy || !remarks.trim()} onClick={() => decide('reject')}>
                  I-reject
                </button>
              </div>
            </div>
          ) : (
            <div className="form-actions">
              <button className="danger-btn" onClick={() => setRejecting(true)}>Reject</button>
              <button className="primary" disabled={busy} onClick={() => decide('approve')}>Approve</button>
            </div>
          )}
        </div>
      )}

      {canConvert && pr.status === 'Approved' && (
        <div className="decision form-actions">
          <button className="primary" onClick={() => setConverting(true)}>Gumawa ng PO</button>
        </div>
      )}

      {pr.status === 'Converted' && (
        <div className="decision form-actions">
          <button onClick={() => navigate(`/purchase-orders?pr_number=${pr.pr_number}`)}>Tingnan ang mga PO →</button>
        </div>
      )}

      {converting && (
        <ConvertToPoModal
          pr={pr}
          onClose={() => setConverting(false)}
          onDone={(result) => {
            setConverting(false);
            window.alert(result.message);
            navigate(`/purchase-orders?pr_number=${pr.pr_number}`);
          }}
        />
      )}
    </Modal>
  );
}
