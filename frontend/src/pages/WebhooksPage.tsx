import { Fragment, useCallback, useEffect, useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
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
    AlertTitle,
    Breadcrumbs,
    Link,
    Chip,
    Switch,
    Stack,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    TextField,
    FormControlLabel,
    Checkbox,
    FormGroup,
    FormHelperText,
    Collapse,
} from '@mui/material';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { useAuth } from '../contexts/useAuth';
import { getWorkspace } from '../api/workspaces';
import {
    listWebhooks,
    createWebhook,
    updateWebhook,
    deleteWebhook,
    rotateWebhookSecret,
    testWebhook,
    listDeliveries,
} from '../api/webhooks';
import { errorMessage, validationErrors } from '../api/errors';
import { isOwner } from '../utils/roles';
import type { Webhook, WebhookDelivery, WebhookEventName, Workspace } from '../types';

const DOCS_URL = 'https://github.com/Aberfort/linkfleet/blob/main/docs/API.md#webhooks';

const EVENTS: { value: WebhookEventName; label: string; hint: string }[] = [
    { value: 'link.created', label: 'Посилання створено', hint: 'Зокрема кожен рядок імпорту CSV' },
    { value: 'link.updated', label: 'Посилання змінено', hint: 'Адреса, пароль, термін дії, вмикання й вимикання' },
    { value: 'link.deleted', label: 'Посилання видалено', hint: 'Не спрацьовує, коли видаляється весь сайт' },
    { value: 'link.clicked', label: 'Клік по посиланню', hint: 'Може бути багато — кожен клік окрема подія' },
    { value: 'conversion.created', label: 'Нова конверсія', hint: 'Реєстрація чи покупка, про яку повідомив ваш сайт' },
];

const eventLabel = (event: string): string =>
    event === 'ping' ? 'Тест' : (EVENTS.find((e) => e.value === event)?.label ?? event);

const validationSchema = Yup.object({
    url: Yup.string().required("Адреса є обов'язковою").max(2048),
    events: Yup.array().of(Yup.string()).min(1, 'Оберіть хоча б одну подію'),
});

const formatDate = (iso: string): string =>
    new Date(iso).toLocaleString('uk-UA', { dateStyle: 'medium', timeStyle: 'medium' });

interface FormValues {
    url: string;
    events: WebhookEventName[];
}

function DeliveryChip({ delivery }: { delivery: Pick<WebhookDelivery, 'success' | 'status_code'> }) {
    return (
        <Chip
            size="small"
            color={delivery.success ? 'success' : 'error'}
            variant={delivery.success ? 'filled' : 'outlined'}
            label={delivery.status_code ?? 'без відповіді'}
        />
    );
}

