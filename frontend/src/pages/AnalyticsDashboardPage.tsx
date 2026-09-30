import { useCallback, useEffect, useMemo, useState } from 'react';
import { useParams, useSearchParams, Link as RouterLink } from 'react-router-dom';
import { toast } from 'react-toastify';
import {
    Container,
    Typography,
    Box,
    Paper,
    Grid,
    Table,
    TableHead,
    TableBody,
    TableRow,
    TableCell,
    Breadcrumbs,
    Link,
    Alert,
    Button,
    Chip,
    FormControlLabel,
    Menu,
    MenuItem,
    Stack,
    Switch,
    TextField,
    ToggleButton,
    ToggleButtonGroup,
    Tooltip,
} from '@mui/material';
import FileDownloadIcon from '@mui/icons-material/FileDownload';
import { LineChart } from '@mui/x-charts/LineChart';
import { BarChart } from '@mui/x-charts/BarChart';
import { fetchAnalytics, exportAnalytics, type AnalyticsTarget, type ExportType } from '../api/analytics';
import { errorMessage, validationErrors } from '../api/errors';
import { shortLabel } from '../utils/shortUrl';
import { delta, type Delta } from '../utils/delta';
import { saveBlob } from '../utils/download';
import { formatMoney, formatRate } from '../utils/format';
import type { Analytics, AnalyticsQuery, Money } from '../types';

const PRESETS = [
    { days: 7, label: '7 днів' },
    { days: 30, label: '30 днів' },
    { days: 90, label: '90 днів' },
    { days: 365, label: 'Рік' },
] as const;

const DEFAULT_DAYS = 30;

function BreakdownChart({ title, data }: { title: string; data: Analytics['referrers'] }) {
    return (
        <Paper sx={{ p: 2, height: '100%' }}>
            <Typography variant="h6" gutterBottom>
                {title}
            </Typography>
            {data.length === 0 ? (
                <Typography color="text.secondary">Немає даних.</Typography>
            ) : (
                <BarChart
                    height={240}
                    layout="horizontal"
                    yAxis={[{ scaleType: 'band', data: data.map((d) => d.label) }]}
                    series={[{ data: data.map((d) => d.clicks), label: 'Кліки' }]}
                    margin={{ left: 100 }}
                />
            )}
        </Paper>
    );
}

function DeltaChip({ value }: { value: Delta }) {
    const color = value.direction === 'up' || value.direction === 'new' ? 'success' : value.direction === 'down' ? 'error' : 'default';

    return <Chip size="small" color={color} variant="outlined" label={value.label} />;
}

/** Every currency of `list`, each with its change against the same currency a period ago. */
function MoneyList({ list, before }: { list: Money[]; before?: Money[] }) {
    if (list.length === 0) {
        return (
            <Typography variant="h4" component="div" color="text.secondary">
                —
            </Typography>
        );
    }

    return (
        <Stack gap={0.5}>
            {list.map((money) => (
                <Stack key={money.currency} direction="row" alignItems="baseline" gap={1.5}>
                    <Typography variant="h5" component="div">
                        {formatMoney(money)}
                    </Typography>
                    {before && <DeltaChip value={delta(money.amount, before.find((m) => m.currency === money.currency)?.amount ?? 0)} />}
                </Stack>
            ))}
        </Stack>
    );
}

function Kpi({ label, value, change, hint }: { label: string; value: number; change?: Delta; hint?: string }) {
    const title = (
        <Typography variant="body2" color="text.secondary">
            {label}
        </Typography>
    );

    return (
        <Paper sx={{ p: 2 }}>
            {hint ? <Tooltip title={hint}>{title}</Tooltip> : title}
            <Stack direction="row" alignItems="baseline" gap={1.5}>
                <Typography variant="h4" component="div">
                    {value.toLocaleString('uk-UA')}
                </Typography>
                {change && <DeltaChip value={change} />}
            </Stack>
        </Paper>
    );
}

/** What the URL says the user asked for. Nothing in it means the server's default. */
function queryFrom(params: URLSearchParams): AnalyticsQuery {
    const days = Number(params.get('days'));

    return {
        ...(days > 0 ? { days } : {}),
        ...(params.get('from') ? { from: params.get('from') as string } : {}),
        ...(params.get('to') ? { to: params.get('to') as string } : {}),
        compare: params.get('compare') === 'previous',
    };
}

