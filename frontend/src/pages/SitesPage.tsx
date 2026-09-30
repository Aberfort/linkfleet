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
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    TextField,
    MenuItem,
    FormControlLabel,
    Switch,
} from '@mui/material';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import LinkIcon from '@mui/icons-material/Link';
import BarChartIcon from '@mui/icons-material/BarChart';
import PublicIcon from '@mui/icons-material/Public';
import PaidIcon from '@mui/icons-material/Paid';
import { useAuth } from '../contexts/useAuth';
import { listSites, createSite, updateSite, deleteSite } from '../api/sites';
import { listWorkspaces } from '../api/workspaces';
import { errorMessage, validationErrors } from '../api/errors';
import { canEdit, isOwner } from '../utils/roles';
import type { Site, Workspace } from '../types';

interface FormValues {
    workspace_id: number | '';
    name: string;
    domain: string;
    description: string;
    conversion_tracking: boolean;
}

const emptyValues: FormValues = { workspace_id: '', name: '', domain: '', description: '', conversion_tracking: false };

const validationSchema = Yup.object({
    workspace_id: Yup.number().required('Оберіть workspace'),
    name: Yup.string().required("Назва є обов'язковою").max(255),
    domain: Yup.string().max(255),
    description: Yup.string().max(1000),
});

