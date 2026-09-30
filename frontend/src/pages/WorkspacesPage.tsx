import { useEffect, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Formik, Form } from 'formik';
import * as Yup from 'yup';
import { toast } from 'react-toastify';
import {
    Container,
    Typography,
    Table,
    TableHead,
    TableBody,
    TableRow,
    TableCell,
    TableContainer,
    Paper,
    Button,
    IconButton,
    Tooltip,
    Box,
    Alert,
    Chip,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    TextField,
} from '@mui/material';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import GroupIcon from '@mui/icons-material/Group';
import WebhookIcon from '@mui/icons-material/Webhook';
import { useAuth } from '../contexts/useAuth';
import { listWorkspaces, createWorkspace, renameWorkspace, deleteWorkspace } from '../api/workspaces';
import { errorMessage, validationErrors } from '../api/errors';
import { isOwner, roleLabels } from '../utils/roles';
import type { Workspace } from '../types';

const validationSchema = Yup.object({
    name: Yup.string().required("Назва є обов'язковою").max(255),
});

function WorkspacesPage() {
    const { user } = useAuth();
    const isDemo = Boolean(user?.is_demo);

    const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
    const [loading, setLoading] = useState(true);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Workspace | null>(null);

    useEffect(() => {
        (async () => {
            try {
                setWorkspaces(await listWorkspaces());
            } catch (error) {
                toast.error(errorMessage(error, 'Помилка при завантаженні workspaces.'));
            } finally {
                setLoading(false);
            }
        })();
    }, []);

    const openDialog = (workspace: Workspace | null) => {
        setEditing(workspace);
        setDialogOpen(true);
    };

    const handleDelete = async (workspace: Workspace) => {
        if (
            !window.confirm(
                `Видалити workspace "${workspace.name}"? Разом із ним зникнуть усі його сайти та посилання.`
            )
        ) {
            return;
        }
        try {
            await deleteWorkspace(workspace.id);
            toast.success('Workspace видалено.');
            setWorkspaces((prev) => prev.filter((w) => w.id !== workspace.id));
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при видаленні workspace.'));
        }
    };

    return (
        <Container maxWidth="lg" sx={{ mt: 4 }}>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
                <Typography variant="h4">Workspaces</Typography>
                <Button variant="contained" onClick={() => openDialog(null)} disabled={isDemo}>
                    Створити workspace
                </Button>
            </Box>
            <Typography color="text.secondary" sx={{ mb: 3 }}>
                Workspace — це команда й набір сайтів, до яких вона має доступ. Окремий workspace на клієнта
                дає змогу дати йому доступ лише до його посилань.
            </Typography>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — створення й зміни вимкнені.
                </Alert>
            )}

            {loading ? (
                <Typography>Завантаження...</Typography>
            ) : workspaces.length === 0 ? (
                <Typography>Workspaces поки немає.</Typography>
            ) : (
                <TableContainer component={Paper}>
                    <Table>
                        <TableHead>
                            <TableRow>
                                <TableCell>Назва</TableCell>
                                <TableCell>Ваша роль</TableCell>
                                <TableCell align="right">Учасників</TableCell>
                                <TableCell align="right">Сайтів</TableCell>
                                <TableCell align="right">Дії</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {workspaces.map((workspace) => (
                                <TableRow key={workspace.id}>
                                    <TableCell>{workspace.name}</TableCell>
                                    <TableCell>
                                        <Chip
                                            size="small"
                                            label={roleLabels[workspace.role]}
                                            color={workspace.role === 'owner' ? 'primary' : 'default'}
                                            variant={workspace.role === 'owner' ? 'filled' : 'outlined'}
                                        />
                                    </TableCell>
                                    <TableCell align="right">{workspace.members_count ?? 0}</TableCell>
                                    <TableCell align="right">{workspace.sites_count ?? 0}</TableCell>
                                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                                        <Tooltip title="Учасники">
                                            <IconButton
                                                component={RouterLink}
                                                to={`/workspaces/${workspace.id}/members`}
                                                aria-label="Учасники"
                                            >
                                                <GroupIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        {isOwner(workspace.role) && (
                                            <Tooltip title="Вебхуки">
                                                <IconButton
                                                    component={RouterLink}
                                                    to={`/workspaces/${workspace.id}/webhooks`}
                                                    aria-label="Вебхуки"
                                                >
                                                    <WebhookIcon fontSize="small" />
                                                </IconButton>
                                            </Tooltip>
                                        )}
                                        <Tooltip title="Перейменувати">
                                            <span>
                                                <IconButton
                                                    onClick={() => openDialog(workspace)}
                                                    disabled={isDemo || !isOwner(workspace.role)}
                                                    aria-label="Перейменувати"
                                                >
                                                    <EditIcon fontSize="small" />
                                                </IconButton>
                                            </span>
                                        </Tooltip>
                                        <Tooltip title="Видалити">
                                            <span>
                                                <IconButton
                                                    onClick={() => handleDelete(workspace)}
                                                    disabled={isDemo || !isOwner(workspace.role)}
                                                    aria-label="Видалити"
                                                >
                                                    <DeleteIcon fontSize="small" />
                                                </IconButton>
                                            </span>
                                        </Tooltip>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableContainer>
            )}

            <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} fullWidth maxWidth="xs">
                <Formik
                    enableReinitialize
                    initialValues={{ name: editing?.name ?? '' }}
                    validationSchema={validationSchema}
                    onSubmit={async (values, { setSubmitting, setErrors }) => {
                        try {
                            if (editing) {
                                const updated = await renameWorkspace(editing.id, values.name);
                                setWorkspaces((prev) =>
                                    prev.map((w) => (w.id === updated.id ? { ...w, ...updated } : w))
                                );
                                toast.success('Workspace оновлено.');
                            } else {
                                const created = await createWorkspace(values.name);
                                setWorkspaces((prev) => [...prev, created]);
                                toast.success('Workspace створено.');
                            }
                            setDialogOpen(false);
                        } catch (error) {
                            setErrors(validationErrors(error));
                            toast.error(errorMessage(error, 'Помилка при збереженні workspace.'));
                        } finally {
                            setSubmitting(false);
                        }
                    }}
                >
                    {({ isSubmitting, errors, touched, values, handleChange }) => (
                        <Form>
                            <DialogTitle>{editing ? 'Перейменувати workspace' : 'Новий workspace'}</DialogTitle>
                            <DialogContent>
                                <TextField
                                    autoFocus
                                    fullWidth
                                    margin="normal"
                                    label="Назва"
                                    name="name"
                                    value={values.name}
                                    onChange={handleChange}
                                    error={touched.name && Boolean(errors.name)}
                                    helperText={touched.name && errors.name}
                                />
                            </DialogContent>
                            <DialogActions>
                                <Button onClick={() => setDialogOpen(false)}>Скасувати</Button>
                                <Button type="submit" variant="contained" disabled={isSubmitting}>
                                    Зберегти
                                </Button>
                            </DialogActions>
                        </Form>
                    )}
                </Formik>
            </Dialog>
        </Container>
    );
}

export default WorkspacesPage;
