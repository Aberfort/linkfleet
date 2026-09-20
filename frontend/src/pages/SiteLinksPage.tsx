import { useCallback, useEffect, useRef, useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
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
    Switch,
    Link,
    Breadcrumbs,
    Chip,
    Dialog,
    DialogTitle,
    DialogContent,
    DialogActions,
    Stack,
} from '@mui/material';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/Delete';
import BarChartIcon from '@mui/icons-material/BarChart';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import QrCode2Icon from '@mui/icons-material/QrCode2';
import LockIcon from '@mui/icons-material/Lock';
import ScheduleIcon from '@mui/icons-material/Schedule';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import { useAuth } from '../contexts/useAuth';
import { getSite } from '../api/sites';
import { listLinks, deleteLink, toggleLink, importLinks } from '../api/links';
import { errorMessage } from '../api/errors';
import { publicBaseUrl } from '../api/client';
import { shortLabel } from '../utils/shortUrl';
import type { Site, Link as LinkType } from '../types';

function isExpired(link: LinkType): boolean {
    return link.expires_at !== null && new Date(link.expires_at) < new Date();
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('uk-UA', { dateStyle: 'short', timeStyle: 'short' });
}

function SiteLinksPage() {
    const { siteId } = useParams<{ siteId: string }>();
    const { user } = useAuth();
    const [site, setSite] = useState<Site | null>(null);
    const [links, setLinks] = useState<LinkType[]>([]);
    const [loading, setLoading] = useState(true);
    const [qrLink, setQrLink] = useState<LinkType | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const isDemo = Boolean(user?.is_demo);
    const id = Number(siteId);

    const fetchData = useCallback(async () => {
        setLoading(true);
        try {
            const [siteData, linksData] = await Promise.all([getSite(id), listLinks(id)]);
            setSite(siteData);
            setLinks(linksData);
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при завантаженні посилань.'));
        } finally {
            setLoading(false);
        }
    }, [id]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const handleCopy = async (link: LinkType) => {
        try {
            await navigator.clipboard.writeText(link.short_url);
            toast.success('Посилання скопійовано.');
        } catch {
            toast.error('Не вдалося скопіювати посилання.');
        }
    };

    const handleToggle = async (link: LinkType) => {
        try {
            const updated = await toggleLink(link.id);
            setLinks((prev) => prev.map((l) => (l.id === link.id ? updated : l)));
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при зміні статусу.'));
        }
    };

    const handleDelete = async (link: LinkType) => {
        if (!window.confirm('Видалити це посилання?')) {
            return;
        }
        try {
            await deleteLink(link.id);
            toast.success('Посилання видалено.');
            setLinks((prev) => prev.filter((l) => l.id !== link.id));
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при видаленні посилання.'));
        }
    };

    const handleImport = async (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        // Reset immediately so picking the same file twice still fires onChange.
        event.target.value = '';

        if (!file) {
            return;
        }

        try {
            const result = await importLinks(id, file);
            if (result.imported > 0) {
                toast.success(`Імпортовано посилань: ${result.imported}.`);
            }
            if (result.skipped.length > 0) {
                toast.warn(
                    `Пропущено рядків: ${result.skipped.length}. Перший — рядок ${result.skipped[0].row}: ${result.skipped[0].reason}`
                );
            }
            if (result.imported === 0 && result.skipped.length === 0) {
                toast.info('У файлі не знайшлося рядків для імпорту.');
            }
            await fetchData();
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при імпорті файлу.'));
        }
    };

    return (
        <Container maxWidth="lg" sx={{ mt: 4, mb: 6 }}>
            <Breadcrumbs sx={{ mb: 2 }}>
                <Link component={RouterLink} to="/sites" underline="hover">
                    Сайти
                </Link>
                <Typography color="text.primary">{site?.name ?? '...'}</Typography>
            </Breadcrumbs>

            <Box
                sx={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    gap: 2,
                    flexWrap: 'wrap',
                    mb: 2,
                }}
            >
                <Typography variant="h4">Посилання{site ? `: ${site.name}` : ''}</Typography>
                <Stack direction="row" spacing={1}>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept=".csv,text/csv"
                        onChange={handleImport}
                        style={{ display: 'none' }}
                        data-testid="import-input"
                    />
                    <Button
                        variant="outlined"
                        startIcon={<UploadFileIcon />}
                        onClick={() => fileInputRef.current?.click()}
                        disabled={isDemo}
                    >
                        Імпорт CSV
                    </Button>
                    <Button
                        variant="contained"
                        color="primary"
                        component={RouterLink}
                        to={`/sites/${id}/links/new`}
                        disabled={isDemo}
                    >
                        Додати посилання
                    </Button>
                </Stack>
            </Box>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — створення, редагування та видалення вимкнені.
                </Alert>
            )}

            {loading ? (
                <Typography>Завантаження...</Typography>
            ) : links.length === 0 ? (
                <Typography>Посилань поки немає.</Typography>
            ) : (
                <TableContainer component={Paper}>
                    <Table>
                        <TableHead>
                            <TableRow>
                                <TableCell>Коротке посилання</TableCell>
                                <TableCell>Ціль</TableCell>
                                <TableCell align="right">Кліки</TableCell>
                                <TableCell align="center">Активне</TableCell>
                                <TableCell align="right">Дії</TableCell>
                            </TableRow>
                        </TableHead>
                        <TableBody>
                            {links.map((link) => (
                                <TableRow key={link.id}>
                                    <TableCell>
                                        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                                            <code>{shortLabel(link)}</code>
                                            <Tooltip title="Копіювати">
                                                <IconButton size="small" onClick={() => handleCopy(link)}>
                                                    <ContentCopyIcon fontSize="inherit" />
                                                </IconButton>
                                            </Tooltip>
                                            {link.has_password && (
                                                <Tooltip title="Захищене паролем">
                                                    <LockIcon fontSize="inherit" color="action" />
                                                </Tooltip>
                                            )}
                                            {link.expires_at && (
                                                <Tooltip
                                                    title={
                                                        isExpired(link)
                                                            ? `Термін дії минув ${formatDate(link.expires_at)}`
                                                            : `Діє до ${formatDate(link.expires_at)}`
                                                    }
                                                >
                                                    <ScheduleIcon
                                                        fontSize="inherit"
                                                        color={isExpired(link) ? 'error' : 'action'}
                                                    />
                                                </Tooltip>
                                            )}
                                        </Box>
                                    </TableCell>
                                    <TableCell
                                        sx={{
                                            maxWidth: 320,
                                            overflow: 'hidden',
                                            textOverflow: 'ellipsis',
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        {link.target_url}
                                    </TableCell>
                                    <TableCell align="right">{link.clicks_count}</TableCell>
                                    <TableCell align="center">
                                        {isDemo ? (
                                            <Chip
                                                size="small"
                                                label={link.is_active ? 'Активне' : 'Вимкнене'}
                                                color={link.is_active ? 'success' : 'default'}
                                            />
                                        ) : (
                                            <Switch
                                                checked={link.is_active}
                                                onChange={() => handleToggle(link)}
                                                size="small"
                                            />
                                        )}
                                    </TableCell>
                                    {/* nowrap so the four icons stay on one
                                        line and the table scrolls sideways
                                        instead of the row growing tall. */}
                                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                                        <Tooltip title="QR-код">
                                            <IconButton onClick={() => setQrLink(link)}>
                                                <QrCode2Icon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Аналітика">
                                            <IconButton component={RouterLink} to={`/links/${link.id}/analytics`}>
                                                <BarChartIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Редагувати">
                                            <IconButton
                                                component={RouterLink}
                                                to={`/links/${link.id}/edit`}
                                                disabled={isDemo}
                                            >
                                                <EditIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                        <Tooltip title="Видалити">
                                            <IconButton onClick={() => handleDelete(link)} disabled={isDemo}>
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

            <Dialog open={qrLink !== null} onClose={() => setQrLink(null)} maxWidth="xs" fullWidth>
                <DialogTitle>QR-код: {qrLink && shortLabel(qrLink)}</DialogTitle>
                <DialogContent>
                    {qrLink && (
                        <Box sx={{ display: 'flex', justifyContent: 'center', p: 1 }}>
                            <img
                                src={`${publicBaseUrl}/qr/${qrLink.short_code}.svg`}
                                alt={`QR-код для ${shortLabel(qrLink)}`}
                                width={280}
                                height={280}
                            />
                        </Box>
                    )}
                </DialogContent>
                <DialogActions>
                    {qrLink && (
                        <Button
                            component="a"
                            href={`${publicBaseUrl}/qr/${qrLink.short_code}.svg`}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Відкрити SVG
                        </Button>
                    )}
                    <Button onClick={() => setQrLink(null)}>Закрити</Button>
                </DialogActions>
            </Dialog>
        </Container>
    );
}

export default SiteLinksPage;
