import { lazy, Suspense, useEffect, useState } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import { AuthProvider } from './contexts/AuthContext';
import { useAuth } from './contexts/useAuth';
import Navbar from './components/Navbar';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import SitesPage from './pages/SitesPage';
import SiteLinksPage from './pages/SiteLinksPage';
import LinkFormPage from './pages/LinkFormPage';
import SiteDomainPage from './pages/SiteDomainPage';
import WorkspacesPage from './pages/WorkspacesPage';
import WorkspaceMembersPage from './pages/WorkspaceMembersPage';
import ApiKeysPage from './pages/ApiKeysPage';
import WebhooksPage from './pages/WebhooksPage';
import { fetchAppConfig } from './api/config';

// @mui/x-charts is the single largest dependency in the app - keeping it
// out of the main bundle means everyone who never opens analytics never
// downloads it.
const AnalyticsDashboardPage = lazy(() => import('./pages/AnalyticsDashboardPage'));

function PrivateRoute({ children }: { children: React.ReactNode }) {
    const { user, loading } = useAuth();

    if (loading) {
        return null;
    }

    return user ? <>{children}</> : <Navigate to="/login" replace />;
}

function AppRoutes({ registrationEnabled }: { registrationEnabled: boolean }) {
    return (
        <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route
                path="/register"
                element={registrationEnabled ? <RegisterPage /> : <Navigate to="/login" replace />}
            />
            <Route
                path="/"
                element={
                    <PrivateRoute>
                        <SitesPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/sites"
                element={
                    <PrivateRoute>
                        <SitesPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/settings/api-keys"
                element={
                    <PrivateRoute>
                        <ApiKeysPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/workspaces"
                element={
                    <PrivateRoute>
                        <WorkspacesPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/workspaces/:workspaceId/webhooks"
                element={
                    <PrivateRoute>
                        <WebhooksPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/workspaces/:workspaceId/members"
                element={
                    <PrivateRoute>
                        <WorkspaceMembersPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/sites/:siteId/links"
                element={
                    <PrivateRoute>
                        <SiteLinksPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/sites/:siteId/domain"
                element={
                    <PrivateRoute>
                        <SiteDomainPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/sites/:siteId/links/new"
                element={
                    <PrivateRoute>
                        <LinkFormPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/links/:linkId/edit"
                element={
                    <PrivateRoute>
                        <LinkFormPage />
                    </PrivateRoute>
                }
            />
            <Route
                path="/sites/:siteId/analytics"
                element={
                    <PrivateRoute>
                        <Suspense fallback={null}>
                            <AnalyticsDashboardPage />
                        </Suspense>
                    </PrivateRoute>
                }
            />
            <Route
                path="/links/:linkId/analytics"
                element={
                    <PrivateRoute>
                        <Suspense fallback={null}>
                            <AnalyticsDashboardPage />
                        </Suspense>
                    </PrivateRoute>
                }
            />
            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}

function App() {
    // Fetched once at startup - lets the register flow be disabled on a
    // public demo deployment without a frontend rebuild (see
    // ConfigController on the backend).
    const [registrationEnabled, setRegistrationEnabled] = useState(true);

    useEffect(() => {
        fetchAppConfig()
            .then((config) => setRegistrationEnabled(config.registration_enabled))
            .catch(() => setRegistrationEnabled(true));
    }, []);

    return (
        <AuthProvider>
            <Router>
                <Navbar registrationEnabled={registrationEnabled} />
                <AppRoutes registrationEnabled={registrationEnabled} />
                <ToastContainer position="bottom-right" />
            </Router>
        </AuthProvider>
    );
}

export default App;