function WebhooksPage() {
    const { workspaceId } = useParams<{ workspaceId: string }>();
    const { user } = useAuth();
    const id = Number(workspaceId);
    const isDemo = Boolean(user?.is_demo);

    const [workspace, setWorkspace] = useState<Workspace | null>(null);
    const [webhooks, setWebhooks] = useState<Webhook[]>([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<Webhook | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [revealed, setRevealed] = useState<string | null>(null);
    const [logFor, setLogFor] = useState<Webhook | null>(null);
    const [log, setLog] = useState<WebhookDelivery[] | null>(null);
    const [openRow, setOpenRow] = useState<number | null>(null);
    const [testing, setTesting] = useState<number | null>(null);

    const owner = isOwner(workspace?.role);
    const canChange = owner && !isDemo;

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const workspaceData = await getWorkspace(id);
            setWorkspace(workspaceData);
            // Webhooks are owner-only even to read; do not ask if we would be refused.
            setWebhooks(isOwner(workspaceData.role) ? await listWebhooks(id) : []);
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при завантаженні вебхуків.'));
        } finally {
            setLoading(false);
        }
    }, [id]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const replace = (updated: Webhook) =>
        setWebhooks((prev) => prev.map((w) => (w.id === updated.id ? { ...w, ...updated, secret: undefined } : w)));

    const openDialog = (webhook: Webhook | null) => {
        setEditing(webhook);
        setDialogOpen(true);
    };

    const handleToggle = async (webhook: Webhook) => {
        try {
            replace(await updateWebhook(webhook.id, { is_active: !webhook.is_active }));
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося змінити стан вебхука.'));
        }
    };

    const handleDelete = async (webhook: Webhook) => {
        if (!window.confirm(`Видалити вебхук ${webhook.url}? Його журнал доставок теж зникне.`)) {
            return;
        }
        try {
            await deleteWebhook(webhook.id);
            setWebhooks((prev) => prev.filter((w) => w.id !== webhook.id));
            toast.success('Вебхук видалено.');
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося видалити вебхук.'));
        }
    };

    const handleTest = async (webhook: Webhook) => {
        setTesting(webhook.id);
        try {
            const delivery = await testWebhook(webhook.id);
            if (delivery.success) {
                toast.success(`Тест доставлено (${delivery.status_code}).`);
            } else {
                toast.error(delivery.error ?? 'Тест не доставлено.');
            }
            setWebhooks((prev) =>
                prev.map((w) =>
                    w.id === webhook.id
                        ? {
                              ...w,
                              latest_delivery: {
                                  success: delivery.success,
                                  status_code: delivery.status_code,
                                  event: delivery.event,
                                  created_at: delivery.created_at,
                              },
                          }
                        : w
                )
            );
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося надіслати тест.'));
        } finally {
            setTesting(null);
        }
    };

    const handleRotate = async (webhook: Webhook) => {
        if (!window.confirm('Замінити секрет? Старий одразу перестане підходити — отримувач має отримати новий.')) {
            return;
        }
        try {
            const rotated = await rotateWebhookSecret(webhook.id);
            replace(rotated);
            setRevealed(rotated.secret ?? null);
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося замінити секрет.'));
        }
    };

    const openLog = async (webhook: Webhook) => {
        setLogFor(webhook);
        setLog(null);
        setOpenRow(null);
        try {
            setLog(await listDeliveries(webhook.id));
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося завантажити журнал.'));
            setLogFor(null);
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
            <Breadcrumbs sx={{ mb: 2 }}>
                <Link component={RouterLink} to="/workspaces" underline="hover">
                    Workspaces
                </Link>
                <Typography color="text.primary">{workspace?.name ?? '...'}</Typography>
            </Breadcrumbs>

            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
                <Typography variant="h4">Вебхуки</Typography>
                <Button variant="contained" onClick={() => openDialog(null)} disabled={!canChange}>
                    Додати вебхук
                </Button>
            </Box>
            <Typography color="text.secondary" sx={{ mb: 3 }}>
                Коли з посиланнями цього workspace щось відбувається, LinkFleet надсилає POST із підписаним JSON на
                вашу адресу. Так події потрапляють у Slack, CRM чи власний код. Як перевірити підпис —{' '}
                <a href={DOCS_URL} target="_blank" rel="noreferrer">
                    у документації
                </a>
                .
            </Typography>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — створювати вебхуки не можна.
                </Alert>
            )}
            {!loading && workspace && !owner && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Вебхуками керують лише власники workspace: їхні адреси часто містять токени, а журнал
                    зберігає всі надіслані дані.
                </Alert>
            )}

            {loading ? (
                <Typography>Завантаження...</Typography>
            ) : owner && webhooks.length === 0 ? (
                <Typography>Вебхуків поки немає.</Typography>
            ) : (
                owner && (
                    <TableContainer component={Paper}>
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Адреса</TableCell>
                                    <TableCell>Події</TableCell>
                                    <TableCell align="center">Активний</TableCell>
                                    <TableCell>Остання доставка</TableCell>
                                    <TableCell align="right">Дії</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {webhooks.map((webhook) => (
                                    <TableRow key={webhook.id}>
                                        <TableCell sx={{ maxWidth: 280, wordBreak: 'break-all' }}>
                                            <code>{webhook.url}</code>
                                        </TableCell>
                                        <TableCell>
                                            <Stack direction="row" gap={0.5} flexWrap="wrap">
                                                {webhook.events.map((event) => (
                                                    <Chip key={event} size="small" variant="outlined" label={event} />
                                                ))}
                                            </Stack>
                                        </TableCell>
                                        <TableCell align="center">
                                            <Switch
                                                size="small"
                                                checked={webhook.is_active}
                                                onChange={() => handleToggle(webhook)}
                                                disabled={!canChange}
                                                inputProps={{ 'aria-label': `Активний: ${webhook.url}` }}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {webhook.latest_delivery ? (
                                                <Stack direction="row" gap={1} alignItems="center">
                                                    <DeliveryChip delivery={webhook.latest_delivery} />
                                                    <Typography variant="caption" color="text.secondary">
                                                        {formatDate(webhook.latest_delivery.created_at)}
                                                    </Typography>
                                                </Stack>
                                            ) : (
                                                <Typography variant="caption" color="text.secondary">
                                                    Ще не було
                                                </Typography>
                                            )}
                                        </TableCell>
                                        <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                                            <Button
                                                size="small"
                                                onClick={() => handleTest(webhook)}
                                                disabled={!canChange || testing === webhook.id}
                                            >
                                                {testing === webhook.id ? 'Надсилаю...' : 'Тест'}
                                            </Button>
                                            <Button size="small" onClick={() => openLog(webhook)}>
                                                Журнал
                                            </Button>
                                            <Button
                                                size="small"
                                                onClick={() => handleRotate(webhook)}
                                                disabled={!canChange}
                                            >
                                                Новий секрет
                                            </Button>
                                            <Tooltip title="Редагувати">
                                                <span>
                                                    <IconButton
                                                        onClick={() => openDialog(webhook)}
                                                        disabled={!canChange}
                                                        aria-label={`Редагувати ${webhook.url}`}
                                                    >
                                                        <EditIcon fontSize="small" />
                                                    </IconButton>
                                                </span>
                                            </Tooltip>
                                            <Tooltip title="Видалити">
                                                <span>
                                                    <IconButton
                                                        onClick={() => handleDelete(webhook)}
                                                        disabled={!canChange}
                                                        aria-label={`Видалити ${webhook.url}`}
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
                )
            )}

            <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} fullWidth maxWidth="sm">
                <Formik<FormValues>
                    enableReinitialize
                    initialValues={{
                        url: editing?.url ?? '',
                        events: editing?.events ?? ['link.created', 'link.clicked'],
                    }}
                    validationSchema={validationSchema}
                    onSubmit={async (values, { setSubmitting, setErrors }) => {
                        try {
                            if (editing) {
                                replace(await updateWebhook(editing.id, values));
                                toast.success('Вебхук оновлено.');
                            } else {
                                const created = await createWebhook(id, values);
                                setWebhooks((prev) => [...prev, { ...created, secret: undefined }]);
                                setRevealed(created.secret ?? null);
                                toast.success('Вебхук створено.');
                            }
                            setDialogOpen(false);
                        } catch (error) {
                            setErrors(validationErrors(error));
                            toast.error(errorMessage(error, 'Не вдалося зберегти вебхук.'));
                        } finally {
                            setSubmitting(false);
                        }
                    }}
                >
                    {({ isSubmitting, errors, touched, values, handleChange, setFieldValue }) => (
                        <Form>
                            <DialogTitle>{editing ? 'Редагувати вебхук' : 'Новий вебхук'}</DialogTitle>
                            <DialogContent>
                                <TextField
                                    autoFocus
                                    fullWidth
                                    margin="normal"
                                    label="Адреса"
                                    name="url"
                                    placeholder="https://example.com/hooks/linkfleet"
                                    value={values.url}
                                    onChange={handleChange}
                                    error={touched.url && Boolean(errors.url)}
                                    helperText={
                                        (touched.url && errors.url) ||
                                        'Публічна https-адреса. Внутрішні адреси та localhost заборонені.'
                                    }
                                />
                                <Typography variant="subtitle2" sx={{ mt: 2 }}>
                                    Події
                                </Typography>
                                <FormGroup>
                                    {EVENTS.map((event) => (
                                        <FormControlLabel
                                            key={event.value}
                                            control={
                                                <Checkbox
                                                    checked={values.events.includes(event.value)}
                                                    onChange={(e) =>
                                                        setFieldValue(
                                                            'events',
                                                            e.target.checked
                                                                ? [...values.events, event.value]
                                                                : values.events.filter((v) => v !== event.value)
                                                        )
                                                    }
                                                />
                                            }
                                            label={
                                                <Box>
                                                    <Typography variant="body2">{event.label}</Typography>
                                                    <Typography variant="caption" color="text.secondary">
                                                        {event.hint}
                                                    </Typography>
                                                </Box>
                                            }
                                        />
                                    ))}
                                </FormGroup>
                                {touched.events && typeof errors.events === 'string' && (
                                    <FormHelperText error>{errors.events}</FormHelperText>
                                )}
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

            <Dialog open={revealed !== null} onClose={() => setRevealed(null)} fullWidth maxWidth="sm">
                <DialogTitle>Секрет для перевірки підпису</DialogTitle>
                <DialogContent>
                    <Alert severity="warning" sx={{ mb: 2 }}>
                        <AlertTitle>Збережіть його зараз</AlertTitle>
                        Секрет показується лише цього разу. Загубили — замініть його кнопкою «Новий секрет».
                    </Alert>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        <Box
                            component="code"
                            data-testid="webhook-secret"
                            sx={{ flexGrow: 1, p: 1.5, bgcolor: 'action.hover', borderRadius: 1, wordBreak: 'break-all' }}
                        >
                            {revealed}
                        </Box>
                        <Tooltip title="Копіювати">
                            <IconButton onClick={() => revealed && copy(revealed)} aria-label="Копіювати секрет">
                                <ContentCopyIcon fontSize="small" />
                            </IconButton>
                        </Tooltip>
                    </Box>
                </DialogContent>
                <DialogActions>
                    <Button variant="contained" onClick={() => setRevealed(null)}>
                        Я зберіг секрет
                    </Button>
                </DialogActions>
            </Dialog>

            <Dialog open={logFor !== null} onClose={() => setLogFor(null)} fullWidth maxWidth="md">
                <DialogTitle>Журнал доставок</DialogTitle>
                <DialogContent>
                    <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }} noWrap>
                        {logFor?.url} — останні 50 спроб
                    </Typography>
                    {log === null ? (
                        <Typography>Завантаження...</Typography>
                    ) : log.length === 0 ? (
                        <Typography>Доставок ще не було. Натисніть «Тест», щоб перевірити.</Typography>
                    ) : (
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Час</TableCell>
                                    <TableCell>Подія</TableCell>
                                    <TableCell>Спроба</TableCell>
                                    <TableCell>Відповідь</TableCell>
                                    <TableCell align="right">мс</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {log.map((delivery) => (
                                    <Fragment key={delivery.id}>
                                        <TableRow
                                            hover
                                            sx={{ cursor: 'pointer' }}
                                            onClick={() => setOpenRow(openRow === delivery.id ? null : delivery.id)}
                                        >
                                            <TableCell>{formatDate(delivery.created_at)}</TableCell>
                                            <TableCell>{eventLabel(delivery.event)}</TableCell>
                                            <TableCell>{delivery.attempt}</TableCell>
                                            <TableCell>
                                                <DeliveryChip delivery={delivery} />
                                            </TableCell>
                                            <TableCell align="right">{delivery.duration_ms}</TableCell>
                                        </TableRow>
                                        <TableRow>
                                            <TableCell colSpan={5} sx={{ py: 0, border: openRow === delivery.id ? undefined : 0 }}>
                                                <Collapse in={openRow === delivery.id} unmountOnExit>
                                                    <Box sx={{ py: 1.5 }}>
                                                        {delivery.error && (
                                                            <Alert severity="error" sx={{ mb: 1 }}>
                                                                {delivery.error}
                                                            </Alert>
                                                        )}
                                                        {delivery.response_excerpt && (
                                                            <>
                                                                <Typography variant="caption" color="text.secondary">
                                                                    Відповідь отримувача
                                                                </Typography>
                                                                <Box component="pre" sx={{ m: 0, mb: 1, p: 1, bgcolor: 'action.hover', borderRadius: 1, overflowX: 'auto', fontSize: 12 }}>
                                                                    {delivery.response_excerpt}
                                                                </Box>
                                                            </>
                                                        )}
                                                        <Typography variant="caption" color="text.secondary">
                                                            Надіслано
                                                        </Typography>
                                                        <Box component="pre" sx={{ m: 0, p: 1, bgcolor: 'action.hover', borderRadius: 1, overflowX: 'auto', fontSize: 12 }}>
                                                            {JSON.stringify(delivery.payload, null, 2)}
                                                        </Box>
                                                    </Box>
                                                </Collapse>
                                            </TableCell>
                                        </TableRow>
                                    </Fragment>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </DialogContent>
                <DialogActions>
                    <Button onClick={() => setLogFor(null)}>Закрити</Button>
                </DialogActions>
            </Dialog>
        </Container>
    );
}

export default WebhooksPage;
