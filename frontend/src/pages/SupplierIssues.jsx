import { useEffect, useState } from 'react';
import api, { errorMessage } from '../api';
import { ROLES, useAuth } from '../auth.jsx';
import Modal from '../components/Modal.jsx';
import { dateTime, statusColor } from '../format';

const STATUSES = ['Open', 'In Review', 'Resolved', 'Closed'];

export default function SupplierIssues() {
  const { hasRole } = useAuth();
  const canEdit = hasRole(ROLES.ADMIN, ROLES.PURCHASING, ROLES.MANAGER);

  const [issues, setIssues] = useState([]);
  const [suppliers, setSuppliers] = useState([]);
  const [filter, setFilter] = useState({ status: '', supplier_code: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [viewing, setViewing] = useState(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    api.get('/suppliers').then((res) => setSuppliers(res.data.data));
  }, []);

  useEffect(() => {
    setLoading(true);
    api
      .get('/supplier-issues', {
        params: { status: filter.status || undefined, supplier_code: filter.supplier_code || undefined },
      })
      .then((res) => setIssues(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, [filter, reload]);

  const open = (issue) => api.get(`/supplier-issues/${issue.id}`).then((res) => setViewing(res.data.data));

  return (
    <>
      <div className="page-head">
        <h1>Supplier Issues</h1>
      </div>
      <p className="muted">Mga reklamo sa produkto na ipinasa ng CRM ("Forward to Procurement").</p>

      <div className="filters">
        <select value={filter.status} onChange={(e) => setFilter({ ...filter, status: e.target.value })}>
          <option value="">Lahat ng status</option>
          {STATUSES.map((s) => <option key={s}>{s}</option>)}
        </select>
        <select value={filter.supplier_code} onChange={(e) => setFilter({ ...filter, supplier_code: e.target.value })}>
          <option value="">Lahat ng supplier</option>
          <option value="none">(Walang supplier)</option>
          {suppliers.map((s) => <option key={s.supplier_code} value={s.supplier_code}>{s.supplier_code} – {s.name}</option>)}
        </select>
      </div>

      {error && <div className="alert error">{error}</div>}

      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>Issue number</th>
              <th>Galing</th>
              <th>SKU</th>
              <th>Supplier</th>
              <th>Uri</th>
              <th className="num">Qty</th>
              <th>Branch</th>
              <th>Status</th>
              <th>Ini-report</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan="9" className="muted">Naglo-load...</td></tr>
            ) : issues.length === 0 ? (
              <tr><td colSpan="9" className="muted">Walang supplier issue. (Darating ang mga ito mula sa CRM.)</td></tr>
            ) : (
              issues.map((i) => (
                <tr key={i.id} className="clickable" onClick={() => open(i)}>
                  <td className="code">{i.issue_number}</td>
                  <td>
                    <span className="badge blue">{i.source_system}</span> <span className="code small">{i.reference_number}</span>
                  </td>
                  <td><span className="code">{i.sku}</span> <span className="muted small">{i.product?.name}</span></td>
                  <td>{i.supplier_code ? <><span className="code">{i.supplier_code}</span> {i.supplier?.name}</> : <span className="badge red">Wala</span>}</td>
                  <td>{i.issue_type}</td>
                  <td className="num">{i.quantity_affected}</td>
                  <td className="code">{i.branch_code}</td>
                  <td><span className={`badge ${statusColor(i.status)}`}>{i.status}</span></td>
                  <td className="muted">{dateTime(i.reported_at)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {viewing && (
        <IssueDetailModal
          issue={viewing}
          suppliers={suppliers}
          canEdit={canEdit}
          onClose={() => setViewing(null)}
          onSaved={(updated) => {
            setViewing(updated);
            setReload((n) => n + 1);
          }}
        />
      )}
    </>
  );
}

function IssueDetailModal({ issue, suppliers, canEdit, onClose, onSaved }) {
  const [form, setForm] = useState({
    status: issue.status,
    resolution: issue.resolution || '',
    supplier_code: issue.supplier_code || '',
  });
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const save = async (e) => {
    e.preventDefault();
    setSaving(true);
    setError('');
    try {
      const res = await api.put(`/supplier-issues/${issue.id}`, {
        status: form.status,
        resolution: form.resolution || null,
        supplier_code: form.supplier_code || null,
      });
      onSaved(res.data.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={issue.issue_number} onClose={onClose} wide>
      <dl className="details">
        <dt>Galing</dt>
        <dd><span className="badge blue">{issue.source_system}</span> <span className="code">{issue.reference_number}</span></dd>
        <dt>Produkto</dt>
        <dd><span className="code">{issue.sku}</span> {issue.product?.name}</dd>
        <dt>Uri</dt>
        <dd>{issue.issue_type} · {issue.quantity_affected} apektado</dd>
        <dt>Branch</dt>
        <dd className="code">{issue.branch_code}</dd>
        <dt>Ini-report</dt>
        <dd>{dateTime(issue.reported_at)}</dd>
        <dt>Paliwanag</dt>
        <dd>{issue.description || '—'}</dd>
        {issue.supplier?.contact_person && (
          <>
            <dt>Contact ng supplier</dt>
            <dd>{issue.supplier.contact_person} · {issue.supplier.phone} · {issue.supplier.email}</dd>
          </>
        )}
      </dl>

      {canEdit ? (
        <form className="form decision" onSubmit={save}>
          {error && <div className="alert error">{error}</div>}
          <div className="row">
            <label>
              Status
              <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                {STATUSES.map((s) => <option key={s}>{s}</option>)}
              </select>
            </label>
            <label>
              Supplier
              <select value={form.supplier_code} onChange={(e) => setForm({ ...form, supplier_code: e.target.value })}>
                <option value="">(Wala)</option>
                {suppliers.map((s) => <option key={s.supplier_code} value={s.supplier_code}>{s.supplier_code} – {s.name}</option>)}
              </select>
            </label>
          </div>
          <label>
            Resolution {['Resolved', 'Closed'].includes(form.status) && '*'}
            <textarea rows="3" value={form.resolution} onChange={(e) => setForm({ ...form, resolution: e.target.value })} />
          </label>
          <div className="form-actions">
            <button type="button" onClick={onClose}>Isara</button>
            <button className="primary" disabled={saving}>{saving ? 'Sine-save...' : 'I-save'}</button>
          </div>
        </form>
      ) : (
        issue.resolution && <p><strong>Resolution:</strong> {issue.resolution}</p>
      )}
    </Modal>
  );
}
