/** Simpleng pop-up para sa mga form. */
export default function Modal({ title, onClose, children }) {
  return (
    <div className="modal-backdrop" onMouseDown={onClose}>
      <div className="modal card" onMouseDown={(e) => e.stopPropagation()}>
        <div className="modal-head">
          <h2>{title}</h2>
          <button className="link" onClick={onClose} aria-label="Isara">
            ✕
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
