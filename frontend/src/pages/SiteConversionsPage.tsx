import { useCallback, useEffect, useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { toast } from 'react-toastify';
import {
    Container,
    Typography,
    Box,
    Paper,
    Alert,
    AlertTitle,
    Breadcrumbs,
    Button,
    Chip,
    FormControlLabel,
    IconButton,
    Link,
    Stack,
    Switch,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableRow,
    Tooltip,
} from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { useAuth } from '../contexts/useAuth';
import { getSite, updateSite } from '../api/sites';
import { listRecentConversions } from '../api/conversions';
import { publicBaseUrl } from '../api/client';
import { errorMessage } from '../api/errors';
import { canEdit } from '../utils/roles';
import { formatValue } from '../utils/format';
import type { RecentConversion, Site } from '../types';

const DOCS_URL = 'https://github.com/Aberfort/linkfleet/blob/main/docs/API.md#conversions';

function Code({ children, label }: { children: string; label: string }) {
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(children);
            toast.success('Скопійовано.');
        } catch {
            toast.error('Не вдалося скопіювати.');
        }
    };

    return (
        <Box sx={{ position: 'relative' }}>
            <Box
                component="pre"
                sx={{ m: 0, p: 1.5, pr: 5, bgcolor: 'action.hover', borderRadius: 1, overflowX: 'auto', fontSize: 13 }}
            >
                {children}
            </Box>
            <Tooltip title="Копіювати">
                <IconButton size="small" onClick={copy} aria-label={`Копіювати ${label}`} sx={{ position: 'absolute', top: 4, right: 4 }}>
                    <ContentCopyIcon fontSize="inherit" />
                </IconButton>
            </Tooltip>
        </Box>
    );
}

