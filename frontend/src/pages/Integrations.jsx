import { useCallback, useEffect, useState } from 'react';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.jsx';
import { dateTime, statusColor } from '../format';

const SYSTEMS = ['CRM', 'PROC', 'INV', 'HR'];

/** A4 – Integrations page (Settings, Admin lang). */
export default function Integrations() {
  return (
    <>
      <div className="page-head">
        <h1>Integrations</h1>
      </div>
      <Health />
      <OutboxSection />
      <LogsSection />
    </>
  );
}

function Health() {
  const [health, setHealth] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const check = useCallback(() => {
    setLoading(true);
    setError('');
    api
      .get('/integrations/health')
      .then((res) => setHealth(res.data.data))
      .catch((err) => setError(errorMessage(err)))
      .finally(() => setLoading(false));
  }, []);

  useEffect(check, [check]);

  return (
    <section className="section">
      <div className="section-head">
        <h2>Health ng mga system</h2>
        <button onClick={check} disabled={loading}>{loading ? 'Sinusuri...' : 'Suriin ulit'}</button>
      </div>
      {error && <div className="alert error">{error}</div>}
      {health && (
        <>
          <p className="muted small">
            Ito ang <strong>{health.system_code}</strong>. Integration:{' '}
            <span className={`badge ${health.enabled ? 'green' : 'gray'}`}>{health.enabled ? 'ENABLED' : 'DISABLED (Yugto 1)'}</span>{' '}
            · Sariling API key: <span className={`badge ${health.has_own_key ? 'green' : 'red'}`}>{health.has_own_key ? 'naka-set' : 'WALA'}</span>
          </p>
          <div className="health-grid">
            {health.systems.map((s) => {
              const color = s.ok === true ? 'green' : s.ok === false ? 'red' : 'gray';
              return (
                <div key={s.system} className={`card health ${color}`}>
                  <div className="health-head">
                    <span className={`dot ${color}`} />
                    <strong>{s.system}</strong>
                    {s.is_self && <span className="muted small">(ito)</span>}
                  </div>
                  <div className="small">
                    {s.ok === true && <>OK{s.status_code ? ` · HTTP ${s.status_code}` : ''}{s.duration_ms != null ? ` · ${s.duration_ms} ms` : ''}</>}
                    {s.ok === false && <>Down{s.status_code ? ` · HTTP ${s.status_code}` : ''}</>}
                    {s.ok === null && <>Hindi pa naka-set</>}
                  </div>
                  {s.url && <div className="muted small code wrap">{s.url}</div>}
                  {s.error && <div className="muted small wrap">{s.error}</div>}
                </div>
              );
            })}
          </div>
        </>
      )}
    </section>
  );
}

