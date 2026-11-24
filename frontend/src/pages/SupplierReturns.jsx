import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import Modal from '../components/Modal.jsx';
import { dateTime, statusColor } from '../format';
import { SYNC_LABEL } from './GoodsReceipts.jsx';

const STATUSES = ['Pending', 'Returned', 'Cancelled'];

export default function SupplierReturns() {
  const { hasRole } = useAuth();
  const canEdit = hasRole(ROLES.ADMIN, ROLES.PURCHASING);
  const [params, setParams] = useSearchParams();

  const [returns, setReturns] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [viewing, setViewing] = useState(null);
  const [reload, setReload] = useState(0);

  const status = params.get('status') || '';
  const supplierCode = params.get('supplier_code') || '';
  const grnNumber = params.get('grn_number') || '';
  const creating = params.get('new') === '1';

  const setParam = (field, value) => {
    const next = new URLSearchParams(params);
    if (value) next.set(field, value);
    else next.delete(field);
    setParams(next);
  };

  useEffect(() => {
    api.get('/suppliers').then((res) => setSuppliers(res.data.data));
  }, []);

  useEffect(() => {
    setLoading(true);
    api
      .get('/supplier-returns', {
        params: { status: status || undefined, supplier_code: supplierCode || undefined, grn_number: grnNumber || undefined },
      })
      .then((res) => setReturns(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, [status, supplierCode, grnNumber, reload]);

  const open = (r) => api.get(`/supplier-returns/${r.id}`).then((res) => setViewing(res.data.data));

  return (
    <>
      <div className="page-head">
        <h1>Supplier Returns</h1>
        {canEdit && (
          <button className="primary" onClick={() => setParam('new', '1')}>+ Bagong return</button>
        )}
      </div>

      <div className="filters">
        <select value={status} onChange={(e) => setParam('status', e.target.value)}>
          <option value="">Lahat ng status</option>
          {STATUSES.map((s) => <option key={s}>{s}</option>)}
        </select>
        <select value={supplierCode} onChange={(e) => setParam('supplier_code', e.target.value)}>
          <option value="">Lahat ng supplier</option>
          {suppliers.map((s) => (
            <option key={s.supplier_code} value={s.supplier_code}>{s.supplier_code} – {s.name}</option>
          ))}
        </select>
        {grnNumber && <button onClick={() => setParam('grn_number', '')}>GRN: {grnNumber} ✕</button>}
      </div>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>Return number</th>
              <th>Supplier</th>
              <th>Galing GRN</th>
              <th>Warehouse</th>
              <th className="num">Qty</th>
              <th>Status</th>
              <th>Stock sync (INV)</th>
              <th>Ginawa</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="8" className="muted">Naglo-load...</td></tr>
            ) : returns.length === 0 ? (
              <tr><td colSpan="8" className="muted">Walang supplier return.</td></tr>
            ) : (
              returns.map((r) => (
                <tr key={r.id} className="clickable" onClick={() => open(r)}>
                  <td className="code">{r.return_number}</td>
                  <td><span className="code">{r.supplier_code}</span> {r.supplier?.name}</td>
                  <td className="code">{r.grn_number || '—'}</td>
                  <td className="code">{r.warehouse_code}</td>
                  <td className="num">{r.total_quantity}</td>
                  <td><span className={`badge ${statusColor(r.status)}`}>{r.status}</span></td>
                  <td><span className={`badge ${statusColor(r.stock_sync_status)}`}>{r.stock_sync_status}</span></td>
                  <td className="muted">{dateTime(r.created_at)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {creating && canEdit && (
        <CreateReturnModal
          grnNumber={grnNumber}
          suppliers={suppliers}
          onClose={() => setParam('new', '')}
          onSaved={(r) => {
            setParam('new', '');
            setReload((n) => n + 1);
            setViewing(r);
          }}
        />
      )}

      {viewing && (
        <ReturnDetailModal
          ret={viewing}
          canEdit={canEdit}
          onClose={() => setViewing(null)}
          onChanged={(r) => {
            setViewing(r);
            setReload((n) => n + 1);
          }}
        />
      )}
    </>
  );
}

function CreateReturnModal({ grnNumber, suppliers, onClose, onSaved }) {
  const [mode, setMode] = useState(grnNumber ? 'grn' : 'manual');
  const [grns, setGrns] = useState([]);
  const [grn, setGrn] = useState(null);
  const [selectedGrn, setSelectedGrn] = useState(grnNumber);
  const [warehouses, setWarehouses] = useState([]);
  const [products, setProducts] = useState([]);
  const [form, setForm] = useState({ supplier_code: '', warehouse_code: '', reason: '' });
  const [items, setItems] = useState([{ sku: '', quantity: '' }]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.get('/goods-receipts').then((res) => setGrns(res.data.data));
    api.get('/lookups/warehouses').then((res) => setWarehouses(res.data.data));
    api.get('/lookups/products').then((res) => setProducts(res.data.data));
  }, []);

  // Kapag may napiling GRN, kunin ang mga item nito.
  useEffect(() => {
    if (mode !== 'grn' || !selectedGrn) {
      setGrn(null);
      return;
    }
    const found = grns.find((g) => g.grn_number === selectedGrn);
    if (!found) return;
    api.get(`/goods-receipts/${found.id}`).then((res) => {
      setGrn(res.data.data);
      setItems(res.data.data.items.map((i) => ({ sku: i.sku, quantity: '', max: i.quantity, name: i.product?.name })));
    });
  }, [mode, selectedGrn, grns]);

  const setItem = (index, field, value) => setItems(items.map((it, i) => (i === index ? { ...it, [field]: value } : it)));

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    const chosen = items.filter((i) => i.sku && Number(i.quantity) > 0).map((i) => ({ sku: i.sku, quantity: Number(i.quantity) }));
    try {
      const res = await api.post('/supplier-returns', {
        ...(mode === 'grn'
          ? { grn_number: selectedGrn }
          : { supplier_code: form.supplier_code, warehouse_code: form.warehouse_code }),
        reason: form.reason,
        items: chosen,
      });
      onSaved(res.data.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title="Bagong supplier return" onClose={onClose} wide>
      <form className="form" onSubmit={save}>
        {error && <div className="alert error">{error}</div>}
        <div className="tabs">
          <button type="button" className={mode === 'grn' ? 'active' : ''} onClick={() => setMode('grn')}>Mula sa GRN</button>
          <button type="button" className={mode === 'manual' ? 'active' : ''} onClick={() => { setMode('manual'); setItems([{ sku: '', quantity: '' }]); }}>
            Walang GRN
          </button>
        </div>

        {mode === 'grn' ? (
          <label>
            GRN *
            <select value={selectedGrn} onChange={(e) => setSelectedGrn(e.target.value)} required>
              <option value="">Pumili...</option>
              {grns.map((g) => (
                <option key={g.grn_number} value={g.grn_number}>
                  {g.grn_number} · {g.po_number} · {g.purchase_order?.supplier?.name}
                </option>
              ))}
            </select>
            {grn && <span className="muted small">Ibabalik kay {grn.purchase_order?.supplier?.name}, mula sa {grn.warehouse_code}.</span>}
          </label>
        ) : (
          <div className="row">
            <label>
              Supplier *
              <select value={form.supplier_code} onChange={(e) => setForm({ ...form, supplier_code: e.target.value })} required>
                <option value="">Pumili...</option>
                {suppliers.map((s) => <option key={s.supplier_code} value={s.supplier_code}>{s.supplier_code} – {s.name}</option>)}
              </select>
            </label>
            <label>
              Mula sa warehouse *
              <select value={form.warehouse_code} onChange={(e) => setForm({ ...form, warehouse_code: e.target.value })} required>
                <option value="">Pumili...</option>
                {warehouses.map((w) => <option key={w.warehouse_code} value={w.warehouse_code}>{w.warehouse_code} – {w.name}</option>)}
              </select>
            </label>
          </div>
        )}

        <table className="items">
          <thead>
            <tr><th>SKU</th><th className="num">{mode === 'grn' ? 'Na-receive' : ''}</th><th className="num">Ibabalik</th><th /></tr>
          </thead>
          <tbody>
            {items.map((item, i) => (
              <tr key={i}>
                <td>
                  {mode === 'grn' ? (
                    <><span className="code">{item.sku}</span> <span className="muted small">{item.name}</span></>
                  ) : (
                    <select value={item.sku} onChange={(e) => setItem(i, 'sku', e.target.value)} required>
                      <option value="">Pumili...</option>
                      {products.map((p) => <option key={p.sku} value={p.sku}>{p.sku} – {p.name}</option>)}
                    </select>
                  )}
                </td>
                <td className="num">{item.max ?? ''}</td>
                <td style={{ width: 110 }}>
                  <input type="number" min="0" max={item.max} step="1" value={item.quantity} onChange={(e) => setItem(i, 'quantity', e.target.value)} />
                </td>
                <td>
                  {mode === 'manual' && items.length > 1 && (
                    <button type="button" className="link danger" onClick={() => setItems(items.filter((_, j) => j !== i))}>✕</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {mode === 'manual' && (
          <div><button type="button" onClick={() => setItems([...items, { sku: '', quantity: '' }])}>+ Item</button></div>
        )}

        <label>
          Dahilan *
          <textarea rows="2" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} required />
        </label>
        <div className="form-actions">
          <button type="button" onClick={onClose}>Kanselahin</button>
          <button className="primary" disabled={saving}>{saving ? 'Sine-save...' : 'I-save ang return'}</button>
        </div>
      </form>
    </Modal>
  );
}

function ReturnDetailModal({ ret, canEdit, onClose, onChanged }) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const canCancel = canEdit && ret.status === 'Pending' && ['not_sent', 'failed'].includes(ret.stock_sync_status);

  const cancel = async () => {
    if (!window.confirm(`I-cancel ang ${ret.return_number}?`)) return;
    setBusy(true);
    setError('');
    try {
      const res = await api.post(`/supplier-returns/${ret.id}/cancel`);
      onChanged(res.data.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={ret.return_number} onClose={onClose} wide>
      {error && <div className="alert error">{error}</div>}
      <dl className="details">
        <dt>Status</dt>
        <dd><span className={`badge ${statusColor(ret.status)}`}>{ret.status}</span></dd>
        <dt>Supplier</dt>
        <dd><span className="code">{ret.supplier_code}</span> {ret.supplier?.name}</dd>
        <dt>Galing GRN</dt>
        <dd className="code">{ret.grn_number || '—'}</dd>
        <dt>Mula sa</dt>
        <dd className="code">{ret.branch_code} / {ret.warehouse_code}</dd>
        <dt>Dahilan</dt>
        <dd>{ret.reason}</dd>
        <dt>Ginawa ni</dt>
        <dd><span className="code">{ret.returned_by}</span> · {dateTime(ret.created_at)}</dd>
        <dt>Stock sync (INV)</dt>
        <dd>
          <span className={`badge ${statusColor(ret.stock_sync_status)}`}>{ret.stock_sync_status}</span>{' '}
          <span className="muted">{SYNC_LABEL[ret.stock_sync_status]}</span>
          {ret.movement_number && <> · <span className="code">{ret.movement_number}</span></>}
        </dd>
      </dl>
      <table className="items">
        <thead><tr><th>SKU</th><th>Produkto</th><th className="num">Qty</th></tr></thead>
        <tbody>
          {ret.items.map((i) => (
            <tr key={i.id}>
              <td className="code">{i.sku}</td>
              <td>{i.product?.name}</td>
              <td className="num">{i.quantity} {i.product?.unit}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {canCancel && (
        <div className="decision form-actions">
          <button className="danger-btn" disabled={busy} onClick={cancel}>Cancel return</button>
        </div>
      )}
    </Modal>
  );
}
