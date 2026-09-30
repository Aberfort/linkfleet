import { useEffect, useState } from 'react';
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
    Box,
    Alert,
    AlertTitle,
    Chip,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    TextField,
    MenuItem,
    IconButton,
    Tooltip,
} from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { useAuth } from '../contexts/useAuth';
import { listApiKeys, createApiKey, revokeApiKey } from '../api/apiKeys';
import { listWorkspaces } from '../api/workspaces';
import { errorMessage, validationErrors } from '../api/errors';
import { publicBaseUrl } from '../api/client';
import type { ApiKey, ApiKeyAccess, CreatedApiKey, Workspace } from '../types';

const DOCS_URL = 'https://github.com/Aberfort/linkfleet/blob/main/docs/API.md';

const accessLabels: Record<ApiKeyAccess, string> = {
    read: 'Читання',
    write: 'Читання й запис',
};

const validationSchema = Yup.object({
    name: Yup.string().required("Назва є обов'язковою").max(100),
    access: Yup.string().oneOf(['read', 'write']).required(),
});

interface FormValues {
    name: string;
    access: ApiKeyAccess;
    /** '' means every workspace. */
    workspace_id: number | '';
}

const formatDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString('uk-UA', { dateStyle: 'medium', timeStyle: 'short' }) : 'Ніколи';