function SitesPage() {
    const { user } = useAuth();
    const [sites, setSites] = useState<Site[]>([]);
    const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
    const [loading, setLoading] = useState(true);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingSite, setEditingSite] = useState<Site | null>(null);

    const isDemo = Boolean(user?.is_demo);
    // A site can only be created where the user may edit.
    const writable = workspaces.filter((w) => canEdit(w.role));

    useEffect(() => {
        fetchSites();
    }, []);

    const fetchSites = async () => {
        setLoading(true);
        try {
            const [siteList, workspaceList] = await Promise.all([listSites(), listWorkspaces()]);
            setSites(siteList);
            setWorkspaces(workspaceList);
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при завантаженні сайтів.'));
        } finally {
            setLoading(false);
        }
    };

    const openCreateDialog = () => {
        setEditingSite(null);
        setDialogOpen(true);
    };

    const openEditDialog = (site: Site) => {
        setEditingSite(site);
        setDialogOpen(true);
    };

    const handleDelete = async (site: Site) => {
        if (!window.confirm(`Видалити сайт "${site.name}" і всі його посилання?`)) {
            return;
        }
        try {
            await deleteSite(site.id);
            toast.success('Сайт видалено.');
            setSites((prev) => prev.filter((s) => s.id !== site.id));
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при видаленні сайту.'));
        }
    };

    const onSubmit = async (
        values: FormValues,
        { setSubmitting, setErrors }: { setSubmitting: (v: boolean) => void; setErrors: (e: Record<string, string>) => void }
    ) => {
        const { workspace_id, ...fields } = values;

        try {
            if (editingSite) {
                const updated = await updateSite(editingSite.id, fields);
                setSites((prev) => prev.map((s) => (s.id === updated.id ? { ...s, ...updated } : s)));
                toast.success('Сайт оновлено.');
            } else {
                const created = await createSite({ ...fields, workspace_id: Number(workspace_id) });
                setSites((prev) => [created, ...prev]);
                toast.success('Сайт додано.');
            }
            setDialogOpen(false);
        } catch (error) {
            setErrors(validationErrors(error));
            toast.error(errorMessage(error, 'Помилка при збереженні сайту.'));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Container maxWidth="lg" sx={{ mt: 4 }}>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
                <Typography variant="h4">Сайти</Typography>
                <Button
                    variant="contained"
                    color="primary"
                    onClick={openCreateDialog}
                    disabled={isDemo || writable.length === 0}
                >
                    Додати сайт
                </Button>
            </Box>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — створення, редагування та видалення вимкнені.
                </Alert>
            )}

            {loading ? (
                <Typography>Завантаження...</Typography>
            ) : sites.length === 0 ? (
                <Typography>Сайтів поки немає.</Typography>
            ) : (
                <TableContainer component={Paper}>
                    <Table>
                        <TableHead>
                            <TableRow>
                                <TableCell>Назва</TableCell>
                                <TableCell>Workspace</TableCell>
                                <TableCell>Домен</TableCell>
                                <TableCell>Опис</TableCell>
                                <TableCell align="right">Посилань</TableCell>
                                <TableCell align="right">Дії</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {sites.map((site) => (
                                <TableRow key={site.id}>
                                    <TableCell>{site.name}</TableCell>
                                    <TableCell>{site.workspace?.name}</TableCell>
                                    <TableCell>{site.domain}</TableCell>
                                    <TableCell>{site.description}</TableCell>
                                    <TableCell align="right">{site.links_count ?? 0}</TableCell>
                                    <TableCell align="right">
                                        <Tooltip title="Посилання">
                                            <IconButton component={RouterLink} to={`/sites/${site.id}/links`}>
                                                <LinkIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Аналітика">
                                            <IconButton component={RouterLink} to={`/sites/${site.id}/analytics`}>
                                                <BarChartIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Конверсії">
                                            <IconButton component={RouterLink} to={`/sites/${site.id}/conversions`} aria-label="Конверсії">
                                                <PaidIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Власний домен">
                                            <IconButton component={RouterLink} to={`/sites/${site.id}/domain`}>
                                                <PublicIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Редагувати">
                                            <IconButton onClick={() => openEditDialog(site)} disabled={isDemo || !canEdit(site.role)}>
                                                <EditIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Видалити">
                                            <IconButton onClick={() => handleDelete(site)} disabled={isDemo || !isOwner(site.role)}>
                                                <DeleteIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableContainer>
            )}

            <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} fullWidth maxWidth="sm">
                <Formik
                    enableReinitialize
                    initialValues={
                        editingSite
                            ? {
                                  workspace_id: editingSite.workspace_id,
                                  name: editingSite.name,
                                  domain: editingSite.domain ?? '',
                                  description: editingSite.description ?? '',
                                  conversion_tracking: editingSite.conversion_tracking,
                              }
                            : { ...emptyValues, workspace_id: writable[0]?.id ?? '' }
                    }
                    validationSchema={validationSchema}
                    onSubmit={onSubmit}
                >
                    {({ isSubmitting, errors, handleChange, touched, values }) => (
                        <Form>
                            <DialogTitle>{editingSite ? 'Редагувати сайт' : 'Додати сайт'}</DialogTitle>
                            <DialogContent>
                                {!editingSite && writable.length > 1 && (
                                    <TextField
                                        select
                                        fullWidth
                                        margin="normal"
                                        label="Workspace"
                                        name="workspace_id"
                                        value={values.workspace_id}
                                        onChange={handleChange}
                                        error={touched.workspace_id && Boolean(errors.workspace_id)}
                                        helperText={touched.workspace_id && errors.workspace_id}
                                    >
                                        {writable.map((workspace) => (
                                            <MenuItem key={workspace.id} value={workspace.id}>
                                                {workspace.name}
                                            </MenuItem>
                                        ))}
                                    </TextField>
                                )}
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
                                <TextField
                                    fullWidth
                                    margin="normal"
                                    label="Домен"
                                    name="domain"
                                    placeholder="example.com"
                                    value={values.domain}
                                    onChange={handleChange}
                                    error={touched.domain && Boolean(errors.domain)}
                                    helperText={touched.domain && errors.domain}
                                />
                                <TextField
                                    fullWidth
                                    margin="normal"
                                    label="Опис"
                                    name="description"
                                    multiline
                                    rows={2}
                                    value={values.description}
                                    onChange={handleChange}
                                    error={touched.description && Boolean(errors.description)}
                                    helperText={touched.description && errors.description}
                                />
                                <FormControlLabel
                                    sx={{ mt: 1, alignItems: 'flex-start' }}
                                    control={
                                        <Switch
                                            name="conversion_tracking"
                                            checked={values.conversion_tracking}
                                            onChange={handleChange}
                                        />
                                    }
                                    label={
                                        <span>
                                            Відстежувати конверсії
                                            <Typography variant="caption" color="text.secondary" component="span" sx={{ display: 'block' }}>
                                                Перехід дописуватиме до цільового URL параметр <code>lf_click</code>, щоб ваш сайт міг
                                                повідомити про реєстрацію чи покупку. Вимкнено — посилання відкриваються як раніше.
                                            </Typography>
                                        </span>
                                    }
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

export default SitesPage;
