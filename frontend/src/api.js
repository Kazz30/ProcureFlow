import axios from 'axios';

// Ang base URL ay galing sa VITE_API_URL (itinatakda ng docker-compose.yml habang nagbi-build).
// Halimbawa: http://localhost:8000/api
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: { Accept: 'application/json' },
});

export default api;