function ApiKeysPage() {
    const { user } = useAuth();
    const isDemo = Boolean(user?.is_demo);

    const [keys, setKeys] = useState<ApiKey[]>([]);
    const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
    const [loading, setLoading] = useState(true);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [created, setCreated] = useState<CreatedApiKey | null>(null);

    useEffect(() => {
        (async () => {
            try {
                const [keyList, workspaceList] = await Promise.all([listApiKeys(), listWorkspaces()]);
                setKeys(keyList);
                setWorkspaces(workspaceList);
            } catch (error) {
                toast.error(errorMessage(error, 'Помилка при завантаженні ключів.'));
            } finally {
                setLoading(false);
            }
        })();
    }, []);

    const scopeLabel = (key: ApiKey): string => {
        if (key.workspace_id === null) {
            return 'Усі workspaces';
        }

        return workspaces.find((w) => w.id === key.workspace_id)?.name ?? `Workspace #${key.workspace_id} (недоступний)`;
    };

    const handleRevoke = async (key: ApiKey) => {
        if (!window.confirm(`Відкликати ключ "${key.name}"? Усе, що ним користується, одразу втратить доступ.`)) {
            return;
        }
        try {
            await revokeApiKey(key.id);
            setKeys((prev) => prev.filter((k) => k.id !== key.id));
            toast.success('Ключ відкликано.');
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося відкликати ключ.'));
        }
    };

    const copy = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success('Скопійовано.');
        } catch {
            toast.error('Не вдалося скопіювати.');
        }
    };

    return (
        <Container maxWidth="lg" sx={{ mt: 4, mb: 6 }}>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
                <Typography variant="h4">API-ключі</Typography>
                <Button variant="contained" onClick={() => setDialogOpen(true)} disabled={isDemo}>
                    Створити ключ
                </Button>
            </Box>
            <Typography color="text.secondary" sx={{ mb: 3 }}>
                Ключ дає скриптам та інтеграціям доступ до вашого акаунта без пароля. Передавайте його в заголовку{' '}
                <code>Authorization: Bearer …</code>. Повний опис —{' '}
                <a href={DOCS_URL} target="_blank" rel="noreferrer">
                    у документації API
                </a>
                .
            </Typography>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — створювати ключі не можна.
                </Alert>
            )}

            {loading ? (
                <Typography>Завантаження...</Typography>
            ) : keys.length === 0 ? (
                <Typography>Ключів поки немає.</Typography>
            ) : (
                <TableContainer component={Paper}>
                    <Table>
                        <TableHead>
                            <TableRow>
                                <TableCell>Назва</TableCell>
                                <TableCell>Доступ</TableCell>
                                <TableCell>Охоплення</TableCell>
                                <TableCell>Востаннє використано</TableCell>
                                <TableCell>Створено</TableCell>
                                <TableCell align="right" />
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {keys.map((key) => (
                                <TableRow key={key.id}>
                                    <TableCell>{key.name}</TableCell>
                                    <TableCell>
                                        <Chip
                                            size="small"
                                            label={accessLabels[key.access]}
                                            color={key.access === 'write' ? 'warning' : 'default'}
                                            variant={key.access === 'write' ? 'filled' : 'outlined'}
                                        />
                                    </TableCell>
                                    <TableCell>{scopeLabel(key)}</TableCell>
                                    <TableCell>{formatDate(key.last_used_at)}</TableCell>
                                    <TableCell>{formatDate(key.created_at)}</TableCell>
                                    <TableCell align="right">
                                        <Button
                                            size="small"
                                            color="error"
                                            onClick={() => handleRevoke(key)}
                                            aria-label={`Відкликати ${key.name}`}
                                        >
                                            Відкликати
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </TableContainer>
            )}

            <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} fullWidth maxWidth="sm">
                <Formik<FormValues>
                    initialValues={{ name: '', access: 'read', workspace_id: '' }}
                    validationSchema={validationSchema}
                    onSubmit={async (values, { setSubmitting, setErrors, resetForm }) => {
                        try {
                            const key = await createApiKey({
                                name: values.name,
                                access: values.access,
                                workspace_id: values.workspace_id === '' ? null : values.workspace_id,
                            });
                            // The list keeps only what is safe to show again; the secret
                            // lives in `created` until the dialog is dismissed.
                            setKeys((prev) => [
                                {
                                    id: key.id,
                                    name: key.name,
                                    access: key.access,
                                    workspace_id: key.workspace_id,
                                    last_used_at: key.last_used_at,
                                    created_at: key.created_at,
                                },
                                ...prev,
                            ]);
                            setCreated(key);
                            resetForm();
                            setDialogOpen(false);
                        } catch (error) {
                            setErrors(validationErrors(error));
                            toast.error(errorMessage(error, 'Не вдалося створити ключ.'));
                        } finally {
                            setSubmitting(false);
                        }
                    }}
                >
                    {({ isSubmitting, errors, touched, values, handleChange }) => (
                        <Form>
                            <DialogTitle>Новий API-ключ</DialogTitle>
                            <DialogContent>
                                <TextField
                                    autoFocus
                                    fullWidth
                                    margin="normal"
                                    label="Назва"
                                    name="name"
                                    placeholder="Наприклад, Zapier або щотижневий звіт"
                                    value={values.name}
                                    onChange={handleChange}
                                    error={touched.name && Boolean(errors.name)}
                                    helperText={touched.name && errors.name}
                                />
                                <TextField
                                    select
                                    fullWidth
                                    margin="normal"
                                    label="Доступ"
                                    name="access"
                                    value={values.access}
                                    onChange={handleChange}
                                    helperText={
                                        values.access === 'write'
                                            ? 'Може створювати, змінювати й видаляти — у межах ваших прав.'
                                            : 'Лише GET-запити: нічого змінити не зможе.'
                                    }
                                >
                                    <MenuItem value="read">{accessLabels.read}</MenuItem>
                                    <MenuItem value="write">{accessLabels.write}</MenuItem>
                                </TextField>
                                <TextField
                                    select
                                    fullWidth
                                    margin="normal"
                                    label="Охоплення"
                                    name="workspace_id"
                                    value={values.workspace_id}
                                    onChange={handleChange}
                                    error={touched.workspace_id && Boolean(errors.workspace_id)}
                                    helperText={
                                        (touched.workspace_id && errors.workspace_id) ||
                                        'Обмежте ключ одним workspace, якщо інтеграція не має бачити решту.'
                                    }
                                >
                                    <MenuItem value="">Усі мої workspaces</MenuItem>
                                    {workspaces.map((workspace) => (
                                        <MenuItem key={workspace.id} value={workspace.id}>
                                            {workspace.name}
                                        </MenuItem>
                                    ))}
                                </TextField>
                            </DialogContent>
                            <DialogActions>
                                <Button onClick={() => setDialogOpen(false)}>Скасувати</Button>
                                <Button type="submit" variant="contained" disabled={isSubmitting}>
                                    Створити
                                </Button>
                            </DialogActions>
                        </Form>
                    )}
                </Formik>
            </Dialog>

            <Dialog open={created !== null} onClose={() => setCreated(null)} fullWidth maxWidth="sm">
                <DialogTitle>Ключ створено</DialogTitle>
                <DialogContent>
                    <Alert severity="warning" sx={{ mb: 2 }}>
                        <AlertTitle>Скопіюйте ключ зараз</AlertTitle>
                        Ми зберігаємо лише його хеш, тож пізніше показати ключ неможливо — тільки створити новий.
                    </Alert>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 2 }}>
                        <Box
                            component="code"
                            data-testid="created-key"
                            sx={{ flexGrow: 1, p: 1.5, bgcolor: 'action.hover', borderRadius: 1, wordBreak: 'break-all' }}
                        >
                            {created?.token}
                        </Box>
                        <Tooltip title="Копіювати">
                            <IconButton onClick={() => created && copy(created.token)} aria-label="Копіювати ключ">
                                <ContentCopyIcon fontSize="small" />
                            </IconButton>
                        </Tooltip>
                    </Box>
                    <Typography variant="body2" color="text.secondary" gutterBottom>
                        Перевірка:
                    </Typography>
                    <Box component="pre" sx={{ m: 0, p: 1.5, bgcolor: 'action.hover', borderRadius: 1, overflowX: 'auto', fontSize: 13 }}>
                        {`curl ${publicBaseUrl}/api/sites \\\n  -H "Authorization: Bearer ${created?.token ?? ''}"`}
                    </Box>
                </DialogContent>
                <DialogActions>
                    <Button variant="contained" onClick={() => setCreated(null)}>
                        Я скопіював ключ
                    </Button>
                </DialogActions>
            </Dialog>
        </Container>
    );
}

export default ApiKeysPage;
