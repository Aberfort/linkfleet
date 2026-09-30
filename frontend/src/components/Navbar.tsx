import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { AppBar, Toolbar, Typography, Button, Chip } from '@mui/material';
import { toast } from 'react-toastify';
import { useAuth } from '../contexts/useAuth';

interface NavbarProps {
    registrationEnabled: boolean;
}

function Navbar({ registrationEnabled }: NavbarProps) {
    const { user, logout, loading } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        try {
            await logout();
            toast.success('Вихід успішний!');
            navigate('/login');
        } catch {
            toast.error('Помилка при виході.');
        }
    };

    return (
        <AppBar position="static">
            <Toolbar>
                <Typography
                    variant="h6"
                    component={RouterLink}
                    to="/"
                    sx={{ flexGrow: 1, color: 'inherit', textDecoration: 'none' }}
                >
                    LinkFleet
                </Typography>

                {loading ? (
                    <Typography variant="body1">Завантаження...</Typography>
                ) : user ? (
                    <>
                        <Button color="inherit" component={RouterLink} to="/sites">
                            Сайти
                        </Button>
                        <Button color="inherit" component={RouterLink} to="/workspaces">
                            Workspaces
                        </Button>
                        <Button color="inherit" component={RouterLink} to="/settings/api-keys">
                            API-ключі
                        </Button>
                        {user.is_demo && (
                            <Chip
                                label="Демо (лише читання)"
                                color="warning"
                                size="small"
                                sx={{ mx: 2 }}
                            />
                        )}
                        <Typography variant="body1" sx={{ mx: 2 }}>
                            Привіт, {user.name}!
                        </Typography>
                        <Button color="inherit" onClick={handleLogout}>
                            Вийти
                        </Button>
                    </>
                ) : (
                    <>
                        <Button color="inherit" component={RouterLink} to="/login">
                            Вхід
                        </Button>
                        {registrationEnabled && (
                            <Button color="inherit" component={RouterLink} to="/register">
                                Реєстрація
                            </Button>
                        )}
                    </>
                )}
            </Toolbar>
        </AppBar>
    );
}

export default Navbar;