function SiteConversionsPage() {
    const { siteId } = useParams<{ siteId: string }>();
    const { user } = useAuth();
    const id = Number(siteId);

    const [site, setSite] = useState<Site | null>(null);
    const [recent, setRecent] = useState<RecentConversion[] | null>(null);
    const [saving, setSaving] = useState(false);
    const [refreshing, setRefreshing] = useState(false);

    const mayChange = canEdit(site?.role) && !user?.is_demo;

    const loadRecent = useCallback(async () => {
        setRefreshing(true);
        try {
            setRecent(await listRecentConversions(id));
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося завантажити конверсії.'));
        } finally {
            setRefreshing(false);
        }
    }, [id]);

    useEffect(() => {
        (async () => {
            try {
                setSite(await getSite(id));
            } catch (error) {
                toast.error(errorMessage(error, 'Помилка при завантаженні сайту.'));
            }
        })();
        loadRecent();
    }, [id, loadRecent]);

    const toggle = async (enabled: boolean) => {
        setSaving(true);
        try {
            const updated = await updateSite(id, { conversion_tracking: enabled });
            setSite((prev) => (prev ? { ...prev, ...updated } : prev));
            toast.success(enabled ? 'Відстеження конверсій увімкнено.' : 'Відстеження конверсій вимкнено.');
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося змінити налаштування.'));
        } finally {
            setSaving(false);
        }
    };

    const snippet = `<script>
  window.linkfleet = window.linkfleet || { q: [], track: function () { this.q.push(arguments); } };
</script>
<script async src="${publicBaseUrl}/lf.js"></script>`;

    const events = `linkfleet.track('signup');
linkfleet.track('purchase', { value: 49.9, currency: 'USD', id: 'order-1001' });`;

    const server = `curl -X POST ${publicBaseUrl}/api/conversions \\
  -H "Authorization: Bearer lf_ВАШ_КЛЮЧ" \\
  -H "Content-Type: application/json" \\
  -d '{"click_id": "<lf_click з адреси сторінки>", "event": "purchase",
       "value": 49.90, "currency": "USD", "external_id": "order-1001"}'`;

    return (
        <Container maxWidth="md" sx={{ mt: 4, mb: 6 }}>
            <Breadcrumbs sx={{ mb: 2 }}>
                <Link component={RouterLink} to="/sites" underline="hover">
                    Сайти
                </Link>
                <Typography color="text.primary">{site?.name ?? '...'}</Typography>
            </Breadcrumbs>

            <Typography variant="h4" gutterBottom>
                Конверсії
            </Typography>
            <Typography color="text.secondary" sx={{ mb: 3 }}>
                Кліки показують, скільки людей перейшло. Конверсії — скільки з них зробило те, заради чого ви це
                затівали: зареєструвалось, купило. Так видно, які посилання приносять гроші, а не лише трафік.{' '}
                <a href={DOCS_URL} target="_blank" rel="noreferrer">
                    Докладніше в документації
                </a>
                .
            </Typography>

            {user?.is_demo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — змінити налаштування не можна.
                </Alert>
            )}

            <Paper sx={{ p: 3, mb: 3 }}>
                <FormControlLabel
                    control={
                        <Switch
                            checked={site?.conversion_tracking ?? false}
                            disabled={!site || !mayChange || saving}
                            onChange={(e) => toggle(e.target.checked)}
                        />
                    }
                    label="Відстежувати конверсії для цього сайту"
                />
                <Typography variant="body2" color="text.secondary" sx={{ ml: 6 }}>
                    Коли увімкнено, перехід за коротким посиланням дописує до цільового URL параметр{' '}
                    <code>lf_click</code>. Ваш сайт його запам'ятовує й повертає нам, коли відвідувач зробив
                    цільову дію.
                </Typography>
                {site && !canEdit(site.role) && !user?.is_demo && (
                    <Alert severity="info" sx={{ mt: 2 }}>
                        Перемикати відстеження можуть редактори та власники workspace.
                    </Alert>
                )}
            </Paper>

            {site?.conversion_tracking ? (
                <Stack gap={3} sx={{ mb: 3 }}>
                    <Paper sx={{ p: 3 }}>
                        <Typography variant="h6" gutterBottom>
                            1. Підключіть скрипт на сайті призначення
                        </Typography>
                        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
                            Вставте на сторінки, куди ведуть ваші посилання (і на сторінку подяки). Скрипт запам'ятає, з
                            якого кліку прийшов відвідувач, на 90 днів — у cookie <code>lf_click</code> цього сайту.
                        </Typography>
                        <Code label="скрипт">{snippet}</Code>
                    </Paper>

                    <Paper sx={{ p: 3 }}>
                        <Typography variant="h6" gutterBottom>
                            2. Повідомте про дію
                        </Typography>
                        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
                            У момент реєстрації чи покупки. <code>id</code> робить повторну відправку безпечною:
                            та сама покупка не порахується двічі.
                        </Typography>
                        <Code label="події">{events}</Code>
                    </Paper>

                    <Paper sx={{ p: 3 }}>
                        <Typography variant="h6" gutterBottom>
                            Для грошей — з вашого сервера
                        </Typography>
                        <Alert severity="warning" sx={{ mb: 1.5 }}>
                            <AlertTitle>Події з браузера можна підробити</AlertTitle>
                            Кому відомий токен кліку, той може надіслати «покупку». Для реєстрацій це зазвичай
                            допустимо, для доходу — ні. Надсилайте суму зі свого сервера з{' '}
                            <Link component={RouterLink} to="/settings/api-keys">
                                API-ключем
                            </Link>{' '}
                            з правом запису: такі конверсії позначаються як «сервер».
                        </Alert>
                        <Code label="серверний запит">{server}</Code>
                    </Paper>
                </Stack>
            ) : (
                site && (
                    <Alert severity="info" sx={{ mb: 3 }}>
                        Увімкніть відстеження вище — і тут з'являться сніпет та приклади для підключення.
                    </Alert>
                )
            )}

            <Paper sx={{ p: 3 }}>
                <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1 }}>
                    <Typography variant="h6">Останні конверсії</Typography>
                    <Button size="small" onClick={loadRecent} disabled={refreshing}>
                        {refreshing ? 'Оновлюю...' : 'Оновити'}
                    </Button>
                </Stack>
                {recent === null ? (
                    <Typography color="text.secondary">Завантаження...</Typography>
                ) : recent.length === 0 ? (
                    <Typography color="text.secondary">
                        Ще жодної. Щойно ваш сайт надішле першу — вона з'явиться тут.
                    </Typography>
                ) : (
                    <Table size="small">
                        <TableHead>
                            <TableRow>
                                <TableCell>Час</TableCell>
                                <TableCell>Подія</TableCell>
                                <TableCell>Посилання</TableCell>
                                <TableCell align="right">Сума</TableCell>
                                <TableCell>Звідки</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {recent.map((conversion) => (
                                <TableRow key={conversion.id}>
                                    <TableCell>{new Date(conversion.created_at).toLocaleString('uk-UA')}</TableCell>
                                    <TableCell>
                                        <code>{conversion.event}</code>
                                    </TableCell>
                                    <TableCell>
                                        <code>{conversion.link}</code>
                                    </TableCell>
                                    <TableCell align="right">{formatValue(conversion.value, conversion.currency)}</TableCell>
                                    <TableCell>
                                        <Tooltip
                                            title={
                                                conversion.source === 'server'
                                                    ? 'Надіслано з вашого сервера з API-ключем'
                                                    : 'Надіслано з браузера відвідувача — сума не перевірена'
                                            }
                                        >
                                            <Chip
                                                size="small"
                                                variant="outlined"
                                                color={conversion.source === 'server' ? 'success' : 'default'}
                                                label={conversion.source === 'server' ? 'сервер' : 'браузер'}
                                            />
                                        </Tooltip>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Paper>
        </Container>
    );
}

export default SiteConversionsPage;
