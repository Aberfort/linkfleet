import { useCallback, useEffect, useState } from 'react';
import { useParams, Link as RouterLink } from 'react-router-dom';
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
} from '@mui/material';
import { LineChart } from '@mui/x-charts/LineChart';
import { BarChart } from '@mui/x-charts/BarChart';
import { siteAnalytics, linkAnalytics } from '../api/analytics';
import { errorMessage } from '../api/errors';
import { shortLabel } from '../utils/shortUrl';
import type { Analytics } from '../types';

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

function AnalyticsDashboardPage() {
    const { siteId, linkId } = useParams<{ siteId?: string; linkId?: string }>();
    const [analytics, setAnalytics] = useState<Analytics | null>(null);
    const [loading, setLoading] = useState(true);

    const fetchAnalytics = useCallback(async () => {
        setLoading(true);
        try {
            const data = siteId ? await siteAnalytics(Number(siteId)) : await linkAnalytics(Number(linkId));
            setAnalytics(data);
        } catch (error) {
            toast.error(errorMessage(error, 'Помилка при завантаженні аналітики.'));
        } finally {
            setLoading(false);
        }
    }, [siteId, linkId]);

    useEffect(() => {
        fetchAnalytics();
    }, [fetchAnalytics]);

    if (loading || !analytics) {
        return (
            <Container maxWidth="lg" sx={{ mt: 4 }}>
                <Typography>Завантаження...</Typography>
            </Container>
        );
    }

    const totalClicks = analytics.timeseries.reduce((sum, point) => sum + point.clicks, 0);

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
            <Typography variant="subtitle1" color="text.secondary" sx={{ mb: 3 }}>
                {totalClicks} {totalClicks === 1 ? 'клік' : 'кліків'} за останні 30 днів
            </Typography>

            <Paper sx={{ p: 2, mb: 3 }}>
                <Typography variant="h6" gutterBottom>
                    Кліки за днями
                </Typography>
                <LineChart
                    height={300}
                    xAxis={[{ scaleType: 'point', data: analytics.timeseries.map((p) => p.date.slice(5)) }]}
                    series={[{ data: analytics.timeseries.map((p) => p.clicks), label: 'Кліки', area: true }]}
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
                        Топ посилань
                    </Typography>
                    {analytics.top_links.length === 0 ? (
                        <Typography color="text.secondary">Немає даних.</Typography>
                    ) : (
                        <Table>
                            <TableHead>
                                <TableRow>
                                    <TableCell>Коротке посилання</TableCell>
                                    <TableCell>Ціль</TableCell>
                                    <TableCell align="right">Кліки</TableCell>
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
