import axios from 'axios';

// Ang base URL ay galing sa VITE_API_URL (itinatakda ng docker-compose.yml habang nagbi-build).
// Halimbawa: http://localhost:8000/api
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: { Accept: 'application/json' },
});

const TOKEN_KEY = 'proc_token';

export const getToken = () => localStorage.getItem(TOKEN_KEY);
export const setToken = (token) =>
  token ? localStorage.setItem(TOKEN_KEY, token) : localStorage.removeItem(TOKEN_KEY);

// Idinadagdag ang Sanctum token sa bawat request.
api.interceptors.request.use((config) => {
  const token = getToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// Kapag 401 (expired o binurang token), balik sa login.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && getToken()) {
      setToken(null);
      window.location.href = '/login';
    }
    return Promise.reject(error);
  },
);

/** Ginagawang isang mensahe ang error ng Laravel (validation o iba pa). */
export function errorMessage(error) {
  const data = error.response?.data;
  if (data?.errors) {
    return Object.values(data.errors).flat().join(' ');
  }
  return data?.message || error.message || 'May error.';
}

export default api;