function OutboxSection() {
  const [rows, setRows] = useState([]);
  const [counts, setCounts] = useState({ pending: 0, sent: 0, failed: 0 });
  const [filter, setFilter] = useState({ status: '', target_system: '' });
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [viewing, setViewing] = useState(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    api
      .get('/integrations/outbox', {
        params: { status: filter.status || undefined, target_system: filter.target_system || undefined },
      })
      .then((res) => {
        setRows(res.data.data);
        setCounts(res.data.counts);
      })
      .catch((err) => setError(errorMessage(err)));
  }, [filter, reload]);

  const retry = async (row) => {
    setError('');
    setMessage('');
    try {
      const res = await api.post(`/integrations/outbox/${row.id}/retry`);
      setMessage(`${row.reference_number}: ${res.data.message}`);
      setReload((n) => n + 1);
    } catch (err) {
      setError(errorMessage(err));
    }
  };

  return (
    <section className="section">
      <div className="section-head">
        <h2>Outbox</h2>
        <button onClick={() => setReload((n) => n + 1)}>I-refresh</button>
      </div>
      <div className="filters">
        {['', 'pending', 'sent', 'failed'].map((s) => (
          <button key={s || 'all'} className={filter.status === s ? 'primary' : ''} onClick={() => setFilter({ ...filter, status: s })}>
            {s === '' ? `Lahat (${counts.pending + counts.sent + counts.failed})` : `${s} (${counts[s]})`}
          </button>
        ))}
        <select value={filter.target_system} onChange={(e) => setFilter({ ...filter, target_system: e.target.value })}>
          <option value="">Lahat ng system</option>
          {SYSTEMS.map((s) => <option key={s}>{s}</option>)}
        </select>
      </div>
      {error && <div className="alert error">{error}</div>}
      {message && <div className="alert info">{message}</div>}
      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Papunta</th>
              <th>Reference</th>
              <th>Status</th>
              <th className="num">Attempts</th>
              <th>Susunod na subok / Naipadala</th>
              <th>Huling error</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan="8" className="muted">Walang laman.</td></tr>
            ) : (
              rows.map((r) => (
                <tr key={r.id}>
                  <td className="muted">{r.id}</td>
                  <td><span className="code">{r.target_system} {r.method} {r.endpoint}</span></td>
                  <td>
                    <button className="link code" onClick={() => setViewing(r)}>{r.reference_number}</button>
                    {r.payload?.movement_type && <div className="muted small">{r.payload.movement_type}</div>}
                  </td>
                  <td><span className={`badge ${statusColor(r.status)}`}>{r.status}</span></td>
                  <td className="num">{r.attempts}</td>
                  <td className="muted small">
                    {r.status === 'sent' ? dateTime(r.sent_at) : r.next_attempt_at ? dateTime(r.next_attempt_at) : r.status === 'pending' ? 'sa susunod na takbo' : ''}
                  </td>
                  <td className="small error-text">{r.last_error}</td>
                  <td className="actions">
                    {r.status === 'failed' && <button className="primary" onClick={() => retry(r)}>Retry</button>}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      {viewing && (
        <Modal title={`Outbox #${viewing.id} · ${viewing.reference_number}`} onClose={() => setViewing(null)} wide>
          <p className="code">{viewing.target_system} {viewing.method} {viewing.endpoint}</p>
          <pre className="json">{JSON.stringify(viewing.payload, null, 2)}</pre>
          {viewing.last_error && <div className="alert error">{viewing.last_error}</div>}
        </Modal>
      )}
    </section>
  );
}

function LogsSection() {
  const [logs, setLogs] = useState([]);
  const [filter, setFilter] = useState({ direction: '', system: '' });
  const [viewing, setViewing] = useState(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    api
      .get('/integrations/logs', { params: { direction: filter.direction || undefined, system: filter.system || undefined } })
      .then((res) => setLogs(res.data.data));
  }, [filter, reload]);

  return (
    <section className="section">
      <div className="section-head">
        <h2>Huling 100 integration logs</h2>
        <button onClick={() => setReload((n) => n + 1)}>I-refresh</button>
      </div>
      <div className="filters">
        <select value={filter.direction} onChange={(e) => setFilter({ ...filter, direction: e.target.value })}>
          <option value="">Papasok at palabas</option>
          <option value="in">Papasok (in)</option>
          <option value="out">Palabas (out)</option>
        </select>
        <select value={filter.system} onChange={(e) => setFilter({ ...filter, system: e.target.value })}>
          <option value="">Lahat ng system</option>
          {[...SYSTEMS, 'UNKNOWN'].map((s) => <option key={s}>{s}</option>)}
        </select>
      </div>
      <div className="card table-wrap">
        <table>
          <thead>
            <tr>
              <th>Oras</th>
              <th />
              <th>System</th>
              <th>Request</th>
              <th>Reference</th>
              <th className="num">Status</th>
              <th className="num">ms</th>
            </tr>
          </thead>
          <tbody>
            {logs.length === 0 ? (
              <tr><td colSpan="7" className="muted">Wala pang log.</td></tr>
            ) : (
              logs.map((l) => (
                <tr key={l.id} className="clickable" onClick={() => setViewing(l)}>
                  <td className="muted small">{dateTime(l.created_at)}</td>
                  <td><span className={`badge ${l.direction === 'in' ? 'blue' : 'gray'}`}>{l.direction === 'in' ? '← in' : 'out →'}</span></td>
                  <td>{l.system}</td>
                  <td className="code small wrap">{l.method} {shortUrl(l.url)}</td>
                  <td className="code small">{l.reference_number}</td>
                  <td className="num">
                    <span className={`badge ${l.status_code >= 200 && l.status_code < 300 ? 'green' : 'red'}`}>{l.status_code ?? '—'}</span>
                  </td>
                  <td className="num muted">{l.duration_ms}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      {viewing && (
        <Modal title={`Log #${viewing.id}`} onClose={() => setViewing(null)} wide>
          <p className="code wrap">{viewing.direction} · {viewing.system} · {viewing.method} {viewing.url}</p>
          {viewing.error && <div className="alert error">{viewing.error}</div>}
          <h3 className="small muted">Request body</h3>
          <pre className="json">{viewing.request_body ? JSON.stringify(viewing.request_body, null, 2) : '—'}</pre>
          <h3 className="small muted">Response (HTTP {viewing.status_code ?? '—'})</h3>
          <pre className="json">{viewing.response_body ? JSON.stringify(viewing.response_body, null, 2) : '—'}</pre>
        </Modal>
      )}
    </section>
  );
}

/** Ipakita lang ang bahagi pagkatapos ng /api/integration/v1. */
function shortUrl(url) {
  const i = url.indexOf('/api/integration/v1');
  return i >= 0 ? url.slice(i + '/api/integration/v1'.length) : url;
}
