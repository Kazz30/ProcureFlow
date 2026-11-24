import { createContext, useContext, useEffect, useState } from 'react';
import api, { getToken, setToken } from './api';

const AuthContext = createContext(null);

export const ROLES = {
  ADMIN: 'Admin',
  MANAGER: 'Manager',
  PURCHASING: 'Purchasing Officer',
};

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(Boolean(getToken()));

  // Kapag may naka-save na token, alamin kung sino ang user.
  useEffect(() => {
    if (!getToken()) return;
    api
      .get('/me')
      .then((res) => setUser(res.data.user))
      .catch(() => setToken(null))
      .finally(() => setLoading(false));
  }, []);

  const login = async (email, password) => {
    const res = await api.post('/login', { email, password });
    setToken(res.data.token);
    setUser(res.data.user);
  };

  const logout = async () => {
    try {
      await api.post('/logout');
    } finally {
      setToken(null);
      setUser(null);
    }
  };

  const hasRole = (...roles) => Boolean(user && roles.includes(user.role));

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, hasRole }}>
      {children}
    </AuthContext.Provider>
  );
}

export const useAuth = () => useContext(AuthContext);
