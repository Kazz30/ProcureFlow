import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import Modal from '../components/Modal.jsx';
import { dateTime, statusColor } from '../format';

const SYNC = ['not_sent', 'pending', 'synced', 'failed'];

/** Paliwanag ng stock_sync_status para sa user. */
export const SYNC_LABEL = {
  not_sent: 'Hindi pa naipadala sa INV',
  pending: 'Nasa outbox, ipapadala sa INV',
  synced: 'Tinanggap ng INV',
  failed: 'Nag-fail sa INV',
};

/** Detalye ng padala sa INV: attempts, susunod na subok, at ang error (hal. 422 kulang ang stock). */
export function SyncDetail({ outbox }) {
  if (!outbox) return null;
  return (
    <>
      {outbox.status === 'pending' && (
        <div className="alert info">
          Nasa outbox (attempt {outbox.attempts}).{' '}
          {outbox.next_attempt_at ? `Susubok ulit: ${dateTime(outbox.next_attempt_at)}.` : 'Ipapadala sa susunod na takbo ng scheduler.'}
          {outbox.last_error && <div className="small">Huling error: {outbox.last_error}</div>}
        </div>
      )}
      {outbox.status === 'failed' && (
        <div className="alert error">
          <strong>Hindi tinanggap ng INV</strong> (pagkatapos ng {outbox.attempts} attempt):
          <div className="small">{outbox.last_error}</div>
        </div>
      )}
    </>
  );
}

export default function GoodsReceipts() {
  const [params, setParams] = useSearchParams();
  const [receipts, setReceipts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [viewing, setViewing] = useState(null);

  const poNumber = params.get('po_number') || '';
  const sync = params.get('stock_sync_status') || '';

  const setFilter = (field, value) => {
    const next = new URLSearchParams(params);
    if (value) next.set(field, value);
    else next.delete(field);
    setParams(next);
  };

  useEffect(() => {
    setLoading(true);
    api
      .get('/goods-receipts', { params: { po_number: poNumber || undefined, stock_sync_status: sync || undefined } })
      .then((res) => setReceipts(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, [poNumber, sync]);

  const open = (grn) => api.get(`/goods-receipts/${grn.id}`).then((res) => setViewing(res.data.data));

  return (
    <>
      <div className="page-head">
        <h1>Goods Receipts</h1>
      </div>
      <p className="muted">Ginagawa ang GRN mula sa Purchase Order (buksan ang PO → "Receive").</p>

      <div className="filters">
        <select value={sync} onChange={(e) => setFilter('stock_sync_status', e.target.value)}>
          <option value="">Lahat ng stock sync status</option>
          {SYNC.map((s) => (
            <option key={s} value={s}>{s} – {SYNC_LABEL[s]}</option>
          ))}
        </select>
        {poNumber && <button onClick={() => setFilter('po_number', '')}>PO: {poNumber} ✕</button>}
      </div>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>GRN number</th>
              <th>PO</th>
              <th>Supplier</th>
              <th>Warehouse</th>
              <th className="num">Qty</th>
              <th>Na-receive</th>
              <th>Stock sync (INV)</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="7" className="muted">Naglo-load...</td></tr>
            ) : receipts.length === 0 ? (
              <tr><td colSpan="7" className="muted">Walang goods receipt.</td></tr>
            ) : (
              receipts.map((g) => (
                <tr key={g.id} className="clickable" onClick={() => open(g)}>
                  <td className="code">{g.grn_number}</td>
                  <td className="code">{g.po_number}</td>
                  <td>{g.purchase_order?.supplier?.name}</td>
                  <td className="code">{g.warehouse_code}</td>
                  <td className="num">{g.total_quantity}</td>
                  <td className="muted">{dateTime(g.received_at)} · <span className="code">{g.received_by}</span></td>
                  <td>
                    <span className={`badge ${statusColor(g.stock_sync_status)}`}>{g.stock_sync_status}</span>
                    {g.movement_number && <span className="code small"> {g.movement_number}</span>}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {viewing && <GrnDetailModal grn={viewing} onClose={() => setViewing(null)} />}
    </>
  );
}

function GrnDetailModal({ grn, onClose }) {
  const { hasRole } = useAuth();
  const navigate = useNavigate();

  return (
    <Modal title={grn.grn_number} onClose={onClose} wide>
      <dl className="details">
        <dt>PO</dt>
        <dd>
          <span className="code">{grn.po_number}</span> · {grn.purchase_order?.supplier?.name}{' '}
          <span className={`badge ${statusColor(grn.purchase_order?.status)}`}>{grn.purchase_order?.status}</span>
        </dd>
        <dt>Warehouse</dt>
        <dd className="code">{grn.branch_code} / {grn.warehouse_code}</dd>
        <dt>Na-receive ni</dt>
        <dd><span className="code">{grn.received_by}</span> · {dateTime(grn.received_at)}</dd>
        <dt>Stock sync (INV)</dt>
        <dd>
          <span className={`badge ${statusColor(grn.stock_sync_status)}`}>{grn.stock_sync_status}</span>{' '}
          <span className="muted">{SYNC_LABEL[grn.stock_sync_status]}</span>
          {grn.movement_number && <> · <span className="code">{grn.movement_number}</span></>}
        </dd>
      </dl>
      <SyncDetail outbox={grn.stock_outbox} />

      <table className="items">
        <thead>
          <tr><th>SKU</th><th>Produkto</th><th className="num">Qty</th></tr>
        </thead>
        <tbody>
          {grn.items.map((i) => (
            <tr key={i.id}>
              <td className="code">{i.sku}</td>
              <td>{i.product?.name}</td>
              <td className="num">{i.quantity} {i.product?.unit}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="decision form-actions">
        <button onClick={() => navigate(`/supplier-returns?grn_number=${grn.grn_number}`)}>Mga return mula dito</button>
        {hasRole(ROLES.ADMIN, ROLES.PURCHASING) && (
          <button className="primary" onClick={() => navigate(`/supplier-returns?grn_number=${grn.grn_number}&new=1`)}>
            Ibalik sa supplier
          </button>
        )}
      </div>
    </Modal>
  );
}