function AnalyticsDashboardPage() {
    const { siteId, linkId } = useParams<{ siteId?: string; linkId?: string }>();
    const [params, setParams] = useSearchParams();
    const [analytics, setAnalytics] = useState<Analytics | null>(null);
    const [loading, setLoading] = useState(true);
    const [exporting, setExporting] = useState(false);
    const [menuAnchor, setMenuAnchor] = useState<HTMLElement | null>(null);
    const [rangeError, setRangeError] = useState<string | null>(null);
    // Edited freely, applied only when both are complete and valid.
    const [customFrom, setCustomFrom] = useState('');
    const [customTo, setCustomTo] = useState('');

    const target = useMemo<AnalyticsTarget>(
        () => (siteId ? { kind: 'site', id: Number(siteId) } : { kind: 'link', id: Number(linkId) }),
        [siteId, linkId]
    );
    const query = queryFrom(params);
    const isCustom = Boolean(query.from || query.to);
    const activeDays = isCustom ? null : (query.days ?? DEFAULT_DAYS);
    const search = params.toString();

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const data = await fetchAnalytics(target, queryFrom(new URLSearchParams(search)));
            setAnalytics(data);
            setRangeError(null);
        } catch (error) {
            const problems = validationErrors(error);
            const rangeProblem = problems.to ?? problems.from ?? problems.days;

            if (rangeProblem) {
                setRangeError(rangeProblem);
            } else {
                toast.error(errorMessage(error, 'Помилка при завантаженні аналітики.'));
            }
        } finally {
            setLoading(false);
        }
    }, [target, search]);

    useEffect(() => {
        load();
    }, [load]);

    // The date fields always show the range being displayed.
    useEffect(() => {
        if (analytics) {
            setCustomFrom(analytics.range.from);
            setCustomTo(analytics.range.to);
        }
    }, [analytics]);

    const update = (changes: Record<string, string | null>) => {
        const next = new URLSearchParams(params);

        for (const [key, value] of Object.entries(changes)) {
            if (value === null) {
                next.delete(key);
            } else {
                next.set(key, value);
            }
        }
        setParams(next, { replace: true });
    };

    const choosePreset = (days: number) => update({ days: String(days), from: null, to: null });

    const applyCustom = (from: string, to: string) => {
        setCustomFrom(from);
        setCustomTo(to);

        if (from && to) {
            update({ from, to, days: null });
        }
    };

    const handleExport = async (type: ExportType) => {
        setMenuAnchor(null);
        setExporting(true);
        try {
            const { blob, filename } = await exportAnalytics(target, query, type);
            saveBlob(blob, filename);
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося вивантажити дані.'));
        } finally {
            setExporting(false);
        }
    };

    if (!analytics) {
        return (
            <Container maxWidth="lg" sx={{ mt: 4 }}>
                <Typography>{loading ? 'Завантаження...' : (rangeError ?? 'Немає даних.')}</Typography>
            </Container>
        );
    }

    const previous = analytics.previous;
    const clicksChange = previous ? delta(analytics.totals.clicks, previous.totals.clicks) : undefined;
    const visitorsChange = previous ? delta(analytics.totals.visitors, previous.totals.visitors) : undefined;
    const labels = analytics.timeseries.map((p) => p.date.slice(5));
    // Worth the room when it is set up, or when there is history to show.
    const showConversions = analytics.conversion_tracking || analytics.conversions.total > 0;

    return (
        <Container maxWidth="lg" sx={{ mt: 4 }}>
            <Breadcrumbs sx={{ mb: 2 }}>
                <Link component={RouterLink} to="/sites" underline="hover">
                    Сайти
                </Link>
                <Typography color="text.primary">Аналітика</Typography>
            </Breadcrumbs>

            <Typography variant="h4" gutterBottom>
                Аналітика
            </Typography>

            <Paper sx={{ p: 2, mb: 3 }}>
                <Stack direction={{ xs: 'column', md: 'row' }} gap={2} alignItems={{ md: 'center' }} flexWrap="wrap">
                    <ToggleButtonGroup
                        size="small"
                        exclusive
                        value={activeDays}
                        onChange={(_, days: number | null) => days && choosePreset(days)}
                        aria-label="Період"
                    >
                        {PRESETS.map((preset) => (
                            <ToggleButton key={preset.days} value={preset.days}>
                                {preset.label}
                            </ToggleButton>
                        ))}
                    </ToggleButtonGroup>

                    <Stack direction="row" gap={1} alignItems="center">
                        <TextField
                            size="small"
                            type="date"
                            label="Від"
                            value={customFrom}
                            onChange={(e) => applyCustom(e.target.value, customTo)}
                            slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: analytics.range.to } }}
                        />
                        <TextField
                            size="small"
                            type="date"
                            label="До"
                            value={customTo}
                            onChange={(e) => applyCustom(customFrom, e.target.value)}
                            slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: analytics.range.to } }}
                        />
                    </Stack>

                    <FormControlLabel
                        control={
                            <Switch
                                checked={query.compare}
                                onChange={(e) => update({ compare: e.target.checked ? 'previous' : null })}
                            />
                        }
                        label="Порівняти з попереднім періодом"
                    />

                    <Box sx={{ flexGrow: 1 }} />

                    <Button
                        variant="outlined"
                        startIcon={<FileDownloadIcon />}
                        onClick={(e) => setMenuAnchor(e.currentTarget)}
                        disabled={exporting}
                        aria-haspopup="menu"
                    >
                        {exporting ? 'Готую...' : 'Експорт CSV'}
                    </Button>
                    <Menu anchorEl={menuAnchor} open={menuAnchor !== null} onClose={() => setMenuAnchor(null)}>
                        <MenuItem onClick={() => handleExport('clicks')}>Кліки</MenuItem>
                        <MenuItem onClick={() => handleExport('conversions')}>Конверсії</MenuItem>
                    </Menu>
                </Stack>
                {rangeError && (
                    <Typography color="error" variant="body2" sx={{ mt: 1 }}>
                        {rangeError}
                    </Typography>
                )}
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                    {analytics.range.from} — {analytics.range.to}, {analytics.range.days} дн.
                    {previous && ` · попередній період: ${previous.range.from} — ${previous.range.to}`}
                </Typography>
            </Paper>

            <Grid container spacing={3} sx={{ mb: 3 }}>
                <Grid item xs={12} sm={6}>
                    <Kpi label="Кліки" value={analytics.totals.clicks} change={clicksChange} />
                </Grid>
                <Grid item xs={12} sm={6}>
                    <Kpi
                        label="Унікальні відвідувачі (≈)"
                        value={analytics.totals.visitors}
                        change={visitorsChange}
                        hint="Рахуємо унікальні мережі, а не людей: щоб не зберігати IP-адреси, ми усікаємо їх до мережі /24 і хешуємо. Двоє людей в одній мережі — це один відвідувач."
                    />
                </Grid>
            </Grid>

            {showConversions ? (
                <>
                    <Grid container spacing={3} sx={{ mb: 3 }}>
                        <Grid item xs={12} sm={4}>
                            <Kpi
                                label="Конверсії"
                                value={analytics.conversions.total}
                                change={previous ? delta(analytics.conversions.total, previous.conversions.total) : undefined}
                            />
                        </Grid>
                        <Grid item xs={12} sm={4}>
                            <Paper sx={{ p: 2, height: '100%' }}>
                                <Tooltip title="Частка кліків за період, після яких була конверсія. Кілька покупок з одного кліку рахуються один раз.">
                                    <Typography variant="body2" color="text.secondary">
                                        Коефіцієнт конверсії
                                    </Typography>
                                </Tooltip>
                                <Stack direction="row" alignItems="baseline" gap={1.5}>
                                    <Typography variant="h4" component="div">
                                        {formatRate(analytics.conversions.rate)}
                                    </Typography>
                                    {previous && (
                                        <Typography variant="caption" color="text.secondary">
                                            було {formatRate(previous.conversions.rate)}
                                        </Typography>
                                    )}
                                </Stack>
                            </Paper>
                        </Grid>
                        <Grid item xs={12} sm={4}>
                            <Paper sx={{ p: 2, height: '100%' }} data-testid="revenue-kpi">
                                <Typography variant="body2" color="text.secondary">
                                    Дохід
                                </Typography>
                                <MoneyList list={analytics.conversions.revenue} before={previous?.conversions.revenue} />
                            </Paper>
                        </Grid>
                    </Grid>

                    {analytics.conversions.by_event.length > 0 && (
                        <Paper sx={{ p: 2, mb: 3 }}>
                            <Typography variant="h6" gutterBottom>
                                Конверсії за подіями
                            </Typography>
                            <Table size="small">
                                <TableHead>
                                    <TableRow>
                                        <TableCell>Подія</TableCell>
                                        <TableCell align="right">Конверсій</TableCell>
                                        <TableCell align="right">Дохід</TableCell>
                                    </TableRow>
                                </TableHead>
                                <TableBody>
                                    {analytics.conversions.by_event.map((row) => (
                                        <TableRow key={row.event}>
                                            <TableCell>
                                                <code>{row.event}</code>
                                            </TableCell>
                                            <TableCell align="right">{row.conversions}</TableCell>
                                            <TableCell align="right">
                                                {row.revenue.length ? row.revenue.map(formatMoney).join(' · ') : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Paper>
                    )}
                </>
            ) : (
                <Alert severity="info" sx={{ mb: 3 }}>
                    Конверсії поки не відстежуються — тобто видно, скільки людей перейшло, але не скільки з них купило чи
                    зареєструвалось.{' '}
                    <Link component={RouterLink} to={`/sites/${analytics.site_id}/conversions`}>
                        Налаштувати
                    </Link>
                </Alert>
            )}

            <Paper sx={{ p: 2, mb: 3 }}>
                <Typography variant="h6" gutterBottom>
                    Кліки за днями
                </Typography>
                <LineChart
                    height={300}
                    xAxis={[{ scaleType: 'point', data: labels }]}
                    series={[
                        { data: analytics.timeseries.map((p) => p.clicks), label: 'Цей період', area: !previous },
                        ...(previous
                            ? [{ data: previous.timeseries.map((p) => p.clicks), label: 'Попередній період' }]
                            : []),
                    ]}
                />
            </Paper>

            <Grid container spacing={3}>
                <Grid item xs={12} md={4}>
                    <BreakdownChart title="Джерела переходів" data={analytics.referrers} />
                </Grid>
                <Grid item xs={12} md={4}>
                    <BreakdownChart title="Браузери" data={analytics.browsers} />
                </Grid>
                <Grid item xs={12} md={4}>
                    <BreakdownChart title="Пристрої" data={analytics.devices} />
                </Grid>
            </Grid>

            {analytics.top_links && (
                <Paper sx={{ p: 2, mt: 3 }}>
                    <Typography variant="h6" gutterBottom>
                        Топ посилань за період
                    </Typography>
                    {analytics.top_links.length === 0 ? (
                        <Typography color="text.secondary">Немає даних.</Typography>
                    ) : (
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Коротке посилання</TableCell>
                                    <TableCell>Ціль</TableCell>
                                    <TableCell align="right">За період</TableCell>
                                    {showConversions && <TableCell align="right">Конверсій</TableCell>}
                                    {showConversions && <TableCell align="right">Дохід</TableCell>}
                                    <TableCell align="right">Всього</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {analytics.top_links.map((link) => (
                                    <TableRow key={link.id}>
                                        <TableCell>
                                            <code>{shortLabel(link)}</code>
                                        </TableCell>
                                        <TableCell
                                            sx={{
                                                maxWidth: 400,
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            {link.target_url}
                                        </TableCell>
                                        <TableCell align="right">{link.period_clicks}</TableCell>
                                        {showConversions && <TableCell align="right">{link.period_conversions ?? 0}</TableCell>}
                                        {showConversions && (
                                            <TableCell align="right">
                                                {link.period_revenue?.length ? link.period_revenue.map(formatMoney).join(' · ') : '—'}
                                            </TableCell>
                                        )}
                                        <TableCell align="right">{link.clicks_count}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Paper>
            )}
            <Box sx={{ mb: 4 }} />
        </Container>
    );
}

export default AnalyticsDashboardPage;
