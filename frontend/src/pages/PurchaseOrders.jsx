import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import Modal from '../components/Modal.jsx';
import { dateTime, peso, statusColor } from '../format';

const STATUSES = ['Draft', 'Approved', 'Sent', 'Partially Received', 'Received', 'Cancelled'];
const RECEIVABLE = ['Approved', 'Sent', 'Partially Received'];

export default function PurchaseOrders() {
  const [params, setParams] = useSearchParams();
  const [orders, setOrders] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [viewing, setViewing] = useState(null);

  const filter = {
    status: params.get('status') || '',
    supplier_code: params.get('supplier_code') || '',
    pr_number: params.get('pr_number') || '',
  };

  const setFilter = (field, value) => {
    const next = new URLSearchParams(params);
    if (value) next.set(field, value);
    else next.delete(field);
    setParams(next);
  };

  useEffect(() => {
    api.get('/suppliers').then((res) => setSuppliers(res.data.data));
  }, []);

  const load = () => {
    setLoading(true);
    api
      .get('/purchase-orders', {
        params: {
          status: filter.status || undefined,
          supplier_code: filter.supplier_code || undefined,
          pr_number: filter.pr_number || undefined,
        },
      })
      .then((res) => setOrders(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [params]);

  const open = (po) => api.get(`/purchase-orders/${po.id}`).then((res) => setViewing(res.data.data));

  return (
    <>
      <div className="page-head">
        <h1>Purchase Orders</h1>
      </div>
      <p className="muted">Ginagawa ang PO mula sa approved na Purchase Request (buksan ang PR → "Gumawa ng PO").</p>

      <div className="filters">
        <select value={filter.status} onChange={(e) => setFilter('status', e.target.value)}>
          <option value="">Lahat ng status</option>
          {STATUSES.map((s) => (
            <option key={s}>{s}</option>
          ))}
        </select>
        <select value={filter.supplier_code} onChange={(e) => setFilter('supplier_code', e.target.value)}>
          <option value="">Lahat ng supplier</option>
          {suppliers.map((s) => (
            <option key={s.supplier_code} value={s.supplier_code}>
              {s.supplier_code} – {s.name}
            </option>
          ))}
        </select>
        {filter.pr_number && (
          <button onClick={() => setFilter('pr_number', '')}>PR: {filter.pr_number} ✕</button>
        )}
      </div>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>PO number</th>
              <th>Supplier</th>
              <th>Galing PR</th>
              <th>Branch / Warehouse</th>
              <th className="num">Received / Ordered</th>
              <th className="num">Halaga</th>
              <th>Status</th>
              <th>Ginawa</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="8" className="muted">Naglo-load...</td></tr>
            ) : orders.length === 0 ? (
              <tr><td colSpan="8" className="muted">Walang purchase order.</td></tr>
            ) : (
              orders.map((po) => (
                <tr key={po.id} className="clickable" onClick={() => open(po)}>
                  <td className="code">{po.po_number}</td>
                  <td>
                    <span className="code">{po.supplier_code}</span> {po.supplier?.name}
                  </td>
                  <td className="code">{po.pr_number || '—'}</td>
                  <td className="code">{po.branch_code} / {po.warehouse_code}</td>
                  <td className="num">{po.total_received} / {po.total_quantity}</td>
                  <td className="num">{peso(po.total_amount)}</td>
                  <td><span className={`badge ${statusColor(po.status)}`}>{po.status}</span></td>
                  <td className="muted">{dateTime(po.ordered_at)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {viewing && (
        <PoDetailModal
          po={viewing}
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

function PoDetailModal({ po, onClose, onChanged }) {
  const { hasRole } = useAuth();
  const navigate = useNavigate();
  const [receiving, setReceiving] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const isManager = hasRole(ROLES.MANAGER);
  const isPurchasing = hasRole(ROLES.ADMIN, ROLES.PURCHASING);
  const anyReceived = po.items.some((i) => i.received_quantity > 0);
  const canCancel = ['Draft', 'Approved', 'Sent'].includes(po.status) && !anyReceived;

  const act = async (action, confirmText) => {
    if (confirmText && !window.confirm(confirmText)) return;
    setBusy(true);
    setError('');
    try {
      const res = await api.post(`/purchase-orders/${po.id}/${action}`);
      onChanged(res.data.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const total = po.items.reduce((sum, i) => sum + i.quantity * Number(i.unit_cost), 0);

  return (
    <Modal title={po.po_number} onClose={onClose} wide>
      {error && <div className="alert error">{error}</div>}
      <dl className="details">
        <dt>Status</dt>
        <dd><span className={`badge ${statusColor(po.status)}`}>{po.status}</span></dd>
        <dt>Supplier</dt>
        <dd>
          <span className="code">{po.supplier_code}</span> {po.supplier?.name}
          {po.supplier?.contact_person && (
            <div className="muted small">
              {po.supplier.contact_person} · {po.supplier.phone} · {po.supplier.email}
            </div>
          )}
        </dd>
        <dt>Galing PR</dt>
        <dd className="code">{po.pr_number || '—'}</dd>
        <dt>Ide-deliver sa</dt>
        <dd className="code">{po.branch_code} / {po.warehouse_code}</dd>
        <dt>Ginawa ni</dt>
        <dd><span className="code">{po.ordered_by}</span> · {dateTime(po.ordered_at)}</dd>
      </dl>

      <table className="items">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Produkto</th>
            <th className="num">Ordered</th>
            <th className="num">Received</th>
            <th className="num">Unit cost</th>
            <th className="num">Halaga</th>
          </tr>
        </thead>
        <tbody>
          {po.items.map((item) => (
            <tr key={item.id}>
              <td className="code">{item.sku}</td>
              <td>{item.product?.name}</td>
              <td className="num">{item.quantity}</td>
              <td className="num">{item.received_quantity}</td>
              <td className="num">{peso(item.unit_cost)}</td>
              <td className="num">{peso(item.quantity * Number(item.unit_cost))}</td>
            </tr>
          ))}
          <tr>
            <td colSpan="5" className="num"><strong>Kabuuan</strong></td>
            <td className="num"><strong>{peso(total)}</strong></td>
          </tr>
        </tbody>
      </table>

      <div className="decision form-actions">
        {canCancel && (isManager || isPurchasing) && (
          <button className="danger-btn" disabled={busy} onClick={() => act('cancel', `I-cancel ang ${po.po_number}?`)}>
            Cancel PO
          </button>
        )}
        {isManager && po.status === 'Draft' && (
          <button className="primary" disabled={busy} onClick={() => act('approve')}>Approve PO</button>
        )}
        {isPurchasing && po.status === 'Approved' && (
          <button className="primary" disabled={busy} onClick={() => act('send')}>Markahang Sent sa supplier</button>
        )}
        {isPurchasing && RECEIVABLE.includes(po.status) && (
          <button className="primary" onClick={() => setReceiving(true)}>Receive (dumating na)</button>
        )}
        {anyReceived && (
          <button onClick={() => navigate(`/goods-receipts?po_number=${po.po_number}`)}>Tingnan ang mga GRN →</button>
        )}
      </div>

      {receiving && (
        <ReceiveModal
          po={po}
          onClose={() => setReceiving(false)}
          onDone={async (grn) => {
            setReceiving(false);
            window.alert(`Na-save ang ${grn.grn_number}.`);
            const res = await api.get(`/purchase-orders/${po.id}`);
            onChanged(res.data.data);
          }}
        />
      )}
    </Modal>
  );
}

/** F5 – Pag-receive: ilagay kung ilan ang dumating sa bawat item. */
function ReceiveModal({ po, onClose, onDone }) {
  const [qty, setQty] = useState(() =>
    Object.fromEntries(po.items.map((i) => [i.id, String(i.quantity - i.received_quantity)])),
  );
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const save = async () => {
    setSaving(true);
    setError('');
    try {
      const res = await api.post(`/purchase-orders/${po.id}/receive`, {
        items: po.items.map((i) => ({ purchase_order_item_id: i.id, quantity: Number(qty[i.id] || 0) })),
      });
      onDone(res.data.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={`Receive ${po.po_number} → ${po.warehouse_code}`} onClose={onClose} wide>
      {error && <div className="alert error">{error}</div>}
      <table className="items">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Produkto</th>
            <th className="num">Ordered</th>
            <th className="num">Na-receive na</th>
            <th className="num">Dumating ngayon</th>
          </tr>
        </thead>
        <tbody>
          {po.items.map((item) => {
            const remaining = item.quantity - item.received_quantity;
            return (
              <tr key={item.id}>
                <td className="code">{item.sku}</td>
                <td>{item.product?.name}</td>
                <td className="num">{item.quantity}</td>
                <td className="num">{item.received_quantity}</td>
                <td style={{ width: 120 }}>
                  <input
                    type="number" min="0" max={remaining} step="1"
                    value={qty[item.id]} disabled={remaining === 0}
                    onChange={(e) => setQty({ ...qty, [item.id]: e.target.value })}
                  />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <p className="muted small">
        Isang GRN ang mabubuo. Kapag may kulang pa, magiging Partially Received ang PO at puwedeng i-receive ulit mamaya.
      </p>
      <div className="form-actions">
        <button onClick={onClose}>Kanselahin</button>
        <button className="primary" disabled={saving} onClick={save}>{saving ? 'Sine-save...' : 'I-save ang GRN'}</button>
      </div>
    </Modal>
  );
}
