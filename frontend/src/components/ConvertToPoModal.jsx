import { useEffect, useState } from 'react';
import api, { errorMessage } from '../api';
import { peso } from '../format';
import Modal from './Modal.jsx';

/**
 * PR → PO. Kinukuha ang mungkahi (preferred supplier, unit_cost, default warehouse),
 * puwedeng palitan, tapos ise-save. Isang PO bawat supplier.
 */
export default function ConvertToPoModal({ pr, onClose, onDone }) {
  const [draft, setDraft] = useState(null);
  const [lines, setLines] = useState([]);
  const [warehouseCode, setWarehouseCode] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api
      .get(`/purchase-requests/${pr.id}/po-draft`)
      .then((res) => {
        const d = res.data.data;
        setDraft(d);
        setWarehouseCode(d.warehouse_code || '');
        setLines(d.items.map((i) => ({ ...i, unit_cost: i.unit_cost ?? '' })));
      })
      .catch((err) => setError(errorMessage(err)));
  }, [pr.id]);

  const changeSupplier = (index, supplierCode) => {
    setLines(
      lines.map((line, i) => {
        if (i !== index) return line;
        const choice = line.suppliers.find((s) => s.supplier_code === supplierCode);
        return { ...line, supplier_code: supplierCode, unit_cost: choice?.unit_cost ?? '' };
      }),
    );
  };

  const setLine = (index, field, value) =>
    setLines(lines.map((line, i) => (i === index ? { ...line, [field]: value } : line)));

  // Bilang ng PO na mabubuo = bilang ng magkakaibang supplier.
  const groups = lines.reduce((acc, l) => {
    if (!l.supplier_code) return acc;
    acc[l.supplier_code] = (acc[l.supplier_code] || 0) + Number(l.quantity || 0) * Number(l.unit_cost || 0);
    return acc;
  }, {});

  const save = async () => {
    setSaving(true);
    setError('');
    try {
      const res = await api.post(`/purchase-requests/${pr.id}/convert`, {
        warehouse_code: warehouseCode,
        items: lines.map((l) => ({
          purchase_request_item_id: l.purchase_request_item_id,
          supplier_code: l.supplier_code,
          unit_cost: l.unit_cost === '' ? null : Number(l.unit_cost),
          quantity: Number(l.quantity),
        })),
      });
      onDone(res.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal title={`Gumawa ng PO mula sa ${pr.pr_number}`} onClose={onClose} wide>
      {error && <div className="alert error">{error}</div>}
      {!draft ? (
        !error && <p className="muted">Naglo-load...</p>
      ) : (
        <div className="form">
          <label>
            Ide-deliver sa (warehouse ng {draft.branch_code})
            <select value={warehouseCode} onChange={(e) => setWarehouseCode(e.target.value)}>
              {draft.warehouses.map((w) => (
                <option key={w.warehouse_code} value={w.warehouse_code}>
                  {w.warehouse_code} – {w.name}
                  {w.is_default ? ' (default)' : ''}
                </option>
              ))}
            </select>
          </label>

          <table className="items">
            <thead>
              <tr>
                <th>SKU</th>
                <th>Supplier</th>
                <th className="num">Qty</th>
                <th className="num">Unit cost</th>
                <th className="num">Halaga</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line, i) => (
                <tr key={line.purchase_request_item_id}>
                  <td>
                    <span className="code">{line.sku}</span>
                    <div className="muted small">{line.name}</div>
                  </td>
                  <td>
                    {line.suppliers.length === 0 ? (
                      <span className="badge red">Walang supplier sa price list</span>
                    ) : (
                      <select value={line.supplier_code || ''} onChange={(e) => changeSupplier(i, e.target.value)}>
                        {!line.supplier_code && <option value="">Pumili...</option>}
                        {line.suppliers.map((s) => (
                          <option key={s.supplier_code} value={s.supplier_code}>
                            {s.supplier_code} – {s.name} ({peso(s.unit_cost)}, {s.lead_time_days} araw)
                            {s.is_preferred ? ' ★' : ''}
                          </option>
                        ))}
                      </select>
                    )}
                  </td>
                  <td style={{ width: 90 }}>
                    <input type="number" min="1" step="1" value={line.quantity} onChange={(e) => setLine(i, 'quantity', e.target.value)} />
                  </td>
                  <td style={{ width: 110 }}>
                    <input type="number" min="0" step="0.01" value={line.unit_cost} onChange={(e) => setLine(i, 'unit_cost', e.target.value)} />
                  </td>
                  <td className="num">{peso(Number(line.quantity || 0) * Number(line.unit_cost || 0))}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <div className="alert info">
            {Object.keys(groups).length} PO ang mabubuo (isa bawat supplier):{' '}
            {Object.entries(groups)
              .map(([code, total]) => `${code} ${peso(total)}`)
              .join(' · ')}
            . Magiging <strong>Converted</strong> ang {pr.pr_number}. ★ = preferred supplier.
          </div>

          <div className="form-actions">
            <button type="button" onClick={onClose}>Kanselahin</button>
            <button className="primary" disabled={saving} onClick={save}>
              {saving ? 'Ginagawa...' : 'Gumawa ng PO'}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}
