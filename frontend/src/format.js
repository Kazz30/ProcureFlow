// Maliliit na helper para sa pagpapakita ng data.

/** Kulay ng badge ayon sa status. */
const STATUS_COLORS = {
  Pending: 'amber',
  Approved: 'blue',
  Rejected: 'red',
  Converted: 'green',
  Draft: 'gray',
  Sent: 'blue',
  'Partially Received': 'amber',
  Received: 'green',
  Cancelled: 'gray',
  Open: 'amber',
  'In Review': 'blue',
  Resolved: 'green',
  Closed: 'gray',
  not_sent: 'gray',
  pending: 'amber',
  synced: 'green',
  failed: 'red',
};

export const statusColor = (status) => STATUS_COLORS[status] || 'gray';

export const peso = (value) =>
  `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const dateTime = (iso) =>
  iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : '';

export const date = (value) =>
  value ? new Date(value).toLocaleDateString('en-PH', { dateStyle: 'medium' }) : '';
