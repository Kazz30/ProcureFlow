import './App.css';

function App() {
  return (
    <main style={{ fontFamily: 'system-ui, sans-serif', padding: '2rem' }}>
      <h1>PROC – Supplier &amp; Procurement</h1>
      <p>API: {import.meta.env.VITE_API_URL}</p>
    </main>
  );
}

export default App;
